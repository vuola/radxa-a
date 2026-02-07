#!/usr/bin/env bash
set -euo pipefail

DB="/var/lib/moxa/weather.db"
ARCHIVE_DIR="/var/lib/moxa/archive"
BACKUP_FILE="${ARCHIVE_DIR}/weather_$(date -u +%F).db"
UPLOAD_URL="http://radxa-a.local/ingest.php"

mkdir -p "$ARCHIVE_DIR"

if [ ! -f "$DB" ]; then
  echo "DB not found: $DB" >&2
  exit 1
fi

sqlite3 "$DB" ".backup '$BACKUP_FILE'"

if [ ! -s "$BACKUP_FILE" ]; then
  echo "Backup failed: $BACKUP_FILE" >&2
  exit 1
fi

curl -sSf -F "sqlite=@${BACKUP_FILE}" "$UPLOAD_URL" >/dev/null

sqlite3 "$DB" <<'SQL'
DELETE FROM weather WHERE ts < datetime('now','-1 day');
PRAGMA wal_checkpoint(TRUNCATE);
SQL

rm -f "$BACKUP_FILE"
