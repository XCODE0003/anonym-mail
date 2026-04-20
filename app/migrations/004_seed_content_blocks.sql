-- Seed content blocks

INSERT IGNORE INTO content_blocks (`key`, body_md) VALUES
('tos', '# Terms of Service

By using this service, you agree to:

1. Not use the service for illegal activities
2. Not send spam or unsolicited messages
3. Not attempt to compromise the service or other users

We reserve the right to terminate accounts that violate these terms.'),

('privacy', '# Privacy Policy

We collect the minimum data necessary:

- Email address (username@domain)
- Password (hashed with Argon2id)
- Account creation date (DATE only, no time)

We do NOT collect or store:

- IP addresses
- User agents
- Referrer headers
- Precise timestamps

All logs are disabled or sent to /dev/null.'),

('trust', '# Trust & Transparency

This service is designed with privacy as the core principle.

## What we can see
- Email addresses registered
- Whether an account exists
- Account creation date (DATE only)

## What we cannot see
- Your password (hashed)
- Your IP address (not logged)
- When exactly you logged in
- Contents of your emails (stored on disk, not monitored)

## Warrant Canary
See our [warrant canary](/canary) for transparency about legal requests.'),

('abuse', '# Abuse Policy

We take abuse seriously. If you experience:

- Spam from our users
- Harassment
- Illegal content

Please report it via the contact page.

Abusive accounts will be frozen or terminated.'),

('contact', '# Contact

For support or abuse reports, email:

**postmaster@anonym-mail-service.test**

We aim to respond within 48 hours.');
