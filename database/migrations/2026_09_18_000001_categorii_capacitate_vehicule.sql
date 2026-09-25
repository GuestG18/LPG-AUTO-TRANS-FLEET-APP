-- =====================================================================
-- Separarea capacitatii reale de transport de categoria de capacitate
-- 2026-09-18
--
-- CONTEXT
--   Pana acum `vehicule.capacitate_transport` avea doua roluri in acelasi
--   camp: capacitatea tehnica reala (tone) SI eticheta dupa care se grupau
--   vehiculele in dropdown-uri. Ca sa fie usor de selectat, unor vehicule
--   li s-a scris manual capacitatea grupei (de exemplu 18.5) desi real duc
--   19 sau 20 de tone. Asta strica gradul de umplere si validarea incarcarii.
--
--   Dupa aceasta migrare:
--     * `vehicule.capacitate_transport`      = capacitatea tehnica REALA;
--       singura folosita in calcule (grad de umplere, supraincarcare, KPI).
--     * `vehicule.categorie_capacitate_id`   = eticheta de grupare; folosita
--       DOAR la grupare / filtrare / selectie in masa. Niciodata in calcule.
--
-- SAFETY CONTRACT
--   * Aditiva: un tabel nou de categorii, un tabel nou de audit, coloane noi
--     pe `vehicule` si `curse_dispecer`. Nicio coloana nu este stearsa.
--   * Idempotenta: tabelele au CREATE TABLE IF NOT EXISTS, coloanele sunt
--     adaugate prin guard pe information_schema, iar seed-ul si asignarea
--     ating doar randurile inca neatinse.
--   * `capacitate_transport` NU este modificata de migrare. Valoarea de
--     dinainte de migrare este copiata in `vehicule_capacitate_audit`
--     (sursa = 'migrare'), deci rollback-ul este posibil oricand.
--   * Vehiculele care primesc categorie raman cu
--     `capacitate_transport_confirmata = 0`: valoarea stocata provine din
--     epoca in care campul servea si la grupare, deci nu poate fi
--     considerata capacitate tehnica confirmata pana nu o verifica un om.
--     Migrarea NU deduce capacitati reale din numele categoriei.
--
-- ROLLBACK
--   UPDATE vehicule v
--     JOIN vehicule_capacitate_audit a
--       ON a.vehicle_id = v.id AND a.sursa = 'migrare'
--      SET v.capacitate_transport = a.capacitate_veche,
--          v.categorie_capacitate_id = a.categorie_veche_id,
--          v.capacitate_transport_confirmata = COALESCE(a.confirmata_veche, 0);
--   Coloanele si tabelele noi pot ramane pe loc: fara cod care le citeste
--   sunt inerte.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. Catalogul centralizat de categorii de capacitate
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vehicule_categorii_capacitate (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nume VARCHAR(80) NOT NULL,
    descriere VARCHAR(255) NULL,
    ordine_afisare INT UNSIGNED NOT NULL DEFAULT 0,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_categorii_capacitate_nume (nume),
    INDEX idx_categorii_capacitate_ordine (activ, ordine_afisare, nume)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. Jurnalul de audit pentru capacitate / categorie
--    Tine si snapshot-ul de dinainte de migrare (sursa = 'migrare'), si
--    corectiile ulterioare facute din fisa vehiculului (sursa = 'manual').
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vehicule_capacitate_audit (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NOT NULL,
    capacitate_veche DECIMAL(10,2) NULL,
    capacitate_noua DECIMAL(10,2) NULL,
    categorie_veche_id INT UNSIGNED NULL,
    categorie_noua_id INT UNSIGNED NULL,
    confirmata_veche TINYINT(1) NULL,
    confirmata_noua TINYINT(1) NULL,
    sursa ENUM('migrare', 'manual') NOT NULL DEFAULT 'manual',
    motiv VARCHAR(255) NULL,
    user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_capacitate_audit_vehicul (vehicle_id, created_at),
    INDEX idx_capacitate_audit_sursa (sursa),
    CONSTRAINT fk_capacitate_audit_vehicul FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE,
    CONSTRAINT fk_capacitate_audit_user FOREIGN KEY (user_id) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. Coloanele noi pe `vehicule` (adaugate doar daca lipsesc)
-- ---------------------------------------------------------------------
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicule'
                   AND COLUMN_NAME = 'categorie_capacitate_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE vehicule ADD COLUMN categorie_capacitate_id INT UNSIGNED NULL AFTER capacitate_transport',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicule'
                   AND COLUMN_NAME = 'capacitate_transport_confirmata');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE vehicule ADD COLUMN capacitate_transport_confirmata TINYINT(1) NOT NULL DEFAULT 0 AFTER categorie_capacitate_id',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicule'
                   AND INDEX_NAME = 'idx_vehicule_categorie_capacitate');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE vehicule ADD INDEX idx_vehicule_categorie_capacitate (categorie_capacitate_id)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicule'
                  AND CONSTRAINT_NAME = 'fk_vehicule_categorie_capacitate');
