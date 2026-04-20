# Anonym Mail — Design Spec
**Date:** 2026-04-20  
**Status:** Approved

---

## 0. Overview

Self-hosted anonymous email hosting (cock.li functional clone, original design).  
Brand: **Anonym Mail** | Domain (initial): `anonym-mail-service.test`  
Stack: PHP 8.3 + Slim 4 + Twig | PostgreSQL 16 | Redis | Postfix + Dovecot + Rspamd | nginx | Docker Compose | Tor v3

**Core constraints (non-negotiable):**
- NO JS on public/webmail pages (100% server-rendered HTML+CSS)
- NO LOGS — no IP/UA/Referrer stored anywhere
- NO external resources (fonts, CDN, analytics)
- Webmail: variant (B) — custom PHP NO-JS webmail (`anonym-mail`)

---

## 1. Resolved Decisions (§12)

| # | Decision |
|---|----------|
| 1 | 1 domain: `anonym-mail-service.test` |
| 2 | Brand: **Anonym Mail**, webmail binary: `anonym-mail` |
| 3 | VPS: user-provided |
| 4 | Per-mailbox quota: **1 GB** default |
| 5 | Webmail: **(B)** custom PHP NO-JS |
| 6 | XMPP: disabled (`.env` flag) |
| 7 | Language: **EN only** |
| 8 | Warrant canary PGP key: generated in `make init` |
| 9 | Transparency archive: empty + «No legal orders received» placeholder |
| 10 | Tor: **two separate** hidden services (www + mail) |
| 11 | `+tag` subaddressing: **enabled** (`recipient_delimiter = +`) |
| 12 | Catch-all mailboxes: **not implemented** |
| 13 | Message auto-delete: **never** (infinite storage) |
| 14 | Account deletion delay: **30 days** |
| 15 | Max attachment size: **25 MB** |

---

## 2. Architecture

```
                        ┌─────────────────────────────────────┐
                        │           nginx (TLS + onion)        │
                        │  www.  /  mail.  /  admin.  vhosts   │
                        │  access_log off; error_log crit;      │
                        └────────┬───────────┬────────┬────────┘
                                 │           │        │
                        ┌────────▼──┐  ┌─────▼────┐ ┌▼────────┐
                        │ PHP app   │  │ Webmail  │ │  Admin  │
                        │ (Slim 4)  │  │ (PHP/IMAP│ │ (PHP)   │
                        └────┬──────┘  └────┬─────┘ └────┬────┘
                             │              │             │
                        ┌────▼──────────────▼─────────────▼────┐
                        │              PostgreSQL 16             │
                        │  domains / users / dkim_keys /        │
                        │  announcements / content_blocks /     │
                        │  canary / admin_users / admin_audit   │
                        └──────────────────┬────────────────────┘
                                           │
                        ┌──────────────────▼────────────────────┐
                        │                Redis                   │
                        │  sessions / captcha / PoW challenges  │
                        │  rate-limit counters (in-memory only) │
                        └──────────────────┬────────────────────┘
                                           │
              ┌────────────────────────────▼────────────────────┐
              │              Mail Stack                          │
              │  Postfix (465/587/25) → Rspamd → Dovecot       │
              │  DKIM signing from dkim_keys table              │
              │  policy-service.php (smtp_blocked check)        │
              │  header_checks: strip Received/X-Originating-IP │
              │  maillog → /dev/null                            │
              └──────────────────────────────────────────────────┘
```

**Tor layer:** Two Tor hidden services  
- `www.onion` → nginx port 80 (www vhost)  
- `mail.onion` → Postfix 465 + Dovecot 993 + 995

---

## 3. Repository Structure

