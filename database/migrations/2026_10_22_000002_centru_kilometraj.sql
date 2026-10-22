-- Centru Kilometraj (?page=centru_kilometraj): kilometrajul flotei din 2 surse
-- independente (SAS Fleet = odometru GPS kmIndex, CardOil Refill = odometrul
-- tastat de sofer la pompa), cu T0 individual per vehicul / componenta.
--
-- Ce NU se dubleaza:
--   * vehiculele, pozele, categoriile de capacitate si cuplajele raman in
--     vehicule / vehicule_categorii_capacitate / vehicule_cuplaje;
--   * citirile CardOil raman in fuel_fillups (odometer_km = valoarea efectiva,
--     deja corectata manual din Carburanti) si se citesc de acolo.
-- Aici stau doar: maparea pe furnizor + starea sincronizarii, istoricul citirilor
-- SAS (pe care SAS nu il pastreaza la noi altundeva), referintele T0 versionate,
-- jurnalul de audit si setarile modulului.
--
-- Se aplica si automat la deschiderea paginii (OdometerCenterModel::ensureSchema).
-- Rollback: 2026_10_22_000002_centru_kilometraj_rollback.sql

CREATE TABLE IF NOT EXISTS `vehicle_odometer_sources` (
  `vehicle_id` int unsigned NOT NULL,
  `provider_code` enum('sas','cardoil') COLLATE utf8mb4_unicode_ci NOT NULL,
  `external_provider_vehicle_id` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `last_checked_at` datetime DEFAULT NULL,
  `last_status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_error` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`vehicle_id`,`provider_code`),
  KEY `idx_odo_sources_external` (`provider_code`,`external_provider_vehicle_id`),
  CONSTRAINT `fk_odo_sources_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicule` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vehicle_odometer_readings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `vehicle_id` int unsigned NOT NULL,
  `provider_code` enum('sas','cardoil') COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_reading_id` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `segment_started_at` datetime DEFAULT NULL,
  `measured_at` datetime NOT NULL,
  `received_at` datetime NOT NULL,
  `odometer_km` decimal(12,1) NOT NULL,
  `original_value` decimal(16,4) NOT NULL,
  `original_unit` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'km',
  `measurement_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `validation_status` enum('valid','anomaly') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'valid',
  `validation_note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_odo_reading_source` (`provider_code`,`vehicle_id`,`source_reading_id`),
  KEY `idx_odo_reading_time` (`vehicle_id`,`provider_code`,`measured_at`),
  CONSTRAINT `fk_odo_readings_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicule` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vehicle_odometer_baselines` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `vehicle_id` int unsigned NOT NULL,
  `provider_code` enum('sas','cardoil') COLLATE utf8mb4_unicode_ci NOT NULL,
  `requested_t0_at` datetime NOT NULL,
  `measured_t0_at` datetime DEFAULT NULL,
  `baseline_value_km` decimal(12,1) DEFAULT NULL,
  `original_value` decimal(16,4) DEFAULT NULL,
  `original_unit` varchar(8) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `measurement_type` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_reading_id` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `retrieval_status` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status_note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `baseline_version` int unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `superseded_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_odo_baseline_version` (`vehicle_id`,`provider_code`,`baseline_version`),
  KEY `idx_odo_baseline_vehicle` (`vehicle_id`,`provider_code`,`is_active`),
  CONSTRAINT `fk_odo_baselines_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicule` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_odo_baselines_user` FOREIGN KEY (`created_by`) REFERENCES `utilizatori` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vehicle_odometer_audit` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `vehicle_id` int unsigned DEFAULT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `action` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `old_values` longtext COLLATE utf8mb4_unicode_ci,
  `new_values` longtext COLLATE utf8mb4_unicode_ci,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_odo_audit_vehicle` (`vehicle_id`,`created_at`),
  CONSTRAINT `fk_odo_audit_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicule` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_odo_audit_user` FOREIGN KEY (`user_id`) REFERENCES `utilizatori` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `km_center_setari` (
  `setting_key` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
