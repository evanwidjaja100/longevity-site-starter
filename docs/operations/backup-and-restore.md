# Backup and Restore

## Backup strategy

| Data | Frequency | Retention | Method |
|---|---|---|---|
| Database (MySQL) | Daily | 30 days | `wp db export` via cron |
| `wp-content/uploads/` | Weekly | 90 days | Archive + off-site copy |
| `wp-content/` (code) | On each deploy | Git history | Version control + release artifacts |
| Full site (DB + files) | Weekly | 90 days | Combined archive + encrypted off-site copy |
| MU plugin + theme | On each commit | Git history | `main` + `improvement/reader-experience-v3` branches |

## Reference backup script

A development-reference backup script is at `scripts/backup-example.sh`:

```bash
# Creates a timestamped database export + wp-content archive
# in ./build/backups/ by default
./scripts/backup-example.sh [destination-directory]
```

This script is **not a production backup service**. It demonstrates:
- Database export via WP-CLI (`wp db export`)
- Files archive via `tar`
- SHA256 checksum recording
- Read-mode restriction on backup files (`chmod 600`)

## Production backup requirements

For managed WordPress hosting, use the host's built-in backup system. For self-hosted deployment:

1. **Database**: Automated daily dump via `wp cron event run --due-now` or a system cronjob
2. **Files**: `wp-content/uploads/` — archive and ship off-site
3. **Encryption**: AES-256 encrypt before off-site transfer. Never store plaintext dumps outside the isolated backup directory
4. **Off-site storage**: At least one copy stored in a different geographic region (S3, Wasabi, Backblaze B2)
5. **Monitoring**: Check backup completion daily. Alert if no successful backup in 48 hours

```bash
# Example production-style backup (for reference — adapt to your orchestrator)
#!/bin/sh
set -eu
DEST="${BACKUP_DEST:-/backups}"
stamp=$(date -u +%Y%m%dT%H%M%SZ)
wp db export "/tmp/lel-$stamp.sql" --allow-root
tar -czf "/tmp/lel-wp-content-$stamp.tar.gz" wp-content
gpg --encrypt --recipient backup-key "/tmp/lel-$stamp.sql"
gpg --encrypt --recipient backup-key "/tmp/lel-wp-content-$stamp.tar.gz"
mv "/tmp/lel-$stamp.sql.gpg" "$DEST/"
mv "/tmp/lel-wp-content-$stamp.tar.gz.gpg" "$DEST/"
sha256sum "$DEST/lel-$stamp.sql.gpg" > "$DEST/lel-$stamp.sha256"
rm -f "/tmp/lel-$stamp.sql" "/tmp/lel-wp-content-$stamp.tar.gz"
```

## Restore process

A development-reference restore script is at `scripts/restore-test-example.sh`. It refuses to run in a production environment and requires explicit confirmation.

### Full restore steps for managed WordPress

1. Spin up a clean staging environment (same WP version, same plugin versions)
2. Upload and extract the files archive (`wp-content/`)
3. Import the database dump:
   ```bash
   wp db import /path/to/dump.sql --allow-root
   ```
4. Run any pending migrations:
   ```bash
   wp longevity migrate
   ```
5. Run the health check:
   ```bash
   curl -sf https://staging-url/wp-json/longevity/v1/health
   ```
6. Verify a representative sample:
   - Navigate to 3-5 public pages (home, about, a category archive, a review/article)
   - Confirm search returns expected results
   - Confirm corrections display for corrected posts
   - Log in as each role (writer, fact-checker, medical reviewer, managing editor)
   - Submit a test contact form submission
   - Confirm the readiness gate reports expected status on a draft

### Partial restore (files only)

If only theme/plugin files are corrupted, restore from version control:

```bash
cd /path/to/site
git checkout main -- wp-content/themes/longevity-starter/
git checkout main -- wp-content/mu-plugins/longevity-core/
```

## Recovery verification

At least quarterly, conduct a full restore drill:

1. Restore to an isolated staging environment
2. Verify all of:
   - [ ] Users exist with correct roles and capabilities
   - [ ] Private CPT records (claims, sources, protocols, test records, corrections) are intact
   - [ ] Media library entries resolve to uploaded files
   - [ ] Permalink structure matches production
   - [ ] Readiness gates report the same results as pre-backup
   - [ ] A representative article renders all blocks and components
3. Record the result and time-to-recover in the incident log
4. Report any data loss or process gaps

## Related

- `scripts/backup-example.sh` — dev-reference backup script
- `scripts/restore-test-example.sh` — dev-reference restore script
- `docs/operations/security-checklist.md` — backup sign-off checklist
- `docs/operations/managed-wordpress-deployment.md` — host-specific guidance
