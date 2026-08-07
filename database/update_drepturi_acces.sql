-- =====================================================================
-- Modulul "Drepturi de acces" — drepturi per-utilizator + sabloane de rol.
-- Tabelele sunt create si automat de AccessRightsModel::ensureSchema() la
-- prima folosire; acest script exista pentru deploy manual / documentare.
-- =====================================================================

CREATE TABLE IF NOT EXISTS access_permissions (
    user_id INT UNSIGNED NOT NULL,
    page_key VARCHAR(64) NOT NULL,
    action_key VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, page_key, action_key),
    INDEX idx_access_permissions_user (user_id),
    CONSTRAINT fk_access_permissions_user FOREIGN KEY (user_id)
        REFERENCES utilizatori(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Marcheaza utilizatorii "configurati" explicit. Un utilizator fara rand aici
-- pastreaza accesul implicit al rolului sau (comportamentul legacy).
CREATE TABLE IF NOT EXISTS access_user_state (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    customized_at DATETIME NOT NULL,
    customized_by INT UNSIGNED NULL,
    CONSTRAINT fk_access_user_state_user FOREIGN KEY (user_id)
        REFERENCES utilizatori(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS access_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    slug VARCHAR(80) NOT NULL UNIQUE,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS access_template_permissions (
    template_id INT UNSIGNED NOT NULL,
    page_key VARCHAR(64) NOT NULL,
    action_key VARCHAR(64) NOT NULL,
    PRIMARY KEY (template_id, page_key, action_key),
    CONSTRAINT fk_access_template_permissions_template FOREIGN KEY (template_id)
        REFERENCES access_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
