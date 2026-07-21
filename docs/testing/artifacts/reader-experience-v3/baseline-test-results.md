# Baseline Test Results -- Reader Experience v3

**Date:** 2026-07-20
**Branch:** improvement/reader-experience-v3
**Commit:** 275ed36cc5f951d22896e361b885942dff67737c
**Parent:** improvement/runtime-routes-reader-value-v3

## Environment

| Item | Version |
|---|---|
| WordPress | 7.0.1-php8.3-apache (Docker image) |
| PHP | 8.3 (Docker container) |
| Node | 24.18.0 |
| npm | 12.0.1 |
| Docker | 29.6.1 |
| Docker Compose | v5.2.0 |
| Playwright | 1.61.1 |

## Test Results

| Suite | Status | Notes |
|---|---|---|
| Stylelint (CSS) | PASS | 0 errors |
| ESLint (JS) | 3 FAILURES | Pre-existing: URL not defined in routes.spec.js:45,54,62 |
| Content validation | PASS | All calendars, links, freshness valid |
| Internal-link validation | PASS | 15 launch + 60 legacy rows |
| Freshness validation | PASS | As of 2026-07-20 |
| E2E routes | 25/32 PASS, 7 FAIL | 5 draft pages 404 (correct per plan), 1 start-here 2x H1 (pre-existing), 1 search hidden input (pre-existing) |
| E2E bootstrap | 6/6 PASS | Dry-run, idempotency all pass |
| E2E page-readiness | 2/5 PASS, 3 FAIL | Same pattern: draft pages 404, start-here 2x H1 |
| E2E SEO | Partial run (timeout) | Canonical/OG/Twitter failing on some pages |
| PHP lint | Requires Docker | Runs inside wpcli container |
| PHPUnit | Requires Docker | Runs inside wpcli container |
| Accessiblity (axe) | Not yet run | Needs test environment |
| Lighthouse | Not yet run | Needs test environment |

## Route Inventory

See `route-inventory.csv`.

## Known Issues at Baseline

1. Draft pages (guides, topics, evidence-methodology, ai-assist-disclosure, source-registry) return 404 -- expected per plan, but tests incorrectly expect 200. This is the target of RX-101.
2. Start Here page has 2 H1 elements (site title duplicated). This is a pre-existing theme issue.
3. Search input is present but hidden (dialog-based search). Test needs to open dialog first.
4. SEO metadata (canonical, OG, Twitter) may be incomplete on some page types.
5. PHP tools and accessibility tests require Docker/Playwright setup.
6. Python scripts require `python3` on host (available as `py -3`).
