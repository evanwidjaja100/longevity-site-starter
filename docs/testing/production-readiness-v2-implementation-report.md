# Production Readiness v2 Implementation Report

**Prepared:** 2026-07-21
**Artifact:** reconstructed repository implementation
**Decision:** **NO-GO pending real-checkout, staging, and human gates**

## Implemented controls

### PR2-1 — Editorial authorization

- Added deny-by-default `Meta_Authorization` with one exact policy for every registered editorial field.
- Routed classic editor, REST authorization, workflow completion, CLI mutation, and publication same-request checks through shared policy decisions.
- Made final workflow values service-only: forms may collect supporting state, but only immutable approval creation may project `complete`, `approved`, `ready`, or attested status.
- Prevented absent protected checkboxes from being interpreted as false.
- Audited denied writes and retained permitted descriptive-field updates in mixed requests.

### PR2-2 — Reviewer credential independence

- Added `verify_reviewer_credentials` and prevented self-verification.
- Split claimed profile data from verifier-controlled credential snapshots.
- Enforced verification date, expiry, scope, jurisdiction, verifier identity, and snapshot version.
- Public cards/schema use verified snapshots only; profile changes stale prior verification.
- Added independently authorized verification reporting/CLI surfaces without inventing reviewer evidence or decisions.

### PR2-3 — Immutable approvals

- Added idempotent approval table migration and repository/service layers.
- Bound fact-check, medical, testing, commercial, and editorial approvals to deterministic content, metadata, and dependency fingerprints.
- Added automatic invalidation and explicit stale states.
- Changed publication gates, synthetic fixtures, public renderers, and final editor transitions to require current snapshots rather than legacy status strings.
- Prevented compatibility status projections from invalidating their own newly created snapshot.
- Classified legacy completion states as `legacy_unbound`; no approval was invented.

### PR2-4 — REST boundary

- Removed raw governance meta and private CPTs from REST exposure.
- Added allowlisted `longevity_public` projection that fails closed for stale approval-dependent facts, including material evidence fields and non-neutral commercial relationships.
- Reduced anonymous `/health` to liveness only.
- Added protected internal readiness reporting.

### PR2-5 — Reproducibility and discovery

- Repaired namespaced architecture execution through one reusable assertion utility.
- Added strict PHPUnit failure settings and required-suite/minimum test discovery.
- Added deterministic manifest and dependency verification scripts.
- Generated and tested `package-lock.json`.
- Reworked CI into explicit clean-build, PHP, frontend, content/config, WordPress integration, browser, Linux visual, mobile/desktop Lighthouse, CodeQL, supply-chain, and release-evidence lanes.
- `composer.lock` remains a hard blocker and was not fabricated.

### PR2-6 — Runtime and operations

- Packaged the scoring model inside the MU plugin and added structured fail-closed validation.
- Added semantic UTC date validation and comparison.
- Replaced freshness selection with never-scanned/least-recently-scanned fairness, cycle metrics, heartbeat, lock, and continuation behavior.
- Added protected readiness categories including honest `unknown_external` status for backup, restore, and mail evidence.

### PR2-7 — Separation, audit, affiliate, contact

- Split claim editing/verification, test data entry/approval, affiliate management/commercial approval, reviewer verification, readiness, and audit capabilities.
- Added claim editor/verifier provenance and snapshot hashes; CSV import strips verifier-controlled columns and initializes claims as unverified.
- Added independent protocol and test-record approval bound to canonical hashes; material edits make either record stale, and the tester/submitter/author cannot approve their own record.
- Enforced independent commercial approval against active relationship owners when policy is enabled.
- Added normalized affiliate lifecycle validation for dates, verification recency, credentials, ports, exact/subdomain policy, case, `www`, trailing dots, and IDN when available.
- Added append-only, indexed, hash-chained governance audit storage with bounded private-data-safe payloads.
- Added contact subject allowlist, sensitive-health warning, trusted-proxy parsing, versioned HMAC network identifiers, retention scheduling, and body-free delivery-failure auditing.

## Local verification evidence

- PHP syntax: 104 first-party PHP files passed.
- Dependency-free security runner: 32 assertions plus shared architecture constraints passed.
- PHPUnit discovery contract: 25 classes and 82 test methods discovered; all critical suites present.
- `npm ci`, CSS/JS lint, and `npm audit --audit-level=high` passed; npm reported zero vulnerabilities.
- Content, internal-link, freshness-register, and environment-validation checks passed.
- Composer-managed PHPUnit, PHPCS, PHPStan, Docker/WordPress integration, Playwright, and Lighthouse remain unavailable in this execution environment and are not represented as passes.

## Human decisions still required

- Approve the editorial field-policy matrix and exact role/capability ownership.
- Designate credential verifiers and independently re-verify legacy reviewer records.
- Approve invalidation behavior for already-published material.
- Approve test/commercial separation policy and audit/contact retention periods.
- Review any required privacy/legal wording; no legal text was approved by AI.
- Configure branch protection, production credentials, MFA, WAF, SMTP/SPF/DKIM/DMARC, backup evidence, restore drills, monitoring, CSP progression, and production deployment.

## Execution still required in the real repository

1. Apply/review this reconstructed tree against the authoritative checkout and preserve unrelated human work.
2. Generate `composer.lock` using the supported Composer/PHP toolchain; inspect and commit it.
3. Run PHPCS, PHPStan, PHPUnit, fallback tests, manifest verification, and locked audits from a clean checkout.
4. Run Docker bootstrap, migrations, smoke, authorization, invalidation, REST-boundary, and readiness integration tests.
5. Run Chromium, Firefox, WebKit, accessibility, visual, reflow/zoom, and Lighthouse gates.
6. Deploy privately, exercise the high-risk workflows, and complete backup/rollback drills.

## Rollback posture

All schema changes are additive. Approval and audit tables must be retained during rollback. A temporary compatibility rollback may read legacy metadata, but it must document the reduced security guarantee and must not restore self-verification, broad metadata authorization, or silent trust in legacy completion states.