```
mailservice/
├── Makefile                    # make all, up, tls, tor, admin, test, backup
├── docker-compose.yml
├── docker-compose.test.yml
├── .env.example
├── .gitignore
├── README.md
├── app/
│   ├── composer.json
│   ├── public/                 # document root
│   │   ├── index.php
│   │   ├── register.php
│   │   ├── login.php
│   │   ├── changepass.php
│   │   ├── delete.php
│   │   ├── unblock.php
│   │   ├── contact.php
│   │   ├── abuse.php
│   │   ├── terms.php
│   │   ├── privacy.php
│   │   ├── captcha.php
│   │   ├── transparency/index.php
│   │   └── assets/
│   │       ├── css/site.css    # ≤15kb gzipped
│   │       └── img/
│   ├── src/
│   │   ├── Http/               # Slim middleware, router bootstrap
│   │   ├── Domain/             # User, Domain, Announcement entities + services
│   │   ├── Auth/               # CSRF, session, argon2id
│   │   ├── Captcha/            # GD PNG generator + Redis verifier
│   │   ├── Pow/                # Argon2 PoW challenge + solver script
│   │   ├── Admin/              # Admin controllers + audit
│   │   └── Webmail/            # IMAP client + compose + HTML sanitizer
│   ├── templates/              # Twig templates
│   │   ├── layout.html.twig
│   │   ├── webmail/
│   │   └── admin/
│   └── migrations/             # numbered SQL files
├── webmail/                    # separate document root for mail.<domain>
├── admin/                      # separate document root for admin.<domain>
├── config/
│   ├── postfix/
│   ├── dovecot/
│   ├── rspamd/
│   ├── nginx/
│   ├── tor/
│   ├── unbound/
│   └── php-fpm/
├── scripts/
│   ├── init-db.sh
│   ├── generate-dkim.sh
│   ├── rotate-dkim.sh
│   ├── renew-tls.sh
│   ├── purge-logs.sh
│   ├── unblock-solver.sh       # shell+openssl PoW solver (no JS)
│   └── healthcheck.sh
├── tests/
│   ├── phpunit/
│   ├── integration/
│   └── privacy.sh
└── docs/
    ├── DEPLOY.md
    ├── DNS.md
    ├── TOR.md
    ├── ADMIN.md
    ├── SECURITY.md
    └── FAQ.md
```

---

## 4. Database Schema

Per §6 of the TZ (PostgreSQL 16, citext extension):

- **domains**: id, name (citext unique), active, allow_registration, created_at (DATE)
- **users**: id, local_part (citext), domain_id, password_hash (ARGON2ID), quota_bytes (default 1GB), smtp_blocked, frozen, delete_after (DATE), created_at (DATE). No IP, no timestamps finer than DATE.
- **reserved_names**: local_part (postmaster, admin, abuse, root, support, hostmaster, webmaster, noreply, mailer-daemon, nobody, official, official-* via trigger)
- **admin_users**: username, password_hash, totp_secret, created_at
- **admin_audit**: id, admin_username, action, target, at (DATE only — no time)
- **announcements**: id, body (markdown), active, created_at
- **dkim_keys**: id, domain_id, selector, private_key, active, created_at
- **content_blocks**: key (tos/privacy/trust/abuse/contact), body_md, updated_at
- **canary**: id, body_signed (PGP text), published_at

---

## 5. Public Pages (§1)

All pages: NO JS, server-rendered Twig, semantic HTML5, 100% functional without images.

| Route | Description |
|-------|-------------|
| `/` | Landing: hero, announcement banner, trust section, server info, IMAP/SMTP/onion details |
| `/register.php` | Registration: username+domain select, ARGON2ID password, honey-pot, CAPTCHA, TOS checkbox |
| `/login` | Link to webmail + settings cabinet |
| `/changepass.php` | Change password with CAPTCHA |
| `/delete.php` | Delete account (30-day delay) with CAPTCHA + confirm checkbox |
| `/unblock.php` | PoW SMTP unblock flow (no JS — CLI solver) |
| `/contact.php` | official-* addresses + GPG keys |
| `/abuse.php` | Abuse report instructions |
| `/terms.php` | TOS (markdown from content_blocks) |
| `/privacy.php` | Privacy policy (markdown) |
| `/canary.asc.txt` | PGP-signed warrant canary (static served file) |
| `/log.txt` | Changelog |
| `/transparency/` | Legal orders archive (empty + placeholder) |
| `/gpg/*.asc.txt` | PGP keys |
| `/webmail` | Redirect to `mail.<domain>` |

**Nav:** Home · Webmail · Contact · Unblock SMTP · Change Password · Register  
**Footer:** Site Log · Warrant Canary · Transparency · Terms · Privacy · Report Abuse

---

## 6. Registration Flow (§2)

