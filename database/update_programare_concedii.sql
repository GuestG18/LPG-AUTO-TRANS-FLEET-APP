-- Migrare: modul Programare Concedii
-- Data: 2026-05-08

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS concedii (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_id INT UNSIGNED NOT NULL,
    tip_concediu ENUM('odihna', 'personal', 'medical', 'fara_plata') NOT NULL,
    data_inceput DATE NOT NULL,
    data_sfarsit DATE NOT NULL,
    inlocuitor_id INT UNSIGNED NULL,
    note TEXT NULL,
    status ENUM('aprobat', 'respins', 'in_asteptare', 'in_asteptare_aprobare') NOT NULL DEFAULT 'in_asteptare',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_concedii_driver (driver_id),
    INDEX idx_concedii_inlocuitor (inlocuitor_id),
    INDEX idx_concedii_status (status),
    INDEX idx_concedii_perioada (data_inceput, data_sfarsit),
    CONSTRAINT fk_concedii_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE RESTRICT,
    CONSTRAINT fk_concedii_inlocuitor FOREIGN KEY (inlocuitor_id) REFERENCES soferi(id) ON DELETE SET NULL,
    CONSTRAINT fk_concedii_created_by FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_concedii_table := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'concedii'
);

SET @has_concedii_driver := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'concedii'
      AND COLUMN_NAME = 'driver_id'
);
SET @sql_add_concedii_driver := IF(
    @has_concedii_driver = 0 AND @has_concedii_table > 0,
    'ALTER TABLE concedii ADD COLUMN driver_id INT UNSIGNED NOT NULL AFTER id',
    'SELECT 1'
);
PREPARE stmt_add_concedii_driver FROM @sql_add_concedii_driver;
EXECUTE stmt_add_concedii_driver;
DEALLOCATE PREPARE stmt_add_concedii_driver;

SET @has_concedii_tip := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'concedii'
      AND COLUMN_NAME = 'tip_concediu'
);
SET @sql_add_concedii_tip := IF(
    @has_concedii_tip = 0 AND @has_concedii_table > 0,
    'ALTER TABLE concedii ADD COLUMN tip_concediu ENUM(''odihna'', ''personal'', ''medical'', ''fara_plata'') NOT NULL AFTER driver_id',
    'SELECT 1'
);
PREPARE stmt_add_concedii_tip FROM @sql_add_concedii_tip;
EXECUTE stmt_add_concedii_tip;
DEALLOCATE PREPARE stmt_add_concedii_tip;

SET @has_concedii_data_start := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'concedii'
      AND COLUMN_NAME = 'data_inceput'
);
SET @sql_add_concedii_data_start := IF(
    @has_concedii_data_start = 0 AND @has_concedii_table > 0,
    'ALTER TABLE concedii ADD COLUMN data_inceput DATE NOT NULL AFTER tip_concediu',
    'SELECT 1'
);
PREPARE stmt_add_concedii_data_start FROM @sql_add_concedii_data_start;
EXECUTE stmt_add_concedii_data_start;
DEALLOCATE PREPARE stmt_add_concedii_data_start;

SET @has_concedii_data_end := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'concedii'
      AND COLUMN_NAME = 'data_sfarsit'
);
SET @sql_add_concedii_data_end := IF(
    @has_concedii_data_end = 0 AND @has_concedii_table > 0,
    'ALTER TABLE concedii ADD COLUMN data_sfarsit DATE NOT NULL AFTER data_inceput',
    'SELECT 1'
);
PREPARE stmt_add_concedii_data_end FROM @sql_add_concedii_data_end;
EXECUTE stmt_add_concedii_data_end;
DEALLOCATE PREPARE stmt_add_concedii_data_end;

SET @has_concedii_inlocuitor := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'concedii'
      AND COLUMN_NAME = 'inlocuitor_id'
);
SET @sql_add_concedii_inlocuitor := IF(
    @has_concedii_inlocuitor = 0 AND @has_concedii_table > 0,
    'ALTER TABLE concedii ADD COLUMN inlocuitor_id INT UNSIGNED NULL AFTER data_sfarsit',
    'SELECT 1'
);
PREPARE stmt_add_concedii_inlocuitor FROM @sql_add_concedii_inlocuitor;
EXECUTE stmt_add_concedii_inlocuitor;
DEALLOCATE PREPARE stmt_add_concedii_inlocuitor;

