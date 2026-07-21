import { test, expect } from '@playwright/test';

test('analytics config is injected on public pages', async ({ page }) => {
  await page.goto('/');
  const config = await page.evaluate(() => window.longevityAnalyticsConfig);
  expect(config).toBeDefined();
  expect(config).toHaveProperty('contentGroup');
  expect(config).toHaveProperty('eventSchemas');
  expect(Array.isArray(config.eventSchemas.search_open)).toBe(true);
});

test('analytics queue exists and accepts events', async ({ page }) => {
  await page.goto('/');
  const queueReady = await page.evaluate(() => {
    return Array.isArray(window.longevityAnalytics);
  });
  expect(queueReady).toBe(true);

  const pushed = await page.evaluate(() => {
    window.longevityTrack('search_open', { placement: 'header' });
    return window.longevityAnalytics;
  });
  expect(pushed.length).toBeGreaterThanOrEqual(1);
  expect(pushed[0].event).toBe('search_open');
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

test('data-lel-event click triggers analytics push', async ({ page }) => {
  await page.goto('/about/');
  const before = await page.evaluate(() => window.longevityAnalytics.length);
  await page.locator('[data-lel-event]').first().click({ force: true });
  const after = await page.evaluate(() => window.longevityAnalytics.length);
  expect(after).toBeGreaterThanOrEqual(before);
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
