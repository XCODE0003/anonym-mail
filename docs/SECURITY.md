# Security Documentation

## Threat Model

### Protected Against

- Mass surveillance (no logs, no IP storage)
- Spam and abuse (PoW, CAPTCHA, rate limits)
- Session hijacking (secure cookies, CSRF protection)
- XSS attacks (CSP headers, output escaping)
- SQL injection (prepared statements)
- Bot registration (honey-pot, CAPTCHA)
- Credential stuffing (rate limiting, PoW)
- Email forgery (DKIM, SPF, DMARC)

### Not Protected Against (Out of Scope)

- Physical server seizure
- State-level targeted attacks
- User-side malware
- Social engineering

## Authentication

### User Authentication

- Password requirements: 12+ chars, uppercase, lowercase, digit
- Hash algorithm: Argon2id (memory: 65536, time: 4, parallelism: 1)
- Session storage: Redis with secure cookies
- SMTP auth: Dovecot SASL with same credentials

### Admin Authentication

```
Layer 1: IP Allowlist (nginx)
Layer 2: mTLS Client Certificate
Layer 3: HTTP Basic Auth
Layer 4: Username + Password + TOTP
```

## CSRF Protection

Double-submit cookie pattern:

1. Session stores `csrf_token`
2. Forms include two hidden fields: `csrf` and `csrf_valid`
3. Both must match session token
4. Tokens regenerated on each request

## Rate Limiting

| Endpoint | Limit | Scope |
|----------|-------|-------|
| General | 10 req/s | Per IP |
| Auth endpoints | 3 req/min | Per IP |
| Admin | 3 req/min | Per IP |
| PoW challenges | 5/hour | Per user |

## SMTP Security

### Blocking New Users

All new users start with `smtp_blocked = true`. This prevents:

- Immediate spam after registration
- Automated abuse

### Unblock Process (Proof-of-Work)

1. User requests unblock challenge
2. Server generates Argon2id parameters (high cost)
3. User downloads CLI solver script
4. Runs locally on their machine (resource-intensive)
5. Submits solution
6. Server verifies and unblocks

This ensures:

- Human-level effort required
- No JavaScript dependency
- CPU cost deters mass abuse

## Email Security

### Outbound

- DKIM signing via Rspamd
- SPF record in DNS
- DMARC policy enforcement

### Inbound

- Rspamd spam scoring
- Greylisting for new senders
- Reject score > 15

### Header Privacy

Postfix strips these headers from outbound mail:

- Received (all internal)
- X-Originating-IP
- X-Mailer
- User-Agent

## TLS Configuration

### Protocols

- TLSv1.2 and TLSv1.3 only
- Older protocols disabled

### Ciphers

```
ECDHE-ECDSA-AES128-GCM-SHA256
ECDHE-RSA-AES128-GCM-SHA256
ECDHE-ECDSA-AES256-GCM-SHA384
ECDHE-RSA-AES256-GCM-SHA384
ECDHE-ECDSA-CHACHA20-POLY1305
ECDHE-RSA-CHACHA20-POLY1305
```

### HSTS

```
Strict-Transport-Security: max-age=63072000; includeSubDomains; preload
```

## Content Security Policy

```
default-src 'self';
style-src 'self' 'unsafe-inline';
img-src 'self' data:;
form-action 'self';
frame-ancestors 'none';
base-uri 'self'
```

## Security Headers

| Header | Value |
|--------|-------|
| X-Frame-Options | DENY |
| X-Content-Type-Options | nosniff |
| X-XSS-Protection | 1; mode=block |
| Referrer-Policy | no-referrer |
| Permissions-Policy | geolocation=(), microphone=(), camera=() |

## Warrant Canary

PGP-signed statement updated regularly confirming no legal demands received.

- Location: `/canary` page
- Update frequency: Monthly recommended
- Signature verification: Public key on site

## Incident Response

1. Rotate all secrets immediately
2. Revoke admin mTLS certificates
3. Generate new DKIM keys
4. Review audit logs (date-only)
5. Notify users if data exposed
