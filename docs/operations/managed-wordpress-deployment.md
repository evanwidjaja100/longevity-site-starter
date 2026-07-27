# Managed WordPress Deployment

Confirm that the host supports MU plugins, custom post types, WP-CLI or an equivalent migration path, cron, HTTPS, backups, and required PHP extensions. Upload the first-party theme and complete `wp-content/mu-plugins/longevity-core.php` plus its directory. Do not install development dependencies in the public web root.

Use host-provided caching, WAF, malware scanning, MFA, backups, and staging where reliable. Configure SMTP and DNS authentication externally. Verify that host security plugins do not block REST readiness responses, custom capabilities, cron freshness checks, or private operational post types.
## Production Readiness v2 package requirements

Deploy the complete `wp-content/mu-plugins/longevity-core/` directory, including `config/scoring/default-review-model.json`. Run additive migrations before accepting editorial writes. Verify the protected readiness endpoint as an authorized operator. Treat backup, restore, SMTP, WAF, cron, and branch-protection checks as external evidence; do not mark them green from application configuration alone.

## Artifact-based delivery

All staging and production deployments use the verified release artifact produced by `scripts/build-release-artifact.sh` (also built by the `release-artifact` CI job). Before unpacking, verify the artifact checksum against its recorded `.sha256` file and confirm the source SHA in `release-info-<sha>.txt`. Unpack only `wp-content/mu-plugins/longevity-core.php`, `wp-content/mu-plugins/longevity-core/`, and `wp-content/themes/longevity-starter/` into the host's `wp-content` tree. Production must receive the exact checksum previously verified on staging. Keep the previous verified artifact available as the immediate code rollback target.

The managed host is the only supported production target. Docker, Compose, and the local stack are for development and disposable CI only.
