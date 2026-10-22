-- Migration v5: personal user accounts + department memberships.
-- "Register as a person (Julian), then create OR join a department."
-- Run once via phpMyAdmin or CLI after migration_v4.sql

-- 1) Personal accounts. Login identity is the e-mail address.
CREATE TABLE IF NOT EXISTS users (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    name              VARCHAR(255) NOT NULL,
    email             VARCHAR(255) NOT NULL UNIQUE,
    password_hash     VARCHAR(255) NOT NULL,
    email_verified_at DATETIME     NULL,
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Which users belong to which departments (and their role).
CREATE TABLE IF NOT EXISTS memberships (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    department_id INT NOT NULL,
    role          ENUM('owner','member') NOT NULL DEFAULT 'member',
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_dept (user_id, department_id),
    FOREIGN KEY (user_id)       REFERENCES users(id)            ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES fire_departments(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_dept (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) A token now belongs to a user; department_id is the *active* department
--    (null until the user creates or joins one).
ALTER TABLE auth_tokens
    ADD COLUMN user_id INT NULL AFTER department_id,
    ADD INDEX idx_user (user_id),
    ADD CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- 4) E-mail verification now confirms a *user* (v4 confirmed a department).
ALTER TABLE email_verifications
    ADD COLUMN user_id INT NULL AFTER department_id,
    MODIFY COLUMN department_id INT NULL;

-- 5) Departments no longer log in directly — the password becomes legacy/optional.
ALTER TABLE fire_departments
    MODIFY COLUMN password_hash VARCHAR(255) NULL;
