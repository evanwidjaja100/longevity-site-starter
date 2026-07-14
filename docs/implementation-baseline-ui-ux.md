# UI/UX Implementation Baseline

Baseline date: 2026-07-14  
Repository root: `D:\Desktop\test\longevity-site-starter`

## Scope reviewed

The required repository configuration, CI workflows, architecture, editorial, testing, operations, theme templates, MU-plugin services, scripts, PHP tests, integration tests, and browser tests were inspected before implementation. WordPress remains the canonical CMS, the `longevity-core` MU-plugin remains the governance control plane, and no evidence, reviewer identity, citation, approval, product observation, commercial relationship, or correction record was created.

## Current strengths

- The codebase already uses a portable WordPress modular monolith with a block theme and first-party MU-plugin services.
- Publication gates, private operational post types, role-specific capabilities, medical-review attestation, scoring, corrections, affiliate controls, conservative schema, analytics, REST health/readiness endpoints, and WP-CLI tooling are present.
- Public templates already include a skip link, visible focus styles, responsive tables, reduced-motion handling, print rules, trust shortcodes, useful review empty-state copy, and a minimal public health response.
- Docker Compose configuration is valid and the local WordPress/MySQL stack starts successfully.
- `package-lock.json` was present. The missing `composer.lock` was generated from the declared constraints with the official Composer Docker image.
- Composer dependency audit and the existing PHPUnit suite pass.

## Current weaknesses

- The primary navigation uses a `core/page-list` fallback and exposes pages according to WordPress page state instead of a curated reader hierarchy.
- The homepage is mostly the front-page body plus two query loops. It lacks deliberate trust principles, topic entry points, methodology/corrections navigation, robust content metadata, and conditional Consumer Lab presentation.
- Cards generally omit featured images, content type, dates, evidence grade, tested state, and medical-review state.
- Article and review templates have no visible breadcrumbs, source list, table of contents, related-content component, descriptive previous/next titles, or structured review decision summary.
- Trust rendering is concentrated in `class-shortcodes.php`; no dynamic trust blocks or shared public rendering service exists.
- The governance meta box is a long linear form and uses raw JSON as the primary score-dimension editor.
- Search has no allowlisted GET filters and archive/author cards are minimally differentiated.
- Analytics exposes an event allowlist but does not yet enforce parameter schemas or consent-aware vendor forwarding.
- No migration/freshness service or internal operational status report exists.
- Browser tests cover only the homepage, review archive, and health endpoint.
- Lighthouse budgets and browser CI are not configured.
- The smoke test's diagnostic grep matches the legitimate CSS custom property name `--wp--preset--color--warning`, causing a false failure.
- PHPCS references the unavailable `PHPCompatibilityWP` ruleset with the current Composer dependency set.

## Baseline command results

