-- WTP Kartenobjekte – Fahrzeuge, Schlauchleitungen, Texte pro Plan
-- Diese SQL-Statements zur bestehenden Datenbank hinzufügen

CREATE TABLE IF NOT EXISTS wtp_map_objects (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    plan_id    INT NOT NULL,
    type       ENUM('TLF','Motorspritze') NOT NULL,
    name       VARCHAR(255) NOT NULL,
    lat        DECIMAL(10,7) NOT NULL,
    lng        DECIMAL(10,7) NOT NULL,
    FOREIGN KEY (plan_id) REFERENCES wasser_transport_plans(id) ON DELETE CASCADE,
    INDEX idx_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wtp_map_hoses (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    plan_id     INT NOT NULL,
    coordinates JSON NOT NULL,
    FOREIGN KEY (plan_id) REFERENCES wasser_transport_plans(id) ON DELETE CASCADE,
    INDEX idx_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wtp_map_texts (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    plan_id INT NOT NULL,
    label   VARCHAR(500) NOT NULL,
    lat     DECIMAL(10,7) NOT NULL,
    lng     DECIMAL(10,7) NOT NULL,
    FOREIGN KEY (plan_id) REFERENCES wasser_transport_plans(id) ON DELETE CASCADE,
    INDEX idx_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
