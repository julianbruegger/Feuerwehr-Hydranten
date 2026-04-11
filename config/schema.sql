-- =====================================================================
-- Hydrantennavigator – Datenbank-Schema
-- Für Hostpoint MariaDB
-- Ausführen: einmalig über phpMyAdmin oder per CLI
-- =====================================================================

-- Feuerwehren (Mandanten)
CREATE TABLE IF NOT EXISTS fire_departments (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,                  -- z.B. "FW Luzern"
    password_hash VARCHAR(255) NOT NULL,                -- bcrypt via PHP
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Langzeit-Auth-Tokens (für localStorage-Cache)
-- Token wird beim Login generiert, bis zum Ablauf gültig
CREATE TABLE IF NOT EXISTS auth_tokens (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    department_id   INT NOT NULL,
    token           CHAR(64) NOT NULL UNIQUE,           -- 32 Byte Hex, random
    expires_at      DATETIME NOT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES fire_departments(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sensible Einträge (Notizen pro Objekt, pro Feuerwehr)
CREATE TABLE IF NOT EXISTS sensitive_notes (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    department_id   INT NOT NULL,
    title           VARCHAR(255) NOT NULL,              -- Objektname / Adresse
    address         VARCHAR(255),
    lat             DECIMAL(10, 7),                     -- Koordinaten für Kartenmarker
    lng             DECIMAL(10, 7),
    category        ENUM('schluessel','gefahrgut','gebaeude','kontakt','sonstiges')
                    DEFAULT 'sonstiges',
    content         TEXT,                               -- Freitext-Notiz
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES fire_departments(id) ON DELETE CASCADE,
    INDEX idx_dept (department_id),
    INDEX idx_coords (lat, lng)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Abgelaufene Tokens regelmäßig bereinigen (optionaler Event)
-- CREATE EVENT cleanup_tokens ON SCHEDULE EVERY 1 DAY DO
--   DELETE FROM auth_tokens WHERE expires_at < NOW();
