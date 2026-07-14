import { test, expect } from '@playwright/test';

const pages = [
  ['home', '/'],
  ['article', '/test-evidence-guide/'],
  ['review', '/reviews/test-valid-review/'],
  ['search', '/?s=evidence'],
  ['archive', '/reviews/']
];

for (const [name, path] of pages) {
  test(`${name} captures desktop and mobile visual evidence`, async ({ page }, testInfo) => {
    for (const viewport of [
      { name: 'desktop', width: 1440, height: 1000 },
      { name: 'mobile', width: 360, height: 800 }
    ]) {
      await page.setViewportSize(viewport);
      await page.goto(path);
      await expect(page.getByRole('main')).toBeVisible();
      await page.screenshot({
        animations: 'disabled',
        fullPage: true,
        path: testInfo.outputPath(`${name}-${viewport.name}.png`)
      });
    }
  });
}
