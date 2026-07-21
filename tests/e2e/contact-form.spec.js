import { test, expect } from '@playwright/test';

/**
 * Contact page (ID 13) is draft — returns 404 to anonymous visitors.
 * The contact form shortcode and protection exist in the codebase but
 * the page requires human approval before publication per editorial policy.
 * These tests verify the corrections channel (published) and contact form
 * code paths that are testable given the page state.
 */

test('corrections page resolves and links to contact page', async ({ page }) => {
  const response = await page.goto('/corrections/');
  expect(response?.status()).toBe(200);
  await expect(page.locator('.entry-content a[href="/contact/"]')).toBeVisible();
});

test('correction log table renders on corrections page', async ({ page }) => {
  await page.goto('/corrections/');
  await expect(page.locator('.entry-content table')).toBeVisible();
});

test('corrections page has last reviewed date', async ({ page }) => {
  await page.goto('/corrections/');
  await expect(page.locator('.entry-content p:has(strong)')).toContainText('Last reviewed:');
});

test('contact page is draft and returns 404 anonymously', async ({ page }) => {
  const response = await page.goto('/contact/');
  expect(response?.status()).toBe(404);
});

test('contact form shortcode is not rendered on draft contact page', async ({ page }) => {
  await page.goto('/contact/');
  await expect(page.locator('#longevity-contact-form')).toHaveCount(0);
});
