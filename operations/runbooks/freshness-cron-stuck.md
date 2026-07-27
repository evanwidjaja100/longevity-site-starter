# Runbook: Freshness Cron Stuck

## Trigger

- `System_Readiness` report shows `freshness: degraded` (Freshness has never completed successfully, or cycle recency exceeds 48 hours).
- `System_Readiness` report shows `cron_heartbeat: degraded` (No cron heartbeat or stale).
- `wp option get lel_freshness_last_error` returns a non-empty error code.
- `wp option get lel_cron_heartbeat_at` is older than 2 days.

## Impact

- Stale content review dates are not detected.
- Overdue articles may remain published without flagging.
- Editorial oversight of evidence cutoffs degrades over time.

## Diagnosis

1. Check the freshness lock status:
   ```bash
   wp option get lel_freshness_lock
   ```
   If set, a previous run may have crashed without releasing the lock.
2. Check the last error:
   ```bash
   wp option get lel_freshness_last_error
   ```
3. Check the cron heartbeat:
   ```bash
   wp option get lel_cron_heartbeat_at
   ```
4. Verify WP-Cron is firing:
   ```bash
   wp cron event list --format=table | grep lel_freshness
   ```
5. Check if real cron is configured (recommended for production):
   ```bash
   crontab -l | grep wp-cron
   ```

## Remediation

1. **If a stale lock is held:**
   ```bash
   wp option delete lel_freshness_lock
   ```
2. **If WP-Cron is not firing:**
   - Ensure the `wpcron` service is running (compose profile `cron`):
     ```bash
     docker compose --profile cron up -d wpcron
     ```
   - Or configure a system cron entry:
     ```bash
     */5 * * * * curl -s https://longevityevidencelab.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
     ```
3. **Manually trigger a freshness cycle:**
   ```bash
   wp longevity freshness --report
   ```
4. **If the cycle fails repeatedly:**
   - Inspect the error code in `lel_freshness_last_error`.
   - Check the structured log for `freshness_cycle_failed` events.
   - Run the cycle with `WP_DEBUG_LOG=true` to capture detailed errors.

## Verification

- `wp option get lel_cron_heartbeat_at` updates within 5 minutes.
- `wp option get lel_freshness_last_error` is empty (or was cleared after a successful run).
- `System_Readiness` report shows `freshness: ok`.

## Post-incident

- Record the incident in `operations/incident-response/<date>-freshness-cron-stuck.md`.
- If the lock mechanism is unreliable, investigate the root cause of the crash.
