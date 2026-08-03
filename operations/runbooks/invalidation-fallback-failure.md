# Runbook: Invalidation fallback failures

**Owner:** Operations
**Last reviewed:** 2026-07-30

## Trigger

- Alert `LongevityInvalidationFallbackFailures`:
  `increase(lel_invalidation_fallback_failures_total[15m]) > 0`.
- The application attempted a synchronous cache/ranking invalidation because
  the queued path failed, and that fallback also failed.

## Impact

- Approval, correction, or disclosure changes may not be reflected in public
  rankings or cached projections until invalidation succeeds.
- Governance state itself is not corrupted; this is a propagation failure.

## Diagnose

1. Confirm the raw counter and when it last moved:

   ```bash
   docker compose run --rm wpcli wp option get lel_invalidation_fallback_failures --allow-root
   ```

2. Verify the invalidation queue table exists (missing table is the most
   common cause; it means migrations have not run on this environment):

   ```bash
   docker compose run --rm wpcli wp db query "SHOW TABLES LIKE '%lel_invalidation_queue%'" --allow-root
   ```

3. Check migration state and system readiness:

   ```bash
   docker compose run --rm wpcli wp longevity preflight --allow-root
   docker compose run --rm wpcli wp option get lel_data_version --allow-root
   ```

## Recover

1. If the queue table is missing, run migrations as an administrator:

   ```bash
   docker compose run --rm wpcli wp longevity migrate --user=<admin> --allow-root
   ```

2. Re-run the failed invalidation by re-saving the affected parent record or
   waiting for the next queue cycle; confirm queue depth drains:

   ```bash
   docker compose run --rm wpcli wp db query "SELECT status, COUNT(*) FROM $(docker compose run --rm wpcli wp db prefix --allow-root)lel_invalidation_queue GROUP BY status" --allow-root
   ```

3. Confirm the alert resolves after the next scrape window with no new
   `increase()` in the counter.

## Escalate

- If migrations succeed but fallback failures continue, treat as a database
  incident and follow `operations/runbooks/database-unavailable.md`.
- If public content shows stale rankings after an approval was invalidated,
  escalate to the editorial lead: stale public governance state is a
  launch-blocking condition.
