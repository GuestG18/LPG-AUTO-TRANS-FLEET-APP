-- Taxele de drum devin tipuri de cheltuiala separate: Taxa acces, Port si Trecere.
-- Vechiul tip generic "Taxe drum" ramane in baza doar pentru cheltuielile deja salvate,
-- dar nu mai poate fi selectat in formularul din Dispecer curse.
--
-- Fiecare taxa se inregistreaza pe randul ei, cu locatie, bucati si pret unitar;
-- suma rezulta din bucati x pret unitar.

-- 1. Coloanele noi pe curse_cheltuieli ------------------------------------------------
SET @has_locatie := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curse_cheltuieli' AND COLUMN_NAME = 'locatie'
);
SET @sql := IF(@has_locatie = 0,
    'ALTER TABLE curse_cheltuieli ADD COLUMN locatie VARCHAR(190) NULL AFTER categorie_id',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_bucati := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curse_cheltuieli' AND COLUMN_NAME = 'bucati'
);
SET @sql := IF(@has_bucati = 0,
    'ALTER TABLE curse_cheltuieli ADD COLUMN bucati DECIMAL(12,2) NULL AFTER locatie',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_pret_unitar := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curse_cheltuieli' AND COLUMN_NAME = 'pret_unitar'
);
SET @sql := IF(@has_pret_unitar = 0,
    'ALTER TABLE curse_cheltuieli ADD COLUMN pret_unitar DECIMAL(12,2) NULL AFTER bucati',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_ref_locatie := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curse_cheltuieli' AND COLUMN_NAME = 'refacturare_locatie'
);
SET @sql := IF(@has_ref_locatie = 0,
    'ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_locatie VARCHAR(190) NULL AFTER refacturare_tip_cheltuiala',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_ref_bucati := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curse_cheltuieli' AND COLUMN_NAME = 'refacturare_bucati'
);
SET @sql := IF(@has_ref_bucati = 0,
    'ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_bucati DECIMAL(12,2) NULL AFTER refacturare_locatie',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_ref_pret := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curse_cheltuieli' AND COLUMN_NAME = 'refacturare_pret_unitar'
);
SET @sql := IF(@has_ref_pret = 0,
    'ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_pret_unitar DECIMAL(12,2) NULL AFTER refacturare_bucati',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_locatie_idx := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curse_cheltuieli' AND INDEX_NAME = 'idx_curse_cheltuieli_locatie'
);
SET @sql := IF(@has_locatie_idx = 0,
    'ALTER TABLE curse_cheltuieli ADD INDEX idx_curse_cheltuieli_locatie (locatie)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. ENUM-urile de tip trebuie sa cunoasca noile valori --------------------------------
ALTER TABLE curse_cheltuieli
    MODIFY COLUMN tip_cheltuiala
        ENUM('motorina', 'taxa_acces', 'port', 'trece', 'taxe_drum', 'diurna', 'service', 'alte') NOT NULL,
    MODIFY COLUMN refacturare_tip_cheltuiala
        ENUM('motorina', 'taxa_acces', 'port', 'trece', 'taxe_drum', 'diurna', 'service', 'alte') NULL;

-- 3. Categoriile selectabile -----------------------------------------------------------
INSERT INTO categorii_cheltuieli_curse (nume, descriere, activ, legacy_key, created_at, updated_at) VALUES
('Taxa acces', 'Categorie implicita pentru cheltuieli curse.', 1, 'taxa_acces', NOW(), NOW()),
('Port', 'Categorie implicita pentru cheltuieli curse.', 1, 'port', NOW(), NOW()),
('Trecere', 'Categorie implicita pentru cheltuieli curse.', 1, 'trece', NOW(), NOW())
ON DUPLICATE KEY UPDATE
    nume = VALUES(nume),
    activ = 1,
    legacy_key = VALUES(legacy_key),
    updated_at = VALUES(updated_at);

-- "Taxe drum" ramane pentru istoric, dar iese din selectoare.
UPDATE categorii_cheltuieli_curse
SET activ = 0, updated_at = NOW()
WHERE legacy_key = 'taxe_drum';
