#!/bin/bash
# Rotate DKIM keys for a domain

set -e

DOMAIN="${1:-}"
if [ -z "$DOMAIN" ]; then
    echo "Usage: $0 <domain>"
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "Rotating DKIM keys for $DOMAIN..."
echo "Old keys will remain active for 7 days in DNS."
echo ""

# Generate new keys
"$SCRIPT_DIR/generate-dkim.sh" "$DOMAIN"

echo ""
echo "IMPORTANT: Update your DNS with the new DKIM record."
echo "Keep the old DKIM record for at least 7 days."
