FROM alpine:3.19

LABEL maintainer="Anonym Mail"

# Install Dovecot and dependencies
RUN apk add --no-cache \
    dovecot \
    dovecot-pgsql \
    dovecot-pigeonhole-plugin \
    dovecot-lmtpd \
    ca-certificates \
    openssl \
    bash

# Create vmail user
RUN addgroup -g 5000 vmail \
    && adduser -D -u 5000 -G vmail -h /var/mail vmail

# Create required directories
RUN mkdir -p /var/mail /var/run/dovecot \
    && chown -R vmail:vmail /var/mail \
    && chown -R dovecot:dovecot /var/run/dovecot

# Copy configuration
COPY config/dovecot/dovecot.conf /etc/dovecot/dovecot.conf
COPY config/dovecot/dovecot-sql.conf.ext /etc/dovecot/dovecot-sql.conf.ext
COPY config/dovecot/conf.d/ /etc/dovecot/conf.d/
COPY docker/dovecot/entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh

EXPOSE 993 995 4190 24

ENTRYPOINT ["/entrypoint.sh"]
CMD ["dovecot", "-F"]
