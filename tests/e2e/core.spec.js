import { test, expect } from '@playwright/test';

test('homepage exposes skip link, navigation, and search', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('.longevity-skip-link')).toHaveAttribute('href', '#main-content');
  await expect(page.getByRole('navigation', { name: 'Primary navigation' })).toBeVisible();
  await expect(page.getByRole('searchbox')).toBeVisible();
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
  await expect(page.getByRole('main')).toBeVisible();
  await expect(page.getByRole('contentinfo')).toBeVisible();
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
  await expect(page.locator('.longevity-review-decision')).toBeVisible();
  await expect(page.locator('.longevity-review-score')).toBeVisible();
  await expect(page.locator('.longevity-test-method')).toBeVisible();
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
  await page.getByRole('heading', { level: 1 }).click();
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

test('health endpoint reports ok', async ({ request }) => {
  const response = await request.get('/wp-json/longevity/v1/health');
  expect(response.ok()).toBeTruthy();
  const json = await response.json();
  expect(json.status).toBe('ok');
});
