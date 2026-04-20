#!/bin/bash
set -e

# Substitute environment variables in config files
envsubst < /etc/postfix/main.cf.template > /etc/postfix/main.cf 2>/dev/null || true
envsubst < /etc/postfix/pgsql-virtual-mailbox-domains.cf.template > /etc/postfix/pgsql-virtual-mailbox-domains.cf 2>/dev/null || true
envsubst < /etc/postfix/pgsql-virtual-mailbox-maps.cf.template > /etc/postfix/pgsql-virtual-mailbox-maps.cf 2>/dev/null || true

# Generate aliases database
newaliases 2>/dev/null || true

# Fix permissions
chown -R postfix:postfix /var/spool/postfix
chmod 755 /var/spool/postfix

# Ensure mail directory exists
mkdir -p /var/mail
chown -R 5000:5000 /var/mail

# Start postfix
exec "$@"
