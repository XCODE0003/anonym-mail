-- PostgreSQL initialization script
-- This runs on first container start

-- Enable required extensions
CREATE EXTENSION IF NOT EXISTS citext;

-- Grant privileges (if user doesn't own the database)
-- This is a placeholder; actual migrations are in app/migrations/
