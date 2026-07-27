# Production Readiness v2 Implementation Report (babaooey_2.md, PR 00–15)

- **Project:** Longevity Evidence Lab (WordPress MU-plugin `longevity-core` + block theme `longevity-starter`)
- **Branch:** `improvement/production-readiness-v2`
- **Report date:** 2026-07-27
- **Plan implemented:** `babaooey_2.md` — findings F01–F18 resolved through PRs 00–15
- **Result:** All 15 PRs implemented and committed. Local verification green. Public launch remains **NO-GO** pending human/external gates (Section 8).

---

## 1. Executive summary

This effort completed the Production Readiness v2 plan end to end. The plan identified 18 findings (F01–F18) across authorization, auditability, migrations, invalidation, scoring, claims, corrections, public projection, contact privacy, trust-page governance, accessibility/SEO/analytics/CSP, CI honesty, release packaging, and operational delivery. Each finding was resolved in a focused, individually verifiable commit.

Key outcomes:

- **Trust boundary enforced in code:** metadata writes authorized at the persistence layer, fail-closed audit events, immutable approval snapshots, synchronous invalidation, and a single public-state projection policy.
- **CI made honest:** coverage math fixed (a latent bug read a nonexistent Clover attribute), accessibility promoted to a required job, strict test discovery raised to 14 required suites with a ≥100-test floor.
- **Reproducible releases:** a first-party-only release artifact (tar.gz + per-file sha256 manifest + source-SHA-bound checksum) built by `scripts/build-release-artifact.sh` and produced as a CI artifact.
- **Single production target:** Redis and the VPS deployment path fully retired; managed WordPress hosting is the only supported production path, delivered via the release artifact.
- **Zero known supply-chain advisories:** npm audit reports 0 vulnerabilities after dependency upgrades and one targeted override.

Local evidence at HEAD (`ac0eb7f`): 129 PHPUnit tests / 485 assertions passing, ESLint and Stylelint clean, MANIFEST.sha256 valid (498 entries), release artifact builds reproducibly (123 runtime files).

---

## 2. Scope and constraints

The plan was executed under the project's AI-Assisted Work Policy:

- No content was auto-published; all editorial publication remains a human action.
- No medical claims were verified, no evidence grades assigned, no citations or credentials invented.
- Reviewer verification, legal approval, and testing execution are recorded as human gates, not simulated.
- Redis removal and VPS retirement were mandated by the plan and completed (PR 14).

---

## 3. Commit map (PR → finding → commit)

| PR | Findings | Scope | Commit |
|---:|---|---|---|
| 00 | — | Plan baseline, source reconciliation, manifest regeneration | `202f3ca` |
| 01 | F01 | Metadata authorization enforced at the persistence layer | `738f8a4` |
| 02 | F02 | Fail-closed mandatory audit events for trusted transitions | `8bc6908` |
| 03 | F03, F18 | Atomic migration lock, schema postconditions, role/freshness/readiness | `9951072` |
| 04 | F04, F05 | Synchronous parent-edit invalidation, current-state check, protocol binding | `debd627` |
| 05 | F05 | Score version/dimensions bound to installed model and protocol | `9a03fd2` |
| 06 | F06 | Claim source validation and editor separation of duties | `cc205bc` |
| 07 | F07 | Protected correction transition service with parent propagation | `9a42d91` |
| 08 | F08 | Single public-state policy for test output projection | `e5d73a1` |
| 09 | F08 | Shared public projection surfaces (same commit as PR 08) | `e5d73a1` |
| 10 | F10 | DB-only contact rate limiting, rendered feedback, 303 redirects, legal hold | `6442eda` |
| 11 | F17 | Governed trust-page approval snapshots and seed protection | `4928d69` |
| 12 | F16 | Accessible search name, canonical og:url, CSP style-attr, analytics allowlist, pattern quarantine | `fbf2b09` |
| 13 | F12, F13 | Honest CI coverage, required a11y job, first-party release artifact | `38d726f` |
| 14 | — | Managed-host-only delivery, Redis/VPS retirement, runbooks | `ac0eb7f` |
| 15 | — | Evidence reconciliation and go/no-go record | `ac0eb7f` |

