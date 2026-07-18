# Pre-v2 Runtime Baseline

**Date:** 2026-07-18
**Commit:** `4da4a594bcba8520d4a6dc11227e2dff487408bb`
**Branch:** `improvement/launch-foundation-v2` (forked from `main`)
**Prepared by:** Implementation Agent (automated collection)

---

## Environment

| Component | Version |
|---|---|
| PHP | 8.3.32 (cli, Zend Engine v4.3.32, OPcache enabled) |
| WordPress | 7.0.1 |
| MySQL | 8.4.10 (MySQL Community Server - GPL) |
| Node | 24.18.0 |
| npm | 11.16.0 |
| Docker | 29.6.1 |
| Docker Compose | v5.2.0 |
| Composer | (via Docker) |
| Git | (via Docker) |
| OS | Windows (Docker Desktop) |

### PHP Extensions (relevant)

mysqli: yes, pdo: yes, mbstring: yes, xml: yes, json: yes, curl: yes, gd: yes, imagick: yes, intl: yes, zip: yes, opcache: no

---

## WordPress Options

| Option | Value |
|---|---|
| `home` | `http://localhost:8080` |
| `siteurl` | `http://localhost:8080` |
| `show_on_front` | `page` |
| `page_on_front` | `4` (Start Here) |
| `page_for_posts` | (empty) |
| `blog_public` | (empty, treated as 0 — indexing disabled) |
| `blogname` | Longevity Evidence Lab |
| `blogdescription` | Transparent evidence reviews and reproducible consumer testing with clearly stated uncertainty. |
| `template` | longevity-starter |
| `stylesheet` | longevity-starter |
| `permalink_structure` | `/%postname%/` |
| `timezone_string` | UTC |
| `current_theme` | Longevity Starter |
| `active_plugins` | (none) |
| `longevity_migration_version` | (not set) |

---

## Pages (10 total)

| ID | Title | Slug | Status | Template |
|---|---|---|---|---|
| 4 | Start Here | `start-here` | publish | (default) |
| 5 | About | `about` | publish | (default) |
| 6 | Editorial Policy | `editorial-policy` | publish | (default) |
| 7 | Medical Disclaimer | `medical-disclaimer` | publish | (default) |
| 8 | Affiliate Disclosure | `affiliate-disclosure` | publish | (default) |
| 9 | Corrections | `corrections` | publish | (default) |
| 10 | Testing Methodology | `testing-methodology` | publish | (default) |
| 11 | Privacy | `privacy` | publish | (default) |
| 12 | Terms | `terms` | publish | (default) |
| 13 | Contact | `contact` | publish | (default) |

### Key findings
- **Start Here is `page_on_front`** — violates D-03 (Homepage and Start Here must be separate)
- All pages are `publish` status (not draft as bootstrap intends)
- No `home` page exists (needs creation in P1.3)
- No `guides` or `topics` page exists
- No `evidence-methodology` page exists (only `testing-methodology`)
- All pages use default template (no custom template assignments)

---

## Categories (12 total)

| ID | Name | Slug | Count |
|---|---|---|---|
| 1 | Uncategorized | `uncategorized` | 2 |
| 2 | Evidence Literacy | `evidence-literacy` | 4 |
| 3 | Sleep and Circadian Health | `sleep-and-circadian-health` | 0 |
| 4 | Movement and Physical Capacity | `movement-and-physical-capacity` | 0 |
| 5 | Nutrition and Healthy Aging | `nutrition-and-healthy-aging` | 0 |
| 6 | Wearables and Consumer Measurement | `wearables-and-consumer-measurement` | 0 |
| 7 | Consumer Lab | `consumer-lab` | 0 |
| 8 | Supplements and High-Uncertainty Interventions | `supplements-and-high-uncertainty-interventions` | 0 |
| 11 | [TEST] Empty ranking | `test-empty-ranking` | 0 |
| 12 | Biohacking | `biohacking` | 0 |
| 13 | Supplements | `supplements` | 0 |
| 14 | Wearables | `wearables` | 0 |
| 15 | Nutrition | `nutrition` | 0 |
| 16 | Recovery | `recovery` | 0 |

### Key findings
- **Duplicate short slugs exist** alongside long canonical slugs (sleep, wearables, nutrition, supplements) — some were created by `create-phase4-content.php`
- `Sleep and Circadian Health` (slug: `sleep-and-circadian-health`) has 0 posts while no short `sleep` slug exists as a category name — just a duplicate
- `Biohacking` and `Recovery` exist as categories (violates D-04 — must be reviewed/migrated)
- `[TEST] Empty ranking` exists — test fixture category
- Only `Evidence Literacy` and `Uncategorized` have actual post counts

---

## Registered Post Types (public)

