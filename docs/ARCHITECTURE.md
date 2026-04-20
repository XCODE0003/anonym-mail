# Architecture Overview

## System Design

```
                                  ┌─────────────────┐
                                  │   Tor Network   │
                                  │  (.onion URLs)  │
                                  └────────┬────────┘
                                           │
┌──────────────────────────────────────────┼──────────────────────────────────────────┐
│                                          │                                          │
│  ┌───────────────────────────────────────┴───────────────────────────────────────┐  │
│  │                              nginx (reverse proxy)                            │  │
│  │                                                                               │  │
│  │  Port 443 ─────► Public site ─────► PHP-FPM                                   │  │
│  │  Port 443 ─────► mail.* ─────────► Webmail (PHP-FPM)                          │  │
│  │  Port 8443 ────► Admin panel ────► PHP-FPM (mTLS + Basic Auth)                │  │
│  │  Port 8080 ────► Tor web ─────────► PHP-FPM (onion only)                      │  │
│  │  Port 8081 ────► Tor webmail ─────► PHP-FPM (onion only)                      │  │
│  └───────────────────────────────────────────────────────────────────────────────┘  │
│                                          │                                          │
│  ┌───────────────────────────────────────┴───────────────────────────────────────┐  │
│  │                              PHP-FPM (Slim 4)                                 │  │
│  │                                                                               │  │
│  │  • Public routes (register, login, password, delete, unblock)                 │  │
│  │  • Webmail (IMAP client, compose, search)                                     │  │
│  │  • Admin panel (users, domains, announcements, content)                       │  │
│  │  • CAPTCHA generation (GD)                                                    │  │
│  │  • PoW verification (Argon2id)                                                │  │
│  └───────────────────────────────────────────────────────────────────────────────┘  │
│         │                        │                        │                         │
│         ▼                        ▼                        ▼                         │
│  ┌─────────────┐          ┌─────────────┐          ┌─────────────┐                  │
│  │  PostgreSQL │          │    Redis    │          │   Unbound   │                  │
│  │             │          │             │          │   (DNS)     │                  │
│  │  • Users    │          │  • Sessions │          │             │                  │
│  │  • Domains  │          │  • CAPTCHA  │          └─────────────┘                  │
│  │  • Content  │          │  • PoW      │                                           │
│  │  • Audit    │          │  • Cache    │                                           │
│  └─────────────┘          └─────────────┘                                           │
│                                                                                     │
│  ┌───────────────────────────────────────────────────────────────────────────────┐  │
│  │                              Mail Stack                                       │  │
│  │                                                                               │  │
│  │  ┌─────────────┐    ┌─────────────┐    ┌─────────────┐    ┌─────────────┐     │  │
│  │  │   Postfix   │───►│   Rspamd    │───►│   Dovecot   │    │     Tor     │     │  │
│  │  │   (SMTP)    │    │  (Filter)   │    │ (IMAP/LMTP) │    │  (Hidden)   │     │  │
│  │  │             │    │  + DKIM     │    │             │    │             │     │  │
│  │  │ Port 25,587 │    │             │    │  Port 993   │    │ .onion URLs │     │  │
│  │  └─────────────┘    └─────────────┘    └─────────────┘    └─────────────┘     │  │
│  └───────────────────────────────────────────────────────────────────────────────┘  │
│                                                                                     │
└─────────────────────────────────────────────────────────────────────────────────────┘
```

## Privacy Principles

### NO LOGS Policy

- nginx: `access_log off;`
- PHP: No IP/UA storage
- Postfix: `maillog_file = /dev/null`
- Dovecot: All logs to `/dev/null`
- Database: No timestamp precision (DATE only)

### NO JS Policy

- All pages 100% server-rendered
- CAPTCHA via GD PNG images
- PoW via CLI script (downloaded)
- Forms use standard POST submissions

### Data Minimization

- Only essential data stored
- Passwords hashed with Argon2id
- No analytics or tracking
- Admin audit log: DATE only, no IP

## Security Layers

### Public Site

1. TLS 1.2+ with strong ciphers
2. Security headers (CSP, HSTS, etc.)
3. CSRF double-token validation
4. Honey-pot bot detection
5. Rate limiting

### Webmail

1. TLS encryption
2. Session-based auth (Redis)
3. IMAP credential validation
4. HTML sanitization (HTMLPurifier)
5. External image proxying

### Admin Panel

1. IP allowlist (nginx)
2. mTLS client certificates
3. Basic authentication
4. TOTP 2FA
5. Full audit logging

### Mail Stack

1. DKIM signing (outbound)
2. SPF/DMARC validation
3. Rspamd spam filtering
4. SMTP blocking with PoW unblock
5. Argon2id password hashing

## Data Flow

### User Registration

```
Browser ─► nginx ─► PHP ─► Validate ─► CAPTCHA check (Redis)
                         ─► Honey-pot check
                         ─► CSRF check
                         ─► Username/Password validation
                         ─► Insert user (PostgreSQL)
                         ─► Create maildir (Dovecot)
```

### Email Sending

```
Client ─► Postfix:587 ─► SASL auth (Dovecot)
                     ─► Policy check (smtp_blocked?)
                     ─► Rspamd scan + DKIM sign
                     ─► Deliver to recipient
```

### SMTP Unblock (PoW)

```
Browser ─► Request challenge ─► Generate Argon2id params (Redis)
        ─► User downloads solver script
        ─► Runs locally (CPU-intensive)
        ─► Submits solution ─► Verify ─► Unblock user
```
