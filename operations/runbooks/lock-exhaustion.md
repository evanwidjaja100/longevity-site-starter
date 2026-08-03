# Runbook: Lock Exhaustion

## Trigger

- `System_Readiness` report shows `publication_lock: blocked` (GET_LOCK is not supported on this database server).
- `System_Readiness` report shows `publication_lock: degraded` (publication lock failures recorded).
- `lel_publication_lock_failures_total` Prometheus metric is greater than 0.
- Alert `LongevityPublicationLockExhaustion` fires in Prometheus.
- Approval or invalidation operations are timing out or failing.

## Impact

- Approvals may fail because the publication lock cannot be acquired.
- Invalidation jobs may be enqueued for retry instead of executing.
- Concurrent edits to the same post may cause contention.

## Diagnosis

1. Check the lock failure count:
   ```bash
   wp option get lel_publication_lock_failures
   ```
2. Check if GET_LOCK is supported:
   ```bash
   wp eval 'echo (int) \Longevity\Core\Publication_Lock::get_lock_supported();'
   ```
3. Check for long-held locks:
   ```bash
   wp eval '
   global $wpdb;
   $wpdb->query("SELECT * FROM performance_schema.data_locks WHERE ENGINE = \"InnoDB\" AND OBJECT_NAME LIKE \"%lel%\" LIMIT 20");
   '
   ```
4. Check the invalidation queue depth:
   ```bash
   wp eval 'echo wp_json_encode(\Longevity\Core\Invalidation_Queue::stats());'
   ```
5. Check MySQL `performance_schema` for lock wait timeouts.

## Remediation

1. **If GET_LOCK is not supported (e.g., Galera Cluster):**
   - This is a database configuration issue. GET_LOCK requires a single-writer connection.
   - Consider using a different locking mechanism (e.g., advisory locks via a dedicated table row).
   - As a temporary workaround, the `Publication_Lock` class falls back to a best-effort lock.
2. **If locks are contended (high throughput):**
   - Check for long-running transactions that hold locks.
   - Review the `Publication_Lock::acquire` retry logic (retries with backoff).
   - Consider reducing the scope of transactions that hold locks.
3. **If the invalidation queue is backing up:**
   - Ensure the cron processor is running:
     ```bash
     docker compose --profile cron up -d wpcron
     ```
   - Manually process the queue:
     ```bash
     wp eval '\Longevity\Core\Invalidation_Queue::process_batch();'
     ```
4. **If locks are stale (process crashed without releasing):**
   - Locks acquired via `GET_LOCK` are automatically released when the connection closes.
   - If using a table-based lock, manually clear stale lock rows.

## Verification

- `wp option get lel_publication_lock_failures` returns `0` (or was reset after resolution).
- `System_Readiness` report shows `publication_lock: ok`.
- New approvals succeed without lock errors.
- Invalidation queue depth returns to 0.

## Post-incident

- Record the incident in `operations/incident-response/<date>-lock-exhaustion.md`.
- Include the root cause (GET_LOCK unsupported, contention, or stale locks).
- If the database configuration is the root cause, update the deployment documentation.
