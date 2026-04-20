#!/bin/bash
# Generate DKIM keys for a domain

set -e

DOMAIN="${1:-$PRIMARY_DOMAIN}"
SELECTOR="${DKIM_SELECTOR:-$(date +%Y%m)}"
DKIM_DIR="${DKIM_DIR:-./dkim}"

if [ -z "$DOMAIN" ]; then
    echo "Usage: $0 <domain>"
    echo "Or set PRIMARY_DOMAIN environment variable"
    exit 1
fi

mkdir -p "$DKIM_DIR"

echo "=== Generating DKIM Keys for $DOMAIN ==="

# Generate RSA key pair
openssl genrsa -out "$DKIM_DIR/${DOMAIN}.private" 2048 2>/dev/null
openssl rsa -in "$DKIM_DIR/${DOMAIN}.private" -pubout -out "$DKIM_DIR/${DOMAIN}.public" 2>/dev/null

# Set permissions
chmod 600 "$DKIM_DIR/${DOMAIN}.private"
chmod 644 "$DKIM_DIR/${DOMAIN}.public"

# Extract public key for DNS
PUBLIC_KEY=$(grep -v "PUBLIC KEY" "$DKIM_DIR/${DOMAIN}.public" | tr -d '\n')

echo ""
echo "=== DKIM Keys Generated ==="
echo "Private key: $DKIM_DIR/${DOMAIN}.private"
echo "Public key:  $DKIM_DIR/${DOMAIN}.public"
echo ""
echo "=== DNS Record ==="
echo "Add this TXT record to your DNS:"
echo ""
echo "Name:  ${SELECTOR}._domainkey.${DOMAIN}"
echo "Type:  TXT"
echo "Value: v=DKIM1; k=rsa; p=${PUBLIC_KEY}"
echo ""
echo "Also add DMARC record:"
echo ""
echo "Name:  _dmarc.${DOMAIN}"
echo "Type:  TXT"
echo "Value: v=DMARC1; p=quarantine; rua=mailto:postmaster@${DOMAIN}"
echo ""

# Store in database if available
if command -v docker &> /dev/null && docker compose ps postgres --status running &>/dev/null; then
    echo "Storing DKIM key in database..."
    PRIVATE_KEY_ESCAPED=$(cat "$DKIM_DIR/${DOMAIN}.private" | sed "s/'/''/g")
    
    docker compose exec -T postgres psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "
    INSERT INTO dkim_keys (domain, selector, private_key, active)
    VALUES ('$DOMAIN', '$SELECTOR', '$PRIVATE_KEY_ESCAPED', true)
    ON CONFLICT (domain, selector) DO UPDATE SET private_key = '$PRIVATE_KEY_ESCAPED', active = true;
    " 2>/dev/null && echo "✓ DKIM key stored in database"
fi
