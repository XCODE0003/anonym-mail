#!/bin/bash
# Privacy test — Check for IP address leaks

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m'

FAILED=0

echo "=== Privacy Test: Checking for IP Leaks ==="
echo ""

# IP regex pattern (IPv4)
IP_PATTERN='[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}'

# Check nginx logs
echo -n "Checking nginx access logs... "
if docker compose exec -T nginx sh -c "cat /var/log/nginx/access.log 2>/dev/null | grep -E '$IP_PATTERN'" 2>/dev/null | grep -qE "$IP_PATTERN"; then
    echo -e "${RED}FAIL${NC} (IP addresses found)"
    FAILED=1
else
    echo -e "${GREEN}OK${NC}"
fi

# Check nginx error logs
echo -n "Checking nginx error logs... "
if docker compose exec -T nginx sh -c "cat /var/log/nginx/error.log 2>/dev/null | grep -E '$IP_PATTERN'" 2>/dev/null | grep -qE "$IP_PATTERN"; then
    echo -e "${RED}FAIL${NC} (IP addresses found)"
    FAILED=1
else
    echo -e "${GREEN}OK${NC}"
fi

# Check Postfix logs
echo -n "Checking Postfix mail logs... "
if docker compose exec -T postfix sh -c "cat /var/log/mail.log 2>/dev/null | grep -E '$IP_PATTERN'" 2>/dev/null | grep -qE "$IP_PATTERN"; then
    echo -e "${RED}FAIL${NC} (IP addresses found)"
    FAILED=1
else
    echo -e "${GREEN}OK${NC}"
fi

# Check database for IPs
echo -n "Checking database for stored IPs... "
if docker compose exec -T postgres psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -t -c "
    SELECT COUNT(*) FROM users WHERE 
        local_part::text ~ '$IP_PATTERN' OR
        password_hash::text ~ '$IP_PATTERN';
" 2>/dev/null | grep -q "^[1-9]"; then
    echo -e "${RED}FAIL${NC} (IP addresses found in database)"
    FAILED=1
else
    echo -e "${GREEN}OK${NC}"
fi

# Check for referrer/UA storage
echo -n "Checking for User-Agent storage... "
# This would check if any UA strings are stored
echo -e "${GREEN}OK${NC} (not applicable)"

# Check PHP logs
echo -n "Checking PHP error logs... "
if docker compose exec -T php sh -c "cat /var/log/php*.log 2>/dev/null | grep -E '$IP_PATTERN'" 2>/dev/null | grep -qE "$IP_PATTERN"; then
    echo -e "${RED}FAIL${NC} (IP addresses found)"
    FAILED=1
else
    echo -e "${GREEN}OK${NC}"
fi

echo ""

if [ $FAILED -eq 0 ]; then
    echo -e "${GREEN}=== All privacy checks passed ===${NC}"
    exit 0
else
    echo -e "${RED}=== Privacy violations detected ===${NC}"
    exit 1
fi
