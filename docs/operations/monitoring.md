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

If the cron fails, a structured `freshness_cycle_failed` event is logged (see below) and the error code is stored in the `lel_freshness_last_error` option.

## Structured log monitoring

In development, `WP_DEBUG_LOG` outputs to `wp-content/debug.log`. In production, configure a centralized log aggregator or use the managed host's log stream.

Service-level events are emitted by the `Logger` class as a single-line, machine-parseable record with the `[longevity]` prefix followed by a JSON object:

```
[longevity] {"ts":"2026-07-23T09:15:04+00:00","level":"error","event":"audit_write_failure","request_id":"a1b2...","context":{...}}
```

Each record carries `level` (`debug`/`info`/`warning`/`error`), a stable `event` name, and a `request_id` that correlates the log line with rows in the `wp_lel_audit_events` table (the audit log shares the same request id). Configure the aggregator to index on `event` and `request_id`.

Key `event` names to alert on:
- `audit_write_failure` (error): an append-only audit write failed; approvals/verifications roll back fail-closed.
- `migration_failed` (error): a schema migration aborted.
- `freshness_cycle_failed` (error): the freshness cron cycle threw; see `context.error_code`.
- `invalidation_job_failed` (error): a cache-invalidation job permanently failed after retries.
- `invalidation_fallback_failure` (warning): the synchronous fallback purge failed.
- `csp_violation` (warning): a Content-Security-Policy report was received.

To surface warnings/errors from the raw stream: `grep '"level":"error"' wp-content/debug.log` (or the equivalent aggregator query).

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

## Metrics and alerting

The plugin exposes Prometheus text-format (`0.0.4`) metrics through two collection modes:

- **Pull (REST):** `GET /wp-json/longevity/v1/metrics` — gated by the `view_operational_readiness` capability. Scrape with authenticated Prometheus (see `ops/monitoring/prometheus-scrape.yml`, `basic_auth`). Returns `Content-Type: text/plain; version=0.0.4`.
- **Push (textfile collector):** `wp longevity metrics --file=/var/lib/node_exporter/textfile/longevity.prom` — writes atomically (temp file + rename) for the node_exporter textfile collector. Requires `manage_options`. Run on a short cron interval. Omit `--file` to print to stdout.

Exposed series:

| Metric | Type | Meaning |
|---|---|---|
| `lel_audit_write_failures_total` | counter | Append-only audit write failures |
| `lel_csp_violations_total` | counter | CSP violation reports received |
| `lel_invalidation_fallback_failures_total` | counter | Synchronous fallback purge failures |
| `lel_publication_lock_failures_total` | counter | Publication lock acquisition failures |
| `lel_readiness_check{check,status}` | gauge | Per-check readiness (1 = active status) |
| `lel_readiness_overall` | gauge | Overall readiness: `1` ok, `0.5` degraded, `0` blocked |

Alert rules ship in `ops/monitoring/alert-rules.yml` (load into Prometheus). Each alert carries a runbook annotation pointing at an anchor in `docs/operations/incident-response.md`. To fan out firing alerts to a chat channel, point Alertmanager's webhook receiver at `scripts/alert-notify.sh` (reads Alertmanager JSON on stdin, forwards to `ALERT_WEBHOOK_URL`).

## Backup monitoring

See `docs/operations/backup-and-restore.md` for backup verification steps.

After each backup run, verify:
1. Backup file exists and size is non-zero
2. SHA256 checksum matches recorded value
3. Database dump opens cleanly (`mysql < dump.sql` in isolated env)
4. File archive extracts without errors

## Related

- `content/evidence/freshness-register.csv` — freshness audit output
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

Violations are logged as structured `csp_violation` warning events via the `Logger` class (see Structured log monitoring above) and counted in the `lel_csp_violation_count` option. Monitor this counter in staging to resolve violations before enabling enforcement in production.
