-- Rapoarte activitate utilizatori (?page=rapoarte_activitate).
-- Toate modificarile sunt aditive (coloane NULL, tabele si index noi): jurnalele
-- existente raman neatinse. Se aplica si automat la deschiderea paginii
-- (ActivityReportModel::ensureSchema, idempotent acolo).

-- IP + dispozitiv pentru actiunile inregistrate de acum inainte.
ALTER TABLE audit_log
    ADD COLUMN ip_address VARCHAR(45) NULL,
    ADD COLUMN user_agent VARCHAR(255) NULL;

ALTER TABLE cursa_audit_log
    ADD COLUMN ip_address VARCHAR(45) NULL,
    ADD COLUMN user_agent VARCHAR(255) NULL,
    ADD INDEX idx_cursa_audit_performed (performed_at);

ALTER TABLE login_email_codes
    ADD COLUMN ip_address VARCHAR(45) NULL,
    ADD COLUMN user_agent VARCHAR(255) NULL;

-- Autentificari reusite / esuate (cod email, passkey, parola). Nu contine parole sau coduri.
CREATE TABLE IF NOT EXISTS user_login_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    email VARCHAR(190) NULL,
    metoda VARCHAR(30) NOT NULL,
    reusit TINYINT(1) NOT NULL DEFAULT 1,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_login_events_created (created_at),
    INDEX idx_login_events_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vizualizarile salvate ale raportului (per utilizator).
CREATE TABLE IF NOT EXISTS activity_report_views (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    config_json TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_activity_report_view (user_id, name),
    CONSTRAINT fk_activity_report_views_user FOREIGN KEY (user_id) REFERENCES utilizatori(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
