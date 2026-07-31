# Incident 2026-07-18 — Committed Database Credential (P1)

**Owner:** Security and editorial operations
**Incident lead:** evanwidjaja100 (repository owner)
**Status:** Contained — rotation complete (IR-01); history purge (IR-02) and closure review (IR-03) open
**Last reviewed:** 2026-07-31

## Summary

Database credential material (the `longevity_app` application credential and
related local environment values) was committed to Git history and remained
reachable in historical blobs. The project has only ever run on the owner's
local machine; no managed host, shared database, or third-party deployment has
ever used the exposed values.

## IR-01 — Containment and rotation (completed 2026-07-31)

All actions below were performed by the incident lead on 2026-07-31. No secret
values are recorded here or anywhere in Git.

| Action | Detail | Verification |
|---|---|---|
| Rotated `WORDPRESS_DB_PASSWORD` | New value generated with `openssl rand -base64 24`, stored only in the untracked local `.env` | `scripts/validate-env.sh .env` passes |
| Rotated `WORDPRESS_DB_ROOT_PASSWORD` | Same procedure | Same |
| Rotated `WP_ADMIN_PASSWORD` | Same procedure | Same |
| Destroyed all credential-bearing local state | Removed Docker volumes for the active project and all stale project sets (`longevity-site_*` including one orphaned cache-service volume, `longevity-a11y_*`, `longevity-pr-fresh_*`, `longevity-remediation-ci_*`), each of which contained a MySQL data directory initialized with the exposed password | `docker volume ls` shows no longevity volumes other than the freshly created set |
| Rebuilt the environment from scratch | `make up && make bootstrap` on an empty database with the rotated credentials | `make smoke` passes; full test suite green |
| Rotated GitHub account password | Owner attestation | — |
| Enabled GitHub two-factor authentication | Authenticator app; recovery codes stored outside the repository. Owner attestation | — |

### Collateral findings

Rebuilding from an empty database exposed two fresh-install defects that
long-lived volumes had masked; both are fixed in commit `1badd2e`:

1. `scripts/bootstrap.sh` never ran `wp longevity migrate`, so audit tables
   and role capabilities were absent on a new database and trust-page seeding
   failed closed.
2. Migrations 16–18 asserted the `contact_idempotency` table contract although
   that table is only created by migration 19.

## Open follow-ups

- **IR-02 — History purge:** historical secret-bearing blobs remain reachable
  in Git history until a `git filter-repo` rewrite is executed and
  force-pushed by the repository administrator, followed by full-history
  secret scans and re-clones. The rotated values render the leaked material
  unusable in the meantime.
- **IR-03 — Closure:** post-mortem, prevention actions (push protection,
  scheduled full-history scans), and incident sign-off.

This record is an engineering log, not gate sign-off. The production launch
decision remains **NO-GO**.
