'use strict';

/**
 * Shared Lighthouse target URLs and budgets (PRV3-PERF-02).
 *
 * URLs derive from the canonical route-state contract (config/routes.json):
 * every audited page route is public in CI fixture mode, plus the synthetic
 * fixture article/review, search hit/empty states, and the intentional 404
 * page. Run scripts/lighthouse-preflight.mjs before scoring so a 404, login
 * redirect, or missing fixture fails fast instead of being scored.
 */

const contract = require('./config/routes.json');

const BASE_URL = process.env.WP_SITE_URL || 'http://localhost:8080';

/** Critical public page routes from the contract (CI fixture mode). */
const criticalPageKeys = ['home', 'start_here', 'topics', 'editorial_policy', 'testing_methodology'];

const pagePaths = criticalPageKeys.map((key) => {
  const page = contract.pages[key];
  if (!page || !page.ci_fixture_public) {
    throw new Error(`Lighthouse target "${key}" is not public in CI fixture mode per config/routes.json.`);
  }
  return page.path;
});

const firstCategoryPath = Object.values(contract.categories)[0].path;

/** Paths expected to respond 200 in CI fixture mode. */
const okPaths = [
  ...pagePaths,
  '/test-evidence-guide/',
  '/reviews/test-valid-review/',
  contract.pages.reviews.path,
  firstCategoryPath,
  `${contract.search.path}evidence`,
  `${contract.search.path}nonexistent`,
];

/**
 * The themed 404 state must return HTTP 404, verified by the preflight
 * (scripts/lighthouse-preflight.mjs) via notFoundPaths. It is intentionally
 * excluded from the scored `urls` below: Lighthouse cannot score a document
 * that returns 404 (ERRORED_DOCUMENT_REQUEST), which would fail the run.
 */
const notFoundPaths = ['/this-route-does-not-exist/'];

const urls = okPaths.map((path) => BASE_URL + path);

const assertions = {
  'categories:performance': ['error', { minScore: 0.9 }],
  'categories:accessibility': ['error', { minScore: 0.95 }],
  'categories:best-practices': ['error', { minScore: 0.95 }],
  'categories:seo': ['error', { minScore: 0.95 }],
  'largest-contentful-paint': ['error', { maxNumericValue: 2500 }],
  'cumulative-layout-shift': ['error', { maxNumericValue: 0.1 }],
  'total-blocking-time': ['error', { maxNumericValue: 200 }],
  'total-byte-weight': ['error', { maxNumericValue: 1536000 }],
  'unused-javascript': ['warn', { maxLength: 0 }],
  'unused-css-rules': ['warn', { maxLength: 0 }],
};

module.exports = { urls, assertions, okPaths, notFoundPaths, BASE_URL };
