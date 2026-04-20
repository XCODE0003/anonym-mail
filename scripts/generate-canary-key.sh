#!/bin/bash
# Generate PGP key for warrant canary signing

set -e

CANARY_KEY_DIR="${CANARY_KEY_DIR:-./certs/canary}"
KEY_NAME="${CANARY_KEY_NAME:-Anonym Mail Canary}"
KEY_EMAIL="${CANARY_KEY_EMAIL:-canary@${PRIMARY_DOMAIN:-example.com}}"

mkdir -p "$CANARY_KEY_DIR"

echo "=== Generating Canary PGP Key ==="

# Generate key without passphrase (for automated signing)
# In production, consider using a hardware key or passphrase
gpg --batch --gen-key <<EOF
Key-Type: RSA
Key-Length: 4096
Subkey-Type: RSA
Subkey-Length: 4096
Name-Real: $KEY_NAME
Name-Email: $KEY_EMAIL
Expire-Date: 0
%no-protection
%commit
EOF

# Export public key
gpg --armor --export "$KEY_EMAIL" > "$CANARY_KEY_DIR/canary-public.asc"

# Export private key (KEEP SECURE!)
gpg --armor --export-secret-keys "$KEY_EMAIL" > "$CANARY_KEY_DIR/canary-private.asc"
chmod 600 "$CANARY_KEY_DIR/canary-private.asc"

# Get key fingerprint
FINGERPRINT=$(gpg --fingerprint "$KEY_EMAIL" | grep -A1 "pub" | tail -1 | tr -d ' ')

echo ""
echo "=== Canary Key Generated ==="
echo "Public key:  $CANARY_KEY_DIR/canary-public.asc"
echo "Private key: $CANARY_KEY_DIR/canary-private.asc"
echo "Fingerprint: $FINGERPRINT"
echo ""
echo "Publish the public key on your website and keyservers."
echo "KEEP THE PRIVATE KEY SECURE!"
