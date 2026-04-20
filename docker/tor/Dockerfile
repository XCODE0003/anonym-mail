# Tor Hidden Service Container

FROM alpine:3.19

RUN apk add --no-cache tor

# Create data directory
RUN mkdir -p /var/lib/tor && \
    chown -R tor:tor /var/lib/tor && \
    chmod 700 /var/lib/tor

# Copy config
COPY --chown=tor:tor torrc /etc/tor/torrc

USER tor

ENTRYPOINT ["tor", "-f", "/etc/tor/torrc"]
