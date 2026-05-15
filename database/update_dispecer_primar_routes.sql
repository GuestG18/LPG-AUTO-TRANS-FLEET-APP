SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS configurare_rute_primar (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    beneficiar_id INT UNSIGNED NOT NULL,
    loc_incarcare_id INT UNSIGNED NOT NULL,
    zona_distributie_id INT UNSIGNED NOT NULL,
    km_tarifare INT UNSIGNED NOT NULL DEFAULT 0,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_config_rute_primar_beneficiar_loc_zona (beneficiar_id, loc_incarcare_id, zona_distributie_id),
    INDEX idx_config_rute_primar_beneficiar (beneficiar_id),
    INDEX idx_config_rute_primar_loc (loc_incarcare_id),
    INDEX idx_config_rute_primar_zona (zona_distributie_id),
    INDEX idx_config_rute_primar_activ (activ),
    CONSTRAINT fk_config_rute_primar_beneficiar FOREIGN KEY (beneficiar_id) REFERENCES configurare_beneficiari_transport(id) ON DELETE CASCADE,
    CONSTRAINT fk_config_rute_primar_loc FOREIGN KEY (loc_incarcare_id) REFERENCES configurare_locuri_incarcare(id) ON DELETE CASCADE,
    CONSTRAINT fk_config_rute_primar_zona FOREIGN KEY (zona_distributie_id) REFERENCES configurare_zone_distributie(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_km_totali := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'km_totali'
);
SET @sql_add_km_totali := IF(
    @has_km_totali = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN km_totali INT UNSIGNED NULL AFTER km_cursa',
    'SELECT 1'
);
PREPARE stmt_add_km_totali FROM @sql_add_km_totali;
EXECUTE stmt_add_km_totali;
DEALLOCATE PREPARE stmt_add_km_totali;