SET @has_concedii_note := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'concedii'
      AND COLUMN_NAME = 'note'
);
SET @sql_add_concedii_note := IF(
    @has_concedii_note = 0 AND @has_concedii_table > 0,
    'ALTER TABLE concedii ADD COLUMN note TEXT NULL AFTER inlocuitor_id',
    'SELECT 1'
);
PREPARE stmt_add_concedii_note FROM @sql_add_concedii_note;
EXECUTE stmt_add_concedii_note;
DEALLOCATE PREPARE stmt_add_concedii_note;

SET @has_concedii_status := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'concedii'
      AND COLUMN_NAME = 'status'
);
SET @sql_add_concedii_status := IF(
    @has_concedii_status = 0 AND @has_concedii_table > 0,
    'ALTER TABLE concedii ADD COLUMN status ENUM(''aprobat'', ''respins'', ''in_asteptare'', ''in_asteptare_aprobare'') NOT NULL DEFAULT ''in_asteptare'' AFTER note',
    'SELECT 1'
);
PREPARE stmt_add_concedii_status FROM @sql_add_concedii_status;
EXECUTE stmt_add_concedii_status;
DEALLOCATE PREPARE stmt_add_concedii_status;

SET @sql_resize_concedii_status := IF(
    @has_concedii_status > 0,
    'ALTER TABLE concedii MODIFY COLUMN status ENUM(''aprobat'', ''respins'', ''in_asteptare'', ''in_asteptare_aprobare'') NOT NULL DEFAULT ''in_asteptare''',
    'SELECT 1'
);
PREPARE stmt_resize_concedii_status FROM @sql_resize_concedii_status;
EXECUTE stmt_resize_concedii_status;
DEALLOCATE PREPARE stmt_resize_concedii_status;

SET @has_concedii_created_by := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'concedii'
      AND COLUMN_NAME = 'created_by'
);
SET @sql_add_concedii_created_by := IF(
    @has_concedii_created_by = 0 AND @has_concedii_table > 0,
    'ALTER TABLE concedii ADD COLUMN created_by INT UNSIGNED NULL AFTER status',
    'SELECT 1'
);
PREPARE stmt_add_concedii_created_by FROM @sql_add_concedii_created_by;
EXECUTE stmt_add_concedii_created_by;
DEALLOCATE PREPARE stmt_add_concedii_created_by;

SET @has_concedii_created_at := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'concedii'
      AND COLUMN_NAME = 'created_at'
);
SET @sql_add_concedii_created_at := IF(
    @has_concedii_created_at = 0 AND @has_concedii_table > 0,
    'ALTER TABLE concedii ADD COLUMN created_at DATETIME NOT NULL AFTER created_by',
    'SELECT 1'
);
PREPARE stmt_add_concedii_created_at FROM @sql_add_concedii_created_at;
EXECUTE stmt_add_concedii_created_at;
DEALLOCATE PREPARE stmt_add_concedii_created_at;

SET @has_concedii_updated_at := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'concedii'
      AND COLUMN_NAME = 'updated_at'
);
SET @sql_add_concedii_updated_at := IF(
    @has_concedii_updated_at = 0 AND @has_concedii_table > 0,
    'ALTER TABLE concedii ADD COLUMN updated_at DATETIME NOT NULL AFTER created_at',
    'SELECT 1'
);
PREPARE stmt_add_concedii_updated_at FROM @sql_add_concedii_updated_at;
EXECUTE stmt_add_concedii_updated_at;
DEALLOCATE PREPARE stmt_add_concedii_updated_at;

SET @has_idx_concedii_driver := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'concedii'
      AND INDEX_NAME = 'idx_concedii_driver'
);
SET @sql_add_idx_concedii_driver := IF(
    @has_idx_concedii_driver = 0 AND @has_concedii_table > 0,
    'ALTER TABLE concedii ADD INDEX idx_concedii_driver (driver_id)',
    'SELECT 1'
);
PREPARE stmt_add_idx_concedii_driver FROM @sql_add_idx_concedii_driver;
EXECUTE stmt_add_idx_concedii_driver;
DEALLOCATE PREPARE stmt_add_idx_concedii_driver;

