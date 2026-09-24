-- Eigene Hydranten pro Feuerwehr (Import aus Werkkataster / manuell erfasst)
-- Nur für eingeloggte Mitglieder der Feuerwehr sichtbar (z.B. Daten aus dem
-- Raumdatenpool Kanton Luzern, Zugang "beschränkt öffentlich").
-- Wird von admin/hydrants_api.php bei Bedarf automatisch angelegt.

CREATE TABLE IF NOT EXISTS department_hydrants (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    lat           DECIMAL(10,7) NOT NULL,
    lng           DECIMAL(10,7) NOT NULL,
    type          ENUM('underground','pillar','wall','pond','other') NOT NULL DEFAULT 'underground',
    ref           VARCHAR(100) NULL,
    address       VARCHAR(255) NULL,
    notes         VARCHAR(500) NULL,
    source        ENUM('manual','import') NOT NULL DEFAULT 'manual',
    external_id   VARCHAR(100) NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES fire_departments(id) ON DELETE CASCADE,
    UNIQUE KEY uq_dept_external (department_id, external_id),
    INDEX idx_dept   (department_id),
    INDEX idx_coords (lat, lng)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
