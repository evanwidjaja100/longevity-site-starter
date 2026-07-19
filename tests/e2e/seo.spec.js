import { test, expect } from '@playwright/test';

test.describe('SEO metadata', () => {
  const publicRoutes = [
    ['homepage', '/'],
    ['start here', '/start-here/'],
    ['category archive', '/category/evidence-literacy/'],
    ['review archive', '/reviews/'],
    ['search results', '/?s=evidence'],
  ];

  for (const [name, path] of publicRoutes) {
    test(`${name} has exactly one H1 and a visible main landmark`, async ({ page }) => {
      const response = await page.goto(path);
      expect(response?.status()).toBe(200);
      await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
      await expect(page.getByRole('main')).toBeVisible();
    });

    test(`${name} has a nonempty meta description`, async ({ page }) => {
      await page.goto(path);
      const description = await page.locator('meta[name="description"]').getAttribute('content');
      expect(description).toBeTruthy();
      expect(description?.trim().length).toBeGreaterThan(0);
    });

    test(`${name} has a canonical link`, async ({ page }) => {
      await page.goto(path);
      const canonical = await page.locator('link[rel="canonical"]').getAttribute('href');
      expect(canonical).toBeTruthy();
    });

    test(`${name} has Open Graph meta tags`, async ({ page }) => {
      await page.goto(path);
      await expect(page.locator('meta[property="og:title"]')).toHaveCount(1);
      await expect(page.locator('meta[property="og:description"]')).toHaveCount(1);
      await expect(page.locator('meta[property="og:url"]')).toHaveCount(1);
      await expect(page.locator('meta[property="og:type"]')).toHaveCount(1);
    });

    test(`${name} has Twitter card meta tags`, async ({ page }) => {
      await page.goto(path);
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

  test('placeholder page (draft) has noindex', async ({ page }) => {
    await page.goto('/guides/');
    const robots = await page.locator('meta[name="robots"]').getAttribute('content');
    expect(robots).toContain('noindex');
  });
});
