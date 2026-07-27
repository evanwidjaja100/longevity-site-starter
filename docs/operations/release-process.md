# Release Process

1. Open a focused change with documentation and tests.
2. Run static validation, unit tests, dependency checks, and Docker configuration validation.
3. Build the release artifact from the candidate SHA: `bash scripts/build-release-artifact.sh <sha>`. The artifact contains only first-party runtime files (`wp-content/mu-plugins/longevity-core.php`, `wp-content/mu-plugins/longevity-core/`, `wp-content/themes/longevity-starter/`) plus a per-file manifest and a checksum tied to the source SHA.
4. Deploy that exact artifact to managed staging and run bootstrap only for a new installation. Never rebuild separately for staging and production.
5. Execute smoke, permission, publication-gate, schema, accessibility, and responsive checks against staging.
6. Create and verify a provider backup; identify the previous verified artifact checksum as the rollback target and record the database plan.
7. Obtain technical and editorial approval. Production promotion requires manual approval by the technical release owner.
8. Promote the same artifact checksum to production, run required additive migrations once, clear caches, and repeat smoke checks.
9. Observe logs, error monitoring, cron, queue, and contact-delivery signals; record the release and any follow-up.

Rollback redeploys the previous verified artifact; additive database migrations stay in place. Restore the database only for a separately declared data incident under maintenance mode with human approval.

Do not combine unrelated schema, workflow, theme, and infrastructure changes in one emergency release.
## Production Readiness v2 release gates

A release candidate must pass the same repository commands used by CI: dependency-state verification, deterministic manifest verification, strict test discovery, PHP quality, frontend lint, fallback security assertions, authoritative PHPUnit, WordPress integration contracts, Chromium and critical cross-browser suites, Linux visual regression, mobile and desktop Lighthouse, and supply-chain checks. Missing lockfiles, undiscovered suites, skipped critical tests, stale approvals, or private REST exposure are blocking.

The generated evidence bundle records `PASS`, `FAIL`, or `UNAVAILABLE`; unavailable infrastructure is never represented as a pass. Branch protection, private staging, backup/restore, external mail/security controls, manual accessibility, and human editorial/medical/legal approval require separate verified evidence.
