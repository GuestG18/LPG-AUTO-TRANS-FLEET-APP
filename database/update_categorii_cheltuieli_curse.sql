-- Migrare: categorii dinamice pentru cheltuieli curse
-- Ruleaza dupa tabelele Dispecer curse existente.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS categorii_cheltuieli_curse (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nume VARCHAR(150) NOT NULL,
    descriere TEXT NULL,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    legacy_key VARCHAR(50) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uk_categorii_cheltuieli_curse_nume (nume),
    UNIQUE KEY uk_categorii_cheltuieli_curse_legacy (legacy_key),
    INDEX idx_categorii_cheltuieli_curse_activ (activ)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_legacy_key := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'categorii_cheltuieli_curse'
      AND COLUMN_NAME = 'legacy_key'
);
SET @sql_add_legacy_key := IF(
    @has_legacy_key = 0,
    'ALTER TABLE categorii_cheltuieli_curse ADD COLUMN legacy_key VARCHAR(50) NULL AFTER activ',
    'SELECT 1'
);
PREPARE stmt_add_legacy_key FROM @sql_add_legacy_key;
EXECUTE stmt_add_legacy_key;
DEALLOCATE PREPARE stmt_add_legacy_key;

INSERT INTO categorii_cheltuieli_curse (nume, descriere, activ, legacy_key, created_at, updated_at) VALUES
('Taxe drum', 'Categorie implicita pentru cheltuieli curse.', 1, 'taxe_drum', NOW(), NOW()),
('Diurna', 'Categorie implicita pentru cheltuieli curse.', 1, 'diurna', NOW(), NOW()),
('Reparatii', 'Categorie implicita pentru cheltuieli curse.', 1, 'service', NOW(), NOW()),
('Alte cheltuieli', 'Categorie implicita pentru cheltuieli curse.', 1, 'alte', NOW(), NOW())
ON DUPLICATE KEY UPDATE
    nume = VALUES(nume),
    activ = 1,
    legacy_key = VALUES(legacy_key),
    updated_at = VALUES(updated_at);

SET @has_categorie_id := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_cheltuieli'
      AND COLUMN_NAME = 'categorie_id'
);
SET @sql_add_categorie_id := IF(
    @has_categorie_id = 0,
    'ALTER TABLE curse_cheltuieli ADD COLUMN categorie_id INT UNSIGNED NULL AFTER tip_cheltuiala',
    'SELECT 1'
);
PREPARE stmt_add_categorie_id FROM @sql_add_categorie_id;
EXECUTE stmt_add_categorie_id;
DEALLOCATE PREPARE stmt_add_categorie_id;

SET @has_added_by := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_cheltuieli'
      AND COLUMN_NAME = 'added_by'
);
SET @sql_add_added_by := IF(
    @has_added_by = 0,
    'ALTER TABLE curse_cheltuieli ADD COLUMN added_by INT UNSIGNED NULL AFTER observatii',
    'SELECT 1'
);
PREPARE stmt_add_added_by FROM @sql_add_added_by;
EXECUTE stmt_add_added_by;
DEALLOCATE PREPARE stmt_add_added_by;

SET @has_idx_categorie := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_cheltuieli'
      AND INDEX_NAME = 'idx_curse_cheltuieli_categorie'
);
SET @sql_add_idx_categorie := IF(
    @has_idx_categorie = 0,
    'ALTER TABLE curse_cheltuieli ADD INDEX idx_curse_cheltuieli_categorie (categorie_id)',
    'SELECT 1'
);
PREPARE stmt_add_idx_categorie FROM @sql_add_idx_categorie;
EXECUTE stmt_add_idx_categorie;
DEALLOCATE PREPARE stmt_add_idx_categorie;

SET @has_idx_added_by := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_cheltuieli'
      AND INDEX_NAME = 'idx_curse_cheltuieli_added_by'
);
SET @sql_add_idx_added_by := IF(
    @has_idx_added_by = 0,
    'ALTER TABLE curse_cheltuieli ADD INDEX idx_curse_cheltuieli_added_by (added_by)',
    'SELECT 1'
);
PREPARE stmt_add_idx_added_by FROM @sql_add_idx_added_by;
EXECUTE stmt_add_idx_added_by;
DEALLOCATE PREPARE stmt_add_idx_added_by;

UPDATE curse_cheltuieli e
INNER JOIN categorii_cheltuieli_curse c ON c.legacy_key = e.tip_cheltuiala
SET e.categorie_id = c.id
WHERE e.categorie_id IS NULL
  AND e.tip_cheltuiala <> 'motorina';
