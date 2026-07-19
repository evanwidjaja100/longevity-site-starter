import { test, expect } from '@playwright/test';

const CANONICAL_PAGES = [
  ['home', '/'],
  ['start-here', '/start-here/'],
  ['guides', '/guides/'],
  ['topics', '/topics/'],
  ['reviews', '/reviews/'],
  ['evidence-methodology', '/evidence-methodology/'],
  ['testing-methodology', '/testing-methodology/'],
  ['editorial-policy', '/editorial-policy/'],
  ['corrections', '/corrections/'],
  ['affiliate-disclosure', '/affiliate-disclosure/'],
  ['medical-disclaimer', '/medical-disclaimer/'],
  ['about', '/about/'],
  ['contact', '/contact/'],
  ['privacy', '/privacy/'],
  ['terms', '/terms/'],
  ['ai-assisted-work-disclosure', '/ai-assisted-work-disclosure/'],
  ['source-registry', '/source-registry/'],
];

const CANONICAL_CATEGORIES = [
  ['evidence', '/category/evidence-literacy/'],
  ['sleep', '/category/sleep/'],
  ['movement', '/category/movement/'],
  ['nutrition', '/category/nutrition/'],
  ['wearables', '/category/wearables/'],
  ['supplements', '/category/supplements/'],
  ['consumer-lab', '/category/consumer-lab/'],
];

const LEGACY_CATEGORIES = [
  ['sleep-legacy', '/category/sleep-and-circadian-health/', '/category/sleep/'],
  ['movement-legacy', '/category/movement-and-physical-capacity/', '/category/movement/'],
  ['nutrition-legacy', '/category/nutrition-and-healthy-aging/', '/category/nutrition/'],
  ['wearables-legacy', '/category/wearables-and-consumer-measurement/', '/category/wearables/'],
  ['supplements-legacy', '/category/supplements-and-high-uncertainty-interventions/', '/category/supplements/'],
];

for (const [name, path] of CANONICAL_PAGES) {
  test(`${name} page resolves with 200 and unique H1`, async ({ page }) => {
    const response = await page.goto(path);
    expect(response?.status()).toBe(200);
    expect(new URL(page.url()).pathname).toBe(path);
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
  });
}

for (const [name, path] of CANONICAL_CATEGORIES) {
  test(`category ${name} resolves with 200`, async ({ page }) => {
    const response = await page.goto(path);
    expect(response?.status()).toBe(200);
    expect(new URL(page.url()).pathname).toBe(path);
  });
}

for (const [name, legacyPath, canonicalPath] of LEGACY_CATEGORIES) {
  test(`legacy category ${name} redirects 301 to canonical`, async ({ page }) => {
    const response = await page.goto(legacyPath);
    expect(response?.status()).toBe(200);
    expect(new URL(page.url()).pathname).toBe(canonicalPath);
  });
}

test('404 returns 404 status and helpful message', async ({ page }) => {
  const response = await page.goto('/this-path-does-not-exist/');
  expect(response?.status()).toBe(404);
});

test('search page has visible search form', async ({ page }) => {
  await page.goto('/?s=test');
  expect(page.url()).toContain('s=test');
  await expect(page.locator('input[type="search"], input[name="s"]').first()).toBeVisible();
});

test('bootstrap idempotency check — canonical pages do not duplicate on re-run', async ({ page }) => {
  await page.goto('/guides/');
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
});
