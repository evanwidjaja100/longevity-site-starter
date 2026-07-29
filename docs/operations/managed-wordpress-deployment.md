# Managed WordPress Deployment

**Owner:** Operations
**Last reviewed:** 2026-07-28

Confirm that the host supports MU plugins, custom post types, WP-CLI or an equivalent migration path, cron, HTTPS, backups, and required PHP extensions. Upload the first-party theme and complete `wp-content/mu-plugins/longevity-core.php` plus its directory. Do not install development dependencies in the public web root. The application connects to the database only as a least-privilege user; managed staging/production never carry a database root credential (see `docs/operations/database-privileges.md` for the exact grant set and the operator-only elevated-credential procedure).

Use host-provided caching, WAF, malware scanning, MFA, backups, and staging where reliable. Configure SMTP and DNS authentication externally. Verify that host security plugins do not block REST readiness responses, custom capabilities, cron freshness checks, or private operational post types.

## Platform baseline

| Component | Minimum | Enforced by |
|---|---|---|
| WordPress core | 7.0.2 (latest reviewed security release; verify against official WordPress sources at deployment time) | `Platform_Requirements::MIN_WORDPRESS`, `wp longevity preflight`, readiness |
| PHP | 8.3 | `Platform_Requirements::MIN_PHP`, `composer.json`, CI |
| MySQL | 8.0 (Oracle MySQL; MariaDB is not qualified) | `Platform_Requirements::MIN_MYSQL` |

Preflight and readiness fail closed below these versions. Do not launch or keep serving production traffic on an unsupported baseline.

Managed hosts typically apply WordPress core security updates independently of the application artifact. Confirm with the provider whether minor core security releases are auto-applied; if they are not, the operations owner runs the emergency procedure below.

### Emergency core security update procedure

1. On a WordPress core security release, the operations owner confirms the patched version from official WordPress sources.
2. Apply the core update on the managed host (control plane or host support), staging first when time permits; for actively exploited issues the host may patch production directly.
3. Run `wp longevity preflight` and the protected readiness endpoint; both must pass on the patched version.
4. Run authenticated smoke checks (admin login, publish-gate evaluation on a draft, contact form, rankings render).
5. Record version, date, operator, and evidence location in the operations log.
6. Update `Platform_Requirements::MIN_WORDPRESS`, documentation, and local image pins in the next application release so the enforced floor tracks the patched version.

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
