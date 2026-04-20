-- Anonym Mail — Initial Schema (MySQL)
-- Run migrations in order: 001, 002, 003...

-- Domains table
CREATE TABLE IF NOT EXISTS domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    active BOOLEAN DEFAULT TRUE,
    allow_registration BOOLEAN DEFAULT TRUE,
    created_at DATE DEFAULT (CURRENT_DATE)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    local_part VARCHAR(64) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    smtp_blocked BOOLEAN DEFAULT TRUE,
    frozen BOOLEAN DEFAULT FALSE,
    delete_after DATE DEFAULT NULL,
    created_at DATE DEFAULT (CURRENT_DATE),
    UNIQUE KEY unique_email (domain_id, local_part),
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE,
    INDEX idx_users_local_part (local_part),
    INDEX idx_users_smtp_blocked (smtp_blocked),
    INDEX idx_users_delete_after (delete_after)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reserved usernames
CREATE TABLE IF NOT EXISTS reserved_names (
    local_part VARCHAR(64) PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin users
CREATE TABLE IF NOT EXISTS admin_users (
    username VARCHAR(64) PRIMARY KEY,
    password_hash VARCHAR(255) NOT NULL,
    totp_secret VARCHAR(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin audit log (DATE only, no IP — privacy)
CREATE TABLE IF NOT EXISTS admin_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_username VARCHAR(64) NOT NULL,
    action VARCHAR(64) NOT NULL,
    target VARCHAR(255) DEFAULT NULL,
    at DATE DEFAULT (CURRENT_DATE),
    INDEX idx_audit_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Announcements
CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    body TEXT NOT NULL,
    active BOOLEAN DEFAULT FALSE,
    created_at DATE DEFAULT (CURRENT_DATE)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DKIM keys
CREATE TABLE IF NOT EXISTS dkim_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL,
    selector VARCHAR(64) NOT NULL,
    private_key TEXT NOT NULL,
    active BOOLEAN DEFAULT TRUE,
    created_at DATE DEFAULT (CURRENT_DATE),
    UNIQUE KEY unique_dkim (domain, selector)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Editable content blocks
CREATE TABLE IF NOT EXISTS content_blocks (
    `key` VARCHAR(64) PRIMARY KEY,
    body_md TEXT NOT NULL,
    updated_at DATE DEFAULT (CURRENT_DATE)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Warrant canary
CREATE TABLE IF NOT EXISTS canary (
    id INT PRIMARY KEY DEFAULT 1,
    statement TEXT NOT NULL,
    signed_statement TEXT,
    signed_date DATE DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
