# Runbook: Database Unavailable

## Trigger

- Health endpoint `GET /wp-json/longevity/v1/health` returns 500 or times out.
- `System_Readiness` report shows `database: blocked` (Database connectivity).
- Application logs show `Error establishing a database connection`.
- MySQL container healthcheck is failing.

## Impact

- The site cannot serve any dynamic content.
- Editorial workflows (save, approve, publish) are blocked.
- The audit chain cannot accept new events.

## Diagnosis

1. Check the database container health (local/CI Docker stack only — managed
   production carries no DB root credential; use the host control plane and
   provider diagnostics instead):
   ```bash
   docker compose ps
   docker compose exec db mysqladmin ping -h 127.0.0.1 -u root -p"$WORDPRESS_DB_ROOT_PASSWORD"
   ```
2. Check MySQL error log:
   ```bash
   docker compose logs db --tail=100
   ```
3. Check available disk space on the DB volume:
   ```bash
   df -h
   docker system df
   ```
4. Verify the `WORDPRESS_DB_HOST` environment variable matches the service name.

## Remediation

1. **If the container crashed or was OOM-killed:**
   ```bash
   docker compose up -d db
   ```
2. **If the database is corrupted:**
    - Restore from the most recent provider-managed DB backup via the host control panel.
    - Coordinate with the database owner and follow the provider's documented restore procedure.
3. **If the volume is damaged:**
   - Restore the `db_data` volume from a known-good snapshot.
4. **If disk is full:**
   - Remove old Docker images and stopped containers: `docker system prune -af`
   - Extend the volume or migrate to a larger disk.

## Verification

- `docker compose exec db mysqladmin ping` returns `mysqld is alive`.
- `curl -sf http://localhost:8080/wp-json/longevity/v1/health` returns `{"status":"ok"}`.
- `wp option get lel_data_version` returns the expected version.

## Post-incident

- Record the incident in `operations/incident-response/<date>-database-unavailable.md`.
- Schedule a backup restore drill if the restore path was used.
