# Consumer Lab Experience Implementation

## Baseline status

The implementation began on 2026-07-15 in `D:\Desktop\test\longevity-site-starter` on `main`. Git was not on the shell PATH during baseline inspection; its installed executable was found during final verification. `.env` was not edited.

Baseline checks:

- `npm ci`: passed; 479 packages installed and no vulnerabilities reported.
- `npm run lint`: passed before implementation.
- `docker compose config --quiet`: passed.
- `python scripts/validate-content.py`: passed.
- `python scripts/validate-internal-links.py`: passed.
- `python scripts/validate-freshness.py --no-fail`: passed.
- Composer, PHP, Make, and Git commands were initially unavailable on PATH.
- Docker Desktop was installed but its Linux engine was not initially running.

## Existing constraints retained

- WordPress remains the CMS and canonical public rendering runtime.
- `longevity-starter` remains the block theme; `longevity-core` remains the governance/application layer.
- Reviews continue to use the built-in `category` taxonomy and `/reviews/` archive. No duplicate ranking taxonomy or SPA was introduced.
- The existing 0–5 score, publication gates, private protocols/test records, affiliate registry, REST routes, blocks, shortcodes, schema suppression, and correction records remain authoritative.
- Production content is never synthesized by this change. Test data remains `[TEST]`-marked and restricted to non-production fixtures.

## Decisions

- `Rankings` is the sole public eligibility, filtering, ordering, and directory-aggregate service.
- Built-in categories are reused because `review` already supports `category`; category archives can present a ranking followed by mixed related content without changing existing URLs.
- Public test results are a bounded projection stored on a private approved test record. Raw observations and operational identifiers remain private.
- Primary ranking controls use allowlisted GET parameters and server-rendered HTML. JavaScript is limited to the search dialog and admin row editing.
- Value sorting is intentionally absent because the repository has no versioned value model with complete, current regional price inputs.

## Implementation scope

Theme changes add a blue/navy evidence-publication design system, responsive header and footer, accessible search dialog, composed homepage patterns, ranking directory, category ranking presentation, product-report layout, structured result table, print rules, and narrow-screen table-to-card presentation.

MU-plugin changes add authoritative ranking rules, four dynamic blocks, structured public-result sanitization and REST schema, an accessible admin row editor, cache invalidation, bounded analytics events, conservative collection schema, and migration version 2.

## Principal file groups

- Ranking/application layer: `class-rankings.php`, `class-public-components.php`, `class-blocks.php`, `class-review-methodology.php`, `class-admin-ui.php`, `class-schema.php`, `class-analytics.php`, and four dynamic block definitions.
- Theme/presentation: `theme.json`, `functions.php`, header/footer template parts, archive/category/review/front-page templates, five homepage patterns, `consumer-lab.css`, and `site-ui.js`.
- Data and fixtures: editorial JSON Schema, migration version 2, and deterministic `[TEST]` product reports with structured public result rows.
- Verification and guidance: ranking/sanitizer unit coverage, functional/accessibility/visual Playwright coverage, ADR 0009, architecture/editorial/testing documentation, and this implementation report.

## Public routes and screens

- `/` presents the evidence-publication homepage and progressive-enhancement search dialog.
- `/reviews/` presents the Consumer Lab category directory.
- `/category/evidence-literacy/` presents the server-rendered ranking, GET sort/filter controls, and related evidence.
- `/reviews/test-valid-review/` presents the product verdict, score, confidence, method, structured results, disclosures, sources, and correction affordances.
- Search, author, evidence article, and 404 routes retain the shared responsive shell and trust presentation.

## Data migration

Migration version 2 creates only version options for rankings cache invalidation and the public-result schema. Existing records are not rewritten. Missing new fields continue to resolve to safe empty defaults. Editors may populate structured rows over time.

## Accessibility decisions

- The existing skip link and focus target are retained.
- The search trigger uses a native `dialog`, Escape/click-outside close, focus restoration, and a non-JavaScript search link.
- Rankings use one semantic table source; CSS presents its cells as ordered cards on narrow screens without duplicated accessible content.
- Statuses and confidence always include text, not color alone.
- Controls retain visible focus, 44px minimum height, reduced-motion behavior, forced-colors borders, 320px reflow, and print-specific hiding.

## Security and privacy

- Query parameters use strict allowlists and WordPress sanitizers; no raw SQL or arbitrary `orderby` is accepted.
- Admin structured-result writes require the test-protocol capability and a nonce.
- All public test-result fields are length-bounded, escaped on output, and stripped of unexpected properties.
- Public renderers validate the approved test record and protocol version before accessing structured results.
- Private tester IDs, unit identifiers, raw observations, evidence locations, conflicts, and internal notes are never included in the public component.
- Analytics additions accept only bounded categorical values; search free text is not captured.

## Known limitations

- Production ranking cards remain empty until real reports satisfy every eligibility condition.
- Real product photography, observations, sources, professional credentials, merchant approvals, legal review, and medical review must come from governed editorial workflows.
- No value sort is exposed until a versioned value model and complete current regional cost data exist.
- Automated accessibility checks supplement but do not replace manual keyboard, zoom, screen-reader, and forced-colors testing.

## Test results

Passed:

- `npm ci` (479 packages, zero reported vulnerabilities), `npm run lint`, JSON/YAML/PHP syntax checks, Composer validation, Docker Compose configuration, and a targeted WordPress PHPCS pass over all newly added PHP files.
- PHPUnit: 17 tests and 29 assertions.
- Playwright functional: 19 tests, including ranking eligibility/order/filtering, affiliate ordering, blocked-report behavior, keyboard interaction, health, and 320px reflow.
- Playwright accessibility: 11 route/component checks with no critical axe violations.
- Playwright visual: 6 scenarios captured at desktop, tablet, and mobile widths under `reports/playwright-artifacts/` (ignored generated output).
- Content, internal-link, and freshness validators; WordPress bootstrap/migration; deterministic fixtures; health endpoint; direct smoke-equivalent HTTP checks; and a regenerated 686-entry checksum manifest.

Environment-limited or baseline failures:

- The repository-wide PHPCS command reports the existing WordPress coding-standard backlog in both touched and untouched files; the Composer PHP 8.5 image also emits PHPCompatibility deprecations. No rule was disabled.
- PHPStan requires a larger memory limit, then reports 1,000+ unresolved WordPress functions/classes because the existing configuration does not load WordPress stubs. No errors were suppressed.
- The aggregate `scripts/validate.sh` reaches its final whitespace phase after its PHP/JSON/YAML/content/link/freshness/environment checks pass, then reports pre-existing Markdown line breaks and binary WordPress/font/image assets as trailing whitespace. The first container also used BusyBox `grep`, which does not support the script's GNU-only flags. The implementation diff passes Git's whitespace check apart from documented line-ending conversion warnings.
- Host Lighthouse was prevented from completing because installed security software injected a third-party script into Chromium and locked its temporary profile. A Linux browser-container retry could not start because the external image pull timed out after ten minutes. The configured thresholds were not weakened.
- The repository smoke shell script cannot run inside the existing WP-CLI image because it lacks Python; its homepage and health assertions were exercised by equivalent host HTTP checks and Playwright.