1. CSRF double-token check (`csrf == csrf_valid == session['csrf']`)
2. Honey-pot: `password_confirm` non-empty → silent fake-OK (bots get no signal)
3. CAPTCHA: GD PNG, 5-6 chars `[a-z0-9]` (no ambiguous 0/o/1/l/i), Redis TTL 10min
4. Username: regex `^[a-z0-9._-]{3,32}$`, not in reserved_names, not conflicting `official-*`
5. Domain: exists, active, allow_registration=true
6. Password: min 10 chars, matches `password_confinm` (typo field, as in reference)
7. Hash: `{ARGON2ID}` Dovecot-compatible via `password_hash(..., PASSWORD_ARGON2ID, ['memory_cost'=>65536,'time_cost'=>3,'threads'=>2])`
8. INSERT users with `smtp_blocked=true`
9. **No IP/UA/Referrer stored. created_at = DATE only.**
10. Success page with IMAP/SMTP setup instructions + "password is unrecoverable" warning

---

## 7. PoW SMTP Unblock (§3)

Since JS is forbidden, the flow is:

1. New account has `smtp_blocked=true`. Postfix policy service returns 550 with unblock URL.
2. `/unblock.php` POST: email+password → generate Argon2id PoW challenge (default 22 bits ≈ 1-3 min CPU). Store in Redis TTL 30min. Show challenge + link to download `unblock-solver.sh`.
3. User runs `./unblock-solver.sh` on their machine (shell + openssl). Gets `unblock_code`.
4. `/unblock.php` GET `?email=...&unblock_code=...` → verify → set `smtp_blocked=false`.
5. Rate-limit: 5 attempts/hour per email (Redis counter, no IP).

---

## 8. Mail Stack (§4)

### Postfix
- Virtual mailboxes via Postgres lookups
- SMTP submission: 465 (implicit TLS), 587 (STARTTLS)
- Port 25: inbound, STARTTLS optional
- `recipient_delimiter = +` for subaddressing
- privacy `header_checks`: strip Received, User-Agent, X-Originating-IP, X-Mailer, X-Forwarded-For, X-Source-IP, Authentication-Results
- `smtpd_banner = $myhostname ESMTP` (no version)
- `maillog_file = /dev/null`
- policy-service (PHP CLI, unix socket): checks `smtp_blocked`, per-user send rate-limit

### Dovecot
- passdb/userdb via Postgres SQL
- Password scheme: `ARGON2ID`
- IMAPS 993, POP3S 995, ManageSieve 4190
- Quota plugin: 1GB default (`quota_bytes` from users table)
- Sieve: vacation autoresponder + filters
- `auth_verbose=no`, `log_path=/dev/null`

### Rspamd
- Spam filter inbound/outbound
- DKIM signing on submission — keys from `dkim_keys` table (selector dynamic from DB)
- Redis backend

### Webmail (variant B — `anonym-mail`)
- Separate vhost `mail.<domain>`, own document root `webmail/`
- Pure PHP IMAP client, zero JS
- Modules: auth (IMAP login), folders, inbox list, read message, compose, reply, forward, search, settings (quota display, sieve), logout
- Three-pane layout (folders / message list / content), two-pane on narrow screens (CSS only)
- HTML email sanitizer: `ezyang/htmlpurifier` strict preset
- External images: replaced with placeholder + server-side proxy `/imgproxy?url=...&sig=HMAC`
- Max attachment display: 25 MB
- Separate `webmail.css`

---

## 9. Admin Panel (§5)

Separate vhost `admin.<domain>` with three-layer protection:
1. nginx IP allowlist (from `.env`)
2. TLS client certificate (mTLS)
3. HTTP Basic Auth + TOTP (PHP `robthree/twofactorauth`)

**Sections:** Dashboard (aggregates only) · Domains · Users · Reserved Names · DKIM Keys · Announcements · Content Editors (TOS/Privacy/Trust/Abuse/Contact) · Warrant Canary · Transparency · Abuse Queue · Unblock/CAPTCHA Controls · System Log (aggregates) · Admin Accounts

**Audit log:** admin_username + action + target + DATE (no time, no IP).

---

## 10. Design System (§7)

- **Fonts:** System stack only (`-apple-system, Segoe UI, sans-serif` / `ui-monospace, SF Mono, Menlo, monospace`)
- **Theme:** Dark (default) via `prefers-color-scheme`, toggled by cookie (form POST, no JS/localStorage)
- **Dark palette:** BG `#0a0a0a` · Surface `#141414` · Border `#1f1f1f` · Text `#ededed` · Muted `#8f8f8f` · Accent `#9ae66e` · Danger `#ff6b6b`
- **Light palette:** Inverted, accent `#2a7a2a`
- Border-radius 8px. No shadows. 1px borders. Transition 80ms ease on hover only.
- Content width: 720px (forms/text), 1100px (webmail, admin)
- Icons: inline SVG from Lucide (MIT), all local
- No Google Fonts, no CDN, no trackers, no external resources
- CSS: `site.css` (≤15kb gzipped) + `webmail.css` + `admin.css`. Pure CSS variables. No preprocessors, no Tailwind.

