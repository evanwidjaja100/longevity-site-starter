# VPS Deployment

Docker Compose in this repository is a reference starting point, not a complete production control plane. Terminate TLS at a maintained reverse proxy, restrict database exposure, use immutable images, external secrets, daily encrypted off-site backups, central logs, monitoring, automatic security updates with staged testing, and least-privilege SSH access.

Set production-safe WordPress constants, disable the built-in file editor and dashboard modifications, use a real cron runner, constrain outbound email, and protect admin/login endpoints. Test restore, rollback, and incident procedures before launch.
## Production Readiness v2 operational checks

After deployment, run migrations, verify the packaged scoring model, establish a reliable system cron heartbeat, confirm freshness cycles complete beyond one batch, and query protected readiness. Supply environment/integration evidence for last backup and last restore drill. Public `/health` confirms only process liveness and is not a deployment-readiness attestation.
