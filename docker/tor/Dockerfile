# Tor Hidden Service Container

FROM alpine:3.19

RUN apk add --no-cache tor

COPY --chown=tor:tor torrc /etc/tor/torrc
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

USER tor

ENTRYPOINT ["/entrypoint.sh"]
