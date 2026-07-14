import { test, expect } from '@playwright/test';

test('homepage exposes skip link, navigation, and search', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('.longevity-skip-link')).toHaveAttribute('href', '#main-content');
  await expect(page.getByRole('navigation', { name: 'Primary navigation' })).toBeVisible();
  await expect(page.getByRole('searchbox')).toBeVisible();
});

test('health endpoint reports ok', async ({ request }) => {
  const response = await request.get('/wp-json/longevity/v1/health');
  expect(response.ok()).toBeTruthy();
  const json = await response.json();
  expect(json.status).toBe('ok');
});
