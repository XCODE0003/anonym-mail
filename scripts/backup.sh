#!/bin/bash
# Backup script for database and mail data

set -e

BACKUP_DIR="${BACKUP_DIR:-./backups}"
DATE=$(date +%Y%m%d_%H%M%S)
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"

mkdir -p "$BACKUP_DIR"

echo "=== Starting Backup ($DATE) ==="

# 1. Database backup
echo "Backing up PostgreSQL..."
docker compose exec -T postgres pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB" | gzip > "$BACKUP_DIR/db_$DATE.sql.gz"

# 2. DKIM keys backup
echo "Backing up DKIM keys..."
if [ -d "./dkim" ]; then
    tar -czf "$BACKUP_DIR/dkim_$DATE.tar.gz" -C ./dkim .
fi

# 3. Tor hidden service keys (critical!)
echo "Backing up Tor keys..."
if [ -d "./tor" ]; then
    tar -czf "$BACKUP_DIR/tor_$DATE.tar.gz" -C ./tor .
fi

# 4. Mail data (optional, can be large)
if [ "${BACKUP_MAIL_DATA:-false}" = "true" ]; then
    echo "Backing up mail data (this may take a while)..."
    docker compose exec -T dovecot tar -czf - /var/vmail 2>/dev/null > "$BACKUP_DIR/mail_$DATE.tar.gz" || true
fi

# 5. Cleanup old backups
echo "Cleaning up backups older than $RETENTION_DAYS days..."
find "$BACKUP_DIR" -type f -mtime +$RETENTION_DAYS -delete

# Calculate sizes
echo ""
echo "=== Backup Complete ==="
ls -lh "$BACKUP_DIR"/*_$DATE.* 2>/dev/null || echo "No backups created"
echo ""
echo "Total backup size:"
du -sh "$BACKUP_DIR"
