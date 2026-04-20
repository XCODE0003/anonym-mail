#!/bin/bash
# Health check script for all services

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

pass() { echo -e "${GREEN}[PASS]${NC} $1"; }
fail() { echo -e "${RED}[FAIL]${NC} $1"; FAILED=1; }
warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }

FAILED=0

echo "=== Anonym Mail Health Check ==="
echo ""

# Check Docker containers
echo "--- Containers ---"
for svc in nginx php postgres redis postfix dovecot rspamd; do
    if docker compose ps --services --filter "status=running" | grep -q "^${svc}$"; then
        pass "$svc is running"
    else
        fail "$svc is NOT running"
    fi
done

echo ""
echo "--- Services ---"

# PostgreSQL
if docker compose exec -T postgres pg_isready -U "$POSTGRES_USER" &>/dev/null; then
    pass "PostgreSQL is accepting connections"
else
    fail "PostgreSQL is NOT accepting connections"
fi

# Redis
if docker compose exec -T redis redis-cli ping 2>/dev/null | grep -q "PONG"; then
    pass "Redis is responding"
else
    fail "Redis is NOT responding"
fi

# Postfix
if docker compose exec -T postfix postconf mail_version &>/dev/null; then
    pass "Postfix is configured"
else
    fail "Postfix is NOT configured"
fi

# Dovecot
if docker compose exec -T dovecot doveadm pw -l 2>/dev/null | grep -q "ARGON2"; then
    pass "Dovecot supports ARGON2"
else
    warn "Dovecot may not support ARGON2"
fi

echo ""
echo "--- Web Services ---"

# Public site
if curl -sf -o /dev/null "http://localhost:80" 2>/dev/null; then
    pass "Public site (HTTP) is accessible"
else
    warn "Public site (HTTP) not accessible (may be redirecting to HTTPS)"
fi

# Check nginx config
if docker compose exec -T nginx nginx -t &>/dev/null; then
    pass "Nginx configuration is valid"
else
    fail "Nginx configuration has errors"
fi

echo ""
echo "--- Security ---"

# Check for IP leaks in logs
if [ -f "./tests/privacy.sh" ]; then
    if ./tests/privacy.sh &>/dev/null; then
        pass "No IP leaks detected in logs"
    else
        warn "Privacy test found potential issues"
    fi
fi

# Check TLS certificates
if [ -f "./certs/fullchain.pem" ]; then
    EXPIRY=$(openssl x509 -enddate -noout -in ./certs/fullchain.pem 2>/dev/null | cut -d= -f2)
    if [ -n "$EXPIRY" ]; then
        pass "TLS certificate valid until: $EXPIRY"
    fi
else
    warn "TLS certificate not found (./certs/fullchain.pem)"
fi

echo ""
echo "=== Summary ==="
if [ $FAILED -eq 0 ]; then
    echo -e "${GREEN}All critical checks passed${NC}"
    exit 0
else
    echo -e "${RED}Some checks failed${NC}"
    exit 1
fi
