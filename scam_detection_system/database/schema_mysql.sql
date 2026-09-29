-- ------------------------------------------------------------------
-- schema_mysql.sql - The same schema for MySQL 8 (future migration).
-- Differences from SQLite: AUTO_INCREMENT instead of AUTOINCREMENT,
-- explicit engine/charset, and utf8mb4 so messages with emoji work.
-- Python code would use a MySQL driver (e.g. mysql-connector-python)
-- and "%s" instead of "?" as the query placeholder.
-- ------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS analysis_history (
    id               INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    message          TEXT         NOT NULL,
    prediction       VARCHAR(20)  NOT NULL CHECK (prediction IN ('scam', 'legitimate')),
    confidence       DOUBLE       NOT NULL CHECK (confidence BETWEEN 0 AND 1),
    scam_probability DOUBLE       NOT NULL CHECK (scam_probability BETWEEN 0 AND 1),
    indicators       TEXT         NOT NULL,
    source           VARCHAR(10)  NOT NULL DEFAULT 'web',
    model_name       VARCHAR(100),
    analyzed_at      VARCHAR(32)  NOT NULL,
    INDEX idx_history_analyzed_at (analyzed_at),
    INDEX idx_history_prediction (prediction)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
    id             INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username       VARCHAR(50)  NOT NULL UNIQUE,
    password_hash  VARCHAR(255) NOT NULL,
    created_at     VARCHAR(32)  NOT NULL,
    last_login_at  VARCHAR(32)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