SET @has_idx_concedii_inlocuitor := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'concedii'
      AND INDEX_NAME = 'idx_concedii_inlocuitor'
);
SET @sql_add_idx_concedii_inlocuitor := IF(
    @has_idx_concedii_inlocuitor = 0 AND @has_concedii_table > 0,
    'ALTER TABLE concedii ADD INDEX idx_concedii_inlocuitor (inlocuitor_id)',
    'SELECT 1'
);
PREPARE stmt_add_idx_concedii_inlocuitor FROM @sql_add_idx_concedii_inlocuitor;
EXECUTE stmt_add_idx_concedii_inlocuitor;
DEALLOCATE PREPARE stmt_add_idx_concedii_inlocuitor;

SET @has_idx_concedii_status := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'concedii'
      AND INDEX_NAME = 'idx_concedii_status'
);
SET @sql_add_idx_concedii_status := IF(
    @has_idx_concedii_status = 0 AND @has_concedii_table > 0,
    'ALTER TABLE concedii ADD INDEX idx_concedii_status (status)',
    'SELECT 1'
);
PREPARE stmt_add_idx_concedii_status FROM @sql_add_idx_concedii_status;
EXECUTE stmt_add_idx_concedii_status;
DEALLOCATE PREPARE stmt_add_idx_concedii_status;

SET @has_idx_concedii_perioada := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'concedii'
      AND INDEX_NAME = 'idx_concedii_perioada'
);
SET @sql_add_idx_concedii_perioada := IF(
    @has_idx_concedii_perioada = 0 AND @has_concedii_table > 0,
    'ALTER TABLE concedii ADD INDEX idx_concedii_perioada (data_inceput, data_sfarsit)',
    'SELECT 1'
);
PREPARE stmt_add_idx_concedii_perioada FROM @sql_add_idx_concedii_perioada;
EXECUTE stmt_add_idx_concedii_perioada;
DEALLOCATE PREPARE stmt_add_idx_concedii_perioada;

SET @has_fk_concedii_driver := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'concedii'
      AND CONSTRAINT_NAME = 'fk_concedii_driver'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_add_fk_concedii_driver := IF(
    @has_fk_concedii_driver = 0 AND @has_concedii_table > 0,
    'ALTER TABLE concedii ADD CONSTRAINT fk_concedii_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE RESTRICT',
    'SELECT 1'
);
PREPARE stmt_add_fk_concedii_driver FROM @sql_add_fk_concedii_driver;
EXECUTE stmt_add_fk_concedii_driver;
DEALLOCATE PREPARE stmt_add_fk_concedii_driver;

SET @has_fk_concedii_inlocuitor := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'concedii'
      AND CONSTRAINT_NAME = 'fk_concedii_inlocuitor'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_add_fk_concedii_inlocuitor := IF(
    @has_fk_concedii_inlocuitor = 0 AND @has_concedii_table > 0,
    'ALTER TABLE concedii ADD CONSTRAINT fk_concedii_inlocuitor FOREIGN KEY (inlocuitor_id) REFERENCES soferi(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt_add_fk_concedii_inlocuitor FROM @sql_add_fk_concedii_inlocuitor;
EXECUTE stmt_add_fk_concedii_inlocuitor;
DEALLOCATE PREPARE stmt_add_fk_concedii_inlocuitor;

SET @has_fk_concedii_created_by := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'concedii'
      AND CONSTRAINT_NAME = 'fk_concedii_created_by'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_add_fk_concedii_created_by := IF(
    @has_fk_concedii_created_by = 0 AND @has_concedii_table > 0,
    'ALTER TABLE concedii ADD CONSTRAINT fk_concedii_created_by FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt_add_fk_concedii_created_by FROM @sql_add_fk_concedii_created_by;
EXECUTE stmt_add_fk_concedii_created_by;
DEALLOCATE PREPARE stmt_add_fk_concedii_created_by;
