-- =============================================================================
-- Modul "Cazare" (cheltuieli de cazare introduse separat, asociate automat la cursa)
-- =============================================================================
-- Logica modulului:
--   Cazarea NU se introduce pe cursa, ci pe pagina proprie, cu 4 campuri:
--   Data / Sofer / Total / Total cu TVA. Sistemul cauta in curse_dispecer
--   cursa soferului a carei perioada (data_inceput .. data_sfarsit) contine
--   data cazarii si o asociaza automat.
--
--   - 0 curse gasite  -> status 'neasociat' (se reincearca automat mai tarziu)
--   - 1 cursa gasita  -> status 'asociat'   (se oglindeste in curse_cheltuieli)
--   - 2+ curse gasite -> status 'ambiguu'   (utilizatorul alege cursa manual)
--
--   Costul care intra in rapoarte este TOTAL CU TVA.
-- =============================================================================

-- 1. Categoria "Cazare" lipsea complet din catalogul de cheltuieli curse.
-- (uk_categorii_cheltuieli_curse_nume face INSERT IGNORE idempotent)
INSERT IGNORE INTO categorii_cheltuieli_curse (nume, descriere, activ, legacy_key, created_at, updated_at)
VALUES (
    'Cazare',
    'Cheltuieli de cazare sofer. Se introduc pe pagina Cazare si se asociaza automat la cursa.',
    1,
    NULL,
    NOW(),
    NOW()
);

-- 2. Registrul de cazari.
CREATE TABLE IF NOT EXISTS cheltuieli_cazare (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    data DATE NOT NULL,
    sofer_id INT UNSIGNED NOT NULL,
    -- total = valoarea fara TVA (informativ / reconciliere contabila)
    total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    -- total_cu_tva = costul real, cel care intra in cheltuielile cursei
    total_cu_tva DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cursa_id INT UNSIGNED NULL,
    status ENUM('asociat', 'neasociat', 'ambiguu') NOT NULL DEFAULT 'neasociat',
    -- 1 = cursa a fost aleasa manual; reasocierea automata nu o mai suprascrie
    asociere_manuala TINYINT(1) NOT NULL DEFAULT 0,
    observatii TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_cheltuieli_cazare_data (data),
    INDEX idx_cheltuieli_cazare_sofer (sofer_id),
    INDEX idx_cheltuieli_cazare_cursa (cursa_id),
    INDEX idx_cheltuieli_cazare_status (status),
    INDEX idx_cheltuieli_cazare_sofer_data (sofer_id, data),
    INDEX idx_cheltuieli_cazare_created_by (created_by),
    CONSTRAINT fk_cheltuieli_cazare_sofer FOREIGN KEY (sofer_id) REFERENCES soferi(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cheltuieli_cazare_cursa FOREIGN KEY (cursa_id) REFERENCES curse_dispecer(id) ON DELETE SET NULL,
    CONSTRAINT fk_cheltuieli_cazare_created_by FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Legatura catre randul-oglinda din curse_cheltuieli.
--    Randul-oglinda exista doar cat timp cazarea are o cursa asociata si este
--    gestionat exclusiv de modulul Cazare (nu se editeaza din Dispecer curse).
SET @has_cazare_id := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_cheltuieli'
      AND COLUMN_NAME = 'cazare_id'
);
SET @sql := IF(@has_cazare_id = 0,
    'ALTER TABLE curse_cheltuieli ADD COLUMN cazare_id INT UNSIGNED NULL AFTER categorie_id',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_cazare_idx := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_cheltuieli'
      AND INDEX_NAME = 'uk_curse_cheltuieli_cazare'
);
SET @sql := IF(@has_cazare_idx = 0,
    'ALTER TABLE curse_cheltuieli ADD UNIQUE KEY uk_curse_cheltuieli_cazare (cazare_id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_cazare_fk := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_cheltuieli'
      AND CONSTRAINT_NAME = 'fk_curse_cheltuieli_cazare'
);
SET @sql := IF(@has_cazare_fk = 0,
    'ALTER TABLE curse_cheltuieli ADD CONSTRAINT fk_curse_cheltuieli_cazare FOREIGN KEY (cazare_id) REFERENCES cheltuieli_cazare(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Facturile de cazare. Fisierele stau in uploads/curse_cheltuieli, acelasi folder
--    ca documentele de cheltuiala cursa, ca randul-oglinda sa trimita catre acelasi
--    fisier fara sa il duplicam pe disc.
CREATE TABLE IF NOT EXISTS cheltuieli_cazare_documente (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cazare_id INT UNSIGNED NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(150) NULL,
    file_size INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_cheltuieli_cazare_doc_cazare (cazare_id),
    CONSTRAINT fk_cheltuieli_cazare_doc_cazare FOREIGN KEY (cazare_id) REFERENCES cheltuieli_cazare(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
