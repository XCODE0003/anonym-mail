#!/bin/bash
# Display Tor .onion addresses

set -e

TOR_DATA_DIR="${TOR_DATA_DIR:-./tor}"

echo "=== Tor .onion Addresses ==="
echo ""

# Web
if [ -f "$TOR_DATA_DIR/hidden_service_web/hostname" ]; then
    WEB_ONION=$(cat "$TOR_DATA_DIR/hidden_service_web/hostname")
    echo "Web (public site): http://$WEB_ONION"
else
    echo "Web: Not yet generated (start Tor container first)"
fi

# Webmail
if [ -f "$TOR_DATA_DIR/hidden_service_webmail/hostname" ]; then
    MAIL_ONION=$(cat "$TOR_DATA_DIR/hidden_service_webmail/hostname")
    echo "Webmail:           http://$MAIL_ONION"
else
    echo "Webmail: Not yet generated"
fi

# SMTP
if [ -f "$TOR_DATA_DIR/hidden_service_smtp/hostname" ]; then
    SMTP_ONION=$(cat "$TOR_DATA_DIR/hidden_service_smtp/hostname")
    echo "SMTP:              $SMTP_ONION:25 / :587"
else
    echo "SMTP: Not yet generated"
fi

# IMAP
if [ -f "$TOR_DATA_DIR/hidden_service_imap/hostname" ]; then
    IMAP_ONION=$(cat "$TOR_DATA_DIR/hidden_service_imap/hostname")
    echo "IMAP:              $IMAP_ONION:993 / :143"
else
    echo "IMAP: Not yet generated"
fi

echo ""
echo "=== Client Configuration ==="
echo ""
echo "To access via Tor Browser:"
echo "  1. Install Tor Browser"
echo "  2. Navigate to the .onion address above"
echo ""
echo "For email clients via Tor:"
echo "  1. Configure SOCKS5 proxy: 127.0.0.1:9050"
echo "  2. Use the SMTP/IMAP .onion addresses above"
