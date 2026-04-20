#!/bin/bash
# Delete users who requested account deletion after grace period

set -e

echo "=== Processing Expired User Deletions ==="

# Get users to delete
USERS=$(docker compose exec -T postgres psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -t -A -c "
SELECT id, local_part, d.name 
FROM users u 
JOIN domains d ON u.domain_id = d.id 
WHERE delete_after IS NOT NULL AND delete_after <= CURRENT_DATE;
")

if [ -z "$USERS" ]; then
    echo "No users to delete"
    exit 0
fi

while IFS='|' read -r id local_part domain; do
    EMAIL="${local_part}@${domain}"
    echo "Deleting user: $EMAIL"
    
    # Delete mailbox from filesystem
    docker compose exec -T dovecot rm -rf "/var/vmail/$domain/$local_part" 2>/dev/null || true
    
    # Delete from database
    docker compose exec -T postgres psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "
        DELETE FROM users WHERE id = $id;
    "
    
    echo "  Deleted: $EMAIL"
done <<< "$USERS"

echo "=== Deletion Complete ==="
