-- ------------------------------------------------------------------
-- schema.sql - SQLite database schema for the Scam Detection System.
-- Run automatically by app.py when the app starts ("IF NOT EXISTS"
-- means existing tables and data are never deleted).
--
-- Portability to MySQL: standard column types and ISO-8601 text
-- timestamps (written by Python) are used. The MySQL version of this
-- schema is in schema_mysql.sql.
-- ------------------------------------------------------------------

-- One row per analysed message.
-- No personal data about the USER is stored (no IP address, browser or
-- account). Phone numbers and e-mail addresses inside the message are
-- replaced with placeholders before saving (see db.redact_personal_data).
CREATE TABLE IF NOT EXISTS analysis_history (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    message          TEXT         NOT NULL,
    prediction       VARCHAR(20)  NOT NULL CHECK (prediction IN ('scam', 'legitimate')),
    confidence       REAL         NOT NULL CHECK (confidence BETWEEN 0 AND 1),
    scam_probability REAL         NOT NULL CHECK (scam_probability BETWEEN 0 AND 1),
    indicators       TEXT         NOT NULL DEFAULT '[]',   -- JSON list of indicator names
    source           VARCHAR(10)  NOT NULL DEFAULT 'web',  -- 'web' form or 'api'
    model_name       VARCHAR(100),
    analyzed_at      VARCHAR(32)  NOT NULL                 -- UTC, e.g. 2026-09-28T14:03:00+00:00
);

CREATE INDEX IF NOT EXISTS idx_history_analyzed_at ON analysis_history (analyzed_at);
CREATE INDEX IF NOT EXISTS idx_history_prediction  ON analysis_history (prediction);

-- Administrator accounts. Passwords are stored ONLY as salted hashes.
CREATE TABLE IF NOT EXISTS admin_users (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    username       VARCHAR(50)  NOT NULL UNIQUE,
    password_hash  VARCHAR(255) NOT NULL,
    created_at     VARCHAR(32)  NOT NULL,
    last_login_at  VARCHAR(32)
);
