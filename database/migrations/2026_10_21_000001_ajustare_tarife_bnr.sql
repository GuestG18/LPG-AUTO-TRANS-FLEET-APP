-- Ajustare tarife combustibil & curs BNR (?page=ajustare_tarife).
-- Cursurile BNR, jurnalul de sincronizare, referintele salvate (imuabile), ponderile
-- confirmate, instantaneele de simulare si aprobarile manuale. Tarifele contractuale
-- raman in transport_tariff_versions; setarile fx_* stau in transport_tariff_settings.
-- Se aplica si automat la deschiderea paginii (FuelFxAdjustmentModel::ensureSchema).

CREATE TABLE IF NOT EXISTS `bnr_exchange_rates` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `rate_date` date NOT NULL,
  `rate` decimal(14,6) NOT NULL,
  `multiplier` int unsigned NOT NULL DEFAULT '1',
  `source` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fetched_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bnr_currency_date` (`currency`,`rate_date`),
  KEY `idx_bnr_date` (`rate_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bnr_sync_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `status` enum('running','success','error','skipped') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'running',
  `sources` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `received` int unsigned NOT NULL DEFAULT '0',
  `inserted` int unsigned NOT NULL DEFAULT '0',
  `conflicts` int unsigned NOT NULL DEFAULT '0',
  `latest_rate_date` date DEFAULT NULL,
  `message` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `triggered_by` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cron',
  `user_id` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_bnr_sync_status` (`status`,`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fx_tariff_baselines` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `window_start` date NOT NULL,
  `window_end` date NOT NULL,
  `diesel_ron` decimal(14,6) NOT NULL,
  `eur_ron` decimal(14,6) NOT NULL,
  `diesel_eur` decimal(14,6) NOT NULL,
  `fx_observations` int unsigned NOT NULL DEFAULT '0',
  `fuel_observations` int unsigned NOT NULL DEFAULT '0',
  `fuel_liters` decimal(14,2) NOT NULL DEFAULT '0.00',
  `source` enum('manual','approval') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fx_baseline_default` (`is_default`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fx_tariff_rule_baselines` (
  `rule_signature` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `baseline_id` int unsigned NOT NULL,
  `tariff_version_id` int unsigned DEFAULT NULL,
  `assigned_at` datetime NOT NULL,
  PRIMARY KEY (`rule_signature`),
  KEY `idx_fx_rule_baseline` (`baseline_id`),
  CONSTRAINT `fk_fx_rule_baseline` FOREIGN KEY (`baseline_id`) REFERENCES `fx_tariff_baselines` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fx_tariff_rule_weights` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `rule_signature` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fuel_weight` decimal(5,4) NOT NULL,
  `basis_note` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `confirmed_by` int unsigned DEFAULT NULL,
  `confirmed_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fx_weight_signature` (`rule_signature`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fx_tariff_snapshots` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `kind` enum('auto','saved') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'auto',
  `snapshot_date` date NOT NULL,
  `input_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `baseline_id` int unsigned DEFAULT NULL,
  `indicators_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `simulation_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fx_snapshot_kind_hash` (`kind`,`input_hash`),
  KEY `idx_fx_snapshot_date` (`snapshot_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fx_tariff_approvals` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `approved_at` datetime NOT NULL,
  `approved_by` int unsigned DEFAULT NULL,
  `approved_by_name` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `effective_from` date NOT NULL,
  `note` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `baseline_id` int unsigned DEFAULT NULL,
  `new_baseline_id` int unsigned DEFAULT NULL,
  `snapshot_id` int unsigned DEFAULT NULL,
  `adjust_basis` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `threshold_percent` decimal(8,4) DEFAULT NULL,
  `variation_percent` decimal(12,6) DEFAULT NULL,
  `reference_diesel_ron` decimal(14,6) DEFAULT NULL,
  `reference_eur_ron` decimal(14,6) DEFAULT NULL,
  `current_diesel_ron` decimal(14,6) DEFAULT NULL,
  `current_eur_ron` decimal(14,6) DEFAULT NULL,
  `items_count` int unsigned NOT NULL DEFAULT '0',
  `avg_change_percent` decimal(12,6) DEFAULT NULL,
  `indicators_json` longtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `idx_fx_approval_date` (`approved_at`),
  KEY `fk_fx_approval_baseline` (`baseline_id`),
  KEY `fk_fx_approval_new_baseline` (`new_baseline_id`),
  CONSTRAINT `fk_fx_approval_baseline` FOREIGN KEY (`baseline_id`) REFERENCES `fx_tariff_baselines` (`id`),
  CONSTRAINT `fk_fx_approval_new_baseline` FOREIGN KEY (`new_baseline_id`) REFERENCES `fx_tariff_baselines` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fx_tariff_approval_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `approval_id` int unsigned NOT NULL,
  `rule_signature` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `beneficiar_id` int unsigned NOT NULL,
  `beneficiar_nume` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transport_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `component_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `route_ref_id` int unsigned DEFAULT NULL,
  `route_label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `group_label` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `old_value` decimal(14,4) NOT NULL,
  `new_value` decimal(14,4) NOT NULL,
  `change_percent` decimal(12,6) NOT NULL,
  `fuel_weight` decimal(5,4) NOT NULL,
  `variation_percent` decimal(12,6) NOT NULL,
  `baseline_id` int unsigned DEFAULT NULL,
  `old_version_id` int unsigned DEFAULT NULL,
  `new_version_id` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fx_item_approval` (`approval_id`),
  KEY `idx_fx_item_signature` (`rule_signature`),
  CONSTRAINT `fk_fx_item_approval` FOREIGN KEY (`approval_id`) REFERENCES `fx_tariff_approvals` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mod test (?page=ajustare_tarife&test=1): prețuri de motorină introduse manual pe lună,
-- folosite doar în simularea de test; nu intră în indicatorii reali, cron sau aprobări.
CREATE TABLE IF NOT EXISTS `fx_test_diesel_months` (
  `month` char(7) COLLATE utf8mb4_unicode_ci NOT NULL,
  `diesel_ron` decimal(14,6) NOT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
