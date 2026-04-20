# Anonym Mail — Implementation Plan
**Date:** 2026-04-20  
**Spec:** `docs/superpowers/specs/2026-04-20-anonym-mail-design.md`  
**Status:** In Progress

---

## Overview

15-step iterative implementation based on §10 of the spec. Each step produces a working commit. Parallel execution where dependencies allow.

---

## Phase 1: Foundation (Steps 1-3)

### Step 1: Repository Skeleton
**Dependencies:** None  
**Files:**
- `git init`
- `.gitignore` (PHP, Docker, env, IDE)
- `README.md` (project overview + TOC)
- `.env.example` (all config vars)
- `docker-compose.yml` (skeleton)
- `docker-compose.test.yml` (skeleton)
- `Makefile` (skeleton targets)
- Directory structure per spec §3

**Acceptance:**
- [ ] Clean `git status`
- [ ] `make help` shows all targets

---

### Step 2: Database
**Dependencies:** Step 1  
**Files:**
- `app/migrations/001_initial_schema.sql` — full §6 schema
- `app/migrations/002_seed_reserved_names.sql`
- `app/migrations/003_seed_initial_domain.sql`
- `scripts/init-db.sh`
- `config/postgres/` — init scripts

**Acceptance:**
- [ ] `make init-db` creates tables
- [ ] `reserved_names` populated
- [ ] Domain `anonym-mail-service.test` seeded

---

### Step 3: PHP Core
**Dependencies:** Step 2  
**Files:**
- `app/composer.json` (slim/slim, twig, php-di, predis)
- `app/public/index.php` — front controller
- `app/src/Http/Router.php`
- `app/src/Http/Middleware/Session.php`
- `app/src/Http/Middleware/Csrf.php`
- `app/templates/layout.html.twig`
- `app/public/assets/css/site.css` — base dark theme
- `config/php-fpm/`

**Acceptance:**
- [ ] `composer install` clean
- [ ] Landing page renders with layout
- [ ] Session starts in Redis
- [ ] PHPStan level 8 pass

---

## Phase 2: Public Pages (Steps 4-5)

### Step 4: Forms & Pages
**Dependencies:** Step 3  
**Files:**
- All routes from spec §5:
  - `/` (landing)
  - `/register.php`
  - `/login`
  - `/changepass.php`
  - `/delete.php`
  - `/unblock.php`
  - `/contact.php`
  - `/abuse.php`
  - `/terms.php`
  - `/privacy.php`
  - `/transparency/`
  - `/canary.asc.txt`
  - `/log.txt`
- `app/src/Captcha/Generator.php` — GD PNG
- `app/src/Captcha/Validator.php` — Redis check
- `app/src/Auth/HoneyPot.php`
- `app/src/Domain/User/UserService.php`
- `app/src/Domain/Domain/DomainRepository.php`
- Templates for all pages

**Acceptance:**
- [ ] All pages render without JS
- [ ] CAPTCHA generates + validates
- [ ] Honey-pot catches bots
- [ ] CSRF double-token works
- [ ] Registration creates user with `smtp_blocked=true`
- [ ] Change password works
- [ ] Delete account sets `delete_after` +30 days

---

### Step 5: PoW SMTP Unblock
**Dependencies:** Step 4  
**Files:**
- `app/src/Pow/Challenge.php` — Argon2id challenge generator
- `app/src/Pow/Verifier.php` — solution verification
- `scripts/unblock-solver.sh` — CLI solver (shell + openssl)
- `app/src/Mail/PolicyService.php` — Postfix policy daemon

**Acceptance:**
- [ ] Challenge generates with configurable difficulty
- [ ] `unblock-solver.sh` finds valid nonce
- [ ] Verification unlocks user
- [ ] Policy service rejects blocked users

---

## Phase 3: Mail Stack (Steps 6-7)

