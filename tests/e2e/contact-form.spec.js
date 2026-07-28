import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';
import { request as httpRequest } from 'http';
import { Buffer } from 'buffer';
import { URL, URLSearchParams } from 'url';

/**
 * Contact-form end-to-end contracts (T04-02).
 *
 * The private message record is authoritative and mail is a durable-outbox
 * notification, so these tests assert on database truth (one private
 * longevity_message row + one outbox row) rather than on an SMTP sink. No
 * mail transport is configured, which is the honest "no external mail"
 * guarantee: the outbox row is created and delivery stays pending.
 *
 * The fixture owns its page/messages and snapshots any temporarily changed
 * limiter state. It refuses production, non-local hosts, or an unconfirmed DB.
 */

const ENABLED = process.env.E2E_CONTACT_ENABLED === '1';
const DATABASE = process.env.WORDPRESS_DB_NAME || 'longevity';
if (!/^[A-Za-z0-9_]+$/.test(DATABASE)) throw new Error('Unsafe E2E contact database name.');
const WPCLI = `docker compose run --rm -e E2E_CONTACT_ENABLED=1 -e E2E_CONTACT_DATABASE=${DATABASE} wpcli`;
const FIXTURE = 'wp eval-file /scripts/e2e-contact-fixture.php';

function fixture(action) {
  const cmd = `${WPCLI} ${FIXTURE} ${action} --allow-root 2>&1`;
  return execSync(cmd, { encoding: 'utf8', timeout: 60000 });
}

/** Read the authoritative record/outbox counts as an object. */
function report() {
  const out = fixture('report');
  const line = out.split('\n').find((l) => l.trim().startsWith('{'));
  if (!line) {
    throw new Error(`Unexpected fixture report output: ${out}`);
  }
  return JSON.parse(line.trim());
}

function pathFromSetup(output) {
  const line = output.split('\n').find((value) => value.trim().startsWith('{'));
  if (!line) throw new Error(`Unexpected fixture setup output: ${output}`);
  return JSON.parse(line).path;
}

function postForm(url, fields) {
  const body = new URLSearchParams(fields).toString();
  const endpoint = new URL(url.trim());
  return new Promise((resolve, reject) => {
    const request = httpRequest(endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'Content-Length': Buffer.byteLength(body),
      },
    }, (response) => {
      response.resume();
      response.on('end', () => resolve({ status: response.statusCode, location: response.headers.location }));
    });
    request.on('error', reject);
    request.end(body);
  });
}

/** Fill the contact form's visible fields with a valid payload. */
async function fillValid(page, overrides = {}) {
  const v = {
    name: 'E2E Synthetic Tester',
    email: 'e2e-tester@example.invalid',
    subject: 'general',
    message: 'This is a synthetic end-to-end submission for automated verification only.',
    ...overrides,
  };
  await page.fill('#longevity-contact-name', v.name);
  await page.fill('#longevity-contact-email', v.email);
  await page.selectOption('#longevity-contact-subject', v.subject);
  await page.fill('#longevity-contact-message', v.message);
  return v;
}

async function submitForm(page) {
  const redirected = page.waitForURL(
    (url) => url.searchParams.has('submitted') || url.searchParams.has('contact_error'),
    { waitUntil: 'domcontentloaded', timeout: 90000 },
  );
  await page.locator('.longevity-contact-form button[type="submit"]').click({ noWaitAfter: true });
  await redirected;
}

