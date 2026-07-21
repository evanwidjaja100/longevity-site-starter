import { test, expect } from '@playwright/test';
import { ALL_PUBLIC_ROUTES } from './support/route-expectations.js';

const VIEWPORTS = [
  { name: 'mobile', width: 360, height: 800 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'desktop', width: 1440, height: 1000 },
];

test.describe.configure({ mode: 'parallel' });

for (const route of ALL_PUBLIC_ROUTES) {
  for (const viewport of VIEWPORTS) {
    test(`${route.key} @ ${viewport.name} matches snapshot`, async ({ page }) => {
      const testPath = route.path.endsWith('=') ? `${route.path}evidence` : route.path;
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await page.goto(testPath);
      await expect(page.getByRole('main')).toBeVisible({ timeout: 15000 });
      await expect(page).toHaveScreenshot(`${route.key}-${viewport.name}.png`, {
        animations: 'disabled',
        fullPage: true,
        maxDiffPixelRatio: 0.02,
      });
    });
  }
}

test.describe('visual edge cases', () => {
  for (const viewport of VIEWPORTS) {
    test(`empty search @ ${viewport.name}`, async ({ page }) => {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await page.goto('/?s=nonexistent-search-term-xyz');
      await expect(page.getByRole('main')).toBeVisible();
      await expect(page).toHaveScreenshot(`empty-search-${viewport.name}.png`, {
        animations: 'disabled',
        fullPage: true,
        maxDiffPixelRatio: 0.02,
      });
    });

    test(`404 @ ${viewport.name}`, async ({ page }) => {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await page.goto('/this-route-does-not-exist/');
      await expect(page.locator('body')).toBeVisible();
      await expect(page).toHaveScreenshot(`404-${viewport.name}.png`, {
        animations: 'disabled',
        fullPage: true,
        maxDiffPixelRatio: 0.02,
      });
    });
  }
});
