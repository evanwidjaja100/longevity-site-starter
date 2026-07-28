# Incident: Committed Database Dump and Credential Transcript (2026-07-18)

**Severity:** P1 (security/privacy)
**Status:** OPEN — containment incomplete, human/operator gates outstanding
**Incident lead:** TBD (must be a named human before this record can close)
**Prepared:** 2026-07-28 (evidence collection by implementation agent; no secrets reproduced here)

## Summary

A full WordPress database dump, a wp-content archive, and a README containing a
captured terminal transcript that leaked the `longevity_app` MySQL password in
plaintext were committed to the repository. The files were removed from the
tip three days later, but the blobs remain reachable in Git history on every
clone, mirror, fork, CI cache, and packed review file created since.

## Exposure scope (privacy-safe evidence — object IDs only)

| Item | Path | Blob (at cb56dc5) | Added | Removed from tip |
|---|---|---|---|---|
| Database dump | `docs/testing/artifacts/pre-v2-backup/database-2026-07-18.sql` | `e1c05162f364` | `cb56dc5` (2026-07-18) | `ec13d79` (2026-07-21) |
| wp-content archive | `docs/testing/artifacts/pre-v2-backup/wp-content-2026-07-18.tgz` | `a02ab5d42507` | `cb56dc5` (2026-07-18) | `ec13d79` (2026-07-21) |
| README with credential transcript | `docs/testing/artifacts/pre-v2-backup/README.md` | `1d834fd1de3b` | `cb56dc5` (2026-07-18) | Rewritten (sanitized) at `ec13d79`; historical blob still reachable |

Dump contents (table names only; no values copied here): all core WordPress
tables including `wp_users` and `wp_usermeta` (user accounts and password
hashes), `wp_options`, `wp_posts`, `wp_postmeta`, `wp_comments`. The dump must
be treated as containing personal information and unpublished editorial data
until the privacy/legal owner determines otherwise.

Credential exposed: the `longevity_app` MySQL account password (plaintext, in
the historical README transcript). The secret is NOT reproduced in this record
and must never be copied into any report, ticket, or audit payload.

## Operator checklist (human-owned; the agent must not mark these complete)

- [ ] **Revoke** the exposed `longevity_app` database account or its grants.
- [ ] **Issue** a new least-privilege application credential (see
      `docs/operations/managed-wordpress-deployment.md` for the approved grant
      set; no root/admin grants).
- [ ] **Update** every authorized environment (staging, production, CI
      secrets) with the new credential; confirm no environment still holds the
      old one.
- [ ] **Verify** the old credential no longer authenticates against any
      database host.
- [ ] **Review** database access logs covering 2026-07-18 through rotation
      date for unauthorized access.
- [ ] **Rewrite history** with an approved tool (`git filter-repo` or BFG) to
      drop blobs `e1c05162f364`, `a02ab5d42507`, and the pre-sanitization
      README blob `1d834fd1de3b`; obtain force-push approval for every
      protected branch first.
- [ ] **Remediate mirrors/caches**: maintained mirrors, forks under the
      organization's control, CI caches and previously uploaded artifacts,
      packed review files (Repomix or similar) built from pre-rewrite history.
- [ ] **Instruct collaborators** to re-clone after the rewrite.
- [ ] **Run full-history secret scanning** (e.g. trufflehog against all refs)
      on the rewritten repository; document any remaining findings as
      reviewed false positives.
- [ ] **Privacy/legal review**: determine whether the dump's `wp_users` /
      `wp_usermeta` / comment / contact contents trigger notification duties
      in the applicable jurisdictions; record the decision with owner and
      date.
- [ ] **Confirm** the final release candidate SHA is created only after
      rotation and history remediation.

## Rollback rule

Credential revocation must not be rolled back. If the replacement credential
causes an outage, issue another new credential; never restore the exposed one.

## Closure criteria

This incident may be closed only when every checklist item above carries a
named human owner, a date, and an evidence location, per
`docs/operations/incident-response.md`. Repository-side changes alone do not
close it.
