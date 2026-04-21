#!/bin/bash
set -e

export DB_PORT="${DB_PORT:-3306}"

if [ -f /etc/dovecot/dovecot-sql.conf.ext.template ]; then
    envsubst '$DB_HOST $DB_PORT $DB_NAME $DB_USER $DB_PASSWORD' \
        < /etc/dovecot/dovecot-sql.conf.ext.template \
        > /etc/dovecot/dovecot-sql.conf.ext
fi

chown -R vmail:vmail /var/mail
chown -R dovecot:dovecot /var/run/dovecot
chmod 600 /etc/dovecot/dovecot-sql.conf.ext

exec "$@"
