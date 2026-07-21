# Pre-v2 Backup Artifacts

**Note:** The files previously documented here (`database-2026-07-18.sql` and `wp-content-2026-07-18.tgz`) have been removed from version control because they contained a captured terminal transcript that leaked the `longevity_app` MySQL password in plaintext.

## Current Backup Policy

Database dumps, file archives, and other backup artifacts **must never be committed to this repository**. They belong in secure, off-site storage managed by the hosting/operations team.

- **Production backups:** Stored in [hosting provider]'s automated backup system / an off-site encrypted bucket — see `docs/operations/backup-and-restore.md`.
- **Local CI/test environments:** Use `make bootstrap` to spin up a fresh WordPress installation from scratch. No backup restore needed for disposable environments.
- **Pre-deployment snapshot:** Use `docs/operations/backup-and-restore.md` for the documented manual backup procedure (which uses encrypted, temporary files outside the repo).

## Why This Matters

- Version control is not a backup system — it's a collaboration and history tool.
- Committed credentials (even in terminal transcripts) persist in git history and can be found by anyone with access to the repository, now or in the future.
- The correct response to "I need a backup of the current state" is documented in `docs/operations/backup-and-restore.md`, not `git add`.
