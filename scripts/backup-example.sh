#!/bin/sh
set -eu
ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
DEST=${1:-"$ROOT/build/backups"}
mkdir -p "$DEST"
stamp=$(date -u +%Y%m%dT%H%M%SZ)

echo 'Reference backup only: production backups must be encrypted, off-site, monitored, and restore-tested.'
docker compose run --rm wpcli wp db export "/tmp/lel-$stamp.sql" --allow-root
docker compose cp "wpcli:/tmp/lel-$stamp.sql" "$DEST/lel-$stamp.sql"
tar -C "$ROOT" -czf "$DEST/lel-wp-content-$stamp.tar.gz" wp-content
sha256sum "$DEST/lel-$stamp.sql" "$DEST/lel-wp-content-$stamp.tar.gz" > "$DEST/lel-$stamp.sha256"
chmod 600 "$DEST/lel-$stamp.sql" "$DEST/lel-wp-content-$stamp.tar.gz" "$DEST/lel-$stamp.sha256"
echo "Backup example written to $DEST. Perform a separate restore drill."
