ALTER TABLE documente
    ADD COLUMN fisier_original VARCHAR(255) NULL AFTER data_expirare,
    ADD COLUMN fisier_stocat VARCHAR(255) NULL AFTER fisier_original;

CREATE TABLE audit_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    modul VARCHAR(50) NOT NULL,
    record_id INT UNSIGNED NOT NULL,
    actiune VARCHAR(50) NOT NULL,
    descriere VARCHAR(255) NOT NULL,
    before_data LONGTEXT NULL,
    after_data LONGTEXT NULL,
    user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_audit_modul_record (modul, record_id),
    INDEX idx_audit_created_at (created_at),
    CONSTRAINT fk_audit_log_user FOREIGN KEY (user_id) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
