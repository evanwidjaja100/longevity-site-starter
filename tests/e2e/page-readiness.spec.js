import { test, expect } from '@playwright/test';
import { PUBLIC_PAGES, ALL_PRIVATE_ROUTES } from './support/route-expectations.js';

test.describe('page readiness', () => {
  test.describe('public pages resolve', () => {
    for (const { key, path } of PUBLIC_PAGES) {
      test(`${key} loads successfully`, async ({ page }) => {
        const response = await page.goto(path);
        expect(response?.status()).toBe(200);
      });
    }
  });

  test.describe('draft pages are not publicly accessible', () => {
    for (const { key, path } of ALL_PRIVATE_ROUTES) {
      test(`${key} returns 404`, async ({ page }) => {
        const response = await page.goto(path);
        expect(response?.status()).toBe(404);
      });
    }
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
