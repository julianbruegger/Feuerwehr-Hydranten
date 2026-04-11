-- WasserTransportPlan-Tabellen
-- Diese SQL-Statements zur bestehenden Datenbank hinzufügen (via phpMyAdmin)

CREATE TABLE IF NOT EXISTS wasser_transport_plans (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    department_id   INT NOT NULL,
    name            VARCHAR(255) NOT NULL,
    description     TEXT,
    lat             DECIMAL(10, 7),
    lng             DECIMAL(10, 7),
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES fire_departments(id) ON DELETE CASCADE,
    INDEX idx_dept (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wtp_files (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    plan_id         INT NOT NULL,
    original_name   VARCHAR(255) NOT NULL,
    stored_name     VARCHAR(255) NOT NULL UNIQUE,  -- UUID-basierter Dateiname
    mime_type       VARCHAR(100),
    file_size       INT,
    uploaded_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plan_id) REFERENCES wasser_transport_plans(id) ON DELETE CASCADE,
    INDEX idx_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
