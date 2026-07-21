# Monitoring

## Application health endpoint

The longevity-core MU plugin exposes a public health endpoint:

```
GET /wp-json/longevity/v1/health
```

Response (200 OK):
```json
{ "status": "ok", "version": "3.0.0" }
```

This endpoint requires no authentication and exposes only non-sensitive service state. Use it for external uptime monitoring.

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
| Repeated publication overrides | Audit log `_longevity_audit_log` meta query |
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
