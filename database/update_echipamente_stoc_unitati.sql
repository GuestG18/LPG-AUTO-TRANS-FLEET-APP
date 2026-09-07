-- ============================================================================
-- Stoc echipamente: dimensiunea "marime" pe stoc, unitati individuale
-- (serializate) si detalii de miscare pentru audit.
--
-- Context: pagina "Echipamente soferi" a fost impartita in trei ecrane
-- (echipamente soferi / catalog / stoc). Stocul devine ecran propriu si are
-- nevoie de doua lucruri pe care ledgerul pe cantitate nu le acopera:
--   1. disponibilitate PE MARIME (bocanci 43 nu inlocuiesc bocanci 42);
--   2. unitati identificabile individual (telefon, tableta, SIM, statie radio,
--      card) cu serie / IMEI / ICCID, stare si furnizor.
-- ============================================================================

SET NAMES utf8mb4;

-- 1. Stocul se tine pe articol + gestiune + marime.
ALTER TABLE `echipamente_stoc`
    ADD COLUMN `marime` VARCHAR(20) NOT NULL DEFAULT '' AFTER `locatie`;

ALTER TABLE `echipamente_stoc`
    ADD UNIQUE KEY `uk_echip_stoc_articol_locatie_marime` (`catalog_id`, `locatie`, `marime`);

-- Cheia unica veche sustinea cheia straina pe catalog_id, deci intai ii dam
-- un index propriu si abia apoi o putem elimina.
ALTER TABLE `echipamente_stoc`
    ADD KEY `idx_echip_stoc_catalog` (`catalog_id`);

ALTER TABLE `echipamente_stoc`
    DROP INDEX `uk_echip_stoc_articol_locatie`;

-- 2. Catalogul spune daca articolul se urmareste bucata cu bucata.
ALTER TABLE `echipamente_catalog`
    ADD COLUMN `serializat` TINYINT(1) NOT NULL DEFAULT 0 AFTER `necesita_identificator`;

-- 3. Unitatile individuale din stoc (doar pentru articolele serializate).
--    O unitate este ori disponibila, ori alocata unui sofer, ori scoasa din uz.
CREATE TABLE IF NOT EXISTS `echipamente_stoc_unitati` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `catalog_id` INT UNSIGNED NOT NULL,
  `stoc_id` INT UNSIGNED DEFAULT NULL,
  `alocare_id` INT UNSIGNED DEFAULT NULL,
  `serie` VARCHAR(120) DEFAULT NULL,
  `iccid` VARCHAR(40) DEFAULT NULL,
  `numar_telefon` VARCHAR(40) DEFAULT NULL,
  `operator` VARCHAR(60) DEFAULT NULL,
  `tip_abonament` ENUM('abonament','prepay','n/a') NOT NULL DEFAULT 'n/a',
  `cost_lunar` DECIMAL(10,2) DEFAULT NULL,
  `marime` VARCHAR(20) NOT NULL DEFAULT '',
  `locatie` VARCHAR(120) NOT NULL DEFAULT 'Depozit principal',
  `stare` ENUM('noua','buna','uzata','deteriorata','pierduta') NOT NULL DEFAULT 'buna',
  `status` ENUM('disponibil','rezervat','alocat','deteriorat','pierdut','casat','transferat') NOT NULL DEFAULT 'disponibil',
  `data_intrarii` DATE DEFAULT NULL,
  `cost_achizitie` DECIMAL(10,2) NOT NULL DEFAULT '0.00',
  `furnizor` VARCHAR(120) DEFAULT NULL,
  `document` VARCHAR(120) DEFAULT NULL,
  `observatii` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_echip_unitati_catalog` (`catalog_id`),
  KEY `idx_echip_unitati_status` (`status`),
  KEY `idx_echip_unitati_alocare` (`alocare_id`),
  KEY `idx_echip_unitati_serie` (`serie`),
  CONSTRAINT `fk_echip_unitati_catalog` FOREIGN KEY (`catalog_id`) REFERENCES `echipamente_catalog` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_echip_unitati_stoc` FOREIGN KEY (`stoc_id`) REFERENCES `echipamente_stoc` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Miscarile primesc contextul necesar auditului (motiv, marime, furnizor).
ALTER TABLE `echipamente_miscari`
    ADD COLUMN `unitate_id` INT UNSIGNED DEFAULT NULL AFTER `alocare_id`,
    ADD COLUMN `marime` VARCHAR(20) DEFAULT NULL AFTER `cantitate`,
    ADD COLUMN `motiv` VARCHAR(60) DEFAULT NULL AFTER `locatie`,
    ADD COLUMN `furnizor` VARCHAR(120) DEFAULT NULL AFTER `motiv`,
    ADD COLUMN `document` VARCHAR(120) DEFAULT NULL AFTER `furnizor`,
    ADD COLUMN `cost_unitar` DECIMAL(10,2) DEFAULT NULL AFTER `document`;

-- 5. Alocarea stie din ce unitate a plecat, ca returnarea sa o poata readuce.
ALTER TABLE `echipamente_alocari`
    ADD COLUMN `unitate_id` INT UNSIGNED DEFAULT NULL AFTER `catalog_id`,
    ADD KEY `idx_echip_alocari_unitate` (`unitate_id`);