test.describe('contact form (real submission)', () => {
  test.skip(!ENABLED, 'Set E2E_CONTACT_ENABLED=1 to run the guarded local contact suite.');
  test.describe.configure({ mode: 'serial', timeout: 120000 });

  let fixturePath;

  test.beforeEach(() => {
    fixturePath = pathFromSetup(fixture('setup'));
  });

  test.afterEach(() => {
    expect(fixture('cleanup')).toContain('contact-fixture-clean');
  });

  test('golden path: valid submission persists one private record and one outbox row', async ({ page }) => {
    expect(report()).toEqual({ messages: 0, outbox: 0, states: [] });

    await page.goto(fixturePath);
    await expect(page.locator('.longevity-contact-form')).toBeVisible();
    await fillValid(page);
    await submitForm(page);

    await expect(page.locator('.longevity-contact-success')).toBeVisible();

    const after = report();
    expect(after.messages).toBe(1);
    expect(after.outbox).toBe(1);
    expect(after.states).toEqual({ pending: 1 });
  });

  test('honeypot submission is rejected and stores no record', async ({ page }) => {
    await page.goto(fixturePath);
    await fillValid(page);
    // Populate the hidden anti-spam field a human never sees.
    await page.evaluate(() => {
      const el = document.querySelector('#longevity-website');
      if (el) {
        el.value = 'https://spam.example.invalid';
      }
    });
    await submitForm(page);

		await expect(page).toHaveURL(/contact_error=rejected/);
    await expect(page.locator('.longevity-contact-error')).toBeVisible();
    expect(report()).toEqual({ messages: 0, outbox: 0, states: [] });
  });

  test('tampered nonce is rejected with a security error and no record', async ({ page }) => {
    await page.goto(fixturePath);
    await fillValid(page);
    await page.evaluate(() => {
      const el = document.querySelector('.longevity-contact-form input[name="_wpnonce"]');
      if (el) {
        el.value = 'invalid-nonce-value';
      }
    });
    await submitForm(page);

    await expect(page.locator('.longevity-contact-error')).toBeVisible();
    expect(report()).toEqual({ messages: 0, outbox: 0, states: [] });
  });

  test('invalid email is rejected server-side and stores no record', async ({ page }) => {
    await page.goto(fixturePath);
    await fillValid(page, { email: 'not-an-email@invalid' });
    // Bypass native browser validation so the server-side check is exercised.
    await page.evaluate(() => {
      const el = document.querySelector('#longevity-contact-email');
      if (el) {
        el.setAttribute('type', 'text');
        el.value = 'not-an-email@invalid';
      }
    });
    await submitForm(page);

    await expect(page.locator('.longevity-contact-error')).toBeVisible();
    await expect(page.locator('.longevity-contact-error')).toContainText('valid email');
    expect(report()).toEqual({ messages: 0, outbox: 0, states: [] });
  });

  test('header injection is rejected before sanitization with no persistence', async ({ page }) => {
    await page.goto(fixturePath);
    await fillValid(page);
    const submission = await page.locator('.longevity-contact-form').evaluate((form) => ({
      action: form.getAttribute('action'),
      fields: Object.fromEntries(new window.FormData(form)),
    }));
    submission.fields.longevity_contact_name = 'Tester\r\nBcc: victim@example.com';
    const response = await postForm(submission.action, submission.fields);
    expect(response.status).toBe(303);
    expect(response.location).toContain('contact_error=invalid_chars');
    expect(report()).toEqual({ messages: 0, outbox: 0, states: [] });
  });

  test('oversized name is rejected server-side with the exact safe error', async ({ page }) => {
    await page.goto(fixturePath);
    await fillValid(page);
    await page.evaluate(() => {
      const input = document.querySelector('#longevity-contact-name');
      input.removeAttribute('maxlength');
      input.value = 'n'.repeat(101);
    });
    await submitForm(page);
    await expect(page.locator('.longevity-contact-error')).toContainText('name provided is too long');
    expect(report()).toEqual({ messages: 0, outbox: 0, states: [] });
  });

  test('oversized message is rejected server-side and stores no record', async ({ page }) => {
    await page.goto(fixturePath);
    await fillValid(page);
    const huge = 'x'.repeat(6000);
    await page.evaluate((value) => {
      const el = document.querySelector('#longevity-contact-message');
      if (el) {
        el.removeAttribute('maxlength');
        el.value = value;
      }
    }, huge);
    await submitForm(page);

    await expect(page.locator('.longevity-contact-error')).toBeVisible();
    await expect(page.locator('.longevity-contact-error')).toContainText('message provided is too long');
    expect(report()).toEqual({ messages: 0, outbox: 0, states: [] });
  });

  test('duplicate POST and refresh preserve one aggregate and one outbox row', async ({ page }) => {
    await page.goto(fixturePath);
    await fillValid(page);
    const submission = await page.locator('.longevity-contact-form').evaluate((form) => ({
      action: form.getAttribute('action'),
      fields: Object.fromEntries(new window.FormData(form)),
    }));

    const first = await page.request.post(submission.action, { form: submission.fields, maxRedirects: 0 });
    const duplicate = await page.request.post(submission.action, { form: submission.fields, maxRedirects: 0 });
    expect(first.status()).toBe(303);
    expect(duplicate.status()).toBe(303);

    await page.goto(`${fixturePath}?submitted=1`);
    await expect(page.locator('.longevity-contact-success')).toBeVisible();
    await page.reload();
    await expect(page.locator('.longevity-contact-success')).toBeVisible();
    expect(report()).toEqual({ messages: 1, outbox: 1, states: { pending: 1 } });
  });

  test('limiter-exceeded: repeated submissions are eventually rate limited', async ({ page }) => {
    // The limiter accepts counts up to 5; the sixth submission is blocked.
    for (let i = 0; i < 5; i++) {
      await page.goto(fixturePath);
      await fillValid(page, { message: `Synthetic accepted submission number ${i + 1}.` });
      await submitForm(page);
      await expect(page.locator('.longevity-contact-success')).toBeVisible();
    }

    await page.goto(fixturePath);
    await fillValid(page, { message: 'Synthetic submission that should be rate limited.' });
    await submitForm(page);
    await expect(page.locator('.longevity-contact-error')).toBeVisible();

    // Exactly the five accepted submissions were persisted.
    expect(report().messages).toBe(5);
  });

  test('limiter-unavailable: missing rate table fails closed with no form and no record', async ({ page }) => {
    try {
      expect(fixture('disable-limiter')).toContain('contact-limiter-disabled');

      await page.goto(fixturePath);
      // Fail closed at render time: the unavailable notice replaces the form.
      await expect(page.locator('.longevity-contact-unavailable')).toBeVisible();
      await expect(page.locator('.longevity-contact-form')).toHaveCount(0);
      expect(report()).toEqual({ messages: 0, outbox: 0, states: [] });
    } finally {
      expect(fixture('enable-limiter')).toContain('contact-limiter-enabled');
    }
  });

  test('outbox persistence failure compensates the private record and returns 503', async ({ page }) => {
    await page.goto(fixturePath);
    await fillValid(page);
    try {
      expect(fixture('disable-outbox')).toContain('contact-outbox-disabled');
      const [response] = await Promise.all([
        page.waitForNavigation({ timeout: 90000 }),
        page.locator('.longevity-contact-form button[type="submit"]').click({ noWaitAfter: true }),
      ]);
      expect(response.status()).toBe(503);
      await expect(page.locator('body')).not.toContainText('Your message was received');
    } finally {
      expect(fixture('enable-outbox')).toContain('contact-outbox-enabled');
    }
    expect(report()).toEqual({ messages: 0, outbox: 0, states: [] });
  });
});
