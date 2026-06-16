-- Migrare: adauga poza pentru soferi

SET @has_poza_original := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'soferi'
      AND COLUMN_NAME = 'poza_original'
);

SET @sql_add_poza_original := IF(
    @has_poza_original = 0,
    'ALTER TABLE soferi ADD COLUMN poza_original VARCHAR(255) NULL AFTER nume',
    'SELECT 1'
);

PREPARE stmt_add_poza_original FROM @sql_add_poza_original;
EXECUTE stmt_add_poza_original;
DEALLOCATE PREPARE stmt_add_poza_original;

SET @has_poza_stocata := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'soferi'
      AND COLUMN_NAME = 'poza_stocata'
);

SET @sql_add_poza_stocata := IF(
    @has_poza_stocata = 0,
    'ALTER TABLE soferi ADD COLUMN poza_stocata VARCHAR(255) NULL AFTER poza_original',
    'SELECT 1'
);

PREPARE stmt_add_poza_stocata FROM @sql_add_poza_stocata;
EXECUTE stmt_add_poza_stocata;
DEALLOCATE PREPARE stmt_add_poza_stocata;