### Step 6: Postfix + Dovecot
**Dependencies:** Step 2  
**Files:**
- `config/postfix/main.cf`
- `config/postfix/master.cf`
- `config/postfix/pgsql-virtual-mailbox-domains.cf`
- `config/postfix/pgsql-virtual-mailbox-maps.cf`
- `config/postfix/header_checks`
- `config/dovecot/dovecot.conf`
- `config/dovecot/dovecot-sql.conf.ext`
- `config/dovecot/conf.d/10-auth.conf`
- `config/dovecot/conf.d/10-mail.conf`
- `config/dovecot/conf.d/10-ssl.conf`
- `config/dovecot/conf.d/90-quota.conf`
- Docker services in `docker-compose.yml`

**Acceptance:**
- [ ] Dovecot authenticates via Postgres (ARGON2ID)
- [ ] IMAP login works
- [ ] SMTP submission works (with blocked check)
- [ ] Privacy headers stripped
- [ ] Logs → /dev/null

---

### Step 7: Rspamd + DKIM
**Dependencies:** Step 6  
**Files:**
- `config/rspamd/local.d/dkim_signing.conf`
- `config/rspamd/local.d/redis.conf`
- `scripts/generate-dkim.sh`
- `scripts/rotate-dkim.sh`

**Acceptance:**
- [ ] DKIM keys in database
- [ ] Outgoing mail signed
- [ ] Rotation script works

---

## Phase 4: Webmail (Step 8)

### Step 8: Custom PHP Webmail
**Dependencies:** Step 6  
**Files:**
- `webmail/public/index.php`
- `app/src/Webmail/ImapClient.php`
- `app/src/Webmail/Controller/AuthController.php`
- `app/src/Webmail/Controller/FolderController.php`
- `app/src/Webmail/Controller/MessageController.php`
- `app/src/Webmail/Controller/ComposeController.php`
- `app/src/Webmail/Controller/SearchController.php`
- `app/src/Webmail/Controller/SettingsController.php`
- `app/src/Webmail/HtmlSanitizer.php` — HTMLPurifier wrapper
- `app/src/Webmail/ImageProxy.php`
- `app/templates/webmail/` — all templates
- `app/public/assets/css/webmail.css`

**Acceptance:**
- [ ] IMAP login via form
- [ ] Folder list renders
- [ ] Inbox shows messages
- [ ] Read message (HTML sanitized)
- [ ] Compose + send
- [ ] Reply/Forward
- [ ] Search
- [ ] Settings (quota display)
- [ ] Logout
- [ ] External images proxied
- [ ] Zero JS

---

## Phase 5: Admin Panel (Step 9)

### Step 9: Admin Panel
**Dependencies:** Step 8  
**Files:**
- `admin/public/index.php`
- `app/src/Admin/Controller/DashboardController.php`
- `app/src/Admin/Controller/DomainController.php`
- `app/src/Admin/Controller/UserController.php`
- `app/src/Admin/Controller/DkimController.php`
- `app/src/Admin/Controller/AnnouncementController.php`
- `app/src/Admin/Controller/ContentController.php`
- `app/src/Admin/Controller/CanaryController.php`
- `app/src/Admin/Controller/TransparencyController.php`
- `app/src/Admin/Controller/ControlsController.php`
- `app/src/Admin/Controller/AdminUserController.php`
- `app/src/Admin/Auth/TotpAuth.php`
- `app/src/Admin/AuditLog.php`
- `app/templates/admin/` — all templates
- `app/public/assets/css/admin.css`

**Acceptance:**
- [ ] mTLS + Basic Auth + TOTP
- [ ] All sections functional
- [ ] Audit log records actions
- [ ] No personal data exposed

---

## Phase 6: Infrastructure (Steps 10-12)

