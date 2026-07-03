FROM alpine:3.19

LABEL maintainer="Anonym Mail"

# Install Postfix and dependencies
# SASL auth is delegated to Dovecot (smtpd_sasl_type = dovecot), so no cyrus
# SASL plugins are needed here. Virtual lookups use MySQL (postfix-mysql).
RUN apk add --no-cache \
    postfix \
    postfix-mysql \
    postfix-pcre \
    ca-certificates \
    openssl \
    bash \
    gettext

# Create mail directories
RUN mkdir -p /var/mail /var/spool/postfix \
    && chown -R postfix:postfix /var/mail

# Copy configuration
COPY config/postfix/main.cf /etc/postfix/main.cf
COPY config/postfix/master.cf /etc/postfix/master.cf
COPY config/postfix/header_checks /etc/postfix/header_checks
COPY config/postfix/mysql-virtual-mailbox-domains.cf.template /etc/postfix/
COPY config/postfix/mysql-virtual-mailbox-maps.cf.template /etc/postfix/
COPY config/postfix/mysql-virtual-alias-maps.cf.template /etc/postfix/
COPY docker/postfix/entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh

EXPOSE 25 465 587

ENTRYPOINT ["/entrypoint.sh"]
CMD ["postfix", "start-fg"]
