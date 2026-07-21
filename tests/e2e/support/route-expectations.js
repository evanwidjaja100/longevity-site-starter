/**
 * Centralized route expectations for E2E test suites.
 *
 * Defines the expected state of every known route in the longevity
 * WordPress install. Tests MUST import from this module rather than
 * duplicating route lists.
 *
 * Two runtime modes:
 *   PRODUCTION  – draft / placeholder pages return 404.
 *   CI_FIXTURE  – synthetic test fixtures may publish pages so
 *                 browser tests can assert 200. This mode MUST
 *                 never be used against a production site.
 */

import { test } from '@playwright/test';

/** Routes published by production bootstrap (should return 200). */
export const PUBLIC_PAGES = [
  { key: 'home',                  path: '/',                               indexable: true },
  { key: 'start_here',            path: '/start-here/',                    indexable: true },
  { key: 'about',                 path: '/about/',                         indexable: true },
  { key: 'editorial_policy',      path: '/editorial-policy/',              indexable: true },
  { key: 'medical_disclaimer',    path: '/medical-disclaimer/',            indexable: true },
  { key: 'affiliate_disclosure',  path: '/affiliate-disclosure/',          indexable: true },
  { key: 'corrections',           path: '/corrections/',                   indexable: true },
  { key: 'testing_methodology',   path: '/testing-methodology/',           indexable: true },
  { key: 'topics',                path: '/topics/',                        indexable: true },
];

/** Routes that exist as WP draft pages (should return 404 anonymously). */
export const PRIVATE_DRAFT_PAGES = [
  { key: 'guides',                path: '/guides/' },
  { key: 'evidence_methodology',  path: '/evidence-methodology/' },
  { key: 'privacy',               path: '/privacy/' },
  { key: 'terms',                 path: '/terms/' },
  { key: 'contact',               path: '/contact/' },
  { key: 'ai_assist_disclosure',  path: '/ai-assisted-work-disclosure/' },
  { key: 'source_registry',       path: '/source-registry/' },
];

/** Routes defined in Routes but not yet created as pages (should return 404). */
export const NOT_YET_CREATED_PAGES = [];

/** Canonical category archives. */
export const PUBLIC_CATEGORIES = [
  { key: 'evidence',      path: '/category/evidence-literacy/',  indexable: true },
  { key: 'sleep',         path: '/category/sleep/',              indexable: true },
  { key: 'movement',      path: '/category/movement/',           indexable: true },
  { key: 'nutrition',     path: '/category/nutrition/',          indexable: true },
  { key: 'wearables',     path: '/category/wearables/',          indexable: true },
  { key: 'supplements',   path: '/category/supplements/',        indexable: true },
  { key: 'consumer_lab',  path: '/category/consumer-lab/',       indexable: true },
];

/** Legacy category paths that should redirect 301 to the canonical path. */
export const LEGACY_REDIRECTS = [
  { key: 'sleep_legacy',        from: '/category/sleep-and-circadian-health/',          to: '/category/sleep/' },
  { key: 'movement_legacy',     from: '/category/movement-and-physical-capacity/',      to: '/category/movement/' },
  { key: 'nutrition_legacy',    from: '/category/nutrition-and-healthy-aging/',         to: '/category/nutrition/' },
  { key: 'wearables_legacy',    from: '/category/wearables-and-consumer-measurement/',  to: '/category/wearables/' },
  { key: 'supplements_legacy',  from: '/category/supplements-and-high-uncertainty-interventions/', to: '/category/supplements/' },
];

/** Review post-type archive. */
export const REVIEW_ARCHIVE = '/reviews/';

/** Search results page (query parameter). */
export const SEARCH_PATH = '/?s=';

/** All routes that should return 200 (public pages + categories + review archive + search). */
export const ALL_PUBLIC_ROUTES = [
  ...PUBLIC_PAGES,
  ...PUBLIC_CATEGORIES,
  { key: 'reviews',    path: REVIEW_ARCHIVE,     indexable: false },
  { key: 'search',     path: SEARCH_PATH,         indexable: false },
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
