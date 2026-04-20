# Public Pages Reference

## Overview

This service has NO JavaScript and NO external API. All interactions are via HTML forms with server-side rendering.

## Public Endpoints

### Landing Page

```
GET /
```

Displays service information, announcements, and navigation.

### Registration

```
GET  /register.php
POST /register.php
```

**Form Fields:**

| Field | Required | Description |
|-------|----------|-------------|
| username | Yes | 3-64 chars, alphanumeric + underscore |
| domain | Yes | Domain ID from dropdown |
| password | Yes | 12+ chars, mixed case + digit |
| password_confirm | Yes | Must match password |
| captcha_id | Yes | Hidden field from CAPTCHA |
| captcha | Yes | User-entered CAPTCHA code |
| csrf | Yes | CSRF token |
| csrf_valid | Yes | CSRF token (double submit) |
| website | No | Honey-pot field (must be empty) |

**Responses:**

- Success: Redirect to success page
- Error: Re-render form with error message

### Password Change

```
GET  /changepass.php
POST /changepass.php
```

**Form Fields:**

| Field | Required | Description |
|-------|----------|-------------|
| email | Yes | Full email address |
| current_password | Yes | Current password |
| new_password | Yes | New password (12+ chars) |
| new_password_confirm | Yes | Confirm new password |
| csrf, csrf_valid | Yes | CSRF tokens |

### Account Deletion

```
GET  /delete.php
POST /delete.php
```

Initiates account deletion with 72-hour grace period.

**Form Fields:**

| Field | Required | Description |
|-------|----------|-------------|
| email | Yes | Full email address |
| password | Yes | Account password |
| confirm | Yes | Checkbox confirmation |
| csrf, csrf_valid | Yes | CSRF tokens |

### SMTP Unblock

```
GET  /unblock.php
POST /unblock.php
```

**Step 1: Request Challenge**

```
POST /unblock.php
Fields: email, password, csrf, csrf_valid
```

Returns page with:
- Challenge parameters (seed, salt, difficulty)
- Link to download solver script

**Step 2: Submit Solution**

```
POST /unblock.php
Fields: challenge_id, nonce, csrf, csrf_valid
```

### CAPTCHA Image

```
GET /captcha-image.php?id={captcha_id}
```

Returns PNG image of CAPTCHA code.

### Static Content Pages

```
GET /terms.php      # Terms of Service
GET /privacy.php    # Privacy Policy
GET /abuse.php      # Abuse Policy
GET /contact.php    # Contact Information
GET /canary         # Warrant Canary
```

## Webmail Endpoints

All webmail endpoints require authentication via IMAP credentials.

```
GET  /                     # Login page / redirect to inbox
POST /login                # Authenticate
GET  /logout               # End session
GET  /inbox                # Redirect to /folder/INBOX
GET  /folder/{folder}      # Message list
GET  /message/{folder}/{uid}  # View message
GET  /compose              # Compose form
POST /compose              # Send message
GET  /reply/{folder}/{uid} # Reply form
GET  /forward/{folder}/{uid} # Forward form
POST /message/delete       # Delete message
POST /message/move         # Move message
GET  /search               # Search messages
GET  /settings             # Account settings
GET  /imgproxy             # Proxy external images
```

## Admin Endpoints

Protected by IP allowlist + mTLS + Basic Auth + TOTP.

```
GET  /                     # Login
POST /login                # Authenticate with TOTP
GET  /logout               # End session
GET  /dashboard            # Stats overview
GET  /domains              # Domain management
POST /domains/add          # Add domain
GET  /users                # User list with search
POST /users/freeze         # Freeze/unfreeze user
POST /users/unblock-smtp   # Manual SMTP unblock
GET  /announcements        # Announcement management
POST /announcements/save   # Post announcement
GET  /content              # Content blocks
GET  /content/{key}        # Edit content block
POST /content/{key}        # Save content block
GET  /audit                # Audit log
```

## Error Responses

All errors are rendered as HTML pages with:

- HTTP status code (403, 404, 500)
- User-friendly error message
- No stack traces in production
- No IP/request logging
