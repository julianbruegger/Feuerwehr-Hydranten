-- ============================================================
-- Hydrantennavigator – Fresh Docker init (all migrations applied)
-- ============================================================

CREATE TABLE IF NOT EXISTS fire_departments (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    name              VARCHAR(255) NOT NULL,
    email             VARCHAR(255) NULL,
    email_verified_at DATETIME     NULL,
    password_hash     VARCHAR(255) NULL,
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Personal accounts (login identity = e-mail).
CREATE TABLE IF NOT EXISTS users (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    name              VARCHAR(255) NOT NULL,
    email             VARCHAR(255) NOT NULL UNIQUE,
    password_hash     VARCHAR(255) NOT NULL,
    email_verified_at DATETIME     NULL,
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User ↔ department membership.
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

CREATE TABLE IF NOT EXISTS auth_tokens (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    department_id   INT NULL,
    user_id         INT NULL,
    is_admin        TINYINT(1) NOT NULL DEFAULT 0,
    source          ENUM('password','magic','admin','invite','register') NOT NULL DEFAULT 'password',
    label           VARCHAR(255) NULL,
    revoked_at      DATETIME NULL,
    last_seen_at    DATETIME NULL,
    token           CHAR(64) NOT NULL UNIQUE,
    expires_at      DATETIME NOT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES fire_departments(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)       REFERENCES users(id)            ON DELETE CASCADE,
    INDEX idx_token       (token),
    INDEX idx_expires     (expires_at),
    INDEX idx_revoked     (revoked_at),
    INDEX idx_user        (user_id),
    INDEX idx_dept_active (department_id, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sensitive_notes (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    title         VARCHAR(255) NOT NULL,
    address       VARCHAR(255),
    lat           DECIMAL(10, 7),
    lng           DECIMAL(10, 7),
    category      ENUM('schluessel','gefahrgut','gebaeude','kontakt','sonstiges') DEFAULT 'sonstiges',
    content       TEXT,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES fire_departments(id) ON DELETE CASCADE,
    INDEX idx_dept   (department_id),
    INDEX idx_coords (lat, lng)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wasser_transport_plans (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    name          VARCHAR(255) NOT NULL,
    description   TEXT,
    lat           DECIMAL(10, 7),
    lng           DECIMAL(10, 7),
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES fire_departments(id) ON DELETE CASCADE,
    INDEX idx_dept (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wtp_files (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    plan_id       INT NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name   VARCHAR(255) NOT NULL UNIQUE,
    mime_type     VARCHAR(100),
    file_size     INT,
    uploaded_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plan_id) REFERENCES wasser_transport_plans(id) ON DELETE CASCADE,
    INDEX idx_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS substation_assignments (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    department_id       INT NOT NULL,
    substation_osm_type VARCHAR(10)  NOT NULL,
    substation_osm_id   BIGINT       NOT NULL,
    substation_name     VARCHAR(255),
    building_name       VARCHAR(255) NOT NULL,
    building_address    TEXT,
    building_lat        DECIMAL(10, 8),
    building_lng        DECIMAL(11, 8),
    notes               TEXT,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES fire_departments(id) ON DELETE CASCADE,
    INDEX idx_dept     (department_id),
    INDEX idx_dept_sub (department_id, substation_osm_type, substation_osm_id)
);

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

CREATE TABLE IF NOT EXISTS email_verifications (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NULL,
    user_id       INT NULL,
    email         VARCHAR(255) NOT NULL,
    token         CHAR(64) NOT NULL UNIQUE,
    expires_at    DATETIME NOT NULL,
    used_at       DATETIME NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES fire_departments(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)       REFERENCES users(id)            ON DELETE CASCADE,
    INDEX idx_token   (token),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invitations (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    email         VARCHAR(255) NOT NULL,
    token         CHAR(64) NOT NULL UNIQUE,
    status        ENUM('pending','accepted','revoked') NOT NULL DEFAULT 'pending',
    created_by    INT NULL,
    expires_at    DATETIME NOT NULL,
    accepted_at   DATETIME NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES fire_departments(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_dept  (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Test data: one fire department + one verified user (Julian), owner of it.
-- User login: julian@example.com / test123
INSERT IGNORE INTO fire_departments (id, name, password_hash) VALUES
(1, 'FW Teststadt', NULL);

INSERT IGNORE INTO users (id, name, email, password_hash, email_verified_at) VALUES
(1, 'Julian', 'julian@example.com', '$2y$10$RnzhDX5kc58QO57pxP5qVuz47nJhQhySyZnde.tKDkmYZ.PqGNPyG', NOW());

INSERT IGNORE INTO memberships (user_id, department_id, role) VALUES
(1, 1, 'owner');