| Type | Rewrite Slug | Has Archive |
|---|---|---|
| `post` | (none) | false |
| `page` | (none) | false |
| `attachment` | (none) | false |
| `review` | `/reviews` | true |

### Registered Post Statuses (custom workflow)

`publish`, `future`, `draft`, `pending`, `private`, `trash` plus custom: `lel_assigned`, `lel_researching`, `lel_editorial_review`, `lel_fact_check`, `lel_medical_review`, `lel_testing_incomplete`, `lel_commercial_review`, `lel_ready`, `lel_update_due`, `lel_correction_pending`, `lel_archived`

---

## Content Inventory (all posts and reviews)

| ID | Title | Type | Status | Categories | Date |
|---|---|---|---|---|---|
| 1 | Hello world! | post | publish | Uncategorized | 2026-07-18 |
| 15 | [TEST] Evidence guide rendering | post | publish | Evidence Literacy | 2026-07-18 |
| 17 | [TEST] Medical review workflow | post | publish | Uncategorized | 2026-07-18 |
| 21 | [TEST] Valid review workflow | review | publish | Evidence Literacy | 2026-07-18 |
| 25 | [TEST] Alpha product report | review | publish | Evidence Literacy | 2026-07-18 |
| 26 | [TEST] Zeta product report | review | publish | Evidence Literacy | 2026-07-18 |
| 27 | [TEST] Blocked incomplete review | review | draft | — | 2026-07-18 |
| 45 | What Is Biohacking? An Evidence-Aware... | post | draft | Evidence Literacy | 2026-07-18 |
| 50 | NAD+ Precursors Explained: NMN, NR, Ev... | post | draft | Supplements | 2026-07-18 |
| 55 | Oura Ring 4 Review: Sleep Trends, Reco... | review | draft | Wearables | 2026-07-18 |
| 56 | Foods That Support Brain Health Across... | post | draft | Nutrition | 2026-07-18 |
| 60 | Nootropic Stacks: What the Evidence Su... | post | draft | Supplements | 2026-07-18 |
| 64 | WHOOP Review: Recovery Coaching, Sleep... | review | draft | Wearables | 2026-07-18 |
| 65 | Intermittent Fasting and Healthy Aging | post | draft | Nutrition | 2026-07-18 |
| 70 | Best Home Saunas: Infrared vs Traditio... | review | draft | Recovery | 2026-07-18 |

### Published content (6 items)
- 3 posts: Hello world, [TEST] Evidence guide rendering, [TEST] Medical review workflow
- 3 reviews: [TEST] Valid review workflow, [TEST] Alpha product report, [TEST] Zeta product report

### Draft content (9 items)
- 3 posts: Biohacking, NAD+, Foods That Support Brain Health, Nootropic Stacks, Intermittent Fasting
- 3 reviews: Oura Ring 4, WHOOP, Best Home Saunas
- 1 review: [TEST] Blocked incomplete review

---

## Navigation / Menus

No registered menus. All navigation is handled via static HTML links in theme template parts.

---

## Active Theme

**Longevity Starter** v3.0.0 (block theme)
- Templates: index, front-page, single, single-review, page, archive, archive-review, category, search, author, 404
- Parts: header, footer
- Patterns: 18 patterns (homepage-hero, topic-navigation, featured-guide, methodology-cta, etc.)
- Assets: style.css, consumer-lab.css, site-ui.js

---

## Active Plugins

None.

---

## Theme Mods

`custom_css_post_id`: -1

---

## Widgets

No active sidebar widgets.

---

## Migration Version

`longevity_migration_version`: not set

---

## Confirmed Plan-Gap Matches

| Plan Ref | Gap | Baseline Evidence |
|---|---|---|
| D-03 | Start Here is front page | `page_on_front` = 4 (Start Here) |
| D-04 | Biohacking, Recovery as categories | ID 12: biohacking, ID 16: recovery |
| D-04 | Long slugs + short slug duplicates | sleep-and-circadian-health + sleep (no slug), nutrition-and-healthy-aging + nutrition, etc. |
| §3-1 | Static theme links to `/evidence-guides/` | Header nav links to `/evidence-guides/` (page does not exist) |
| §3-5 | Markdown content in pages | Pages created from `.md` files via bootstrap |
| P1.1 | No central route registry | Route strings are scattered unmanaged in theme |
| P1.3 | No `home` page | No page with slug `home` exists |
| P4.1 | No standard meta description | Not tested yet (requires E2E) |
| P6.1 | Claim-source integrity | Not verified |

---

## Commands Used for Collection

```bash
# Inside WordPress container:
php -r "require '/var/www/html/wp-load.php'; ..."  # for options, pages, categories, post types, content
docker exec longevity-site-wordpress-1 php ...
docker exec longevity-site-db-1 mysql --version
node --version
npm --version
docker --version
docker compose version
```

