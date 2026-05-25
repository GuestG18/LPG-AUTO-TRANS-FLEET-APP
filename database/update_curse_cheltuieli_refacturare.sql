-- Migrare: adauga campul de refacturare pentru cheltuieli cursa
SET NAMES utf8mb4;

SET @has_curse_cheltuieli_table := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_cheltuieli'
);

SET @has_expense_refacturare := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_cheltuieli'
      AND COLUMN_NAME = 'refacturare_tip_cheltuiala'
);

SET @sql_add_expense_refacturare := IF(
    @has_curse_cheltuieli_table > 0 AND @has_expense_refacturare = 0,
    'ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_tip_cheltuiala ENUM(''motorina'', ''taxe_drum'', ''diurna'', ''service'', ''alte'') NULL AFTER tip_cheltuiala',
    'SELECT 1'
);

PREPARE stmt_add_expense_refacturare FROM @sql_add_expense_refacturare;
EXECUTE stmt_add_expense_refacturare;
DEALLOCATE PREPARE stmt_add_expense_refacturare;

SET @has_expense_refacturare_detalii := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_cheltuieli'
      AND COLUMN_NAME = 'refacturare_detalii'
);

SET @sql_add_expense_refacturare_detalii := IF(
    @has_curse_cheltuieli_table > 0 AND @has_expense_refacturare_detalii = 0,
    'ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_detalii TEXT NULL AFTER refacturare_tip_cheltuiala',
    'SELECT 1'
);

PREPARE stmt_add_expense_refacturare_detalii FROM @sql_add_expense_refacturare_detalii;
EXECUTE stmt_add_expense_refacturare_detalii;
DEALLOCATE PREPARE stmt_add_expense_refacturare_detalii;

SET @has_expense_refacturare_suma := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curse_cheltuieli' AND COLUMN_NAME = 'refacturare_suma'
);
SET @sql_add_expense_refacturare_suma := IF(
    @has_curse_cheltuieli_table > 0 AND @has_expense_refacturare_suma = 0,
    'ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_suma DECIMAL(12,2) NULL AFTER refacturare_detalii',
    'SELECT 1'
);
PREPARE stmt_add_expense_refacturare_suma FROM @sql_add_expense_refacturare_suma;
EXECUTE stmt_add_expense_refacturare_suma;
DEALLOCATE PREPARE stmt_add_expense_refacturare_suma;

SET @has_expense_refacturare_data := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curse_cheltuieli' AND COLUMN_NAME = 'refacturare_data'
);
SET @sql_add_expense_refacturare_data := IF(
    @has_curse_cheltuieli_table > 0 AND @has_expense_refacturare_data = 0,
    'ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_data DATE NULL AFTER refacturare_suma',
    'SELECT 1'
);
PREPARE stmt_add_expense_refacturare_data FROM @sql_add_expense_refacturare_data;
EXECUTE stmt_add_expense_refacturare_data;
DEALLOCATE PREPARE stmt_add_expense_refacturare_data;

SET @has_expense_refacturare_observatii := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curse_cheltuieli' AND COLUMN_NAME = 'refacturare_observatii'
);
SET @sql_add_expense_refacturare_observatii := IF(
    @has_curse_cheltuieli_table > 0 AND @has_expense_refacturare_observatii = 0,
    'ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_observatii TEXT NULL AFTER refacturare_data',
    'SELECT 1'
);
PREPARE stmt_add_expense_refacturare_observatii FROM @sql_add_expense_refacturare_observatii;
EXECUTE stmt_add_expense_refacturare_observatii;
DEALLOCATE PREPARE stmt_add_expense_refacturare_observatii;

SET @has_expense_refacturare_document_path := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curse_cheltuieli' AND COLUMN_NAME = 'refacturare_document_path'
);
SET @sql_add_expense_refacturare_document_path := IF(
    @has_curse_cheltuieli_table > 0 AND @has_expense_refacturare_document_path = 0,
    'ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_document_path VARCHAR(255) NULL AFTER refacturare_observatii',
    'SELECT 1'
);
PREPARE stmt_add_expense_refacturare_document_path FROM @sql_add_expense_refacturare_document_path;
EXECUTE stmt_add_expense_refacturare_document_path;
DEALLOCATE PREPARE stmt_add_expense_refacturare_document_path;

SET @has_expense_refacturare_document_original_name := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curse_cheltuieli' AND COLUMN_NAME = 'refacturare_document_original_name'
);
SET @sql_add_expense_refacturare_document_original_name := IF(
    @has_curse_cheltuieli_table > 0 AND @has_expense_refacturare_document_original_name = 0,
    'ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_document_original_name VARCHAR(255) NULL AFTER refacturare_document_path',
    'SELECT 1'
);
PREPARE stmt_add_expense_refacturare_document_original_name FROM @sql_add_expense_refacturare_document_original_name;
EXECUTE stmt_add_expense_refacturare_document_original_name;
DEALLOCATE PREPARE stmt_add_expense_refacturare_document_original_name;

SET @has_expense_refacturare_document_mime_type := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curse_cheltuieli' AND COLUMN_NAME = 'refacturare_document_mime_type'
);
SET @sql_add_expense_refacturare_document_mime_type := IF(
    @has_curse_cheltuieli_table > 0 AND @has_expense_refacturare_document_mime_type = 0,
    'ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_document_mime_type VARCHAR(150) NULL AFTER refacturare_document_original_name',
    'SELECT 1'
);
PREPARE stmt_add_expense_refacturare_document_mime_type FROM @sql_add_expense_refacturare_document_mime_type;
EXECUTE stmt_add_expense_refacturare_document_mime_type;
DEALLOCATE PREPARE stmt_add_expense_refacturare_document_mime_type;

SET @has_expense_refacturare_document_file_size := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curse_cheltuieli' AND COLUMN_NAME = 'refacturare_document_file_size'
);
SET @sql_add_expense_refacturare_document_file_size := IF(
    @has_curse_cheltuieli_table > 0 AND @has_expense_refacturare_document_file_size = 0,
    'ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_document_file_size INT UNSIGNED NULL AFTER refacturare_document_mime_type',
    'SELECT 1'
);
PREPARE stmt_add_expense_refacturare_document_file_size FROM @sql_add_expense_refacturare_document_file_size;
EXECUTE stmt_add_expense_refacturare_document_file_size;
DEALLOCATE PREPARE stmt_add_expense_refacturare_document_file_size;
