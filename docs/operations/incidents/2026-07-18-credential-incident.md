# Incident 2026-07-18 — Committed Database Credential (P1)

**Owner:** Security and editorial operations
**Incident lead:** evanwidjaja100 (repository owner)
**Status:** Contained — rotation (IR-01) and history purge (IR-02) complete; closure review (IR-03) open
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
long-lived volumes had masked; both are fixed in commit `4f01281`:

1. `scripts/bootstrap.sh` never ran `wp longevity migrate`, so audit tables
   and role capabilities were absent on a new database and trust-page seeding
   failed closed.
2. Migrations 16–18 asserted the `contact_idempotency` table contract although
   that table is only created by migration 19.

## IR-02 — History purge (completed 2026-07-31)

The three secret-bearing paths were removed from all Git history with
`git-filter-repo` and the rewrite was published.

| Action | Detail | Verification |
|---|---|---|
| Backed up pre-rewrite history | `git clone --mirror` to `D:\Desktop\test\longevity-backup-mirror.git` (local only; still contains the old secrets — delete once satisfied) | Mirror clone succeeded |
| Purged paths | `.env.ci`, `docs/testing/artifacts/pre-v2-backup/database-2026-07-18.sql`, `docs/testing/artifacts/pre-v2-backup/wp-content-2026-07-18.tgz` across all 97 commits and all refs | `git rev-list --all --objects` shows no secret blobs; repo `fsck` clean; manifest valid |
| Published rewrite | Force-pushed the 5 first-party branches; the 10 stale `dependabot/*` branches are no longer present on the remote (their PRs were invalidated by the base rewrite) | Remote lists only the 5 first-party branches |
| Confirmed remote clean | Fresh `git clone` from GitHub + full-ref scan | No secret paths and no secret blobs in any remote ref |

Residual note: GitHub retains unreachable objects for a background window
before garbage collection, and any pre-existing fork or cached view may still
reference old commits. Because every affected credential was already rotated
(IR-01), the residual objects carry no usable secret. GitHub Support can be
asked to expedite GC if required.

## Open follow-ups

- **IR-03 — Closure:** post-mortem, prevention actions (push protection,
  scheduled full-history scans), and incident sign-off.

This record is an engineering log, not gate sign-off. The production launch
decision remains **NO-GO**.
