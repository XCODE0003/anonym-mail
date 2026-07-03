#!/bin/bash
set -e

# Render MySQL map configs from templates, injecting DB credentials at runtime.
for map in mysql-virtual-mailbox-domains mysql-virtual-mailbox-maps mysql-virtual-alias-maps; do
    if [ -f "/etc/postfix/${map}.cf.template" ]; then
        envsubst '$DB_HOST $DB_NAME $DB_USER $DB_PASSWORD' \
            < "/etc/postfix/${map}.cf.template" \
            > "/etc/postfix/${map}.cf"
        chmod 640 "/etc/postfix/${map}.cf"
    fi
done

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
