#!/bin/bash
# Create admin user with TOTP

set -e

if [ -z "$1" ] || [ -z "$2" ]; then
    echo "Usage: $0 <username> <password>"
    exit 1
fi

USERNAME="$1"
PASSWORD="$2"

# Generate password hash
HASH=$(docker compose exec -T php php -r "echo password_hash('$PASSWORD', PASSWORD_ARGON2ID);")

# Generate TOTP secret
TOTP_SECRET=$(docker compose exec -T php php -r "
require '/var/www/app/vendor/autoload.php';
\$tfa = new RobThree\Auth\TwoFactorAuth('AnonymMail');
echo \$tfa->createSecret();
")

# Insert into database
docker compose exec -T postgres psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "
INSERT INTO admin_users (username, password_hash, totp_secret) 
VALUES ('$USERNAME', '$HASH', '$TOTP_SECRET')
ON CONFLICT (username) DO UPDATE SET password_hash = '$HASH', totp_secret = '$TOTP_SECRET';
"

echo ""
echo "=== Admin User Created ==="
echo "Username: $USERNAME"
echo ""
echo "TOTP Secret: $TOTP_SECRET"
echo ""
echo "Scan this QR code with your authenticator app:"
docker compose exec -T php php -r "
require '/var/www/app/vendor/autoload.php';
\$tfa = new RobThree\Auth\TwoFactorAuth('AnonymMail');
echo \$tfa->getQRCodeImageAsDataUri('$USERNAME', '$TOTP_SECRET');
" | cut -d',' -f2 | base64 -d > /tmp/admin_qr.png 2>/dev/null && echo "[QR code saved to /tmp/admin_qr.png]" || echo "[Use TOTP secret above to configure app manually]"
echo ""
echo "Or enter the secret manually in your authenticator app."
