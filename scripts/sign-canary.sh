#!/bin/bash
# Sign and update warrant canary

set -e

CANARY_KEY_EMAIL="${CANARY_KEY_EMAIL:-canary@${PRIMARY_DOMAIN:-example.com}}"
DATE=$(date +%Y-%m-%d)

echo "=== Signing Warrant Canary ==="

# Create canary statement
CANARY_TEXT="WARRANT CANARY

As of $DATE, we have NOT received any:

1. National Security Letters
2. FISA orders or any other classified requests for user information
3. Court orders or subpoenas requiring disclosure of user data
4. Gag orders preventing us from disclosing such requests

We would update this canary if the situation changes.

This statement is signed with our PGP key.
Users should verify the signature and the date.

Signed: $DATE
"

# Sign the statement
SIGNED_CANARY=$(echo "$CANARY_TEXT" | gpg --armor --clearsign --local-user "$CANARY_KEY_EMAIL" 2>/dev/null)

# Update database
docker compose exec -T postgres psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "
UPDATE canary SET 
    statement = '$CANARY_TEXT',
    signed_statement = '$(echo "$SIGNED_CANARY" | sed "s/'/''/g")',
    signed_date = CURRENT_DATE
WHERE id = 1;
"

echo ""
echo "=== Canary Updated ==="
echo ""
echo "$SIGNED_CANARY"
