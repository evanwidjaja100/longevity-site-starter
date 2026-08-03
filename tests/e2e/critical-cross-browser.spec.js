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
      await expect(page.getByRole('navigation', { name: 'Primary navigation', exact: true })).toBeVisible();
      await expect(page.getByRole('link', { name: 'Skip to content', exact: true })).toHaveCount(1);
      await expect(page.locator('#main-content')).toBeVisible();
    });
  }

  test('mobile overlay opens and closes', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 800 });
    await page.goto('/');
    const toggle = page.getByRole('button', { name: 'Open menu', exact: true });
    const overlay = page.locator('.wp-block-navigation__responsive-container');
    await expect(toggle).toBeVisible();
    await toggle.click();
    await expect(overlay).toHaveClass(/is-menu-open/);
    const close = page.getByRole('button', { name: 'Close menu', exact: true });
    await expect(close).toBeVisible();
    await close.click();
    await expect(overlay).not.toHaveClass(/is-menu-open/);
    await expect(toggle).toBeFocused();
  });

  test('search dialog opens and has input', async ({ page }) => {
    await page.goto('/');
    const trigger = page.getByRole('button', { name: 'Search', exact: true });
    const dialog = page.getByRole('dialog', { name: 'Find evidence guides and product reports', exact: true });
    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('searchbox', { name: 'Search terms', exact: true })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(dialog).not.toBeVisible();
    await expect(trigger).toBeFocused();
  });

  test('search filters interact', async ({ page }) => {
    await page.goto('/?s=evidence');
    await page.getByRole('combobox', { name: 'Content type', exact: true }).selectOption('review');
    await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
    await expect(page.locator('.longevity-result-count')).toBeVisible();
  });

  test('ranking filters interact', async ({ page }) => {
    await page.goto('/category/evidence-literacy/');
    const sortSelect = page.getByRole('combobox', { name: 'Sort rankings', exact: true });
    await expect(sortSelect).toBeVisible();
    await sortSelect.selectOption('confidence');
    await page.getByRole('button', { name: 'Apply', exact: true }).click();
    await expect(page.getByRole('table')).toBeVisible();
  });

  test('skip link moves focus', async ({ page, browserName }) => {
    await page.goto('/');
    const skipLink = page.getByRole('link', { name: 'Skip to content', exact: true });
    // Headless WebKit emulates Safari with full keyboard access disabled, which
    // intentionally skips links in the Tab order; focus the native link as that
    // browser setting would, then verify the same activation and target focus.
    if (browserName === 'webkit') await skipLink.focus();
    else await page.keyboard.press('Tab');
    await expect(skipLink).toBeFocused();
    await expect(skipLink).toBeVisible();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/#main-content$/);
    await expect(page.locator('#main-content')).toBeFocused();
  });

  test('forms and focus are accessible', async ({ page }) => {
    await page.goto('/?s=evidence');
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    const searchInput = page
      .getByRole('dialog', { name: 'Find evidence guides and product reports', exact: true })
      .getByRole('searchbox', { name: 'Search terms', exact: true });
    await expect(searchInput).toBeFocused();
    await searchInput.fill('example');
    await page.keyboard.press('Enter');
    await expect(page.locator('main')).toBeVisible();
  });
});
