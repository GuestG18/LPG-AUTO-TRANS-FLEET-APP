-- Centru Kilometraj: odometrul manual al semiremorcilor.
-- Semiremorca nu are SAS si nu alimenteaza: are un singur odometru (hubodometrul),
-- citit si introdus de operator. Citirile anulate raman in tabel (istoric + audit).
-- T0 poate avea si o referinta 'manual'.
--
-- Se aplica si automat la deschiderea paginii (OdometerCenterModel::ensureSchema).

CREATE TABLE IF NOT EXISTS `vehicle_odometer_manual_readings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `vehicle_id` int unsigned NOT NULL,
  `measured_at` datetime NOT NULL,
  `odometer_km` decimal(12,1) NOT NULL,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `canceled_at` datetime DEFAULT NULL,
  `canceled_by` int unsigned DEFAULT NULL,
  `cancel_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_odo_manual_vehicle` (`vehicle_id`, `measured_at`),
  CONSTRAINT `fk_odo_manual_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicule` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_odo_manual_user` FOREIGN KEY (`created_by`) REFERENCES `utilizatori` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_odo_manual_cancel_user` FOREIGN KEY (`canceled_by`) REFERENCES `utilizatori` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `vehicle_odometer_baselines`
  MODIFY `provider_code` enum('sas','cardoil','manual') COLLATE utf8mb4_unicode_ci NOT NULL;

-- Verificarea fiecarei citiri manuale fata de cea anterioara: km semiremorcii vs km SAS ai
-- capului tractor cuplat. Intervalele brute raman aici; pragul (km_center_setari.manual_toleranta_km,
-- implicit 20) se aplica la afisare.
CREATE TABLE IF NOT EXISTS `vehicle_odometer_manual_checks` (
  `reading_id` int unsigned NOT NULL,
  `previous_reading_id` int unsigned DEFAULT NULL,
  `tractor_id` int unsigned DEFAULT NULL,
  `status` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `trailer_km` decimal(12,1) DEFAULT NULL,
  `sas_from_min` decimal(12,1) DEFAULT NULL,
  `sas_from_max` decimal(12,1) DEFAULT NULL,
  `sas_to_min` decimal(12,1) DEFAULT NULL,
  `sas_to_max` decimal(12,1) DEFAULT NULL,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checked_at` datetime NOT NULL,
  PRIMARY KEY (`reading_id`),
  CONSTRAINT `fk_odo_check_reading` FOREIGN KEY (`reading_id`) REFERENCES `vehicle_odometer_manual_readings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_odo_check_tractor` FOREIGN KEY (`tractor_id`) REFERENCES `vehicule` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