| Command | Result | Notes |
| --- | --- | --- |
| `git status` / `git rev-parse --show-toplevel` | Unavailable | Git is not installed or available on `PATH`; repository root was confirmed from the shared workspace context and `.git` directory. |
| `php -v` | Unavailable on host | PHP checks were run in the official WordPress/Composer containers. |
| `composer --version` | Unavailable on host | Official `composer:2` Docker image used. |
| `node --version` | Pass | Node `v24.18.0`. |
| `npm --version` | Pass | npm `11.16.0`. |
| `python --version` | Pass | Python `3.14.6`. |
| `docker --version` | Pass | Docker `29.6.1`. |
| `docker compose version` | Pass | Docker Compose `v5.2.0`. |
| `make --version` | Unavailable | GNU Make is not installed. Equivalent commands were run individually where possible. |
| `docker compose config --quiet` | Pass | Compose interpolation and schema validation succeeded with the existing local `.env`. |
| `composer validate --strict` | Pass | Executed through `composer:2`. |
| `composer install --no-interaction --prefer-dist` | Pass | Generated `composer.lock`; installed 35 development packages. |
| `composer audit --locked` | Pass | No security vulnerability advisories found. |
| `composer test` | Pass | PHPUnit: 12 tests, 21 assertions. |
| `composer lint` | Fail | PHPCS cannot resolve the configured `PHPCompatibilityWP` sniff. |
| Docker-backed `tests/php/run-unit-tests.php` | Pass | Dependency-free fallback tests passed under PHP 8.3. |
| `npm ci` | Fail | Timed out downloading `write-file-atomic` from the configured internal registry; cleanup also reported a Windows `EPERM` directory error. |
| `npm audit --audit-level=high` | Pass | Lockfile audit reported 0 vulnerabilities. This does not imply installation succeeded. |
| `npm run lint` | Fail | `stylelint` was unavailable because `npm ci` did not complete. |
| `python scripts/validate-content.py` | Fail | Local `.env` is intentionally ignored, but the validator treats every `.env*` file as tracked because it does not consult Git or the manifest. |
| `docker compose up -d db wordpress` | Pass | MySQL and WordPress became healthy. |
| `docker compose run --rm --entrypoint sh wpcli /scripts/bootstrap.sh` | Pass | Site bootstrapped at `http://localhost:8080`; policy/legal pages remain drafts and indexing remains disabled. |
| Homepage HTTP request | Pass | HTTP 200. |
| `GET /wp-json/longevity/v1/health` | Pass | HTTP 200 with minimal `status`, `version`, and `site` fields. |
| `scripts/smoke-test.sh` | Fail (false positive) | Requests succeed, but the diagnostic grep matches WordPress global CSS containing `Warning:` as part of a token name. |
| Existing Playwright / axe tests | Unavailable | npm dependencies and Playwright browsers could not be installed because `npm ci` timed out. |
| Lighthouse | Unavailable | Lighthouse tooling is not installed and the npm dependency path is currently blocked. |

## Baseline screenshots

Responsive homepage screenshots were captured from the live bootstrapped WordPress site using the in-app browser:

- `docs/testing/artifacts/baseline-home-360.png`
- `docs/testing/artifacts/baseline-home-768.png`
- `docs/testing/artifacts/baseline-home-1440.png`

The screenshots confirm accidental `Sample Page` exposure in primary navigation, sparse discovery content, limited card context, and weak footer information architecture.

## Architectural constraints retained

- WordPress remains canonical; no SPA, headless conversion, microservice, GraphQL-first layer, or proprietary CMS dependency may be introduced.
- Publication readiness, claims/sources, specialist review, testing, scoring, disclosure, corrections, roles, and audit history remain authoritative in the MU-plugin.
- Existing post types, metadata keys, capabilities, routes, CLI commands, shortcodes, templates, and URLs remain backward compatible.
- Public components expose only approved, safe metadata and return no output for incomplete state.
- No critical workflow depends on ACF, a page builder, a commercial plugin, or a proprietary SaaS.
- Reader safety and evidence integrity outrank conversion.

## Highest-priority screens and services

1. Header navigation and footer link architecture.
2. Front page hero, trust principles, topic discovery, Start Here path, evidence cards, and conditional Consumer Lab section.
3. Single article and review templates, including breadcrumbs, TOC, sources, decision context, corrections, and related content.
4. Shared public rendering service and first-party dynamic blocks.
5. Governance meta box and score-dimension editor.
6. Search/filter handling, archive cards, and author identity presentation.
7. Analytics consent/parameter enforcement, freshness processing, schema/social fallbacks, and operational status.
8. Browser, accessibility, visual, and CI coverage.

## Assumptions

- Existing local `.env` values are development-only and must not be overwritten or committed.
- Policy and methodology destinations may be linked by stable intended slugs even while their pages remain drafts locally.
- Empty public components should teach readers about methodology without implying that evidence review or product testing occurred.
- Dynamic blocks can use server-rendered PHP with small editor-only registration JavaScript and no public framework runtime.
- Real production reviewer verification, evidence acquisition, product testing, legal approval, SMTP, consent vendor, analytics vendor, WAF/CDN, backup storage, and monitoring remain external release tasks.
- Git-based status/diff checks must remain documented as unavailable unless a Git binary becomes available later in the session.
