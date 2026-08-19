-- =====================================================================
-- Modul Carburanti — Etapa 1: mecanismul FULL / T0
-- Data: 2026-08-19
--
-- Migrare STRICT ADITIVA. Nu sterge, nu reseteaza si nu modifica valori
-- existente in afara backfill-ului documentat mai jos, care nu poate
-- pierde informatie (marcheaza drept "manual" exact acele randuri care
-- NU pot proveni din API).
--
-- Scop:
--   1. Separarea deciziei manuale a operatorului (is_full_manual) de
--      valoarea efectiva folosita in calcule (is_full), astfel incat
--      sincronizarea CardOil sa nu mai poata suprascrie decizia manuala.
--   2. Separarea conceptelor FULL (proprietate a alimentarii) si
--      T0 (rolul alimentarii pentru o anumita luna), prin tabela
--      dedicata fuel_month_t0.
--
-- Rulare:  mysql -u <user> -p <baza> < 2026_08_19_000001_fuel_t0_manual.sql
--
-- Idempotenta: acelasi efect este aplicat automat si de
-- FuelModel::ensureSchema(), deci scriptul poate fi sarit daca aplicatia
-- a fost deja incarcata dupa deploy. Rularea repetata este sigura
-- (procedura verifica information_schema inainte de ALTER).
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. Coloane noi pe fuel_fillups (nullable, fara DEFAULT distructiv)
-- ---------------------------------------------------------------------

DROP PROCEDURE IF EXISTS fleet_t0_add_columns;
DELIMITER //
CREATE PROCEDURE fleet_t0_add_columns()
BEGIN
    -- Decizia explicita a operatorului: NULL = nicio decizie manuala,
    -- 1 = marcat FULL manual, 0 = marcat Partial manual.
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'fuel_fillups'
          AND COLUMN_NAME = 'is_full_manual'
    ) THEN
        ALTER TABLE fuel_fillups
            ADD COLUMN is_full_manual TINYINT(1) NULL DEFAULT NULL AFTER is_full;
    END IF;

    -- Provenienta valorii curente din is_full.
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'fuel_fillups'
          AND COLUMN_NAME = 'full_source'
    ) THEN
        ALTER TABLE fuel_fillups
            ADD COLUMN full_source ENUM('api','manual') NULL DEFAULT NULL AFTER is_full_manual;
    END IF;

    -- Index pentru cautarea T0 (vehicul + motorina + full + data).
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'fuel_fillups'
          AND INDEX_NAME = 'idx_fuel_fillups_manual_full'
    ) THEN
        ALTER TABLE fuel_fillups
            ADD INDEX idx_fuel_fillups_manual_full (is_full_manual, is_full);
    END IF;
END//
DELIMITER ;

CALL fleet_t0_add_columns();
DROP PROCEDURE IF EXISTS fleet_t0_add_columns;

-- ---------------------------------------------------------------------
-- 2. Backfill non-distructiv
--
-- API-ul CardOil nu furnizeaza niciun indicator de plin: orice rand cu
-- is_full = 1 aflat deja in baza a fost setat de un operator (sau de un
-- script de test). Il marcam ca decizie manuala, ca sa nu fie pierdut la
-- primul sync dupa deploy. Nicio valoare nu este modificata sau stearsa.
-- ---------------------------------------------------------------------

UPDATE fuel_fillups
   SET is_full_manual = 1,
       full_source = 'manual'
 WHERE is_full = 1
   AND is_full_manual IS NULL;

-- Restul randurilor provin din import automat.
UPDATE fuel_fillups
   SET full_source = 'api'
 WHERE full_source IS NULL;

-- ---------------------------------------------------------------------
-- 3. Tabela T0 per (vehicul, luna)
--
-- FULL este o proprietate a alimentarii; T0 este ROLUL acelei alimentari
-- pentru o luna anume. Tabela stocheaza exclusiv deciziile MANUALE —
-- selectia automata ramane calculata la runtime si nu se persista, ca sa
-- nu inghete un rezultat care trebuie sa urmeze datele.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS fuel_month_t0 (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_key VARCHAR(40) NOT NULL COMMENT 'Inmatriculare normalizata: UPPER fara spatii',
    month_start DATE NOT NULL COMMENT 'Prima zi a lunii analizate',
    fillup_id INT UNSIGNED NOT NULL,
    mode ENUM('manual') NOT NULL DEFAULT 'manual',
    note VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_fuel_month_t0 (vehicle_key, month_start),
    INDEX idx_fuel_month_t0_fillup (fillup_id),
    CONSTRAINT fk_fuel_month_t0_fillup
        FOREIGN KEY (fillup_id) REFERENCES fuel_fillups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
