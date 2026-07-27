# Longevity Evidence Lab — Agent Harness

## Project
Longevity Evidence Lab is a WordPress-based evidence-led consumer health and product-testing publication. It uses a custom MU plugin (`longevity-core`) for editorial governance and a block theme (`longevity-starter`) for presentation.

## Agents

| Agent | Tag | When to use |
|---|---|---|
| **Dev Agent** | `@dev` | PHP/WordPress plugin or theme work, schema changes, JS/CSS |
| **Content Agent** | `@content` | Editorial workflows, content briefs, evidence grading, policy compliance |
| **QA/Test Agent** | `@qa` | Running tests, validating content/links, accessibility checks |
| **DevOps Agent** | `@devops` | Docker, CI/CD, environment config, deployment, backup/restore |
| **Review Agent** | `@review` | Code review, policy compliance review, PR review, governance checks |

## Global Constraints (AI-Assisted Work Policy)
- AI may **not** independently verify medical claims, approve safety language, or invent citations.
- AI may **not** assign evidence grades without human review.
- AI may **not** publish content or approve disclosure status.
- AI may **not** interpret private health information.
- Any AI-assisted output affecting a material claim must be checked against the source registry by a named human.
- AI may assist topic ideation, outline development, source organization, grammar, clarity, metadata suggestions, internal-link suggestions, mechanical formatting, and code generation — all subject to accountable human review.

## Quick Commands
- `/validate` — full validation pipeline
- `/lint` — validation + PHP lint + JS/CSS lint
- `/test` — PHPUnit + content validation
- `/test:e2e` — Playwright core E2E tests
- `/test:a11y` — axe-core accessibility tests
- `/test:all` — all test suites
- `/deploy:check` — pre-deployment readiness checks
- `/bootstrap:local` — spin up local Docker environment
- `/content:audit` — all content validators + freshness check
- `/manifest` — regenerate MANIFEST.sha256

## Key Documentation
- Editorial policy: `content/editorial-policy.md`
- AI governance: `content/governance/ai-assisted-work-policy.md`
- Content model: `docs/architecture/content-model.md`
- System overview: `docs/architecture/system-overview.md`
- Roles/permissions: `docs/architecture/permissions.md`
- Test strategy: `docs/testing/test-strategy.md`
- Architecture decisions: `docs/adr/`
- Operations: `docs/operations/`

## Coding Standards
- PHP: WordPress Coding Standards (per `phpcs.xml.dist`), PHPStan level 5
- JS: ESLint (per `eslint.config.js`)
- CSS: Stylelint (per `.stylelintrc.json`)
- Shell: ShellCheck
- Accessibility: WCAG 2.1 AA (enforced via axe-core in CI)

## Implementation Progress (Milestone A — Safe Canonical Foundation → Reader Experience v3)

Branch: `improvement/reader-experience-v3`

### Phase 1–8 — Reader Experience v3 Foundation ✅
Previous phases completed Route Consolidation, Production-Grade Bootstrap, SEO/noindex, CSS cascade layers, visual snapshot tests, ranking directory prelaunch gating, and core E2E coverage.

### Phase 9 — Trust-first content launch ✅
- RX-901 ✅: All 11 trust pages (About, Editorial Policy, Evidence Methodology, Testing Methodology, Medical Disclaimer, Affiliate Disclosure, Corrections, Privacy, Terms, Contact, AI-Assisted Work Disclosure) populated with proper content from `content/templates/*.md` via markdown-to-WP-blocks conversion script (`scripts/populate-trust-pages.php`). Review dates recorded on all pages. Contact form implemented with abuse protection (honeypot + rate limiting via transient). Corrections channel functional (CPT `lel_correction` + contact form correction-report subject). Privacy page reflects actual tooling.
- RX-902 ✅: 8 foundational articles (LEL-001 through LEL-008) updated with full editorial metadata — evidence cutoff dates, content summaries, limitations, original contributions, commercial relationships (none), region scopes, next review dates, editorial approval status (`ready`), and medical review flags where required. Metadata set via `scripts/update-article-metadata.php`. Articles await human publication per AI governance policy.
- RX-903 ⏳: Consumer Lab testing infrastructure prepared — testing methodology page published, protocol/test-record CPTs exist. Real product testing (product acquisition, protocol registration, documented test execution) requires human action.
- RX-904 ✅: Content QA and lifecycle checks operational — internal link validation test suite (`internal-links.spec.js`), freshness audit cron (`Freshness` class), analytics event layer (`Analytics` class), correction presentation (`Corrections::render()`), update dates and freshness triggers on all pages.

