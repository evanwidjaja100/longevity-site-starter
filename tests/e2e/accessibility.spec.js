import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

for (const path of [
  '/',
  '/test-evidence-guide/',
  '/reviews/test-valid-review/',
  '/?s=evidence',
  '/category/evidence-literacy/',
  '/reviews/',
  '/category/evidence-literacy/?ranking_sort=confidence&confidence=Preliminary',
  '/author/lel_test_author/',
  '/test-route-that-does-not-exist/'
]) {
  test(`critical accessibility checks pass on ${path}`, async ({ page }) => {
    await page.goto(path);
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(results.violations).toEqual([]);
  });
}

test('open search dialog has no critical accessibility violations', async ({ page }) => {
  await page.goto('/');
  await page.getByRole('button', { name: 'Search' }).click();
  await expect(page.getByRole('dialog')).toBeVisible();
  const results = await new AxeBuilder({ page }).include('#lel-search-dialog').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
  expect(results.violations).toEqual([]);
});

test('ranking table headers and text statuses remain explicit', async ({ page }) => {
  await page.goto('/category/evidence-literacy/');
  await expect(page.locator('.longevity-ranking-table th[scope="col"]')).toHaveCount(6);
  await expect(page.getByText('Testing complete', { exact: true })).toHaveCount(3);
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
});
