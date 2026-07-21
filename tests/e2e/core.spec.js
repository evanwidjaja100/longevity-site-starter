import { test, expect } from '@playwright/test';


test('homepage exposes skip link, navigation, and search dialog', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('.longevity-skip-link')).toHaveAttribute('href', '#main-content');
  await expect(page.getByRole('navigation', { name: 'Primary navigation' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Search' })).toBeVisible();
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
  await expect(page.getByRole('main')).toBeVisible();
  await expect(page.getByRole('contentinfo')).toBeVisible();
});

test('search dialog opens, closes with Escape, and restores focus', async ({ page }) => {
  await page.goto('/');
  const trigger = page.getByRole('button', { name: 'Search' });
  await trigger.click();
  const dialog = page.getByRole('dialog', { name: 'Find evidence guides and product reports' });
  await expect(dialog).toBeVisible();
  await expect(dialog.getByRole('searchbox', { name: 'Search terms' })).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(trigger).toBeFocused();
});

const routes = [
  ['article', '/test-evidence-guide/'],
  ['review', '/reviews/test-valid-review/'],
  ['search', '/?s=evidence'],
  ['category', '/category/evidence-literacy/'],
  ['review archive', '/reviews/'],
  ['author', '/author/lel_test_author/']
];

for (const [name, path] of routes) {
  test(`${name} route has a unique page heading and landmark`, async ({ page }) => {
    const response = await page.goto(path);
    expect(response?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
    await expect(page.getByRole('main')).toBeVisible();
  });
}

test('evidence article renders public trust components and stable heading ids', async ({ page }) => {
  await page.goto('/test-evidence-guide/');
  await expect(page.locator('.longevity-trust-summary')).toBeVisible();
  await expect(page.getByRole('navigation', { name: 'On this page' })).toBeVisible();
  const headingIds = await page.locator('main h2[id]').evaluateAll((headings) => headings.map((heading) => heading.id));
  expect(headingIds.length).toBeGreaterThanOrEqual(3);
  expect(new Set(headingIds).size).toBe(headingIds.length);
});

test('valid product review exposes a decision, reproducible score, and test method', async ({ page }) => {
  await page.goto('/reviews/test-valid-review/');
  await expect(page.locator('.longevity-product-summary')).toBeVisible();
  await expect(page.locator('.longevity-review-score')).toBeVisible();
  await expect(page.locator('.longevity-test-method')).toBeVisible();
  await expect(page.locator('.longevity-test-results')).toBeVisible();
  await expect(page.getByText('Testing complete', { exact: true })).toBeVisible();
});

test('Consumer Lab directory and category ranking use eligible records only', async ({ page }) => {
  await page.goto('/reviews/');
  await expect(page.locator('.longevity-ranking-directory')).toBeVisible();
  await expect(page.getByText('3 eligible reports')).toBeVisible();
  await expect(page.getByText('[TEST] Blocked incomplete review')).toHaveCount(0);

  await page.goto('/category/evidence-literacy/');
  const rows = page.locator('.longevity-ranking-table tbody tr');
  await expect(rows).toHaveCount(3);
  await expect(rows.first()).toContainText('[TEST] Alpha product report');
});

test('ranking sorts and filters remain GET-based and linkable', async ({ page }) => {
  await page.goto('/category/evidence-literacy/?ranking_sort=title');
  const titles = await page.locator('.longevity-ranking-table tbody th a').allTextContents();
  expect(titles).toEqual([...titles].sort((a, b) => a.localeCompare(b)));

  await page.goto('/category/evidence-literacy/?ranking_sort=confidence&confidence=Preliminary');
  await expect(page).toHaveURL(/confidence=Preliminary/);
  await expect(page.locator('.longevity-ranking-table tbody tr')).toHaveCount(1);
  await expect(page.locator('.longevity-ranking-table tbody tr')).toContainText('[TEST] Zeta product report');
});

test('affiliate disclosure precedes the approved synthetic merchant link', async ({ page }) => {
  await page.goto('/reviews/test-alpha-review/');
  const disclosure = page.locator('.longevity-disclosure');
  const seller = page.locator('a[rel~="sponsored"]');
  await expect(disclosure).toBeVisible();
  await expect(seller).toHaveAttribute('href', /merchant\.example\.invalid/);
  const precedes = await disclosure.evaluate((node, other) => Boolean(node.compareDocumentPosition(other) & 4), await seller.elementHandle());
  expect(precedes).toBeTruthy();
});

test('incomplete review remains unavailable publicly', async ({ page }) => {
  const response = await page.goto('/reviews/test-blocked-review/');
  expect(response?.status()).toBe(404);
});

test('search accepts safe filters and reports its result context', async ({ page }) => {
  await page.goto('/?s=evidence&content_type=guide&sort=newest');
  await expect(page.getByRole('heading', { level: 1 })).toContainText('evidence');
  await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
});

test('404 provides a useful recovery route', async ({ page }) => {
  const response = await page.goto('/test-route-that-does-not-exist/');
  expect(response?.status()).toBe(404);
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  await expect(page.getByRole('searchbox', { name: 'Search Longevity Evidence Lab' })).toBeVisible();
});

test('skip link and primary navigation work from the keyboard', async ({ page }) => {
  await page.goto('/');
  await page.evaluate(() => document.activeElement?.blur());
  await page.keyboard.press('Tab');
  await expect(page.locator('.longevity-skip-link')).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.locator('#main-content')).toBeFocused();

  await page.setViewportSize({ width: 360, height: 800 });
  const menuButton = page.getByRole('button', { name: /menu/i });
  if (await menuButton.count()) {
    await menuButton.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('navigation', { name: 'Primary navigation' })).toBeVisible();
  }
});

test('key public routes reflow at 320px without page-level horizontal scrolling', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 800 });

  for (const path of ['/', '/category/evidence-literacy/', '/reviews/test-valid-review/']) {
    await page.goto(path);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow, `${path} should not overflow the 320px viewport`).toBeLessThanOrEqual(1);
  }
});

test('health endpoint reports ok', async ({ request }) => {
  const response = await request.get('/wp-json/longevity/v1/health');
  expect(response.ok()).toBeTruthy();
  const json = await response.json();
  expect(json.status).toBe('ok');
});
