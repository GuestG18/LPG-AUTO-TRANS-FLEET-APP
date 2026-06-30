-- Migrare: modul Alimentare / Fuel Consumption Management
-- T0 este stare initiala de calcul si NU se include in cheltuieli.

SET @has_tip_inregistrare := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alimentari' AND COLUMN_NAME = 'tip_inregistrare'
);
SET @sql := IF(@has_tip_inregistrare = 0,
    "ALTER TABLE alimentari ADD COLUMN tip_inregistrare ENUM('alimentare','t0') NOT NULL DEFAULT 'alimentare' AFTER id",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_cursa_id := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alimentari' AND COLUMN_NAME = 'cursa_id'
);
SET @sql := IF(@has_cursa_id = 0,
    "ALTER TABLE alimentari ADD COLUMN cursa_id INT UNSIGNED NULL AFTER driver_id",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_pret_litru := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alimentari' AND COLUMN_NAME = 'pret_litru'
);
SET @sql := IF(@has_pret_litru = 0,
    "ALTER TABLE alimentari ADD COLUMN pret_litru DECIMAL(10,2) NULL AFTER litri",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_furnizor := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alimentari' AND COLUMN_NAME = 'furnizor'
);
SET @sql := IF(@has_furnizor = 0,
    "ALTER TABLE alimentari ADD COLUMN furnizor VARCHAR(190) NULL AFTER cost_total",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_fuel_state := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alimentari' AND COLUMN_NAME = 'fuel_state'
);
SET @sql := IF(@has_fuel_state = 0,
    "ALTER TABLE alimentari ADD COLUMN fuel_state DECIMAL(10,2) NULL AFTER km_alimentare",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_full_flag := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alimentari' AND COLUMN_NAME = 'full_flag'
);
SET @sql := IF(@has_full_flag = 0,
    "ALTER TABLE alimentari ADD COLUMN full_flag TINYINT(1) NOT NULL DEFAULT 0 AFTER fuel_state",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_t0_manual := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alimentari' AND COLUMN_NAME = 't0_manual'
);
SET @sql := IF(@has_t0_manual = 0,
    "ALTER TABLE alimentari ADD COLUMN t0_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER full_flag",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_factura_original := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alimentari' AND COLUMN_NAME = 'factura_original'
);
SET @sql := IF(@has_factura_original = 0,
    "ALTER TABLE alimentari ADD COLUMN factura_original VARCHAR(255) NULL AFTER observatii",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_factura_stocata := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alimentari' AND COLUMN_NAME = 'factura_stocata'
);
SET @sql := IF(@has_factura_stocata = 0,
    "ALTER TABLE alimentari ADD COLUMN factura_stocata VARCHAR(255) NULL AFTER factura_original",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_factura_mime_type := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alimentari' AND COLUMN_NAME = 'factura_mime_type'
);
SET @sql := IF(@has_factura_mime_type = 0,
    "ALTER TABLE alimentari ADD COLUMN factura_mime_type VARCHAR(150) NULL AFTER factura_stocata",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_factura_file_size := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alimentari' AND COLUMN_NAME = 'factura_file_size'
);
SET @sql := IF(@has_factura_file_size = 0,
    "ALTER TABLE alimentari ADD COLUMN factura_file_size INT UNSIGNED NULL AFTER factura_mime_type",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE alimentari
SET pret_litru = ROUND(cost_total / NULLIF(litri, 0), 2)
WHERE tip_inregistrare = 'alimentare'
  AND (pret_litru IS NULL OR pret_litru = 0)
  AND litri > 0
  AND cost_total > 0;

SET @has_idx_alimentari_tip := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alimentari' AND INDEX_NAME = 'idx_alimentari_tip_data'
);
SET @sql := IF(@has_idx_alimentari_tip = 0,
    "ALTER TABLE alimentari ADD INDEX idx_alimentari_tip_data (tip_inregistrare, data_alimentare)",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx_alimentari_cursa := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alimentari' AND INDEX_NAME = 'idx_alimentari_cursa'
);
SET @sql := IF(@has_idx_alimentari_cursa = 0,
    "ALTER TABLE alimentari ADD INDEX idx_alimentari_cursa (cursa_id)",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx_alimentari_furnizor := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alimentari' AND INDEX_NAME = 'idx_alimentari_furnizor'
);
SET @sql := IF(@has_idx_alimentari_furnizor = 0,
    "ALTER TABLE alimentari ADD INDEX idx_alimentari_furnizor (furnizor)",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
