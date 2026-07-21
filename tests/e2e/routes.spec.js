import { test, expect } from '@playwright/test';
import {
  PUBLIC_PAGES,
  PUBLIC_CATEGORIES,
  ALL_PUBLIC_ROUTES,
  ALL_PRIVATE_ROUTES,
  LEGACY_REDIRECTS,
  SEARCH_PATH,
} from './support/route-expectations.js';

test.describe('public route resolution', () => {
  for (const { key, path } of ALL_PUBLIC_ROUTES) {
    test(`${key} returns 200 with correct pathname`, async ({ page }) => {
      const fullPath = path === SEARCH_PATH ? `${SEARCH_PATH}test` : path;
      const response = await page.goto(fullPath);
      expect(response?.status()).toBe(200);
      const expected = new URL(fullPath, 'http://localhost:8080').pathname;
      expect(new URL(page.url()).pathname).toBe(expected);
    });
  }
});

test.describe('page routes have exactly one H1', () => {
  for (const { key, path } of PUBLIC_PAGES) {
    test(`${key} has exactly one H1`, async ({ page }) => {
      const response = await page.goto(path);
      expect(response?.status()).toBe(200);
      await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
    });
  }
});

test.describe('draft pages return 404', () => {
  for (const { key, path } of ALL_PRIVATE_ROUTES) {
    test(`${key} returns 404`, async ({ page }) => {
      const response = await page.goto(path);
      expect(response?.status()).toBe(404);
    });
  }
});

test.describe('legacy category redirects', () => {
  for (const { key, from, to } of LEGACY_REDIRECTS) {
    test(`${key} returns 301 from ${from} to ${to}`, async ({ request }) => {
      const response = await request.get(from, { maxRedirects: 0 });
      expect(response.status()).toBe(301);
      const location = response.headers()['location'] || '';
      expect(location).toContain(to);
    });
  }
});

test('unknown route returns 404', async ({ page }) => {
  const response = await page.goto('/this-path-does-not-exist/');
  expect(response?.status()).toBe(404);
});

test('search page has accessible search form', async ({ page }) => {
  await page.goto('/');
  const searchButton = page.getByRole('button', { name: /search/i });
  await searchButton.click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  const searchInput = dialog.getByRole('searchbox');
  await expect(searchInput).toBeVisible();
  await searchInput.fill('test');
  await searchInput.press('Enter');
  await page.waitForURL('**/?s=test');
  expect(page.url()).toContain('s=test');
});

test('no duplicate bootstrap pages exist via REST API', async ({ request }) => {
  const slugs = PUBLIC_PAGES.map(p => p.path.replace(/^\/|\/$/g, '')).filter(Boolean);
  for (const slug of slugs) {
    const response = await request.get(`/wp-json/wp/v2/pages?slug=${slug}`);
    expect(response.ok()).toBe(true);
    const pages = await response.json();
    expect(Array.isArray(pages)).toBe(true);
    expect(pages.length).toBe(1, `Expected exactly 1 page with slug '${slug}', found ${pages.length}`);
  }
});

test('no duplicate bootstrap categories exist via REST API', async ({ request }) => {
  const slugs = PUBLIC_CATEGORIES.map(p => p.path.split('/').filter(Boolean).pop());
  for (const slug of slugs) {
    const response = await request.get(`/wp-json/wp/v2/categories?slug=${slug}`);
    expect(response.ok()).toBe(true);
    const cats = await response.json();
    expect(Array.isArray(cats)).toBe(true);
    expect(cats.length).toBe(1, `Expected exactly 1 category with slug '${slug}', found ${cats.length}`);
  }
});
