-- Migrare: tip vehicul + cuplaje cap tractor / semiremorca
-- Data: 2026-04-17

SET NAMES utf8mb4;

SET @has_tip_vehicul := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'vehicule'
      AND COLUMN_NAME = 'tip_vehicul'
);

SET @sql_add_tip_vehicul := IF(
    @has_tip_vehicul = 0,
    "ALTER TABLE vehicule ADD COLUMN tip_vehicul ENUM('autovehicul', 'camion', 'cap_tractor', 'semiremorca') NOT NULL DEFAULT 'autovehicul' AFTER model",
    'SELECT 1'
);

PREPARE stmt_add_tip_vehicul FROM @sql_add_tip_vehicul;
EXECUTE stmt_add_tip_vehicul;
DEALLOCATE PREPARE stmt_add_tip_vehicul;

UPDATE vehicule
SET tip_vehicul = 'autovehicul'
WHERE tip_vehicul IS NULL OR tip_vehicul = '';

ALTER TABLE vehicule
    MODIFY COLUMN tip_vehicul ENUM('autovehicul', 'camion', 'cap_tractor', 'semiremorca') NOT NULL DEFAULT 'autovehicul';

CREATE TABLE IF NOT EXISTS vehicule_cuplaje (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tractor_id INT UNSIGNED NOT NULL,
    semiremorca_id INT UNSIGNED NOT NULL,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    data_start DATETIME NOT NULL,
    data_end DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_vehicule_cuplaje_tractor_activ (tractor_id, activ),
    INDEX idx_vehicule_cuplaje_semiremorca_activ (semiremorca_id, activ),
    INDEX idx_vehicule_cuplaje_start (data_start),
    CONSTRAINT fk_vehicule_cuplaje_tractor FOREIGN KEY (tractor_id) REFERENCES vehicule(id) ON DELETE CASCADE,
    CONSTRAINT fk_vehicule_cuplaje_semiremorca FOREIGN KEY (semiremorca_id) REFERENCES vehicule(id) ON DELETE CASCADE,
    CONSTRAINT fk_vehicule_cuplaje_created_by FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