### Step 10: nginx + TLS
**Dependencies:** Step 9  
**Files:**
- `config/nginx/nginx.conf`
- `config/nginx/sites/www.conf`
- `config/nginx/sites/mail.conf`
- `config/nginx/sites/admin.conf`
- `config/nginx/snippets/security-headers.conf`
- `config/nginx/snippets/no-logs.conf`
- `scripts/renew-tls.sh` — acme.sh DNS-01

**Acceptance:**
- [ ] All vhosts work
- [ ] HSTS + CSP headers
- [ ] `access_log off`
- [ ] TLS A+ on testssl.sh

---

### Step 11: Tor Hidden Services
**Dependencies:** Step 10  
**Files:**
- `config/tor/torrc`
- `docker-compose.yml` — tor service
- `docs/TOR.md`

**Acceptance:**
- [ ] Two .onion addresses generated
- [ ] www.onion → nginx
- [ ] mail.onion → Postfix/Dovecot ports
- [ ] Functional parity with clearnet

---

### Step 12: Scripts + Makefile
**Dependencies:** Step 11  
**Files:**
- `Makefile` — complete
- `scripts/healthcheck.sh`
- `scripts/purge-logs.sh`
- `scripts/backup.sh`

**Makefile targets:**
```makefile
all        # Full install from scratch
up         # Start all containers
down       # Stop all containers
init       # First-time setup (keys, db, etc.)
init-db    # Database migrations
tls        # Generate/renew TLS certs
tor        # Start Tor services
admin      # Create admin user
healthcheck # Run health checks
test       # Run all tests
backup     # Backup data
clean      # Remove containers + volumes
```

**Acceptance:**
- [ ] `make all` on clean Ubuntu 24.04 ≤30 min
- [ ] All targets work

---

## Phase 7: Quality (Steps 13-15)

### Step 13: Tests
**Dependencies:** Step 12  
**Files:**
- `tests/phpunit/CsrfTest.php`
- `tests/phpunit/HoneyPotTest.php`
- `tests/phpunit/CaptchaTest.php`
- `tests/phpunit/RegistrationTest.php`
- `tests/phpunit/UserServiceTest.php`
- `tests/integration/FullFlowTest.php`
- `tests/privacy.sh`
- `phpunit.xml`

**Acceptance:**
- [ ] PHPUnit passes
- [ ] Integration test passes
- [ ] `privacy.sh` confirms zero IPs in logs

---

### Step 14: Documentation
**Dependencies:** Step 13  
**Files:**
- `docs/DEPLOY.md`
- `docs/DNS.md`
- `docs/TOR.md`
- `docs/ADMIN.md`
- `docs/SECURITY.md`
- `docs/FAQ.md`
- `README.md` — update with full instructions

**Acceptance:**
- [ ] Fresh deploy follows DEPLOY.md successfully
- [ ] DNS checklist complete
- [ ] Security checklist has 15 items

---

### Step 15: Final Acceptance
**Dependencies:** All steps  

**Checklist (per spec §13):**
- [ ] `make all` ≤30 min on Ubuntu 24.04
- [ ] All pages work with JS disabled
- [ ] Zero client IPs after 20 registrations + 20 SMTP sessions
- [ ] testssl.sh → A+ on 443/465/993
- [ ] mail-tester.org → ≥9/10
- [ ] Onion identical to clearnet
- [ ] Admin adds domain in ≤3 clicks
- [ ] Warrant canary validates with GPG
- [ ] PoW solved via unblock-solver.sh
- [ ] Honey-pot silently drops bots
- [ ] All 15 SECURITY.md items green

---

## Execution Order

Steps 1-3 must be sequential.  
Steps 4-5 can be parallelized internally.  
Steps 6-7 must be sequential.  
Step 8 depends on 6.  
Step 9 depends on 8.  
Steps 10-11 can start after 9.  
Step 12 depends on 11.  
Steps 13-15 sequential after 12.

**Critical path:** 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9 → 10 → 11 → 12 → 13 → 14 → 15

---

## Now Starting: Step 1 — Repository Skeleton
