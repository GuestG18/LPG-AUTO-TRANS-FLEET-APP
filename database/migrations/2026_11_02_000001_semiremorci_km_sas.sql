-- Km semiremorcilor din SAS-ul capului tractor cuplat (TrailerSasKmService).
-- De la km_center_setari.semiremorci_sas_de_la (fixata la prima rulare = urmatorul 00:00),
-- vehicule.km_bord / km_revizie ale semiremorcilor cresc din km SAS ai capului tractor
-- pe ferestrele de cuplare datate, nu din cursele Dispecer. Ce s-a numarat inainte ramane.
-- Rulare: php scripts/sync_km_semiremorci.php (cron zilnic dupa 00:30) sau butonul
-- „Actualizează datele” din Centru Kilometraj. Tabelele se creeaza si automat.

CREATE TABLE IF NOT EXISTS `semiremorci_km_sas` (
  `vehicle_id` int unsigned NOT NULL,
  `synced_until` datetime NOT NULL,
  `km_rest` decimal(10,3) NOT NULL DEFAULT 0,
  `km_total` decimal(12,1) NOT NULL DEFAULT 0,
  `last_status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`vehicle_id`),
  CONSTRAINT `fk_semi_km_sas_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicule` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `semiremorci_km_sas_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `vehicle_id` int unsigned NOT NULL,
  `tractor_id` int unsigned DEFAULT NULL,
  `window_from` datetime NOT NULL,
  `window_to` datetime NOT NULL,
  `km` decimal(10,1) NOT NULL DEFAULT 0,
  `km_applied` int NOT NULL DEFAULT 0,
  `note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_semi_km_sas_log` (`vehicle_id`, `window_from`),
  CONSTRAINT `fk_semi_km_sas_log_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicule` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
