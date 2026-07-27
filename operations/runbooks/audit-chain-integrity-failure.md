# Runbook: Audit Chain Integrity Failure

## Trigger

- `System_Readiness` report shows `audit_table: blocked` (Governance audit table missing).
- `System_Readiness` report shows `audit_write_failures: degraded` (audit write failures recorded).
- `lel_audit_write_failures_total` Prometheus metric is greater than 0.
- Alert `LongevityAuditWriteFailures` fires in Prometheus.
- `verify_chain()` returns `valid: false` with hash mutation, gap, or predecessor mismatch errors.

## Impact

- Mandatory audit events (approval_completed, etc.) may be failing, causing approvals to roll back fail-closed.
- The append-only audit trail may have gaps or mutations, compromising governance traceability.
- If the chain is forked or mutated, compliance evidence is compromised.

## Diagnosis

1. Check the audit write failure count:
   ```bash
   wp option get lel_audit_write_failures
   ```
2. Run the audit chain verification:
   ```bash
   wp eval 'echo wp_json_encode(\Longevity\Core\Audit_Log::verify_chain());'
   ```
3. Check if the audit table exists:
   ```bash
   wp eval 'echo (int) \Longevity\Core\Audit_Log::exists();'
   ```
4. Check the MySQL error log for deadlock or lock wait timeout errors.
5. Check if the `previous_event_hash` unique constraint is present:
   ```bash
   wp eval '
   $t = \Longevity\Core\Audit_Log::table_name();
   global $wpdb;
   echo $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s", $t, "previous_event_hash"));
   '
   ```

## Remediation

1. **If the table is missing:**
   ```bash
   wp longevity migrate
   ```
   This will run the schema installation which creates the audit tables.
2. **If the unique constraint is missing:**
   ```bash
   wp eval 'echo (int) \Longevity\Core\Audit_Log::ensure_fork_constraint();'
   ```
3. **If the chain has gaps or mutations:**
   - Do NOT attempt to repair the chain manually.
   - Identify the affected sequence range from the `verify_chain()` errors.
   - Document the corruption for compliance review.
   - If the corruption is in recent events, consider rolling back to the last known-good backup and re-applying events.
4. **If audit writes are failing due to deadlocks:**
   - Check for long-running transactions holding locks.
   - Review the `attempt_write` retry logic (retries up to 3 times with jitter).
   - Ensure `attempt_write` is not called inside an outer transaction (nested `START TRANSACTION` implicitly commits in MySQL).

## Verification

- `wp eval 'echo wp_json_encode(\Longevity\Core\Audit_Log::verify_chain());'` returns `{"valid":true,...}`.
- `wp option get lel_audit_write_failures` returns `0` (or was reset after resolution).
- `System_Readiness` report shows `audit_table: ok` and `audit_write_failures: ok`.
- New approval events are recorded successfully.

## Post-incident

- Record the incident in `operations/incident-response/<date>-audit-chain-integrity.md`.
- Include the affected sequence range, error types, and root cause.
- Schedule a backup restore drill if data was restored.