---

## 4. Implementation detail by area

### 4.1 Governance control plane (PR 01–09, committed before this session's final phase)

- **Authorization (F01):** all governed metadata writes pass a shared server-side authorization check at the persistence layer; direct meta writes to protected keys are rejected regardless of entry point.
- **Audit (F02):** trusted state transitions emit mandatory audit events; if the audit write fails, the transition fails (fail-closed).
- **Migrations (F03, F18):** migrations run under an atomic lock, are additive, and verify schema postconditions before reporting success.
- **Invalidation (F04, F05):** parent content edits synchronously invalidate dependent approvals; invalidation checks current state and is bound to the registered protocol version.
- **Scoring (F05):** published scores carry the score model version and dimensions of the installed model (`config/scoring/default-review-model.json`) and the approving protocol; mismatches block publication.
- **Claims (F06):** claims require validated sources from the registry; the editor who authored a claim cannot verify it (separation of duties).
- **Corrections (F07):** correction state transitions go through a protected service that propagates status to parent content.
- **Public projection (F08/PR 09):** one policy decides what test/review state is publicly visible; templates and REST read the projection, never raw meta. Raw metadata stays private; the health endpoint is minimal; readiness is a protected endpoint.

### 4.2 Contact privacy (PR 10, `6442eda`)

- Rate limiting moved from transients to a DB-backed limiter (survives object-cache flushes, works identically on managed hosts).
- Form submissions respond with 303 redirects and rendered user feedback (no resubmission on refresh).
- Retention with legal-hold support for contact records.

### 4.3 Trust-page governance (PR 11, `4928d69`)

- Trust pages (Privacy, Terms, Disclaimers, Methodology, etc.) get immutable approval snapshots; edits after approval invalidate the snapshot.
- Seeded/bootstrap trust content is protected from silent drift.

### 4.4 Accessibility, SEO, analytics, CSP (PR 12, `fbf2b09`)

- **Search accessibility:** search button retains an accessible name at mobile widths; new E2E test asserts it at 360 px.
- **Real zoom testing:** the CSS `zoom` simulation test was replaced with genuine browser zoom via CDP (`Emulation.setPageScaleFactor: 4` at 1280×800, Chromium), asserting no horizontal overflow at 400%.
- **Analytics hardening (`assets/analytics.js`):** payloads are rebuilt from an explicit parameter allowlist (`placement`, `content_id`, `content_group`); empty allowlisted fields are omitted; values are sanitized (control chars stripped, 120-char cap). E2E tests assert exact queue growth (`before + 1`), exact payload key sets, and empty-field omission.
- **SEO/CSP:** canonical `og:url`, `style-src-attr 'unsafe-inline'` for WordPress inline styles, nonce-bound JSON-LD, CSP report-only by default with an enforcement toggle for the operator.
- **Pattern quarantine:** `study-appraisal-worksheet.php` and `buyer-facts-table.php` contained fabricated demo values (sample sizes, prices, evidence grades, "Testing completed: Yes"). Both patterns now carry `Inserter: false` and all fabricated values were replaced with `[TBD]` / `[Product name]` placeholders — no fabricated evidence can enter content through the inserter.

### 4.5 CI truth and release artifact (PR 13, `38d726f`)

