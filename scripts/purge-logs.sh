#!/bin/bash
# Purge any logs that may have been created
# Run periodically to ensure no log accumulation

set -e

echo "=== Log Purge ==="

# Container logs (Docker's own logs)
docker compose logs --since 1s >/dev/null 2>&1 || true

# Truncate any log files in containers
for container in nginx php postgres redis postfix dovecot rspamd tor; do
    docker compose exec -T $container sh -c '
        find /var/log -type f -name "*.log" -exec truncate -s 0 {} \; 2>/dev/null || true
        find /var/log -type f -name "*.log.*" -delete 2>/dev/null || true
    ' 2>/dev/null || true
done

# Clear PHP sessions older than configured lifetime (privacy)
docker compose exec -T php sh -c '
    find /tmp -name "sess_*" -mmin +1440 -delete 2>/dev/null || true
' 2>/dev/null || true

# Clear Redis expired keys
docker compose exec -T redis redis-cli --no-auth-warning SCAN 0 MATCH "captcha:*" COUNT 1000 2>/dev/null | tail -n +2 | while read key; do
    docker compose exec -T redis redis-cli DEL "$key" 2>/dev/null || true
done

echo "Log purge complete"
