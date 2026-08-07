-- Migration v4: e-mail onboarding — register a department or get invited.
-- Run once via phpMyAdmin or CLI after migration_v3.sql

-- 1) Store an owner e-mail + verification status on each department.
ALTER TABLE fire_departments
    ADD COLUMN email             VARCHAR(255) NULL AFTER name,
    ADD COLUMN email_verified_at DATETIME     NULL AFTER email;

-- 2) Allow tokens created by the new flows (magic-link invites, verified register).
ALTER TABLE auth_tokens
    MODIFY COLUMN source ENUM('password','magic','admin','invite','register')
        NOT NULL DEFAULT 'password';

-- 3) E-mail verification links for freshly registered departments.
CREATE TABLE IF NOT EXISTS email_verifications (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    email         VARCHAR(255) NOT NULL,
    token         CHAR(64) NOT NULL UNIQUE,
    expires_at    DATETIME NOT NULL,
    used_at       DATETIME NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES fire_departments(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) Invitations into an existing department (delivered as magic links).
CREATE TABLE IF NOT EXISTS invitations (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    email         VARCHAR(255) NOT NULL,
    token         CHAR(64) NOT NULL UNIQUE,       -- same value as the issued auth_tokens.token
    status        ENUM('pending','accepted','revoked') NOT NULL DEFAULT 'pending',
    created_by    INT NULL,                       -- auth_tokens.id of the inviter
    expires_at    DATETIME NOT NULL,
    accepted_at   DATETIME NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES fire_departments(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_dept  (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