All commands were executed against the local Docker environment at commit `4da4a59`.

---

## P0.4 — Baseline Test Suite Results

### PHP Unit Tests (Fallback Runner)

| Result | Details |
|---|---|
| ✅ Pass | All 6 assertions passed: score calculation, evidence grade validation, date sanitization, public results filtering, ranking sort, publication gates (valid + missing summary) |

See `tests/php/run-unit-tests.php` for exact test cases.

### PHPUnit

| Result | Details |
|---|---|
| ⚠️ Not executed | PHP 8.3 not available on host. Fallback runner used instead. CI runs PHPUnit via Docker workflow. |

### PHPStan (Level 5)

| Result | Details |
|---|---|
| ❌ Known failure | 1000+ unresolved WordPress function/class symbols. WordPress stubs not loaded in `phpstan.neon.dist`. Pre-existing issue documented in `baseline-2026-implementation.md`. |

### PHPCS (WordPress + PHPCompatibilityWP)

| Result | Details |
|---|---|
| ❌ Known failure | PHP 8.x compatibility bug in `phpcompatibility/php-compatibility` v9.3 (`trim()` on null). Pre-existing issue. |

### Stylelint

| Result | Details |
|---|---|
| ✅ Pass | No errors from theme CSS files. |

### ESLint

| Result | Details |
|---|---|
| ⚠️ 48 errors | All 47 of 48 errors are from bundled third-party plugin (Akismet). 1 error in first-party `site-ui.js` (`HTMLElement` is not defined). |

| File | Errors | Notes |
|---|---|---|
| `wp-content/plugins/akismet/_inc/akismet-admin.js` | 4 | Third-party; `navigator`, `setTimeout` |
| `wp-content/plugins/akismet/_inc/akismet-frontend.js` | 16 | Third-party; `setTimeout`, `clearTimeout`, unused vars |
| `wp-content/plugins/akismet/_inc/akismet.js` | 27 | Third-party; `jQuery`, `ajaxurl`, `Image` |
| `wp-content/themes/longevity-starter/assets/js/site-ui.js` | 1 | First-party: `HTMLElement` not defined |
| **Total** | **48** | |

### Playwright E2E — `core.spec.js`

| Result | Tests |
|---|---|
| ✅ Pass | 19/19 passed |

Tests covered: skip link, search dialog, article/review/search/category/archive/author routes, trust components, review decision/score, Consumer Lab directory, ranking sort/filter, affiliate disclosure, blocked review, 404 recovery, keyboard navigation, responsive 320px, health endpoint.

### Playwright Accessibility — `accessibility.spec.js`

| Result | Tests |
|---|---|
| ✅ Pass | 11/11 passed (axe-core WCAG 2.2 AA) |

Routes tested: home, article, review, search, category, review archive, ranked category, author, 404, search dialog, ranking table.

### Playwright Visual — `visual.spec.js`

| Result | Tests |
|---|---|
| ✅ Pass | 6/6 passed (screenshots captured at desktop + mobile) |

Pages: home, article, review, search, archive, ranking.

### Content Validation

| Check | Result |
|---|---|
| `validate-content.py` | ✅ Pass |
| `validate-internal-links.py` | ✅ Pass (15 launch + 60 legacy rows) |
| `validate-freshness.py --no-fail` | ✅ Pass |

### Smoke Test

| Result | Details |
|---|---|
| ✅ Pass (via container) | Site accessible, health endpoint returns `{"status":"ok"}`, no PHP errors in output. Script not directly runnable due to environment URL mismatch (siteurl `http://localhost:8080` vs container internal `http://localhost:80`). |

### Route Inventory (P0.3)

Full CSV at `docs/testing/pre-v2-route-inventory.csv`. Key findings:

| Finding | Impact |
|---|---|
| No meta description tags on any page | P4.1 required |
| All pages `noindex, nofollow` | Correct for dev; will change on launch |
| Homepage and Start Here share same H1/canonical | Confirms D-03 violation |
| Privacy, Terms, Contact have placeholder content | P2.1/P2.2 required |
| Markdown syntax leaks into OG descriptions | P2.1 required |
| Archive/category pages lack canonical URLs | P4.3 required |

### Environment-Specific Notes

- Host has Node v24.18.0, npm 11.16.0, Docker 29.6.1
- Host does NOT have PHP or Composer — all PHP operations go through Docker containers
- PHPStan and PHPCS pre-existing failures are infrastructure issues, not code regressions
- Akismet ESLint errors are from bundled third-party code (not project-owned)
- The `site-ui.js` `HTMLElement` error is minor — the file runs in browser context where `HTMLElement` exists