### Remaining for Phase 9 exit
- A human editor must publish the draft trust pages (Privacy, Terms, Contact, Evidence Methodology, AI-Assisted Work Disclosure) after jurisdiction review
- A human editor must publish the 8 foundational articles after medical review (LEL-002, LEL-005–008 require medical review)
- Consumer Lab testing (RX-903) requires real product acquisition and documented protocol-compliant testing

### Phase 0 — Baseline ✅
- P0.1: Runtime baseline report saved to `docs/testing/pre-v2-runtime-baseline.md`
- P0.2: DB + files backup in `docs/testing/artifacts/pre-v2-backup/`
- P0.3: 48 route screenshots + inventory CSV in `docs/testing/pre-v2-route-inventory.csv`
- P0.4: Full test suite results appended to baseline; all suites pass

### Phase 1 — Route Consolidation
- P1.1 ✅: `Routes` class (`class-routes.php`) — canonical key→ID/URL resolution, request caching, legacy fallback. Unit tested.
- P1.2 ✅: Routes integrated into theme nav-walker via `Route_Nav_Walker` (replaced `Nav_Menu_Fixer`)
- P1.3 ✅: `Bootstrap_Command` in `class-cli.php` — `wp longevity bootstrap pages`. Created Home (ID 72) as `page_on_front`, Start Here (ID 4) as standalone. 14 pages managed. Idempotent.
- P1.4 ✅: Deleted short duplicate slugs `supplements`, `wearables`, `nutrition`; swapped Routes class to use long slugs as canonical; 19/19 E2E pass
- P1.5 ✅: Add `_longevity_noindex` meta to draft pages; `wp_robots` filter in Bootstrap enforces noindex for drafts and placeholder pages
- P1.6 ✅: Added `wp_head` action for canonical URLs on category archives via Routes class; 19/19 E2E pass

### Phase 2 — Production-Grade Bootstrap ✅
- P2.1 ✅: `wp longevity bootstrap categories` — 7 canonical categories created idempotently
- P2.2 ✅: `wp longevity bootstrap content` — 7 placeholder items (6 posts + 1 review) created as drafts with `_longevity_noindex`
- P2.3 ✅: `wp longevity bootstrap all` — orchestrates pages, categories, content in sequence
- P2.4 ✅: 6 E2E smoke tests for bootstrap commands (dry-run, idempotency, all sections)

### Phase PRv1 — Production Readiness Implementation ✅
- PRv1-0 ✅: Committed database dump and file backup removed from working tree; `.gitignore` updated with `*.sql`, `*.tgz`, `*.tar.gz`; CI guard added to `security.yml`; README rewritten to document current backup policy.
  - ⏸ **History purge pending** (requires human sign-off for force-push)
  - 🔴 **HUMAN: Rotate `longevity_app` MySQL password** (prefix `Delta_10` visible in dump header)
- PRv1-1 ✅: `phpstan.neon.dist` includes WordPress stubs + test bootstrap; `RoutesTest.php` assertion corrected to 17 pages with key-based manifest comparison; `ci.yml` now runs PHPCS, PHPStan, PHPUnit, ESLint, Stylelint, and Playwright E2E via `quality`, `frontend`, and `e2e` jobs; CSS and JS lint issues fixed.
- PRv1-2 ✅: All GitHub Actions SHA-pinned across 3 workflow files; Akismet and 3 default themes removed (unused); dead `$api_url` variable removed; contact-form email notification added (gated behind `SMTP_HOST`/`phpmailer_init`).
- PRv1-3 ✅: Hardcoded route literals in 9 PHP pattern files replaced with `Routes::public_page_url()`; duplicate shell-based category creation removed from `bootstrap.sh` (delegated to CLI command); `routes.spec.js` URL comparison uses `process.env.WP_SITE_URL`.
  - ⏸ **a11y/zoom/Lighthouse/cross-browser verification** requires Docker (not available on host)
