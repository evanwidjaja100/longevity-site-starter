# Production Readiness V3 — Implementation Status

**Owner:** Release management
**Last reviewed:** 2026-07-30
**Launch decision:** **NO-GO** (unchanged)
**Document status:** Engineering progress record. Not launch approval and not
completion evidence for any human or external gate.

## Purpose

This note records which tickets from
[`docs/implementation/production-readiness-v3-implementation-plan.md`](../implementation/production-readiness-v3-implementation-plan.md)
have been implemented in the repository, and which remain open because they
require authority an automated agent must not assume: credential rotation,
destructive Git history rewriting, branch-protection administration,
managed-host provisioning, and named human sign-offs.

Implementing repository tickets does **not** move the launch decision. The
decision remains **NO-GO** until every mandatory gate in the plan's
"Definition of production ready" has acceptable, candidate-specific evidence.

## Implemented in this branch

| Ticket | Change | Regression guard |
|---|---|---|
| Docs consistency | `docs/operations/performance-baselines.md` now carries Owner/Last reviewed metadata. | `scripts/verify-docs-consistency.sh` passes. |
| PRV3-BOOT-01 | `config/routes.json` is the single route-state contract; the Playwright inventory and load/Lighthouse targets derive from it. | `tests/php/RouteContractTest.php` fails on any drift between the contract, `Routes`, `Bootstrap_Command`, and `Trust_Pages`. |
| PRV3-BOOT-02 | `scripts/bootstrap.sh` delegates all page/content creation to `wp longevity bootstrap all`; the shell wrapper only orchestrates the environment and seeds trust-page templates through the existing guarded path. | `scripts/ci-setup.sh` runs a second bootstrap and fails if it is not idempotent. |
| PRV3-BOOT-03 | The CI fixture projection publishes only contract `ci_fixture_public` routes, through the real trust-page approval gate, watermarked with `_lel_ci_fixture_published`; fixtures refuse any environment other than `local`/`development`. | `ArchitectureAssertions` enforces the environment allowlist, approval-gate usage, and watermark. |
| PRV3-CI-02 | Dev/CI WordPress image repinned to `7.0.2-php8.3` (satisfies `Platform_Requirements::MIN_WORDPRESS`); `ci-setup.sh` runs `wp longevity preflight` as a fail-closed runtime-drift gate. | `PreflightTest`; preflight gate in CI. |
| PRV3-PERF-01 | `tests/load/k6-scenario.js` derives routes from the contract, drops nonexistent REST endpoints/assets, splits smoke vs. `K6_PROFILE=load`; the k6 container is pinned by digest and the smoke gate is blocking. | Contract-derived routes; docs updated. |
| PRV3-PERF-02 | Lighthouse URLs derive from the contract; `scripts/lighthouse-preflight.mjs` fails on 404/redirect/login before any score. | Preflight gate wired into `test:lighthouse`. |
| PRV3-QA-03 | `phpunit.xml.dist` migrated to the `<source>` element; local runs are assertion-only while CI enforces PCOV coverage, so a missing driver never yields an ambiguous pass. | Full suite green locally with no coverage-driver warning. |
| PRV3-OBS-01 | Metric catalog in `docs/operations/monitoring.md` reconciled with `class-metrics.php`; counter alerts use reset-aware `increase()` windows; alert runbook annotations point to real files; new `operations/runbooks/invalidation-fallback-failure.md`. | `tests/php/MetricsCatalogTest.php` enforces doc/code parity, `increase()` windows, runbook existence, and retirement of the stale series name. |
| PRV3-QA-01 | PHPCS `custom_capabilities` allowlist bound to `Roles::ALL_CUSTOM_CAPS`; all security-significant SQL/escaping/nonce/input findings in reachable first-party code remediated (escaped exceptions, documented trusted-prefix interpolation and fully-prepared SQL, boundary unslash+sanitize). | `tests/php/CapabilitiesConfigTest.php` keeps the allowlist in lockstep with registered roles; a targeted security-sniff scan reports zero findings. |
| PRV3-QA-02 | Mechanical/docblock PHPCS debt cleared: `phpcbf` formatting pass, accurate PHPDoc across first-party classes/scripts, translators comments, Yoda conditions, and semantics-preserving code fixes (short-ternary hoisting, count-out-of-loop, brace syntax, seed-script global renames). Two narrow documented sniff exclusions (`bootstrap.php` filename; `class-cli.php` command registry). | `composer phpcs` exits zero on first-party scope; `composer test` (419), `phpstan` level 5, `psalm --taint-analysis`, and `composer test:fallback` remain green. |

## Completed with repository-owner authority (2026-07-31)

These required the repository owner acting as incident lead / administrator,
not an automated agent alone. They were executed and independently verified.

| Ticket | Change | Verification |
|---|---|---|
| PRV3-IR-01 | Rotated all affected local credentials (DB app/root, WP admin) and destroyed every credential-bearing Docker volume; rotated the GitHub account password and enabled 2FA. | `scripts/validate-env.sh` passes; from-scratch `make bootstrap` + `make smoke` green. See `docs/operations/incidents/2026-07-18-credential-incident.md`. |
| PRV3-IR-02 | Removed `.env.ci` and the two pre-v2 backup artifacts from all 97 commits with `git-filter-repo`; force-pushed the rewrite. | Fresh clone from the remote shows no secret paths or blobs; repo `fsck` clean; manifest valid. |
| PRV3-IR-03 | Incident closed with post-mortem; push-time prevention enabled. | Secret scanning, push protection, and Dependabot verified enabled via the GitHub API. |
| PRV3-GOV-01 | Branch protection on `main`: pull request required, all 15 CI status checks required and strict, force-push and deletion blocked. | Settings read back via the GitHub API confirm 15 required contexts, `strict=true`, force-push/deletion disabled. |

Owner-retained follow-ups: enable "Include administrators" on the `main` rule
before launch, and delete the local pre-rewrite mirror backup once satisfied.

## Open — human or external authority required (still NO-GO)

These tickets are intentionally **not** implemented or signed here. An
automated agent may prepare templates and report observations, but must not
execute or attest them.

- **PRV3-CI-01/03/04** — Publishing/reconciling the audited branch and proving
  required CI is green on the exact candidate on the remote runner.
- **PRV3-HOST-01 / STG-01/02** — Managed-host qualification and private
  production-like staging deployment.
- **PRV3-OPS/PRIV/SEC/CSP** — Backups/restore RPO-RTO, cron/workers, mail/DNS,
  data-subject operations, edge/TLS/WAF, and CSP enforcement, all requiring a
  live managed environment and named owners.
- **PRV3-ACC-01…04** — Staging application, failure/recovery, security/privacy,
  and performance acceptance on the deployed artifact.
- **PRV3-HUM-01…04** — Editorial/medical/source, product-testing/commercial,
  legal/privacy, and manual accessibility sign-offs.
- **PRV3-REL-01…03** — Candidate freeze, signed go/no-go, and production
  promotion/hypercare.

## Verification commands (repository scope)

```bash
composer test
composer phpstan
php vendor/bin/psalm --taint-analysis --no-progress --no-cache
npm run lint
python3 scripts/validate-content.py
bash scripts/verify-docs-consistency.sh
composer phpcs
```

Managed-host, staging, operational-drill, and human-review evidence is out of
repository scope and remains outstanding.
