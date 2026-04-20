FROM alpine:3.19

LABEL maintainer="Anonym Mail"

# Install Postfix and dependencies
RUN apk add --no-cache \
    postfix \
    postfix-pgsql \
    postfix-pcre \
    cyrus-sasl \
    cyrus-sasl-plain \
    ca-certificates \
    openssl \
    bash

# Create mail directories
RUN mkdir -p /var/mail /var/spool/postfix \
    && chown -R postfix:postfix /var/mail

# Copy configuration
COPY config/postfix/main.cf /etc/postfix/main.cf
COPY config/postfix/master.cf /etc/postfix/master.cf
COPY config/postfix/header_checks /etc/postfix/header_checks
COPY config/postfix/pgsql-*.cf /etc/postfix/
COPY docker/postfix/entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh

EXPOSE 25 465 587

ENTRYPOINT ["/entrypoint.sh"]
CMD ["postfix", "start-fg"]
