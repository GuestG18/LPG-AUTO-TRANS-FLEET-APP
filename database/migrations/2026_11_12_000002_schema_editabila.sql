-- Editorul schemei pe pagina Auto: sisteme proprii (categorii noi pe schema) si pozitiile punctelor.
-- Se aplica si la rulare (VehicleStructureService::ensureSchemeTables) la prima deschidere a paginii Auto.

CREATE TABLE IF NOT EXISTS mentenanta_auto_sisteme_custom (
    cheie VARCHAR(40) NOT NULL PRIMARY KEY,
    nume VARCHAR(60) NOT NULL,
    icon VARCHAR(40) NOT NULL,
    culoare CHAR(7) NOT NULL,
    tipuri VARCHAR(60) NOT NULL,
    scope VARCHAR(12) NOT NULL DEFAULT 'vehicle',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_sisteme_custom_nume (nume)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mentenanta_auto_sisteme_custom_componente (
    cheie VARCHAR(40) NOT NULL,
    component_key VARCHAR(80) NOT NULL,
    PRIMARY KEY (cheie, component_key),
    CONSTRAINT fk_sisteme_custom_componente FOREIGN KEY (cheie) REFERENCES mentenanta_auto_sisteme_custom (cheie) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mentenanta_auto_structuri_pozitii (
    structura_id INT UNSIGNED NOT NULL,
    slot_id VARCHAR(80) NOT NULL,
    x DECIMAL(7,3) NOT NULL,
    y DECIMAL(7,3) NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (structura_id, slot_id),
    CONSTRAINT fk_structuri_pozitii FOREIGN KEY (structura_id) REFERENCES mentenanta_auto_structuri (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Piese puse individual pe schema + sistemul ales pentru ele.
CREATE TABLE IF NOT EXISTS mentenanta_auto_structuri_piese (
    structura_id INT UNSIGNED NOT NULL,
    component_key VARCHAR(80) NOT NULL,
    category VARCHAR(40) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (structura_id, component_key),
    CONSTRAINT fk_structuri_piese FOREIGN KEY (structura_id) REFERENCES mentenanta_auto_structuri (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mentenanta_auto_componente_sistem (
    component_key VARCHAR(80) NOT NULL,
    category VARCHAR(40) NOT NULL,
    PRIMARY KEY (component_key, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
