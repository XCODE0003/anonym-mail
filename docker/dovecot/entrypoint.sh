#!/bin/bash
set -e

# Substitute environment variables
envsubst < /etc/dovecot/dovecot-sql.conf.ext.template > /etc/dovecot/dovecot-sql.conf.ext 2>/dev/null || true

# Fix permissions
chown -R vmail:vmail /var/mail
chown -R dovecot:dovecot /var/run/dovecot
chmod 600 /etc/dovecot/dovecot-sql.conf.ext

# Start dovecot
exec "$@"
