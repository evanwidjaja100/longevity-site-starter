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

## Implementation Progress (Milestone A — Safe Canonical Foundation)

Branch: `improvement/launch-foundation-v2`

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
- P1.5 ⬜: Noindex draft/placeholder pages
- P1.6 ⬜: Verify canonical URLs in `<head>` via theme

### Phase 2 — Production-Grade Bootstrap
- P2.1 ⬜: `wp longevity bootstrap categories` — CLI command for canonical categories
- P2.2 ⬜: `wp longevity bootstrap content` — CLI command for placeholder content
- P2.3 ⬜: `wp longevity bootstrap all` — orchestrator command
- P2.4 ⬜: E2E smoke tests for bootstrap commands

### Tip for next agent
- All PHP CLI commands run via `docker compose run --rm wpcli wp ...` (not `docker exec`)
- WordPress function LSP errors are expected (stubs unavailable on host)
- The `Routes` class lives in `Longevity\Core` namespace; use `use` imports in new files
