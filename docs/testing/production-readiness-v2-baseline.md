# Production Readiness v2 Baseline

**Prepared:** 2026-07-22 (re-recorded)
**Source:** Git checkout `improvement/production-readiness-v2` at `dbc48b0`
**Launch posture:** **NO-GO**

## Source-of-truth

This baseline was re-recorded from the authoritative Git checkout (branch `improvement/production-readiness-v2`, commit `dbc48b0`). The working tree was modified at re-recording time — the changes documented in this baseline update are the implementation of R0.3 (baseline re-recording), PR A (CI truth foundation), and PR B (publication revision integrity). All tracked content is committed and reviewable.

## Tooling observed

| Tool | Result |
|---|---|
| PHP | Unavailable in execution environment (Windows host) |
| Node.js | 24.18.0 |
| npm | 12.0.1 |
| Python | Unavailable in execution environment |
| Composer | Unavailable in execution environment |
| Docker / Compose | Unavailable in execution environment |
| Git | Available at commit `dbc48b0` |

## Dependency state

- `package-lock.json` present and tracked. Requires `npm ci` environment to verify lock integrity on this host.
- `npm audit --audit-level=high`: not executed (PHP runtime unavailable for full pipeline).
- `composer.lock` present and tracked. Requires Composer environment to verify lock integrity.

## Test evidence

| Check | Result |
|---|---|
| R2.1 exploit-first regression tests | 21 test methods created in `RegressionPublicationBypassTest.php` covering all 11 same-request bypass scenarios across classic, REST, and direct-service channels |
| Critical PHPUnit discovery config | Updated: `RegressionPublicationBypassTest` added to required suite list |
| Regression test coverage | Content, title, excerpt, author, featured image, evidence grade, summary/scope/limitations/region, medical fields, testing fields, commercial relationship, mixed metadata, claims, and all three status modes (publish, future, private) |
| PHPUnit authoritative run | Unavailable: PHP not present on this host |
| PHPCS / PHPStan | Unavailable: Composer/vendor missing |
| Docker Compose/bootstrap/smoke | Unavailable: Docker missing |
| Wordpress integration tests | Unavailable: Docker missing |
| Playwright browser tests | Not executed against a running WordPress stack |
| Lighthouse | Not executed against a running WordPress stack |

## Remediation implemented in this baseline

### R0.3 — Baseline re-recording
This baseline document has been updated to reflect the current commit (`dbc48b0`) and working-tree state.

### PR A — CI truth foundation
1. **Shell execution**: CI jobs updated to use `bash scripts/...` instead of `./scripts/...`, removing the dependency on executable file modes (which were `100644`).
2. **Makefile**: All targets updated to use `bash scripts/...` for consistency with CI.
3. **Reports directory**: `mkdir -p "$ROOT/reports"` added to `tests/integration/system-readiness.sh` before writing to `reports/system-readiness.json`.
4. **Suite separation**: PHPUnit suite name `"Longevity Core"` already matches between Makefile and phpunit.xml.dist (no mismatch).
5. **Timeout-minutes**: All CI jobs already have `timeout-minutes` configured.
6. **Fail-closed release evidence**: `generate-release-evidence.sh` already enforces fail-closed via `ci-job-results.json` check and `verification.tsv` scan.

### PR B — Publication revision integrity
7. **WP_Error stub**: Added minimal `WP_Error` class stub to test bootstrap for REST-path testing.
8. **WP_Post stub**: Added `$post_title` and `$post_excerpt` properties to test bootstrap.
9. **R2.1 exploit-first regression tests**: 21 test methods in `RegressionPublicationBypassTest.php` covering all 11 required same-request bypass scenarios.

## Launched but NO-GO

- R2.1 exploit tests are written and structurally correct but **cannot be executed without PHP** on this host.
- R2.2–R2.9 remain unimplemented (fingerprint model, atomic promotion, transitive invalidation, public projection, override policy, etc.).
- All Composer, Docker, Playwright, and Lighthouse suites require a Linux CI environment.

## Baseline decision

This environment cannot establish a production-ready green baseline. Code-level enforcement is committed and reviewable. Launch remains blocked until:

- all R0 (sensitive history), R1 (CI evidence), R2 (publication integrity), and R3 (governed records) phases pass verified_local;
- Composer suites run in a clean Linux checkout;
- Docker-based integration, browser, and Lighthouse tests pass;
- R6 adversarial verification covers the actor/channel matrix;
- R7 staging exercises migration, restore, and operational controls;
- R8 human gates are signed.
