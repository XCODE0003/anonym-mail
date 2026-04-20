#!/bin/bash
# Generate mTLS certificates for admin panel

set -e

CERTS_DIR="./certs/admin"
DAYS=365
COUNTRY="${TLS_COUNTRY:-US}"
STATE="${TLS_STATE:-State}"
CITY="${TLS_CITY:-City}"
ORG="${TLS_ORG:-AnonymMail}"

mkdir -p "$CERTS_DIR"
cd "$CERTS_DIR"

echo "=== Generating Admin mTLS Certificates ==="

# 1. Generate CA
echo "Generating CA..."
openssl genrsa -out ca.key 4096
openssl req -new -x509 -days $DAYS -key ca.key -out ca.crt \
    -subj "/C=$COUNTRY/ST=$STATE/L=$CITY/O=$ORG/CN=Admin CA"

# 2. Generate Server certificate
echo "Generating server certificate..."
openssl genrsa -out server.key 4096
openssl req -new -key server.key -out server.csr \
    -subj "/C=$COUNTRY/ST=$STATE/L=$CITY/O=$ORG/CN=Admin Server"
openssl x509 -req -days $DAYS -in server.csr -CA ca.crt -CAkey ca.key \
    -CAcreateserial -out server.crt

# 3. Generate Client certificate
echo "Generating client certificate..."
openssl genrsa -out client.key 4096
openssl req -new -key client.key -out client.csr \
    -subj "/C=$COUNTRY/ST=$STATE/L=$CITY/O=$ORG/CN=Admin Client"
openssl x509 -req -days $DAYS -in client.csr -CA ca.crt -CAkey ca.key \
    -CAcreateserial -out client.crt

# 4. Create PKCS#12 bundle for browser import
echo "Creating client.p12 bundle..."
openssl pkcs12 -export -out client.p12 \
    -inkey client.key -in client.crt -certfile ca.crt \
    -passout pass:changeme

# Cleanup CSR files
rm -f *.csr

# Set permissions
chmod 600 *.key
chmod 644 *.crt *.p12

echo ""
echo "=== Admin mTLS Certificates Generated ==="
echo ""
echo "Files created in $CERTS_DIR:"
echo "  - ca.crt, ca.key      (Certificate Authority)"
echo "  - server.crt, server.key (Server cert for nginx)"
echo "  - client.crt, client.key (Client cert)"
echo "  - client.p12           (Import into browser, password: changeme)"
echo ""
echo "To import in browser:"
echo "  Firefox: Settings > Privacy & Security > Certificates > View Certificates > Import"
echo "  Chrome: Settings > Privacy and security > Security > Manage certificates > Import"
echo ""
