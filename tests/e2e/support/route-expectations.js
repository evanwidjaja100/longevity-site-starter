/**
 * Centralized route expectations for E2E test suites.
 *
 * All route data derives from config/routes.json — the canonical
 * route-state contract (PRV3-BOOT-01). Tests MUST import from this
 * module rather than duplicating route lists, and this module MUST
 * NOT hardcode slugs. The PHP side of the contract is enforced by
 * tests/php/RouteContractTest.php.
 *
 * Two runtime modes:
 *   PRODUCTION  – draft / placeholder pages return 404.
 *   CI_FIXTURE  – the guarded fixture projection publishes the pages
 *                 flagged ci_fixture_public so browser tests can
 *                 assert 200. This mode MUST never be used against a
 *                 production site.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { test } from '@playwright/test';

const contract = JSON.parse(
  readFileSync(fileURLToPath(new URL('../../../config/routes.json', import.meta.url)), 'utf8'),
);

const pageEntries = Object.entries(contract.pages);

/** Routes public in CI fixture mode (bootstrap-published + fixture projection). */
export const PUBLIC_PAGES = pageEntries
  .filter(([, page]) => page.type === 'page' && page.ci_fixture_public)
  .map(([key, page]) => ({ key, path: page.path, indexable: page.indexable }));

/** Routes that exist as WP draft pages (return 404 anonymously in every mode). */
export const PRIVATE_DRAFT_PAGES = pageEntries
  .filter(([, page]) => page.type === 'page' && !page.ci_fixture_public)
  .map(([key, page]) => ({ key, path: page.path }));

/** Routes defined in Routes but not yet created as pages (should return 404). */
export const NOT_YET_CREATED_PAGES = [];

/** Canonical category archives. */
export const PUBLIC_CATEGORIES = Object.entries(contract.categories).map(
  ([key, category]) => ({ key, path: category.path, indexable: true }),
);

/** Legacy category paths that should redirect 301 to the canonical path. */
export const LEGACY_REDIRECTS = Object.entries(contract.categories).flatMap(
  ([key, category]) =>
    category.legacy_slugs.map((legacySlug) => ({
      key: `${key}_legacy`,
      from: `/category/${legacySlug}/`,
      to: category.path,
    })),
);

/** Review post-type archive. */
export const REVIEW_ARCHIVE = contract.pages.reviews.path;

/** Search results page (query parameter). */
export const SEARCH_PATH = contract.search.path;

/** Synthetic fixture content published in CI fixture mode. */
export const CI_FIXTURE_PUBLISHED_PATHS = contract.ci_fixture_content.published_paths;

/** Synthetic fixture content that must stay blocked by publication gates. */
export const CI_FIXTURE_BLOCKED_PATHS = contract.ci_fixture_content.blocked_paths;

/** All routes that should return 200 (public pages + categories + review archive + search). */
export const ALL_PUBLIC_ROUTES = [
  ...PUBLIC_PAGES,
  ...PUBLIC_CATEGORIES,
  { key: 'reviews', path: REVIEW_ARCHIVE, indexable: false },
  { key: 'search', path: SEARCH_PATH, indexable: false },
];

/** All routes that should return 404 (drafts + not-yet-created). */
export const ALL_PRIVATE_ROUTES = [
  ...PRIVATE_DRAFT_PAGES,
  ...NOT_YET_CREATED_PAGES,
];

/**
 * Helper: generate a parameterized test for each public route.
 * Call inside a test.describe block.
 */
export function forEachPublicRoute(fn) {
  for (const route of ALL_PUBLIC_ROUTES) {
    test(`${route.key} (${route.path})`, async ({ page }) => {
      await fn(page, route);
    });
  }
}

/**
 * Helper: generate a parameterized test for each private route.
 * Call inside a test.describe block.
 */
export function forEachPrivateRoute(fn) {
  for (const route of ALL_PRIVATE_ROUTES) {
    test(`${route.key} (${route.path}) returns 404`, async ({ page }) => {
      await fn(page, route);
    });
  }
}
