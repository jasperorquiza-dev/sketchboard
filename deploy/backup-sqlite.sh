#!/usr/bin/env sh
set -eu

APP_DIR="${APP_DIR:-/opt/sketchboard}"
BACKUP_DIR="${BACKUP_DIR:-/opt/sketchboard/backups}"
DB_PATH="${DB_PATH:-$APP_DIR/data/sketchboard.db}"
STAMP="$(date +%Y%m%d-%H%M%S)"

mkdir -p "$BACKUP_DIR"
sqlite3 "$DB_PATH" ".backup '$BACKUP_DIR/sketchboard-$STAMP.db'"
find "$BACKUP_DIR" -name "sketchboard-*.db" -type f -mtime +14 -delete
