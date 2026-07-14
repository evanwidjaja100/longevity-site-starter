import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

for (const path of ['/', '/reviews/']) {
  test(`critical accessibility checks pass on ${path}`, async ({ page }) => {
    await page.goto(path);
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(results.violations).toEqual([]);
  });
}
