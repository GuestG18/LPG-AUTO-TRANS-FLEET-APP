-- Km anvelopelor din SAS (TireSasKmService), nu din vehicule.km_bord / Dispecer curse.
-- Aplicata si la rulare de TireModel::ensureSasKmSchema(), care face si comutarea
-- montarilor deja active: km de pana atunci raman in km_inainte_sas, SAS numara de atunci.
ALTER TABLE anvelope_alocari
  ADD COLUMN sas_de_la DATETIME NULL AFTER km_end,
  ADD COLUMN demontat_la DATETIME NULL AFTER sas_de_la,
  ADD COLUMN km_inainte_sas INT UNSIGNED NOT NULL DEFAULT 0 AFTER demontat_la,
  ADD COLUMN km_sas DECIMAL(10,1) NOT NULL DEFAULT 0 AFTER km_inainte_sas,
  ADD COLUMN km_sas_final TINYINT(1) NOT NULL DEFAULT 0 AFTER km_sas;

CREATE TABLE IF NOT EXISTS `anvelope_sas_miscari` (
  `vehicle_id` int unsigned NOT NULL,
  `start_at` datetime NOT NULL,
  `end_at` datetime NOT NULL,
  `km` decimal(8,1) NOT NULL,
  PRIMARY KEY (`vehicle_id`, `start_at`, `end_at`),
  CONSTRAINT `fk_anvelope_sas_miscari_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicule` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `anvelope_sas_stare` (
  `vehicle_id` int unsigned NOT NULL,
  `acoperit_de_la` datetime NULL,
  `synced_until` datetime NULL,
  `last_status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`vehicle_id`),
  CONSTRAINT `fk_anvelope_sas_stare_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicule` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
