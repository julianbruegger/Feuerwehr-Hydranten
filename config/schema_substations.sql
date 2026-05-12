-- Schema: Gebäude-Trafostation-Zuordnungen
-- Ermöglicht Admins, Gebäude/Adressen einer OSM-Trafostation zuzuordnen.
-- Abhängigkeit: fire_departments Tabelle muss bereits existieren.

CREATE TABLE IF NOT EXISTS substation_assignments (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_id        INT NOT NULL,
    substation_osm_type  VARCHAR(10)  NOT NULL,          -- 'node' | 'way' | 'relation'
    substation_osm_id    BIGINT       NOT NULL,
    substation_name      VARCHAR(255),                   -- optionaler Anzeigename
    building_name        VARCHAR(255) NOT NULL,
    building_address     TEXT,
    building_lat         DECIMAL(10, 8),
    building_lng         DECIMAL(11, 8),
    notes                TEXT,
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES fire_departments(id) ON DELETE CASCADE,
    INDEX idx_dept (department_id),
    INDEX idx_dept_sub (department_id, substation_osm_type, substation_osm_id)
);
