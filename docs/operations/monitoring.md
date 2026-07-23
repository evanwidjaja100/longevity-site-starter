# Monitoring

## Application health endpoint (liveness)

The longevity-core MU plugin exposes a public **liveness** endpoint:

```
GET /wp-json/longevity/v1/health
```

Response (200 OK):
```json
{ "status": "ok" }
```

This endpoint requires no authentication and exposes only non-sensitive service state. It confirms the application process is running and can serve HTTP. Use it for external uptime monitoring and load-balancer health checks.

**Note:** This is a liveness check only. For full operational readiness (database, migrations, queue, audit), use the protected `longevity/v1/system-readiness` endpoint (requires `approve_publication` + `view_operational_readiness` capabilities).

Expected check (every 5 minutes, external):
```bash
curl -sf https://longevityevidencelab.com/wp-json/longevity/v1/health | grep -q '"status":"ok"'
```

## Operational status page

A capability-protected admin page at `Tools → Longevity status` (`/wp-admin/tools.php?page=lel-operational-status`) displays:

- Last freshness audit timestamp and report summary
- Next scheduled freshness run
- Current freshness lock status (locked = job running)
- Last error message, if any

Accessible to users with `approve_publication` capability.

## Freshness audit monitoring

The daily cron job (`Freshness::run()`) audits articles for:

- Overdue `next_review_date`
- Missing or stale `evidence_cutoff_date`
- Content_summary, limitations, or original_contributions needing refresh

Results are stored in the `lel_last_freshness_report` option and reported by the status page. An operationally useful alert should be configured:

```bash
# Example: check if last freshness report indicates failures
wp option get lel_freshness_last_error
```

If the cron fails, the error message is logged via `error_log()` and stored in the `lel_freshness_last_error` option.

## Error log monitoring

In development, `WP_DEBUG_LOG` outputs to `wp-content/debug.log`. In production, configure a centralized log aggregator or use the managed host's log stream.

Key log prefixes to watch:
- `Longevity Core`: all service-level operations
- `Longevity Core freshness job failed:`: freshness cron errors
- `Longevity Core publication gate override`: override events (expected to be rare)
- `PHP Warning`: plugin/theme compatibility warnings

## Editorial monitoring (weekly review)

| Check | Tool / Query |
|---|---|
| Overdue reviews | `wp longevity freshness --report` |
| Articles approaching next_review_date | Freshness audit report |
| Unresolved corrections | `wp post list --post_type=lel_correction --post_status=pending` |
| Unapproved affiliate relationships | `wp post list --post_type=lel_affiliate --post_status=draft` |
| Missing testing evidence | `wp longevity readiness <post_id>` |
| Repeated publication overrides | Audit log custom table: `SELECT * FROM wp_lel_audit_events WHERE event_type = 'publication_gate_override'` |
| Stale evidence cutoffs | Freshness audit `_lel_freshness_due_fields` meta |

## Uptime and infrastructure

For managed WordPress hosting, the host typically provides:
- Uptime monitoring (external probe every 5 minutes)
- Load and database health dashboards
- CDN cache hit ratio
- TLS certificate auto-renewal and expiry alerts

For self-hosted VPS, configure:
- Prometheus + node_exporter + Blackbox exporter
- Grafana dashboards for request rate, latency, error rate
- Alertmanager for pager notifications on P0/P1 conditions

## Backup monitoring

See `docs/operations/backup-and-restore.md` for backup verification steps.

After each backup run, verify:
1. Backup file exists and size is non-zero
2. SHA256 checksum matches recorded value
3. Database dump opens cleanly (`mysql < dump.sql` in isolated env)
4. File archive extracts without errors

## Related

- `docs/operations/freshness-register.csv` — freshness audit output
- `docs/operations/backup-and-restore.md` — backup verification
- `docs/operations/incident-response.md` — escalation paths
- Script: `wp longevity freshness --report` — CLI freshness check
## Protected readiness and freshness metrics

Use the authenticated `longevity/v1/system-readiness` endpoint for database, migration, packaged scoring configuration, freshness heartbeat/cycle, cron heartbeat, audit table, approval table, publication lock, invalidation queue, uploads, and contact-mail configuration checks. Backup timestamp, restore drill, and external mail delivery use structured JSON evidence (set via `wp longevity evidence set --type=<type> --result=ok ...`) and remain `unknown_external` until an operator supplies valid evidence.

Freshness monitoring records `last_run_at`, `last_success_at`, cycle start/completion, processed count, eligible total, due total, remaining estimate, lock age, and last error code. Alert on stale heartbeat, expired lock, or a cycle that does not eventually complete.

## CSP violation monitoring

When Content-Security-Policy is in Report-Only or Enforce mode, browser violation reports are sent to:

```
POST /wp-json/longevity/v1/csp-report
```

Violations are logged via `error_log()` with prefix `[longevity-csp]` and counted in the `lel_csp_violation_count` option. Monitor this counter in staging to resolve violations before enabling enforcement in production.
