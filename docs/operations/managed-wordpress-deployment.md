# Managed WordPress Deployment

**Owner:** Operations
**Last reviewed:** 2026-07-28

Confirm that the host supports MU plugins, custom post types, WP-CLI or an equivalent migration path, cron, HTTPS, backups, and required PHP extensions. Upload the first-party theme and complete `wp-content/mu-plugins/longevity-core.php` plus its directory. Do not install development dependencies in the public web root.

Use host-provided caching, WAF, malware scanning, MFA, backups, and staging where reliable. Configure SMTP and DNS authentication externally. Verify that host security plugins do not block REST readiness responses, custom capabilities, cron freshness checks, or private operational post types.
## Production Readiness v2 package requirements

Deploy the complete `wp-content/mu-plugins/longevity-core/` directory, including `config/scoring/default-review-model.json`. Run additive migrations before accepting editorial writes. Verify the protected readiness endpoint as an authorized operator. Treat backup, restore, SMTP, WAF, cron, and branch-protection checks as external evidence; do not mark them green from application configuration alone.

## Artifact-based delivery

All staging and production deployments use the verified release artifact produced by `scripts/build-release-artifact.sh` (also built by the `release-artifact` CI job). Before upload, run `scripts/verify-release-artifact.sh` with the expected full commit SHA and verify the sidecar checksum. The verifier checks the embedded source identity and per-file manifest, path allowlist, links, secrets/development paths, and normalized modes. Unpack only `wp-content/mu-plugins/longevity-core.php`, `wp-content/mu-plugins/longevity-core/`, and `wp-content/themes/longevity-starter/` into the host's `wp-content` tree; the two `RELEASE-*` files are evidence, not WordPress runtime files. Production must receive the exact checksum previously verified on staging. Keep the previous verified artifact available as the immediate code rollback target.

Set `LEL_RELEASE_SHA` and `LEL_RELEASE_ARTIFACT_SHA256` in host-managed `wp-config.php` from that verified release-info file. The protected acceptance command fails until those values exactly match an intact `release-artifact` evidence record; placeholders, a different environment, and evidence for another artifact are blocking.

The managed host is the only supported production target. Docker, Compose, and the local stack are for development and disposable CI only.

## Exact operator handoff

1. Record the full source commit, release tarball SHA-256, evidence-index SHA-256, and successful GitHub provenance attestation.
2. Verify the tarball locally with `bash scripts/verify-release-artifact.sh <artifact.tar.gz> <full-source-sha>`; upload that exact file to the host's private staging deployment channel.
3. Configure `LEL_RELEASE_SHA` and `LEL_RELEASE_ARTIFACT_SHA256`, then run `wp longevity migrate` as an authorized operator.
4. Run `wp longevity acceptance --source-sha=<full-source-sha> --artifact-checksum=<sha256> --format=json` and the authenticated smoke/accessibility checks. Any nonzero result blocks promotion.
5. After named technical and editorial approval, promote the already-tested bytes through the host control plane; do not rebuild or unpack from a workstation copy.
6. Repeat acceptance and monitoring checks in production. Roll back code by promoting the previous verified artifact checksum. Database restoration is a separate, human-approved incident procedure because migrations are additive.
