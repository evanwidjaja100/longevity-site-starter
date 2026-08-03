#!/bin/sh
# Cron wrapper with structured failure detection.
# Runs WP-Cron due events and tracks consecutive failures.
# Exits non-zero after FAILURE_THRESHOLD consecutive failures.
#
# Usage: docker compose --profile cron up wpcron
# Preferred production alternative: external one-shot cron invocation
# (systemd timer, K8s CronJob, or managed-host scheduler) calling
# `wp cron event run --due-now --allow-root` directly.

set -e

FAILURE_THRESHOLD="${LEL_CRON_FAILURE_THRESHOLD:-5}"
SLEEP_INTERVAL="${LEL_CRON_SLEEP_INTERVAL:-300}"
CONSECUTIVE_FAILURES=0

log() {
  printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1"
}

while true; do
  if wp cron event run --due-now --allow-root 2>&1; then
    # Success: reset failure counter and update heartbeat.
    CONSECUTIVE_FAILURES=0
    wp option update lel_cron_heartbeat_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" --allow-root 2>/dev/null || true
    log "Cron run completed successfully."
  else
    CONSECUTIVE_FAILURES=$((CONSECUTIVE_FAILURES + 1))
    log "ERROR: Cron run failed (consecutive failure ${CONSECUTIVE_FAILURES}/${FAILURE_THRESHOLD})."

    if [ "$CONSECUTIVE_FAILURES" -ge "$FAILURE_THRESHOLD" ]; then
      log "FATAL: ${FAILURE_THRESHOLD} consecutive cron failures. Exiting for container restart."
      exit 1
    fi
  fi

  sleep "$SLEEP_INTERVAL"
done