- PRv1-4 ✅: `Public_Components` god class (~1,225 lines) split into 5 domain classes via backward-compatible facade: `Public_Nav`, `Public_Contact`, `Public_Content`, `Public_Trust`, `Public_Rankings`. No call-site changes needed.
- 🔴 **Phase 5 (human-only):** Production accounts, MFA, HTTPS/HSTS, SMTP/SPF/DKIM/DMARC, off-site encrypted backups with restore drills, uptime monitoring, CSP enforcement flip, branch-protection rules.

### Phase 10 — Measurement and iteration
- RX-1001 ✅: 5 new privacy-safe funnel events defined (`start_here_open`, `topic_open`, `guide_open`, `source_open`, `claim_matrix_expand`) and registered in `class-analytics.php` event allowlist. All events wired via `data-lel-event` attributes across 11 pattern files, 3 template files, 4 PHP render classes.
- RX-1002 ✅: KPI definition document created (`operations/kpi-definitions.md`) covering content quality, reader engagement, product/review, and operational KPIs. All data sources documented.
- RX-1003 ✅: Usability review plan created (`operations/usability-review-plan.md`) with 6 reader profiles, 10 test tasks, session format, privacy/ethics guidelines, and deliverables.

### Tip for next agent
- All PHP CLI commands run via `docker compose run --rm wpcli wp ...` (not `docker exec`)
- WordPress function LSP errors are expected (stubs unavailable on host)
- The `Routes` class lives in `Longevity\Core` namespace; use `use` imports in new files
- `Public_Components` is now a facade; new domain classes are in `Longevity\Core\Public_Nav`, `Public_Contact`, `Public_Content`, `Public_Trust`, `Public_Rankings`
### Phase PRv2 — Trust Boundary and Enforcement

**Source limitation:** This implementation was prepared in an editable reconstruction of the Repomix snapshot. The authoritative Git checkout, branch, commit history, and ignored files were not available in this environment.

- PR2-0: **partial** — baseline recorded in `docs/testing/production-readiness-v2-baseline.md`; real clean-checkout/Git evidence, Composer suites, Docker, browsers, and Lighthouse remain required.
- PR2-1: **implemented, human governance review required** — explicit metadata policies and shared server-side authorization.
- PR2-2: **implemented, human verifier assignment/reverification required** — independent credential snapshots.
- PR2-3: **implemented, staging migration/editorial policy approval required** — immutable approval snapshots and automatic stale-state invalidation.
- PR2-4: **implemented** — allowlisted public REST projection, private raw metadata, minimal health.
- PR2-5: **blocked on authoritative `composer.lock` and real clean checkout** — package lock/test discovery/manifest/CI enforcement implemented; Composer lock was not fabricated.
- PR2-6: **implemented in code, production-like operational verification required** — packaged scoring config, semantic dates, fair freshness cycles, protected readiness.
- PR2-7: **implemented in code, retention/separation policy approval required** — split capabilities, approval-aware records, affiliate lifecycle, append-only audit, contact privacy.
- PR2-8: **CI lanes defined, not executed here** — branch protection and release evidence require GitHub/repository and Docker/browser access.
- PR2-9: **human/external gate outstanding** — private staging, workflow exercise, backup/rollback drill, external controls, CSP, and launch sign-off.

**Public production launch remains NO-GO until all PR2 P0 gates pass in the authoritative repository and human approvals are recorded.**

### Phase babaooey_2 — Production Readiness PR 00-15 (2026-07-27)

All 15 PRs from `babaooey_2.md` are implemented in code on `improvement/production-readiness-v2`:
PR 00-09 (metadata authorization, fail-closed audit, atomic migrations, invalidation,
scoring/claims/corrections/projection), PR 10 (DB-only contact limiter), PR 11 (trust-page
approval snapshots), PR 12 (a11y/SEO/analytics/CSP + pattern quarantine), PR 13 (honest CI
coverage, required a11y job, first-party release artifact, 0 npm advisories), PR 14-15
(managed-host-only runbooks, Redis/VPS retirement, evidence reconciliation).

- Evidence and go/no-go: `docs/testing/production-readiness-v2-evidence-reconciliation.md`
- Release artifact: `scripts/build-release-artifact.sh` (also CI job `release-artifact`)
- Local verification: 129 PHPUnit tests / 485 assertions green, ESLint/Stylelint clean,
  npm audit 0 vulnerabilities, manifest valid.
- Launch remains **NO-GO** until human/external gates (staging, backups, approvals,
  credentials rotation, branch protection, screen-reader sign-off) have named owners and
  dated evidence.
