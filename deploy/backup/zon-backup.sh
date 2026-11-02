#!/usr/bin/env bash
# Data node — nightly at 03:00 (backup/cron): a consistent MySQL dump kept 14 days, and on Sundays a full
# Qdrant snapshot kept 5 weeks (architecture §13 «الاستمرارية»). Copy /var/backups/zon off the provider
# afterwards (a copy at one provider is not a backup); a real restore is tested once a month.
set -euo pipefail

BACKUP_DIR=/var/backups/zon
MYSQL_KEEP_DAYS=14
SNAPSHOT_KEEP_DAYS=35
# QDRANT_API_KEY comes from /etc/zon/backup.env (mode 600).
source /etc/zon/backup.env

mkdir -p "$BACKUP_DIR/mysql"
dump="$BACKUP_DIR/mysql/zone-$(date +%F).sql.gz"
mysqldump --defaults-extra-file=/etc/zon/backup.cnf --single-transaction --routines --triggers --no-tablespaces zone \
  | gzip > "$dump.partial"
mv "$dump.partial" "$dump"
find "$BACKUP_DIR/mysql" -name 'zone-*.sql.gz' -mtime +"$MYSQL_KEEP_DAYS" -delete

if [ "$(date +%u)" = 7 ]; then
  curl -sf -X POST -H "api-key: ${QDRANT_API_KEY}" "http://10.10.0.20:6333/snapshots?wait=true" > /dev/null
  find /var/lib/qdrant/snapshots -maxdepth 1 -name '*.snapshot' -mtime +"$SNAPSHOT_KEEP_DAYS" -delete
fi