- **Latent coverage bug fixed:** CI previously read `percentcoveredstatements` from Clover XML — an attribute that does not exist, silently yielding no enforcement. The check now computes `coveredstatements / statements` from the real Clover metrics, fails when the file is missing/unreadable, and fails on zero total statements (empty coverage cannot pass).
- **Accessibility is a required job:** new `accessibility` CI job runs `npm run test:a11y -- --project=chromium` against the integration environment and is part of the `release-evidence` needs list.
- **Strict test discovery:** `scripts/verify-test-discovery.php` now requires 14 named suites (added ClaimVerificationTest, ProtocolApprovalTest, AffiliateLifecycleTest, ContactPrivacyTest, TrustPagesTest) and a ≥100 discovered-test floor (previously 50).
- **Release artifact (`scripts/build-release-artifact.sh`, new):** packages only first-party runtime code (mu-plugin loader, `longevity-core/`, `longevity-starter/`), strips logs/maps/OS files, and emits:
  - `release-manifest-<sha>.sha256` (per-file hashes, 123 files)
  - `longevity-release-<sha>.tar.gz` + `.sha256` checksum
  - `release-info-<sha>.txt` (source SHA, artifact sha256, file count, build UTC)
  A new `release-artifact` CI job builds this from `$GITHUB_SHA` and uploads it; both new jobs feed `ci-job-results.json` in the evidence bundle.
- **Migration step in CI:** `scripts/ci-setup.sh` runs `wp longevity migrate` between bootstrap and fixture creation, so integration tests exercise the migrated schema.

### 4.6 Managed-host-only delivery (PR 14–15, `ac0eb7f`)

- **Redis retired:** `compose.yaml` no longer defines a redis service, `WP_REDIS_*` defines, `WP_CACHE`, or the redis volume.
- **VPS path retired:** `docs/operations/vps-deployment.md` deleted; `monitoring.md` rewritten for host-provided monitoring; `system-overview.md` describes Docker as dev/CI-only.
- **Artifact-based release flow:** `release-process.md` rewritten as a 9-step flow — build artifact from a specific SHA, deploy that exact artifact to staging, verify, promote the identical checksum to production, roll back by redeploying the previous artifact; migrations remain additive.
- **Evidence reconciliation (`docs/testing/production-readiness-v2-evidence-reconciliation.md`, new):** authoritative PR-state table, verified-evidence list, environment-unavailable list, the full human/external gate table, and the Go/No-Go rule. Decision recorded: **NO-GO**.

---

## 5. Verification evidence (local, at HEAD `ac0eb7f`)

| Check | Result |
|---|---|
| PHPUnit (authoritative + fallback runner) | 129 tests, 485 assertions, 0 failures |
| Test discovery | 14 required suites discoverable; ≥100-test floor met |
| ESLint 10 (runtime JS, test JS, configs) | Clean |
| Stylelint (theme CSS) | Clean |
| npm audit | 0 vulnerabilities |
| `scripts/verify-manifest.sh` | Valid — 498 entries, hashes match git index bytes |
| `scripts/build-release-artifact.sh` | Builds successfully — 123 first-party files, checksums emitted |
| `npx lhci --version` after dependency changes | 0.15.1, functional |
| Redis/VPS references in runtime and compose | None remaining |

---

## 6. Defects discovered and fixed during implementation

| Defect | Resolution |
|---|---|
| CI coverage gate read nonexistent Clover attribute `percentcoveredstatements` (coverage was never actually enforced) | Rewrote check to compute from `statements`/`coveredstatements`; fails on missing/empty coverage |
| PR 01's Redis/VPS retirement had never actually been executed | Completed in PR 14 (compose, docs, delivery model) |
| Zoom E2E test simulated zoom with CSS `zoom` property (not real browser zoom) | Replaced with CDP `Emulation.setPageScaleFactor` at 4× |
| Analytics payloads could include empty allowlisted fields and unbounded values | Allowlist rebuild, empty-field omission, sanitization, 120-char cap |
| Theme patterns exposed fabricated evidence values via the block inserter | `Inserter: false` + `[TBD]` placeholders |
| 9 high npm advisories (eslint chain, chrome-launcher via @lhci/cli, glob/minimatch/rimraf/brace-expansion) | eslint ^10.8.0 + `overrides["chrome-launcher"]: "^1.2.0"` → 0 vulnerabilities |
| ESLint gaps: theme JS and configs unlinted; missing DOM globals; `no-useless-assignment` hit | lint:js scope widened; globals added to `eslint.config.js`; test fixed |
| MANIFEST verification requires a clean index | Process fixed: regenerate → commit → verify (passed after each commit) |

