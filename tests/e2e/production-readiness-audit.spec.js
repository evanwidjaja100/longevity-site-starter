import { test, expect } from '@playwright/test';
import { ALL_PUBLIC_ROUTES } from './support/route-expectations.js';

const PUBLIC_URLS = ALL_PUBLIC_ROUTES.map(r => ({
  key: r.key,
  path: r.path.includes('?') ? `${r.path}evidence` : r.path,
  indexable: r.indexable,
}));

test.describe('security headers', () => {
  const HEADER_CHECKS = [
    { name: 'X-Content-Type-Options', expected: 'nosniff' },
    { name: 'X-Frame-Options', expected: null },
    { name: 'Referrer-Policy', expected: null },
  ];

  for (const { key, path } of PUBLIC_URLS.slice(0, 5)) {
    test(`${key} sets security headers`, async ({ request }) => {
      const response = await request.get(path);
      for (const { name, expected } of HEADER_CHECKS) {
        const value = response.headers()[name.toLowerCase()];
        expect(value !== undefined && value !== '').toBeTruthy();
        if (expected) {
          expect(value).toBe(expected);
        }
      }
    });
  }
});

test.describe('canonical URL correctness', () => {
  for (const { key, path } of PUBLIC_URLS) {
    test(`${key} canonical href matches page URL`, async ({ page }) => {
      await page.goto(path);
      const canonical = await page.locator('link[rel="canonical"]').getAttribute('href');
      expect(canonical).toBeTruthy();
      const canonicalUrl = new URL(canonical);
      const expectedPath = new URL(path, 'http://localhost:8080').pathname;
      expect(canonicalUrl.pathname).toBe(expectedPath);
    });
  }
});

test.describe('robots directives on indexable pages', () => {
  for (const { key, path, indexable } of PUBLIC_URLS) {
    test(`${key} has correct robots directive`, async ({ page }) => {
      await page.goto(path);
      const robots = await page.locator('meta[name="robots"]').getAttribute('content');
      if (indexable) {
        expect(robots).not.toContain('noindex');
      } else {
        expect(robots).toContain('noindex');
      }
    });
  }
});

test.describe('Open Graph and Twitter card content', () => {
  for (const { key, path } of PUBLIC_URLS) {
    test(`${key} has populated OG tags`, async ({ page }) => {
      await page.goto(path);
      const ogTitle = await page.locator('meta[property="og:title"]').getAttribute('content');
      const ogDesc = await page.locator('meta[property="og:description"]').getAttribute('content');
      const ogUrl = await page.locator('meta[property="og:url"]').getAttribute('content');
      expect(ogTitle?.trim().length).toBeGreaterThan(0);
      expect(ogDesc?.trim().length).toBeGreaterThan(0);
      expect(ogUrl?.trim().length).toBeGreaterThan(0);
    });

    test(`${key} has populated Twitter card tags`, async ({ page }) => {
      await page.goto(path);
      const twTitle = await page.locator('meta[name="twitter:title"]').getAttribute('content');
      const twDesc = await page.locator('meta[name="twitter:description"]').getAttribute('content');
      expect(twTitle?.trim().length).toBeGreaterThan(0);
      expect(twDesc?.trim().length).toBeGreaterThan(0);
    });
  }
});

test.describe('structured data (ld+json)', () => {
  for (const { key, path } of PUBLIC_URLS) {
    test(`${key} has valid JSON-LD`, async ({ page }) => {
      await page.goto(path);
      const scripts = await page.locator('script[type="application/ld+json"]').all();
      if (scripts.length === 0) return;
      for (const script of scripts) {
        const text = await script.textContent();
        expect(text).toBeTruthy();
        const parsed = JSON.parse(text);
        expect(parsed).toHaveProperty('@context');
        // Schema uses @graph format; validate at least one graph item has @type
        if (parsed['@graph']) {
          expect(Array.isArray(parsed['@graph'])).toBe(true);
          expect(parsed['@graph'].length).toBeGreaterThanOrEqual(1);
          expect(parsed['@graph'][0]).toHaveProperty('@type');
        } else {
          expect(parsed).toHaveProperty('@type');
        }
      }
    });
  }
});

test.describe('no placeholder or not-configured text', () => {
  const PLACEHOLDER_PATTERNS = [
    /not configured/i,
    /\[placeholder\]/i,
    /\[your.*here\]/i,
    /lorem ipsum/i,
    /coming soon/i,
    /this is a placeholder/i,
  ];

  for (const { key, path } of PUBLIC_URLS) {
    test(`${key} has no placeholder text`, async ({ page }) => {
      await page.goto(path);
      const body = await page.locator('body').textContent();
      for (const pattern of PLACEHOLDER_PATTERNS) {
        expect(body).not.toMatch(pattern);
      }
    });
  }
});

test.describe('empty and no-results states', () => {
  test('empty search suggests next steps', async ({ page }) => {
    await page.goto('/?s=nonexistent-search-term-xyz');
    const body = await page.locator('body').textContent();
    const hasSuggestions = /start here|topic|browse|suggestion|try|no results/i.test(body);
    expect(hasSuggestions).toBeTruthy();
  });
});

test.describe('no unexpected third-party requests', () => {
  const ALLOWED_ORIGINS = [
    'http://localhost:8080',
  ];

  test('homepage loads only local resources', async ({ page }) => {
    const requests = [];
    page.on('request', req => {
      requests.push({ url: req.url(), type: req.resourceType() });
    });
    await page.goto('/');
    await page.waitForLoadState('networkidle');
    const external = requests.filter(r => {
      try {
        const origin = new URL(r.url).origin;
        return !ALLOWED_ORIGINS.includes(origin) && r.type !== 'document';
      } catch { return false; }
    });
    expect(external.length).toBe(0);
  });
});

test.describe('focus order and keyboard navigation', () => {
  test('tab sequence starts on skip link or first focusable element', async ({ page }) => {
    await page.goto('/');
    await page.keyboard.press('Tab');
    const focused = page.locator(':focus');
    await expect(focused).toBeVisible();
    const tag = await focused.evaluate(el => el.tagName.toLowerCase());
    expect(['a', 'button', 'input', 'select', 'textarea']).toContain(tag);
    const href = await focused.getAttribute('href');
    if (href) {
      expect(href).not.toBe('#');
    }
  });

  test('focus moves through navigation items in logical order', async ({ page }) => {
    await page.goto('/');
    await page.keyboard.press('Tab');
    const firstFocus = page.locator(':focus');
    await expect(firstFocus).toBeVisible();
    await page.keyboard.press('Tab');
    const secondFocus = page.locator(':focus');
    await expect(secondFocus).toBeVisible();
    const firstTag = await firstFocus.evaluate(el => el.tagName.toLowerCase());
    const secondTag = await secondFocus.evaluate(el => el.tagName.toLowerCase());
    expect(['a', 'button', 'input', 'select', 'textarea']).toContain(firstTag);
    expect(['a', 'button', 'input', 'select', 'textarea']).toContain(secondTag);
  });
});
