# V3 Pre-Implementation Baseline

**Date:** 2026-07-20
**Branch:** `improvement/runtime-routes-reader-value-v3`
**Base commit:** `8605ed636762d116178ff79f07e8abb1719d19fb`
**Parent branch:** `improvement/launch-foundation-v2`
**Parent HEAD:** `8605ed6 feat(bootstrap): production-grade bootstrap commands and E2E smoke tests`

## Tool Versions

| Tool | Version |
|---|---|
| Node | 24.18.0 |
| npm | 11.16.0 |
| Docker | 29.6.1 |
| Docker Compose | v5.2.0 |
| Playwright | 1.61.1 |
| PHP | (Docker — 8.3 in container) |
| Composer | (Docker — in container) |

## Repository State

Working tree was clean at baseline capture. All changes from previous sessions were stashed.

## Route Inventory

### Pages (17 defined in Routes)

| Key | Slug | Status |
|---|---|---|
| home | (empty — front page) | publish |
| start_here | start-here | publish |
| guides | guides | draft |
| topics | topics | draft |
| reviews | reviews | — |
| evidence_methodology | evidence-methodology | draft |
| testing_methodology | testing-methodology | draft |
| editorial_policy | editorial-policy | draft |
| corrections | corrections | draft |
| affiliate_disclosure | affiliate-disclosure | draft |
| medical_disclaimer | medical-disclaimer | draft |
| ai_assist_disclosure | ai-assisted-work-disclosure | draft |
| source_registry | source-registry | draft |
| about | about | draft |
| contact | contact | draft |
| privacy | privacy | draft |
| terms | terms | draft |

### Categories (7 defined in Routes — currently using LONG slugs as canonical)

| Key | Current Canonical Slug | Display Name |
|---|---|---|
| evidence | evidence-literacy | Evidence Literacy |
| sleep | sleep-and-circadian-health | Sleep and Circadian Health |
| movement | movement-and-physical-capacity | Movement and Physical Capacity |
| nutrition | nutrition-and-healthy-aging | Nutrition and Healthy Aging |
| wearables | wearables-and-consumer-measurement | Wearables and Consumer Measurement |
| supplements | supplements-and-high-uncertainty-interventions | Supplements and High-Uncertainty Interventions |
| consumer_lab | consumer-lab | Consumer Lab |

## Known Divergences from Plan

1. **Route slugs are inverted:** Current `class-routes.php` uses long slugs as canonical with `legacy_slug` pointing to short slugs. P2.1 requires short slugs as canonical with `legacy_slugs` (array) pointing to long slugs.
2. **Bootstrap already includes class-seo.php:** Line 46 of `bootstrap.php` already adds it. P1.1 appears partially addressed but needs verification.
3. **Shell bootstrap still creates categories:** `scripts/bootstrap.sh` lines 58–69 independently creates categories via shell slug generation. P3.1 must remove this.
4. **Shell bootstrap sets Start Here as front page:** Line 85 creates Start Here and line 104 sets it as `page_on_front`. P3.2 requires Home to be front page.
5. **CI installs only Chromium:** `ci.yml` line 54 installs only Chromium, but `playwright.config.js` configures Firefox and WebKit. P4.3.
6. **Makefile placeholders:** Integration tests (line 29) and SBOM (line 47) are placeholders. P4.5, P4.6.
7. **Theme HTML has hardcoded route literals:** Multiple `.html` template files contain `/start-here/`, `/corrections/`, `/evidence-methodology/`, etc. P2.4.
8. **Route tests use absolute URL comparison:** `routes.spec.js` compares `page.url()` directly to relative path strings. P2.5.
9. **SEO duplicates taxonomy descriptions:** `output_meta_description()` and `output_archive_description()` both emit `<meta name="description">` on category pages. P5.1.

## Test Status

All tests from previous session reported as passing. Full re-run will be conducted after implementation changes.

## Artifacts

- Pre-v2 baseline: `docs/testing/pre-v2-runtime-baseline.md`
- Pre-v2 route inventory: `docs/testing/pre-v2-route-inventory.csv`
- This document: `docs/testing/v3-preimplementation-baseline.md`
