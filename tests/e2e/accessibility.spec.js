import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

for (const path of [
  '/',
  '/test-evidence-guide/',
  '/reviews/test-valid-review/',
  '/?s=evidence',
  '/category/evidence-literacy/',
  '/reviews/',
  '/author/lel_test_author/',
  '/test-route-that-does-not-exist/'
]) {
  test(`critical accessibility checks pass on ${path}`, async ({ page }) => {
    await page.goto(path);
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(results.violations).toEqual([]);
  });
}
