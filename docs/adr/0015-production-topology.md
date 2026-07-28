# ADR-0015: Production Deployment Topology

**Status:** Accepted
**Date:** 2026-07-23
**Deciders:** Engineering, Operations

## Context

The production-readiness audit (PR-006) identified that no documented, reproducible production deployment topology exists. The development environment uses Docker Compose with a local MySQL container, but production requires an immutable, hardened deployment with secrets injection, health probes, and rollback capability.

## Decision

We support one production topology:

### Managed WordPress Host

- WordPress managed hosting (e.g., Cloudways, Kinsta, WP Engine)
- MU plugin and theme deployed via Git-based deployment or SFTP artifact push
- Database managed by host; migrations run via SSH + WP-CLI before traffic promotion
- Secrets managed via host control panel environment variables
- CDN/WAF handles TLS termination, rate limiting, and static caching
- External cron via the managed host scheduler calling `wp cron event run --due-now`

## Deployment Sequence

1. Build the deterministic runtime tarball from the exact release commit
2. Push artifact to registry/storage
3. Run `wp longevity migrate` against target database (with lock protection)
4. Activate the uploaded artifact through the managed host's staging/promotion mechanism
5. Verify health endpoint returns `{"status":"ok"}`
6. Promote traffic
7. Monitor error rates for 15 minutes

## Rollback Procedure

1. Redeploy previous immutable artifact
2. Verify health endpoint
3. Promote traffic to previous version
4. Database remains forward-compatible (all migrations are additive)
5. Record incident if rollback was due to failure

## Secrets Injection

- **Never** put secrets in the release artifact or commit them to the repository
- Use environment variables at runtime: `WORDPRESS_DB_PASSWORD`, `AUTH_KEY`, `AUTH_SALT`, etc.
- In local Docker development: use the gitignored `.env` file only with non-production secrets
- In managed hosts: use host-provided environment variable configuration
- Rotate credentials per the security checklist

## Consequences

- All deployments are reproducible from artifact + secrets
- Rollback is safe because migrations are additive-only
- `GET_LOCK` usage must be verified on managed hosts (some restrict it)
- CSP enforcement mode is environment-configurable (`LEL_CSP_ENFORCE`)
- Rate limiting at the application layer is defense-in-depth; primary rate limiting belongs at CDN/WAF
