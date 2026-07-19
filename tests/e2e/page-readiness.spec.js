import { test, expect } from '@playwright/test';

test.describe('page readiness', () => {
  test('start-here page loads with correct heading', async ({ page }) => {
    await page.goto('/start-here/');
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
  });

  test('guides archive is reachable', async ({ page }) => {
    const response = await page.goto('/guides/');
    expect(response?.status()).toBe(200);
  });

  test('topics archive is reachable', async ({ page }) => {
    const response = await page.goto('/topics/');
    expect(response?.status()).toBe(200);
  });

  test('category archives resolve', async ({ page }) => {
    const response = await page.goto('/category/evidence-literacy/');
    expect(response?.status()).toBe(200);
  });

  test('consumer lab archive resolves', async ({ page }) => {
    const response = await page.goto('/reviews/');
    expect(response?.status()).toBe(200);
  });
});
