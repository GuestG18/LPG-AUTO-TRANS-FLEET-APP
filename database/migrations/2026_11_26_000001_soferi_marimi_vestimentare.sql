-- ============================================================================
-- Mărimi vestimentare per șofer (fișa „Detalii șofer” → tab „Mărimi vestimentare”).
--
-- O linie = mărimea preferată a unui șofer pentru un articol din catalogul de
-- echipamente (echipamente_catalog, doar articolele cu necesita_marime = 1).
-- Mărimea NU depinde de alocări: un șofer fără echipamente poate avea mărimi.
-- La predare (Echipamente șoferi) mărimea de aici e doar sugerată.
-- Tabela se creează și la nevoie din DriverClothingSizeModel::ensureTable().
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `soferi_marimi_vestimentare` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `driver_id` INT UNSIGNED NOT NULL,
  `catalog_id` INT UNSIGNED NOT NULL,
  `marime` VARCHAR(20) NOT NULL,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sofer_marime_articol` (`driver_id`, `catalog_id`),
  KEY `idx_sofer_marime_catalog` (`catalog_id`),
  CONSTRAINT `fk_sofer_marime_driver` FOREIGN KEY (`driver_id`) REFERENCES `soferi` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sofer_marime_catalog` FOREIGN KEY (`catalog_id`) REFERENCES `echipamente_catalog` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
