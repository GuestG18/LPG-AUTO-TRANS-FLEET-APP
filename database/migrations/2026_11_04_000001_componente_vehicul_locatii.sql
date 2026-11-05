-- Stoc Anvelope, exploratorul de componente — etapa 2. Doar adaugari; nimic existent nu se schimba.
--
-- 1) mentenanta_piese_utilizari (montarile de piese, existente) primeste locul montarii:
--    componenta din catalogul Reparatii Auto, locul din schema (ex. "A2-L", "A1", "vehicul"),
--    axa / partea, si inchiderea montarii (demontare / inlocuire). Randurile vechi raman cu NULL
--    = montare la nivel de vehicul, fara demontare inregistrata.
-- 2) vehicule_axe_atribute: atributele unei axe care nu exista in configuratia de anvelope
--    (ridicabila, portanta, tip suspensie, tip franare). NULL = nedefinit.
--    Directie / tractiune / roti duble raman din vehicule_anvelope_pozitii (nu se dubleaza).
-- 3) vehicule_componente_inspectii: inspectiile unei componente pe un loc al vehiculului.
--
-- Se aplica si automat la prima folosire (VehicleComponentModel::ensureSchema).

ALTER TABLE `mentenanta_piese_utilizari`
  ADD COLUMN `component_key` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `direct_mount`,
  ADD COLUMN `locatie` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `component_key`,
  ADD COLUMN `axle_no` tinyint unsigned DEFAULT NULL AFTER `locatie`,
  ADD COLUMN `parte` char(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `axle_no`,
  ADD COLUMN `demontat_la` date DEFAULT NULL AFTER `parte`,
  ADD COLUMN `km_demontare` int unsigned DEFAULT NULL AFTER `demontat_la`,
  ADD COLUMN `motiv_demontare` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `km_demontare`,
  ADD COLUMN `observatii_demontare` text COLLATE utf8mb4_unicode_ci AFTER `motiv_demontare`,
  ADD COLUMN `inlocuita_de_id` bigint unsigned DEFAULT NULL AFTER `observatii_demontare`,
  ADD COLUMN `created_by` int unsigned DEFAULT NULL AFTER `inlocuita_de_id`,
  ADD KEY `idx_ment_usage_location` (`vehicle_id`, `component_key`, `locatie`, `demontat_la`);

CREATE TABLE IF NOT EXISTS `vehicule_axe_atribute` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `vehicle_id` int unsigned NOT NULL,
  `axle_no` tinyint unsigned NOT NULL,
  `ridicabila` tinyint(1) DEFAULT NULL,
  `portanta` tinyint(1) DEFAULT NULL,
  `tip_suspensie` enum('pneumatica','arcuri','mixta') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tip_franare` enum('disc','tambur') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `observatii` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vehicul_axa` (`vehicle_id`, `axle_no`),
  CONSTRAINT `fk_axe_atribute_vehicul` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicule` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vehicule_componente_inspectii` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `vehicle_id` int unsigned NOT NULL,
  `categorie` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `component_key` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `locatie` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_inspectie` date NOT NULL,
  `km` int unsigned DEFAULT NULL,
  `rezultat` enum('ok','atentie','inlocuire') COLLATE utf8mb4_unicode_ci NOT NULL,
  `observatii` text COLLATE utf8mb4_unicode_ci,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_inspectii_loc` (`vehicle_id`, `categorie`, `locatie`, `data_inspectie`),
  CONSTRAINT `fk_inspectii_vehicul` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicule` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
