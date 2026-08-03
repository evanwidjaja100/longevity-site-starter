import { test, expect } from '@playwright/test';

test('analytics config is injected on public pages', async ({ page }) => {
  await page.goto('/');
  const config = await page.evaluate(() => {
    const el = document.getElementById('longevity-analytics-config');
    return el ? JSON.parse(el.textContent) : null;
  });
  expect(config).not.toBeNull();
  expect(config).toHaveProperty('contentGroup');
  expect(config).toHaveProperty('eventSchemas');
  expect(Array.isArray(config.eventSchemas.search_open)).toBe(true);
  expect(Array.isArray(config.eventSchemas.start_here_open)).toBe(true);
  expect(Array.isArray(config.eventSchemas.topic_open)).toBe(true);
});

test('analytics queue exists and accepts events', async ({ page }) => {
  await page.goto('/');
  const queueReady = await page.evaluate(() => {
    return Array.isArray(window.longevityAnalytics);
  });
  expect(queueReady).toBe(true);

  const result = await page.evaluate(() => {
    const before = window.longevityAnalytics.length;
    window.longevityTrack('search_open', { placement: 'header' });
    return { before, after: window.longevityAnalytics.length, last: window.longevityAnalytics[window.longevityAnalytics.length - 1] };
  });
  expect(result.after).toBe(result.before + 1);
  expect(result.last.event).toBe('search_open');
  expect(result.last.placement).toBe('header');
  const allowedKeys = ['event', 'placement', 'content_id', 'content_group'];
  expect(Object.keys(result.last).every((k) => allowedKeys.includes(k))).toBe(true);
});

test('analytics rejects unknown events', async ({ page }) => {
  await page.goto('/');
  const { before, after } = await page.evaluate(() => {
    const b = window.longevityAnalytics.length;
    window.longevityTrack('unknown_event', {});
    return { before: b, after: window.longevityAnalytics.length };
  });
  expect(after).toBe(before);
});

test('analytics rejects prohibited payloads (long values)', async ({ page }) => {
  await page.goto('/');
  const pushed = await page.evaluate(() => {
    window.longevityTrack('search_open', { placement: 'x'.repeat(200) });
    return window.longevityAnalytics[window.longevityAnalytics.length - 1];
  });
  expect(pushed.placement.length).toBeLessThanOrEqual(120);
});

test('consent defaults to false', async ({ page }) => {
  await page.goto('/');
  const consent = await page.evaluate(() => window.longevityConsent);
  expect(consent.analytics).toBe(false);
  expect(consent.advertising).toBe(false);
});

test('data-lel-event click triggers exactly one analytics push', async ({ page }) => {
  await page.goto('/about/');
  const before = await page.evaluate(() => window.longevityAnalytics.length);
  await page.locator('[data-lel-event]').first().click({ force: true });
  const after = await page.evaluate(() => window.longevityAnalytics.length);
  expect(after).toBe(before + 1);
});

test('analytics payload omits empty allowlisted fields', async ({ page }) => {
  await page.goto('/');
  const pushed = await page.evaluate(() => {
    window.longevityTrack('search_open', { placement: '' });
    return window.longevityAnalytics[window.longevityAnalytics.length - 1];
  });
  expect(pushed.event).toBe('search_open');
  expect(Object.prototype.hasOwnProperty.call(pushed, 'placement')).toBe(false);
});

test('search_open event is wired on search button', async ({ page }) => {
  await page.goto('/');
  const hasSearchEvent = await page.evaluate(() => {
    const btn = document.querySelector('[data-lel-event="search_open"]');
    return btn !== null && btn.getAttribute('data-placement') === 'header';
  });
  expect(hasSearchEvent).toBe(true);
});

test('evidence_summary_open event wired on trust summary', async ({ page }) => {
  await page.goto('/test-evidence-guide/');
  const hasEvent = await page.evaluate(() => {
    const el = document.querySelector('[data-lel-event="evidence_summary_open"]');
    return el !== null;
  });
  expect(hasEvent).toBe(true);
});

test('start_here_open event wired on homepage hero button', async ({ page }) => {
  await page.goto('/');
  const el = await page.locator('[data-lel-event="start_here_open"][data-placement="hero"]');
  await expect(el).toBeVisible();
});

test('topic_open events wired on homepage topic navigation', async ({ page }) => {
  await page.goto('/');
  const count = await page.locator('[data-lel-event="topic_open"]').count();
  expect(count).toBeGreaterThanOrEqual(1);
});

test('methodology_open event wired on choose-your-path card', async ({ page }) => {
  await page.goto('/');
  const el = await page.locator('[data-lel-event="methodology_open"][data-placement="path-cards"]');
  await expect(el).toBeVisible();
});

test('analytics custom event dispatches on push', async ({ page }) => {
  await page.goto('/');
  const detail = await page.evaluate(() => {
    return new Promise((resolve) => {
      window.addEventListener('longevity:analytics', (e) => resolve(e.detail), { once: true });
      window.longevityTrack('search_open', { placement: 'header' });
    });
  });
  expect(detail).toBeDefined();
  expect(detail.event).toBe('search_open');
  expect(detail.placement).toBe('header');
});
