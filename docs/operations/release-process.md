# Release Process

1. Open a focused change with documentation and tests.
2. Run static validation, unit tests, dependency checks, and Docker configuration validation.
3. Deploy to staging and run bootstrap only for a new installation.
4. Execute smoke, permission, publication-gate, schema, accessibility, and responsive checks.
5. Create and verify a backup; identify rollback commit and database plan.
6. Obtain technical and editorial approval.
7. Deploy code, run required migrations, clear caches, and repeat smoke checks.
8. Observe logs and health metrics; record release and any follow-up.

Do not combine unrelated schema, workflow, theme, and infrastructure changes in one emergency release.
## Production Readiness v2 release gates

A release candidate must pass the same repository commands used by CI: dependency-state verification, deterministic manifest verification, strict test discovery, PHP quality, frontend lint, fallback security assertions, authoritative PHPUnit, WordPress integration contracts, Chromium and critical cross-browser suites, Linux visual regression, mobile and desktop Lighthouse, and supply-chain checks. Missing lockfiles, undiscovered suites, skipped critical tests, stale approvals, or private REST exposure are blocking.

The generated evidence bundle records `PASS`, `FAIL`, or `UNAVAILABLE`; unavailable infrastructure is never represented as a pass. Branch protection, private staging, backup/restore, external mail/security controls, manual accessibility, and human editorial/medical/legal approval require separate verified evidence.
