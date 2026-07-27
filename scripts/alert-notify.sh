#!/bin/sh
# Alert notifier for Longevity Evidence Lab.
#
# Reads a JSON alert payload (Alertmanager webhook format, or a single
# {"alert":..,"severity":..,"summary":..} object) on stdin and forwards a
# concise message to a chat webhook. Designed to be used as an Alertmanager
# webhook receiver target or invoked directly by a cron health probe.
#
# Configuration (environment):
#   ALERT_WEBHOOK_URL   Required. Slack-compatible incoming webhook URL.
#   ALERT_ENV           Optional. Environment label (default: production).
#
# Usage:
#   echo '{"summary":"readiness blocked","severity":"critical"}' | \
#     ALERT_WEBHOOK_URL=https://hooks... scripts/alert-notify.sh
set -eu

: "${ALERT_WEBHOOK_URL:?ALERT_WEBHOOK_URL must be set}"
ENV_LABEL=${ALERT_ENV:-production}

payload=$(cat)
if [ -z "$payload" ]; then
  echo 'No alert payload on stdin.' >&2
  exit 1
fi

# Extract a human-readable summary. Prefer jq when available; fall back to a
# minimal grep so the notifier still works on a bare host.
if command -v jq >/dev/null 2>&1; then
  text=$(printf '%s' "$payload" | jq -r '
    if .alerts then
      [ .alerts[] | "[" + (.labels.severity // "info") + "] " + (.annotations.summary // .labels.alertname // "alert") ] | join("\n")
    else
      "[" + (.severity // "info") + "] " + (.summary // "alert")
    end')
else
  text=$(printf '%s' "$payload" | tr ',' '\n' | grep -iE 'summary|alertname|severity' | sed 's/[",{}]//g' | tr '\n' ' ')
fi

[ -z "$text" ] && text="Longevity alert (unparseable payload)"

message=$(printf 'Longevity [%s]\n%s' "$ENV_LABEL" "$text")

# Send as a Slack-compatible {"text":...} body. Use python for safe JSON
# encoding when present; otherwise a conservative escape.
if command -v python3 >/dev/null 2>&1; then
  body=$(ALERT_MSG="$message" python3 -c 'import json,os;print(json.dumps({"text":os.environ["ALERT_MSG"]}))')
else
  escaped=$(printf '%s' "$message" | sed 's/\\/\\\\/g; s/"/\\"/g' | awk 'BEGIN{ORS="\\n"}{print}')
  body=$(printf '{"text":"%s"}' "$escaped")
fi

curl -fsS -X POST -H 'Content-Type: application/json' --data "$body" "$ALERT_WEBHOOK_URL" >/dev/null
echo 'Alert dispatched.'