SET @sql := IF(@has_fk = 0,
    'ALTER TABLE vehicule ADD CONSTRAINT fk_vehicule_categorie_capacitate FOREIGN KEY (categorie_capacitate_id) REFERENCES vehicule_categorii_capacitate(id) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 4. Snapshot de audit INAINTE de orice asignare (o singura data / vehicul)
-- ---------------------------------------------------------------------
INSERT INTO vehicule_capacitate_audit
    (vehicle_id, capacitate_veche, capacitate_noua, categorie_veche_id, categorie_noua_id,
     confirmata_veche, confirmata_noua, sursa, motiv, user_id, created_at)
SELECT
    src.id, src.capacitate_transport, src.capacitate_transport, src.categorie_capacitate_id, NULL,
    src.capacitate_transport_confirmata, src.capacitate_transport_confirmata, 'migrare',
    'Snapshot inainte de separarea capacitate reala / categorie de capacitate.', NULL, NOW()
FROM (
    SELECT v.id, v.capacitate_transport, v.categorie_capacitate_id, v.capacitate_transport_confirmata
    FROM vehicule v
    WHERE NOT EXISTS (
        SELECT 1 FROM vehicule_capacitate_audit a
        WHERE a.vehicle_id = v.id AND a.sursa = 'migrare'
    )
) src;

-- ---------------------------------------------------------------------
-- 5. Seed: o categorie pentru fiecare valoare distincta existenta
--    Eticheta este TEXT ("18.5 TONE"), nu o capacitate numerica. Numarul din
--    eticheta nu trebuie citit niciodata ca fiind capacitatea vehiculului.
-- ---------------------------------------------------------------------
INSERT INTO vehicule_categorii_capacitate (nume, descriere, ordine_afisare, activ, created_at, updated_at)
SELECT
    src.nume,
    'Creata automat la migrarea din 2026-09-18, din valorile existente in Capacitate transport.',
    src.ordine,
    1,
    NOW(),
    NOW()
FROM (
    SELECT
        CONCAT(
            TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(v.capacitate_transport AS CHAR))),
            ' TONE'
        ) AS nume,
        (
            SELECT COUNT(DISTINCT v2.capacitate_transport)
            FROM vehicule v2
            WHERE v2.capacitate_transport > v.capacitate_transport
              AND v2.capacitate_transport > 0
        ) AS ordine
    FROM vehicule v
    WHERE v.capacitate_transport IS NOT NULL
      AND v.capacitate_transport > 0
    GROUP BY v.capacitate_transport
) src
ON DUPLICATE KEY UPDATE vehicule_categorii_capacitate.nume = vehicule_categorii_capacitate.nume;

-- ---------------------------------------------------------------------
-- 6. Asignarea vehiculelor la categorii (doar cele inca neasignate)
--    Capacitatea reala ramane neatinsa.
-- ---------------------------------------------------------------------
UPDATE vehicule v
JOIN vehicule_categorii_capacitate c
  ON c.nume = CONCAT(
        TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(v.capacitate_transport AS CHAR))),
        ' TONE'
     )
SET v.categorie_capacitate_id = c.id
WHERE v.categorie_capacitate_id IS NULL
  AND v.capacitate_transport IS NOT NULL
  AND v.capacitate_transport > 0;

-- ---------------------------------------------------------------------
-- 7. Inchiderea auditului: ce categorie a primit fiecare vehicul
-- ---------------------------------------------------------------------
UPDATE vehicule_capacitate_audit a
JOIN vehicule v ON v.id = a.vehicle_id
SET a.categorie_noua_id = v.categorie_capacitate_id
WHERE a.sursa = 'migrare'
  AND a.categorie_noua_id IS NULL;

-- ---------------------------------------------------------------------
-- 8. Snapshot istoric pe cursa
--    `curse_dispecer.capacitate_transport` era deja un snapshot per cursa
--    (se scrie la creare din vehicul si nu se rescrie la o simpla salvare).
--    Adaugam DOAR marcajul "capacitatea din snapshot era confirmata?", ca
--    rapoartele sa poata semnala cursele calculate pe o capacitate inca
--    neverificata. Valorile istorice NU sunt recalculate.
-- ---------------------------------------------------------------------
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curse_dispecer'
                   AND COLUMN_NAME = 'capacitate_transport_confirmata');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN capacitate_transport_confirmata TINYINT(1) NOT NULL DEFAULT 0 AFTER capacitate_transport',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
