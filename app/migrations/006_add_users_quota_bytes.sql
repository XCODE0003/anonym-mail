-- Per-user mail quota (Dovecot user_query + app UserService)
ALTER TABLE users
    ADD COLUMN quota_bytes BIGINT NOT NULL DEFAULT 1073741824
    AFTER password_hash;
