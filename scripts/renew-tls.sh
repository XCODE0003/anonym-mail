#!/bin/bash
# TLS Certificate Renewal via Let's Encrypt
# Uses certbot standalone mode

set -e

DOMAIN="${PRIMARY_DOMAIN:-example.com}"
EMAIL="${ADMIN_EMAIL:-admin@example.com}"
CERTS_DIR="./certs"

echo "=== TLS Certificate Renewal ==="

# Check if certbot is available
if ! command -v certbot &> /dev/null; then
    echo "Installing certbot..."
    apt-get update && apt-get install -y certbot
fi

# Stop nginx temporarily
docker compose stop nginx || true

# Obtain/renew certificate
certbot certonly \
    --standalone \
    --non-interactive \
    --agree-tos \
    --email "$EMAIL" \
    -d "$DOMAIN" \
    -d "mail.$DOMAIN" \
    -d "www.$DOMAIN"

# Copy certificates
mkdir -p "$CERTS_DIR"
cp /etc/letsencrypt/live/$DOMAIN/fullchain.pem "$CERTS_DIR/"
cp /etc/letsencrypt/live/$DOMAIN/privkey.pem "$CERTS_DIR/"
chmod 600 "$CERTS_DIR"/*.pem

# Restart nginx
docker compose start nginx

echo "=== Certificate renewed successfully ==="
echo "Certificates saved to: $CERTS_DIR"
