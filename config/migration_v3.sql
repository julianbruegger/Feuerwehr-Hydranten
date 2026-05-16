-- Migration v3: token metadata + login-history
-- Run once via phpMyAdmin or CLI after migration_v2.sql

ALTER TABLE auth_tokens
    ADD COLUMN source       ENUM('password','magic','admin') NOT NULL DEFAULT 'password' AFTER is_admin,
    ADD COLUMN label        VARCHAR(255) NULL AFTER source,
    ADD COLUMN revoked_at   DATETIME NULL AFTER label,
    ADD COLUMN last_seen_at DATETIME NULL AFTER revoked_at,
    ADD INDEX  idx_revoked      (revoked_at),
    ADD INDEX  idx_dept_active  (department_id, revoked_at);

CREATE TABLE IF NOT EXISTS login_log (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NULL,
    token_id      INT NULL,
    is_admin      TINYINT(1) NOT NULL DEFAULT 0,
    source        VARCHAR(20) NOT NULL,
    ip            VARCHAR(45) NULL,
    user_agent    VARCHAR(500) NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES fire_departments(id) ON DELETE SET NULL,
    FOREIGN KEY (token_id)      REFERENCES auth_tokens(id)      ON DELETE SET NULL,
    INDEX idx_dept_created (department_id, created_at),
    INDEX idx_created      (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
