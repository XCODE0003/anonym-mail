#!/bin/bash
# Initialize database with migrations

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

cd "$PROJECT_ROOT"

# Load environment
if [ -f "$PROJECT_ROOT/.env" ]; then
    set -a
    source "$PROJECT_ROOT/.env"
    set +a
fi

DB_NAME="${DB_NAME:-mailservice}"
DB_USER="${DB_USER:-mailservice}"

echo "Initializing database..."

# Wait for PostgreSQL
echo "Waiting for PostgreSQL..."
MAX_RETRIES=30
RETRY=0
until docker compose exec -T postgres pg_isready -h localhost -U "$DB_USER" > /dev/null 2>&1; do
    RETRY=$((RETRY + 1))
    if [ $RETRY -ge $MAX_RETRIES ]; then
        echo "ERROR: PostgreSQL did not become ready in time"
        exit 1
    fi
    echo "  Waiting... ($RETRY/$MAX_RETRIES)"
    sleep 2
done

echo "PostgreSQL is ready"

# Create migrations tracking table
echo "Creating migrations tracking..."
docker compose exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" <<EOF
CREATE TABLE IF NOT EXISTS _migrations (
    filename TEXT PRIMARY KEY,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
EOF

# Run migrations in order
MIGRATIONS_DIR="$PROJECT_ROOT/app/migrations"
if [ -d "$MIGRATIONS_DIR" ]; then
    for migration in $(ls "$MIGRATIONS_DIR"/*.sql 2>/dev/null | sort); do
        FILENAME=$(basename "$migration")
        
        # Check if already applied
        APPLIED=$(docker compose exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" -t -c \
            "SELECT COUNT(*) FROM _migrations WHERE filename = '$FILENAME'" | tr -d ' ')
        
        if [ "$APPLIED" = "0" ]; then
            echo "Running migration: $FILENAME"
            
            # Run migration
            cat "$migration" | docker compose exec -T postgres psql -U "$DB_USER" -d "$DB_NAME"
            
            if [ $? -eq 0 ]; then
                # Record migration
                docker compose exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" -c \
                    "INSERT INTO _migrations (filename) VALUES ('$FILENAME')"
                echo "  Applied: $FILENAME"
            else
                echo "  ERROR: Failed to apply $FILENAME"
                exit 1
            fi
        else
            echo "Skipping (already applied): $FILENAME"
        fi
    done
fi

echo ""
echo "Database initialization complete"
echo ""

# Show summary
echo "Summary:"
docker compose exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" -c \
    "SELECT COUNT(*) as domains FROM domains"
docker compose exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" -c \
    "SELECT COUNT(*) as reserved_names FROM reserved_names"
