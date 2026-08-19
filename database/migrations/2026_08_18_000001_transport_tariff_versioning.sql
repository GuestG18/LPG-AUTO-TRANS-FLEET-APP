-- =====================================================================
-- Administrare tarife transport — versioned commercial tariffs
-- 2026-08-18
--
-- SAFETY CONTRACT
--   * Additive only. No column is dropped, no row is deleted.
--   * Idempotent: every statement is CREATE TABLE IF NOT EXISTS or is
--     guarded by the PHP runner (scripts/migrate_transport_tariffs.php).
--   * No existing financial value in curse_dispecer is read or written here.
--   * Legacy configuration tables (configurare_*) are left untouched.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. Versioned tariff components
--
-- rule_signature = "<beneficiar_id>|<component_key>|<route_ref_id|0>"
-- It is the identity of a *pricing rule*; several versions of the same
-- rule differ only by their validity period.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS transport_tariff_versions (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_signature         VARCHAR(120) NOT NULL,
    beneficiar_id          INT UNSIGNED NOT NULL,
    transport_type         ENUM('primar','primar_tona','distributie','primar_distributie','compresor') NOT NULL,
    component_key          VARCHAR(64) NOT NULL,
    unit                   VARCHAR(20) NOT NULL,

    -- Route binding. NULL/0 = beneficiary-level component.
    route_scope            ENUM('none','primar','distributie','primar_distributie') NOT NULL DEFAULT 'none',
    route_ref_id           INT UNSIGNED NULL,
    loc_incarcare_id       INT UNSIGNED NULL,
    zona_distributie_id    INT UNSIGNED NULL,

    value                  DECIMAL(14,4) NOT NULL DEFAULT 0.0000,

    valid_from             DATE NOT NULL,
    valid_to               DATE NULL,

    -- Fuel monitoring context frozen at the moment this version was confirmed.
    fuel_weight            DECIMAL(5,4) NULL,
    reference_fuel_price   DECIMAL(12,4) NULL,
    reference_captured_at  DATETIME NULL,

    source                 ENUM('migration','manual') NOT NULL DEFAULT 'manual',
    reason                 VARCHAR(255) NULL,
    created_by             INT UNSIGNED NULL,
    created_at             DATETIME NOT NULL,
    updated_at             DATETIME NOT NULL,

    KEY idx_ttv_signature_from (rule_signature, valid_from),
    KEY idx_ttv_signature_to (rule_signature, valid_to),
    KEY idx_ttv_beneficiar_type (beneficiar_id, transport_type),
    KEY idx_ttv_route (route_ref_id),
    KEY idx_ttv_valid (valid_from, valid_to),
    CONSTRAINT fk_ttv_beneficiar FOREIGN KEY (beneficiar_id)
        REFERENCES configurare_beneficiari_transport(id) ON DELETE CASCADE,
    CONSTRAINT fk_ttv_created_by FOREIGN KEY (created_by)
        REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. Immutable audit of every commercial decision
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS transport_tariff_history (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tariff_version_id       INT UNSIGNED NULL,
    rule_signature          VARCHAR(120) NOT NULL,
    beneficiar_id           INT UNSIGNED NOT NULL,
    transport_type          ENUM('primar','primar_tona','distributie','primar_distributie','compresor') NOT NULL,
    component_key           VARCHAR(64) NOT NULL,
    route_ref_id            INT UNSIGNED NULL,
    route_label             VARCHAR(255) NULL,

    action                  ENUM('created','scheduled','superseded','dismissed','reviewed') NOT NULL,
    old_value               DECIMAL(14,4) NULL,
    new_value               DECIMAL(14,4) NULL,
    unit                    VARCHAR(20) NOT NULL,
    effective_from          DATE NULL,
    effective_to            DATE NULL,

    -- Decision-time fuel snapshot. Preserved even if CardOil later corrects
    -- a transaction, so the commercial decision stays auditable.
    reference_fuel_price    DECIMAL(12,4) NULL,
    observed_fuel_price     DECIMAL(12,4) NULL,
    fuel_variation_percent  DECIMAL(8,4) NULL,
    fuel_liters_analysed    DECIMAL(14,2) NULL,
    fuel_period_start       DATE NULL,
    fuel_period_end         DATE NULL,

    reason                  VARCHAR(255) NULL,
    changed_by              INT UNSIGNED NULL,
    changed_by_name         VARCHAR(190) NULL,
    changed_at              DATETIME NOT NULL,

    KEY idx_tth_signature (rule_signature, changed_at),
    KEY idx_tth_beneficiar (beneficiar_id, changed_at),
    KEY idx_tth_version (tariff_version_id),
    CONSTRAINT fk_tth_version FOREIGN KEY (tariff_version_id)
        REFERENCES transport_tariff_versions(id) ON DELETE SET NULL,
    CONSTRAINT fk_tth_user FOREIGN KEY (changed_by)
        REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. Fuel-driven review recommendations (never mutate a tariff)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS transport_tariff_reviews (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tariff_version_id       INT UNSIGNED NOT NULL,
    rule_signature          VARCHAR(120) NOT NULL,
    beneficiar_id           INT UNSIGNED NOT NULL,
    transport_type          ENUM('primar','primar_tona','distributie','primar_distributie','compresor') NOT NULL,
    component_key           VARCHAR(64) NOT NULL,

    status                  ENUM('OK','REVIEW_RECOMMENDED','DATA_STALE','INSUFFICIENT_DATA','NO_REFERENCE','REVIEWED','DISMISSED')
                            NOT NULL DEFAULT 'OK',

    reference_fuel_price    DECIMAL(12,4) NULL,
    current_weighted_price  DECIMAL(12,4) NULL,
    variation_percent       DECIMAL(8,4) NULL,
    liters_analysed         DECIMAL(14,2) NULL,
    observation_count       INT UNSIGNED NOT NULL DEFAULT 0,
    period_start            DATE NULL,
    period_end              DATE NULL,
    last_sync_at            DATETIME NULL,

    -- Only populated when the component has an explicitly configured fuel_weight.
    recommended_value       DECIMAL(14,4) NULL,

    evaluated_at            DATETIME NOT NULL,
    notified_at             DATETIME NULL,
    resolved_at             DATETIME NULL,
    resolved_by             INT UNSIGNED NULL,

    UNIQUE KEY uk_ttr_version (tariff_version_id),
    KEY idx_ttr_status (status),
    KEY idx_ttr_beneficiar (beneficiar_id),
    CONSTRAINT fk_ttr_version FOREIGN KEY (tariff_version_id)
        REFERENCES transport_tariff_versions(id) ON DELETE CASCADE,
    CONSTRAINT fk_ttr_resolved_by FOREIGN KEY (resolved_by)
        REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. Module settings (threshold, freshness window, …)
--    Deliberately scoped to this module rather than a global settings table.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS transport_tariff_settings (
    setting_key   VARCHAR(80) NOT NULL PRIMARY KEY,
    setting_value VARCHAR(255) NULL,
    updated_by    INT UNSIGNED NULL,
    updated_at    DATETIME NOT NULL,
    CONSTRAINT fk_tts_user FOREIGN KEY (updated_by)
        REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
