-- Configuratii de vehicul (pagina Auto): generale pe tip + axe, plus diferentele fiecarui vehicul.
-- Aplicate si la rulare de VehicleStructureService::prepare(), care completeaza configuratiile implicite.

CREATE TABLE IF NOT EXISTS mentenanta_auto_structuri (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nume VARCHAR(120) NOT NULL,
    vehicle_type VARCHAR(20) NOT NULL,
    axle_count TINYINT UNSIGNED NOT NULL,
    axe_json TEXT NOT NULL,
    implicita_cheie VARCHAR(30) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_structuri_nume (nume),
    UNIQUE KEY uk_structuri_implicita (implicita_cheie),
    KEY idx_structuri_tip (vehicle_type, axle_count)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mentenanta_auto_structuri_sisteme (
    structura_id INT UNSIGNED NOT NULL,
    category VARCHAR(40) NOT NULL,
    PRIMARY KEY (structura_id, category),
    CONSTRAINT fk_structuri_sisteme FOREIGN KEY (structura_id) REFERENCES mentenanta_auto_structuri (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mentenanta_auto_structuri_componente (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    structura_id INT UNSIGNED NOT NULL,
    component_key VARCHAR(80) NOT NULL,
    axle_kinds VARCHAR(60) NULL,
    monitoring_type VARCHAR(40) NULL,
    interval_value VARCHAR(40) NULL,
    warning_value VARCHAR(40) NULL,
    critical_value VARCHAR(40) NULL,
    lifetime_value VARCHAR(40) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_structuri_componenta (structura_id, component_key),
    CONSTRAINT fk_structuri_componente FOREIGN KEY (structura_id) REFERENCES mentenanta_auto_structuri (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vehicule_structura_asignare (
    vehicle_id INT UNSIGNED NOT NULL PRIMARY KEY,
    structura_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_structura_asignare_vehicul FOREIGN KEY (vehicle_id) REFERENCES vehicule (id) ON DELETE CASCADE,
    CONSTRAINT fk_structura_asignare_structura FOREIGN KEY (structura_id) REFERENCES mentenanta_auto_structuri (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vehicule_structura_diferente (
    vehicle_id INT UNSIGNED NOT NULL,
    tip VARCHAR(12) NOT NULL,
    element VARCHAR(80) NOT NULL,
    actiune VARCHAR(10) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (vehicle_id, tip, element),
    CONSTRAINT fk_structura_diferente_vehicul FOREIGN KEY (vehicle_id) REFERENCES vehicule (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE mentenanta_auto_configurari ADD COLUMN axle_kinds VARCHAR(60) NULL AFTER category_id;
