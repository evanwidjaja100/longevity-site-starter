'use strict';

/**
 * Shared Lighthouse target URLs and budgets (PRV3-PERF-02).
 *
 * URLs derive from the canonical route-state contract (config/routes.json):
 * every audited page route is public in CI fixture mode, plus the synthetic
 * fixture article/review and the first category archive. Routes that the
 * application intentionally marks non-indexable (search results, review
 * archive) are excluded from scoring — Lighthouse `is-crawlable` cannot pass
 * on a noindexed page — but stay in `okPaths`/`noindexPaths` so the preflight
 * still verifies their HTTP status and robots directive. Run
 * scripts/lighthouse-preflight.mjs before scoring so a 404, login redirect,
 * or missing fixture fails fast instead of being scored.
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

/**
 * Intentionally non-indexable routes: preflighted for HTTP status and the
 * noindex robots directive, but never scored (Lighthouse `is-crawlable`
 * penalizes noindex by design).
 */
const noindexPaths = [
  contract.pages.reviews.path,
  `${contract.search.path}evidence`,
  `${contract.search.path}nonexistent`,
];

/** Paths expected to respond 200 in CI fixture mode. */
const okPaths = [
  ...pagePaths,
  '/test-evidence-guide/',
  '/reviews/test-valid-review/',
  ...noindexPaths,
  firstCategoryPath,
];

const urls = okPaths.filter((path) => !noindexPaths.includes(path)).map((path) => BASE_URL + path);

/**
 * The themed 404 state must return HTTP 404, verified by the preflight
 * (scripts/lighthouse-preflight.mjs) via notFoundPaths. It is intentionally
 * excluded from the scored `urls` below: Lighthouse cannot score a document
 * that returns 404 (ERRORED_DOCUMENT_REQUEST), which would fail the run.
 */
const notFoundPaths = ['/this-route-does-not-exist/'];

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

module.exports = { urls, assertions, okPaths, noindexPaths, notFoundPaths, BASE_URL };
