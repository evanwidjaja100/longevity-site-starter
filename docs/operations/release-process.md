# Release Process

**Owner:** Release engineering
**Last reviewed:** 2026-07-28

The canonical repository builder is the `release-artifact` job on GitHub's `ubuntu-24.04` runner label. Actions and the Playwright container are SHA/digest pinned where the platform supports it; exact tool versions plus per-job action and container identities are retained in evidence metadata. The hosted runner image is provider-controlled, not repository-pinned: confirming its image/version and accepting that provider trust remains a human release-review gap. Push runs attest the evidence index and bundle checksum through GitHub artifact attestations; pull requests validate the same evidence contract but are not release provenance.

1. Open a focused change with documentation and tests.
2. Run static validation, unit tests, dependency checks, and Docker configuration validation.
3. Build the release artifact from the candidate SHA: `bash scripts/build-release-artifact.sh <sha>`. The builder reads only that commit through Git and derives `SOURCE_DATE_EPOCH` only from its commit timestamp; a conflicting override is rejected. Verify it with `bash scripts/verify-release-artifact.sh build/release/longevity-release-<full-sha>.tar.gz <full-sha>` and run `bash scripts/verify-release-reproducibility.sh <full-sha>`. Release evidence freshly opens and verifies the real archive, then cross-checks its SHA-256 sidecar, artifact-verification report, and two-build reproducibility report. The archive contains only first-party runtime files plus embedded `RELEASE-INFO.txt` and `RELEASE-MANIFEST.sha256`.
4. Deploy that exact artifact to managed staging and run bootstrap only for a new installation. Never rebuild separately for staging and production.
5. Execute smoke, permission, publication-gate, schema, accessibility, and responsive checks against staging.
6. Create and verify a provider backup; identify the previous verified artifact checksum as the rollback target and record the database plan.
7. Obtain technical and editorial approval. Production promotion requires manual approval by the technical release owner.
8. Promote the same artifact checksum to production, run required additive migrations once, clear caches, and repeat smoke checks.
9. Observe logs, error monitoring, cron, queue, and contact-delivery signals; record the release and any follow-up.

Rollback redeploys the previous verified artifact; additive database migrations stay in place. Restore the database only for a separately declared data incident under maintenance mode with human approval.

Do not combine unrelated schema, workflow, theme, and infrastructure changes in one emergency release.
## Production Readiness v2 release gates

A release candidate must pass the same repository commands used by CI: dependency-state verification, deterministic manifest verification, strict test discovery, PHP quality, frontend lint, fallback security assertions, authoritative PHPUnit, WordPress integration contracts, Chromium, required axe accessibility and critical cross-browser suites, mobile and desktop Lighthouse, and supply-chain checks. Linux visual regression becomes required only after reviewed baselines from the pinned Playwright container are committed and `LEL_LINUX_VISUAL_BASELINES_READY` is enabled; until then its skipped status is not evidence of a pass. Missing lockfiles, undiscovered suites, skipped critical tests, stale approvals, or private REST exposure are blocking.

The generated evidence bundle records `PASS`, `FAIL`, or `UNAVAILABLE`; unavailable infrastructure is never represented as a pass. Branch protection, private staging, backup/restore, external mail/security controls, manual accessibility, and human editorial/medical/legal approval require separate verified evidence.
