import { test, expect } from '@playwright/test';
import { PUBLIC_PAGES, PUBLIC_CATEGORIES } from './support/route-expectations.js';

test.describe('critical cross-browser paths', () => {
  const criticalRoutes = [
    ...PUBLIC_PAGES.filter(p => ['home', 'start_here', 'topics'].includes(p.key)),
    ...PUBLIC_CATEGORIES.slice(0, 2),
  ];

  for (const route of criticalRoutes) {
    test(`header nav and skip link on ${route.key} (${route.path})`, async ({ page }) => {
      await page.goto(route.path);
      await expect(page.getByRole('navigation')).toBeVisible();
      await expect(page.getByRole('link', { name: 'Skip to main content' })).toBeVisible();
      await expect(page.locator('#main-content')).toBeVisible();
    });
  }

  test('mobile overlay opens and closes', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 800 });
    await page.goto('/');
    const toggle = page.getByRole('button', { name: /menu|navigation/i }).first();
    if (await toggle.isVisible()) {
      await toggle.click();
      await expect(page.getByRole('dialog').or(page.getByRole('navigation').nth(1))).toBeVisible({ timeout: 5000 });
      const close = page.getByRole('button', { name: /close|dismiss/i }).first();
      if (await close.isVisible()) {
        await close.click();
        await expect(page.getByRole('dialog')).not.toBeVisible();
      }
    }
  });

  test('search dialog opens and has input', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('button', { name: /search/i }).first().click();
    await expect(page.getByRole('dialog')).toBeVisible({ timeout: 5000 });
    await expect(page.getByRole('searchbox').or(page.getByPlaceholder(/search/i))).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).not.toBeVisible();
  });

  test('search filters interact', async ({ page }) => {
    await page.goto('/?s=evidence');
    await page.getByRole('combobox', { name: /content type|type/i }).first().selectOption('review');
    await page.getByRole('button', { name: /apply/i }).first().click();
    await expect(page.locator('.longevity-result-count')).toBeVisible();
  });

  test('ranking filters interact', async ({ page }) => {
    await page.goto('/category/evidence-literacy/');
    const sortSelect = page.getByRole('combobox', { name: /sort/i });
    if (await sortSelect.isVisible()) {
      await sortSelect.selectOption('confidence');
      await page.getByRole('button', { name: /apply/i }).click();
      await expect(page.getByRole('table')).toBeVisible();
    }
  });

  test('skip link moves focus', async ({ page }) => {
    await page.goto('/');
    await page.keyboard.press('Tab');
    await page.keyboard.press('Enter');
    await expect(page.locator('#main-content')).toBeFocused();
  });

  test('forms and focus are accessible', async ({ page }) => {
    await page.goto('/?s=evidence');
    const searchInput = page.getByRole('searchbox').first();
    await searchInput.focus();
    await expect(searchInput).toBeFocused();
    await searchInput.fill('example');
    await page.keyboard.press('Enter');
    await expect(page.locator('main')).toBeVisible();
  });
});