Known pre-existing items intentionally not touched: ~51 project-wide PHPStan findings (none introduced by this work; new code is PHPStan-clean) and the root `overflow-x: clip` at ≤700 px (plan conditions its removal on browser-verified overflow fixes — a staging gate).

---

## 7. Evidence not obtainable in this environment

These require CI (GitHub Actions), Docker, real browsers, or humans — the lanes exist and must pass on the candidate SHA:

- Docker-based WordPress integration contracts; Playwright functional, accessibility, cross-browser, and visual suites; Lighthouse (no Docker/browsers on this host).
- Linux visual baselines (must be generated in the pinned Playwright image and human-reviewed).
- Full PHPCS run (local PHP 8.5 crashes the PHPCompatibility sniff; CI pins PHP 8.3).
- Real screen-reader (NVDA/VoiceOver/TalkBack), forced-colors, and manual zoom verification.

---

## 8. Outstanding human and external gates (launch blockers)

| Gate | Owner (role — name TBD) |
|---|---|
| Select/contract managed WordPress provider | Technical release owner |
| Rotate historical `longevity_app` MySQL credential; purge dump from Git history | Technical release owner |
| Branch protection on `main` with required CI checks | Technical release owner |
| Private staging deployment of the exact release artifact; smoke + rollback drill | Technical release owner |
| Provider DB+media backups and quarterly restore-drill evidence | Operations owner |
| Monitoring, cron heartbeat, contact-delivery and error alerts to a named operator | Operations owner |
| SMTP/SPF/DKIM/DMARC, WAF, MFA, HTTPS/HSTS; CSP enforcement flip after clean report-only window | Operations owner |
| Privacy, Terms, Affiliate Disclosure, Medical Disclaimer, retention approval | Privacy/legal owner |
| Jurisdiction and legal entity/controller confirmation | Privacy/legal owner |
| Reviewer credential verification | Editorial owner |
| Claim/source verification, evidence grades, methodology approval | Editorial + medical owners |
| Real product testing under registered protocols | Testing owner |
| Trust-page and launch-content publication (after approvals) | Editorial owner |
| Screen-reader and manual accessibility sign-off | Accessibility reviewer |
| Final signed go/no-go for a specific artifact checksum | All owners above |

---

## 9. Go/No-Go

**Decision: NO-GO** for public production launch.

GO requires, for one specific source SHA and artifact checksum: all required CI jobs green on that SHA, staging exercised with that exact artifact, every Section 8 gate evidenced and signed by a named owner, and no unresolved high/critical finding without a signed exception. Anything less remains NO-GO.

The engineering scope of babaooey_2.md (PR 00–15, findings F01–F18) is complete; what remains is operational execution and accountable human sign-off.

---

## Appendix A — Key verification commands

```bash
# Tests
composer test                       # or the fallback runner
php scripts/verify-test-discovery.php

# Lint / supply chain
npm run lint:js && npm run lint:css
npm audit

# Integrity
bash scripts/verify-manifest.sh     # requires clean index; run after commit
bash scripts/regenerate-manifest.sh # after intentional file changes

# Release
bash scripts/build-release-artifact.sh <source-sha>
```

## Appendix B — Key documents

- Evidence and go/no-go: `docs/testing/production-readiness-v2-evidence-reconciliation.md`
- Release flow: `docs/operations/release-process.md`
- Managed-host deployment: `docs/operations/managed-wordpress-deployment.md`
- Monitoring: `docs/operations/monitoring.md`
- Progress log: `AGENTS.md` (Phase babaooey_2 section)
