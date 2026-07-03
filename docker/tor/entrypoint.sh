#!/bin/sh
set -eu

tor -f /etc/tor/torrc &
TOR_PID=$!

# Wait up to 60s for the hostname file, then print it once for ops visibility.
WEB_HOSTNAME_FILE=/var/lib/tor/hidden_service_web/hostname
for _ in $(seq 1 60); do
    if [ -f "$WEB_HOSTNAME_FILE" ]; then
        echo "=== Tor web hidden service ready ==="
        cat "$WEB_HOSTNAME_FILE"
        echo "===================================="
        break
    fi
    sleep 1
done

wait "$TOR_PID"
