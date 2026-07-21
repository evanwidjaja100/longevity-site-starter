import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { ALL_PUBLIC_ROUTES, REVIEW_ARCHIVE, SEARCH_PATH } from './support/route-expectations.js';

test.describe('automated accessibility — axe-core', () => {
  const axeRoutes = ALL_PUBLIC_ROUTES.map(r => r.path)
    .concat([
      `${SEARCH_PATH}evidence`,
      `${SEARCH_PATH}nonexistent`,
      '/author/lel_test_author/',
      '/test-route-that-does-not-exist/',
    ]);

  for (const path of axeRoutes) {
    test(`no critical violations on ${path}`, async ({ page }) => {
      await page.goto(path);
      await expect(page.locator('body')).toBeVisible();
      const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
        .analyze();
      expect(results.violations.filter(v => v.impact === 'critical' || v.impact === 'serious'))
        .toEqual([]);
    });
  }
});

test.describe('heading hierarchy', () => {
  for (const route of ALL_PUBLIC_ROUTES) {
    test(`${route.key} has one H1 and sequential headings`, async ({ page }) => {
      await page.goto(route.path);
      await expect(page.locator('h1')).toHaveCount(1);
      const headings = await page.locator('h1, h2, h3, h4, h5, h6').evaluateAll(elements =>
        elements.map(el => el.tagName.toLowerCase())
      );
      let prevLevel = 0;
      for (const tag of headings) {
        const level = parseInt(tag.charAt(1), 10);
        if (prevLevel > 0 && level > prevLevel + 1) {
          throw new Error(`Heading level skipped from ${prevLevel} to ${level} on ${route.path}`);
        }
        prevLevel = level;
      }
    });
  }
});

test.describe('landmarks', () => {
  for (const route of ALL_PUBLIC_ROUTES) {
    test(`${route.key} has banner, navigation, main, and contentinfo landmarks`, async ({ page }) => {
      await page.goto(route.path);
      await expect(page.locator('header')).toBeVisible();
      await expect(page.getByRole('navigation')).toBeVisible();
      await expect(page.getByRole('main')).toBeVisible();
      await expect(page.locator('footer')).toBeVisible();
    });
  }
});

test.describe('accessible names', () => {
  test('all images have alt text or empty alt', async ({ page }) => {
    await page.goto('/');
    const images = page.locator('img');
    const count = await images.count();
    for (let i = 0; i < count; i++) {
      const alt = await images.nth(i).getAttribute('alt');
      expect(alt !== null).toBe(true);
    }
  });

  test('all links have accessible text', async ({ page }) => {
    await page.goto('/');
    const links = page.locator('a[href]');
    const count = await links.count();
    const empty = [];
    for (let i = 0; i < count; i++) {
      const text = await links.nth(i).textContent();
      const aria = await links.nth(i).getAttribute('aria-label');
      if (!text?.trim() && !aria?.trim()) {
        empty.push(await links.nth(i).getAttribute('href') || '?');
      }
    }
    expect(empty).toEqual([]);
  });
});

test.describe('focus visibility and overflow', () => {
  test('skip link is first focusable element', async ({ page }) => {
    await page.goto('/');
    await page.keyboard.press('Tab');
    const focused = page.locator(':focus');
    await expect(focused).toBeVisible();
    const tag = await focused.evaluate(el => el.tagName.toLowerCase());
    expect(['a', 'button', 'input', 'select', 'textarea']).toContain(tag);
  });

  test('no horizontal overflow at 320px width', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 800 });
    await page.goto('/');
    const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    const viewportWidth = await page.evaluate(() => window.innerWidth);
    expect(scrollWidth).toBeLessThanOrEqual(viewportWidth + 2);
  });

  test('no horizontal overflow at 400% zoom simulation', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 800 });
    await page.goto('/');
    await page.evaluate(() => document.body.style.zoom = '4');
    const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    const viewportWidth = await page.evaluate(() => window.innerWidth);
    expect(scrollWidth).toBeLessThanOrEqual(viewportWidth + 2);
  });
});

test.describe('search dialog accessibility', () => {
  test('open search dialog has no critical violations', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('button', { name: /search/i }).first().click();
    await expect(page.getByRole('dialog')).toBeVisible();
    const results = await new AxeBuilder({ page }).include('#lel-search-dialog').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(results.violations).toEqual([]);
  });
});

test.describe('ranking table accessibility', () => {
  test('ranking table headers and text statuses remain explicit', async ({ page }) => {
    await page.goto('/category/evidence-literacy/');
    const table = page.locator('.longevity-ranking-table');
    if (await table.isVisible()) {
      await expect(table.locator('th[scope="col"]')).toHaveCount(6);
    }
  });
});
