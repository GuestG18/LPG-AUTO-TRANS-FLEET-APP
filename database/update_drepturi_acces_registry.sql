-- =====================================================================
-- Drepturi de acces — catalogul de permisiuni din BD (registru auto-populat).
--
-- Oglinda in BD a PermissionRegistry (htdocs/permissions/modules/*.php).
-- Tabela este creata si automat de AccessRightsModel::ensureSchema(), iar
-- continutul se sincronizeaza idempotent la deschiderea paginii
-- "Drepturi de acces" sau cu:  php scripts/sync_permissions.php
--
-- Non-distructiv: nu atinge access_permissions / access_user_state /
-- access_templates. Cheile existente (page_key, action_key) raman aceleasi,
-- deci nu e nevoie de backfill pentru drepturile deja acordate.
--
-- Revert:  DROP TABLE access_permission_catalog;   (nu afecteaza drepturile)
-- =====================================================================

CREATE TABLE IF NOT EXISTS access_permission_catalog (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    permission_key VARCHAR(130) NOT NULL,
    module_key VARCHAR(64) NOT NULL,
    action_key VARCHAR(64) NOT NULL,
    module_label VARCHAR(190) NOT NULL,
    label VARCHAR(255) NOT NULL,
    section_key VARCHAR(64) NOT NULL,
    action_group VARCHAR(64) NOT NULL DEFAULT '',
    admin_only TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    deprecated_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_access_permission_catalog_key (permission_key),
    UNIQUE KEY uq_access_permission_catalog_pair (module_key, action_key),
    INDEX idx_access_permission_catalog_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
