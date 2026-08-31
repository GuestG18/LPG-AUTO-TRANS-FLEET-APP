SET NAMES utf8mb4;

-- Rute Primar cu 4 puncte: Loc plecare (garaj) -> Loc incarcare -> Loc descarcare -> Loc intoarcere (garaj).
-- Garajele sunt valorile text existente din vehicule.garaj, nu un catalog separat.
-- Functionalitatea NU este globala: se activeaza per beneficiar, prin
-- configurare_beneficiari_transport.rute_primar_puncte_extinse.

SET @has_rute_primar_puncte_extinse := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_beneficiari_transport'
      AND COLUMN_NAME = 'rute_primar_puncte_extinse'
);
SET @sql_add_rute_primar_puncte_extinse := IF(
    @has_rute_primar_puncte_extinse = 0,
    'ALTER TABLE configurare_beneficiari_transport ADD COLUMN rute_primar_puncte_extinse TINYINT(1) NOT NULL DEFAULT 0 AFTER suporta_compresor',
    'SELECT 1'
);
PREPARE stmt_add_rute_primar_puncte_extinse FROM @sql_add_rute_primar_puncte_extinse;
EXECUTE stmt_add_rute_primar_puncte_extinse;
DEALLOCATE PREPARE stmt_add_rute_primar_puncte_extinse;

SET @has_garaj_plecare := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_rute_primar'
      AND COLUMN_NAME = 'garaj_plecare'
);
SET @sql_add_garaj_plecare := IF(
    @has_garaj_plecare = 0,
    'ALTER TABLE configurare_rute_primar ADD COLUMN garaj_plecare VARCHAR(120) NULL AFTER zona_distributie_id',
    'SELECT 1'
);
PREPARE stmt_add_garaj_plecare FROM @sql_add_garaj_plecare;
EXECUTE stmt_add_garaj_plecare;
DEALLOCATE PREPARE stmt_add_garaj_plecare;

SET @has_garaj_intoarcere := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_rute_primar'
      AND COLUMN_NAME = 'garaj_intoarcere'
);
SET @sql_add_garaj_intoarcere := IF(
    @has_garaj_intoarcere = 0,
    'ALTER TABLE configurare_rute_primar ADD COLUMN garaj_intoarcere VARCHAR(120) NULL AFTER garaj_plecare',
    'SELECT 1'
);
PREPARE stmt_add_garaj_intoarcere FROM @sql_add_garaj_intoarcere;
EXECUTE stmt_add_garaj_intoarcere;
DEALLOCATE PREPARE stmt_add_garaj_intoarcere;

-- Deocamdata doar Vixon lucreaza cu rute pe 4 puncte.
-- Pentru a activa si pentru alt beneficiar:
--   UPDATE configurare_beneficiari_transport SET rute_primar_puncte_extinse = 1 WHERE nume = '<beneficiar>';
UPDATE configurare_beneficiari_transport
SET rute_primar_puncte_extinse = 1
WHERE nume = 'Vixon';

-- Punctele traseului ajung si pe cursa: garajul de plecare in curse_dispecer.loc_plecare
-- (coloana exista deja, folosita pana acum doar de Compresor) si cel de intoarcere intr-o
-- coloana noua. Valorile sunt scrise din ruta configurata, nu din formular.
SET @has_loc_intoarcere := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'loc_intoarcere'
);
SET @sql_add_loc_intoarcere := IF(
    @has_loc_intoarcere = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN loc_intoarcere VARCHAR(255) NULL AFTER loc_plecare',
    'SELECT 1'
);
PREPARE stmt_add_loc_intoarcere FROM @sql_add_loc_intoarcere;
EXECUTE stmt_add_loc_intoarcere;
DEALLOCATE PREPARE stmt_add_loc_intoarcere;

-- O ruta poate avea MAI MULTE locuri de intoarcere (acelasi km / pret, capat diferit).
-- Se stocheaza ca lista separata prin virgula in aceeasi coloana, ca la vehicle_ids.
SET @garaj_intoarcere_len := (
    SELECT COALESCE(MAX(CHARACTER_MAXIMUM_LENGTH), 0)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_rute_primar'
      AND COLUMN_NAME = 'garaj_intoarcere'
);
SET @sql_widen_garaj_intoarcere := IF(
    @garaj_intoarcere_len > 0 AND @garaj_intoarcere_len < 500,
    'ALTER TABLE configurare_rute_primar MODIFY garaj_intoarcere VARCHAR(500) NULL',
    'SELECT 1'
);
PREPARE stmt_widen_garaj_intoarcere FROM @sql_widen_garaj_intoarcere;
EXECUTE stmt_widen_garaj_intoarcere;
DEALLOCATE PREPARE stmt_widen_garaj_intoarcere;
