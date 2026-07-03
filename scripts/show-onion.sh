#!/bin/bash
# Display Tor .onion addresses from the running prod stack.
#
# Usage:
#   ./scripts/show-onion.sh                  # auto-detect prod compose project
#   COMPOSE_PROJECT=anonym-mail ./scripts/show-onion.sh

set -e

CONTAINER="${TOR_CONTAINER:-mail-tor}"

if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER}$"; then
    echo "Tor container '${CONTAINER}' is not running."
    echo "Start it with: docker compose -f docker-compose.prod.yml up -d tor"
    exit 1
fi

echo "=== Tor .onion Addresses ==="
echo ""

WEB=$(docker exec "$CONTAINER" cat /var/lib/tor/hidden_service_web/hostname 2>/dev/null || true)
if [ -n "$WEB" ]; then
    echo "Web (public site): http://$WEB"
else
    echo "Web: not yet generated — wait ~30s after first start, then re-run."
fi

echo ""
echo "To surface this on the site footer, add to .env on the host:"
echo "  TOR_ONION_WEB=$WEB"
echo "Then: docker compose -f docker-compose.prod.yml up -d php"
