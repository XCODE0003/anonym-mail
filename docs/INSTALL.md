# Installation Guide

## Prerequisites

- Docker 24+ and Docker Compose v2
- Domain with DNS control
- Server with 2GB+ RAM, 20GB+ storage
- (Optional) VPS or dedicated server for production

## Quick Start

```bash
# 1. Clone repository
git clone <repository-url>
cd anonym-mail-service

# 2. Copy and configure environment
cp .env.example .env
nano .env  # Edit all required values

# 3. Run full installation
make all
```

## Step-by-Step Installation

### 1. Environment Configuration

```bash
cp .env.example .env
```

Edit `.env` and set at minimum:

```env
PRIMARY_DOMAIN=yourdomain.com
POSTGRES_PASSWORD=<strong-random-password>
APP_KEY=<32-char-random-string>
ADMIN_EMAIL=admin@yourdomain.com
```

### 2. DNS Configuration

Add these DNS records before proceeding:

| Type | Name | Value |
|------|------|-------|
| A | @ | Your server IP |
| A | mail | Your server IP |
| A | www | Your server IP |
| MX | @ | mail.yourdomain.com (priority 10) |
| TXT | @ | v=spf1 mx ~all |
| TXT | _dmarc | v=DMARC1; p=quarantine; rua=mailto:postmaster@yourdomain.com |

DKIM record will be generated during installation.

### 3. Initialize

```bash
# Create directories and generate keys
make init

# Start containers
make up

# Initialize database
make init-db
```

### 4. TLS Certificates

```bash
# Generate Let's Encrypt certificates
make tls
```

### 5. DKIM Setup

After running `make init-keys`, add the DKIM TXT record shown in the output.

### 6. Create Admin User

```bash
make admin
# Follow prompts for username/password
# Scan QR code with authenticator app
```

### 7. Tor Hidden Services (Optional)

```bash
make tor
# Note the .onion addresses displayed
```

### 8. Verify Installation

```bash
make healthcheck
```

## Post-Installation

### Firewall Rules

Open these ports:

- 80 (HTTP → HTTPS redirect)
- 443 (HTTPS)
- 25 (SMTP)
- 465 (SMTPS)
- 587 (Submission)
- 993 (IMAPS)

### Backup Setup

```bash
# Test backup
make backup

# Add to crontab for daily backups
0 3 * * * cd /path/to/anonym-mail && make backup
```

### Log Purge

Schedule regular log purging:

```bash
# Add to crontab (every hour)
0 * * * * cd /path/to/anonym-mail && make purge-logs
```

### User Deletion Processing

```bash
# Add to crontab (daily)
0 4 * * * cd /path/to/anonym-mail && make delete-expired
```

## Troubleshooting

### Containers not starting

```bash
docker compose logs <service-name>
```

### TLS certificate issues

```bash
# Check certificate expiry
openssl x509 -enddate -noout -in certs/fullchain.pem

# Force renewal
make tls
```

### Database connection issues

```bash
# Check PostgreSQL is running
docker compose exec postgres pg_isready

# Check credentials
docker compose exec postgres psql -U $POSTGRES_USER -d $POSTGRES_DB
```

### Mail not being received

1. Check DNS MX records: `dig MX yourdomain.com`
2. Check Postfix logs: `docker compose logs postfix`
3. Verify ports are open: `netstat -tlnp | grep -E '25|465|587'`

### DKIM failures

```bash
# Verify DKIM record
dig TXT $(date +%Y%m)._domainkey.yourdomain.com

# Check Rspamd is signing
docker compose logs rspamd
```