**HTTP headers (nginx):**
- `Strict-Transport-Security: max-age=63072000; includeSubDomains; preload`
- `Content-Security-Policy: default-src 'self'; script-src 'none'; ...`
- `Referrer-Policy: no-referrer`
- `X-Frame-Options: DENY`
- `access_log off;` globally

---

## 11. Security & Privacy (§8)

- CSRF: double-token (`csrf` + `csrf_valid` + `session['csrf']`)
- Honey-pot: hidden `password_confirm` field (CSS `width:0;height:0;opacity:0`)
- CAPTCHA: GD PNG, Redis TTL 10min, case-insensitive, one-time use
- Sessions: Redis, `cookie_secure=1`, `cookie_httponly=1`, `cookie_samesite=Strict`
- PHP: `strict_types=1` everywhere, PSR-12, PHPStan level 8, Slim 4 (no heavy framework)
- Composer deps (minimal): `slim/slim`, `slim/psr7`, `php-di/php-di`, `twig/twig`, `ezyang/htmlpurifier`, `robthree/twofactorauth`
- fail2ban: nginx-forbidden, postfix-sasl, dovecot jails. Ban via iptables DROP (no persistent IP log).
- DNS: local unbound on 127.0.0.1:53, DNSSEC enabled
- Log purge cron: hourly truncate of non-ERROR logs

---

## 12. Implementation Steps (§10)

Steps execute iteratively; commit + wait for "ok" after each:

0. Read TZ + ask §12 questions → **done**
1. Repo skeleton: `git init`, structure, `.gitignore`, `README.md`, `.env.example`, empty `docker-compose.yml`
2. Database: PostgreSQL migrations (§6), seeder for reserved_names + initial domain, `make init-db`
3. PHP core: Slim 4, DI container, Redis sessions, Twig, base layout + minimal CSS
4. Public pages: all routes §1 + §2 — register/login/changepass/delete/unblock/contact/abuse/terms/privacy/transparency/canary. CSRF + honey-pot + CAPTCHA (GD).
5. PoW unblock: §3 full. `unblock-solver.sh`. Redis challenge. Postfix policy service (PHP CLI, unix socket).
6. Postfix + Dovecot + Postgres: virtual users, ARGON2ID passdb, header_checks, logs → `/dev/null`
7. Rspamd + DKIM: signing from `dkim_keys`, rotation script `rotate-dkim.sh`
8. Webmail (variant B): auth, folders, inbox, read, compose, reply, forward, search, settings, logout. HTML sanitizer. Image proxy.
9. Admin panel: all §5 sections. mTLS + Basic + TOTP. Audit log.
10. nginx + TLS: vhosts, HSTS/CSP/headers, `acme.sh` DNS-01 wildcard
11. Tor hidden services: two HiddenServiceDir, container, `docs/TOR.md`
12. Scripts + Makefile: `make all/up/tls/tor/admin/healthcheck/test/backup`
13. Tests: PHPUnit (forms, CSRF, honey-pot, CAPTCHA, validators) + integration (`docker-compose.test.yml`) + `privacy.sh` + testssl A+
14. Documentation: DEPLOY/DNS/TOR/ADMIN/SECURITY/FAQ
15. Final acceptance per §11

---

## 13. Acceptance Criteria (§11)

- `make all` on clean Ubuntu 24.04 VPS completes in ≤30 minutes
- All §1 pages work via `curl` / `lynx` with JS disabled
- After 20 registrations + 20 SMTP sessions: zero client IPs in any log
- `testssl.sh` → A+ on 443/465/993
- mail-tester.org → ≥9/10
- Onion version functionally identical to clearnet
- Admin can add new domain in ≤3 clicks + DNS block shown
- Warrant canary validates with `gpg --verify`
- PoW unblock solved via `unblock-solver.sh` without JS
- Honey-pot silently drops `curl -F password_confirm=x` requests
- All 15 items in `docs/SECURITY.md` green
