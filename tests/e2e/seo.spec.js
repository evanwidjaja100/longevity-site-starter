import { test, expect } from '@playwright/test';
import { ALL_PUBLIC_ROUTES } from './support/route-expectations.js';

test.describe('SEO metadata', () => {
  for (const { key, path } of ALL_PUBLIC_ROUTES) {
    const fullPath = path === '/?s=' ? '/?s=evidence' : path;

    test(`${key} has exactly one H1 and a visible main landmark`, async ({ page }) => {
      const response = await page.goto(fullPath);
      expect(response?.status()).toBe(200);
      await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
      await expect(page.getByRole('main')).toBeVisible();
    });

    test(`${key} has a nonempty meta description`, async ({ page }) => {
      await page.goto(fullPath);
      const description = await page.locator('meta[name="description"]').getAttribute('content');
      expect(description).toBeTruthy();
      expect(description?.trim().length).toBeGreaterThan(0);
    });

    test(`${key} has a canonical link`, async ({ page }) => {
      await page.goto(fullPath);
      const canonical = await page.locator('link[rel="canonical"]').getAttribute('href');
      expect(canonical).toBeTruthy();
    });

    test(`${key} has Open Graph meta tags`, async ({ page }) => {
      await page.goto(fullPath);
      await expect(page.locator('meta[property="og:title"]')).toHaveCount(1);
      await expect(page.locator('meta[property="og:description"]')).toHaveCount(1);
      await expect(page.locator('meta[property="og:url"]')).toHaveCount(1);
      await expect(page.locator('meta[property="og:type"]')).toHaveCount(1);
    });

    test(`${key} has Twitter card meta tags`, async ({ page }) => {
      await page.goto(fullPath);
      await expect(page.locator('meta[name="twitter:card"]')).toHaveCount(1);
      await expect(page.locator('meta[name="twitter:title"]')).toHaveCount(1);
      await expect(page.locator('meta[name="twitter:description"]')).toHaveCount(1);
    });
  }

  test('search results page has noindex robots directive', async ({ page }) => {
    await page.goto('/?s=evidence');
    const robots = await page.locator('meta[name="robots"]').getAttribute('content');
    expect(robots).toContain('noindex');
  });

  test('draft page returns 404 with noindex', async ({ page }) => {
    const response = await page.goto('/guides/');
    expect(response?.status()).toBe(404);
    const robots = await page.locator('meta[name="robots"]').getAttribute('content');
    if (robots) {
      expect(robots).toContain('noindex');
    }
  });
});
