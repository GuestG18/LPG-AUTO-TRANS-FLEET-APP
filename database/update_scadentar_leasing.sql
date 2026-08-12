CREATE TABLE IF NOT EXISTS leasing_contracts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NOT NULL,
    financier VARCHAR(160) NOT NULL,
    contract_number VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    initial_value DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    advance_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    total_installments INT UNSIGNED NOT NULL DEFAULT 0,
    default_installment_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(12) NOT NULL DEFAULT 'lei',
    frequency ENUM('monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
    due_day TINYINT UNSIGNED NOT NULL DEFAULT 15,
    status ENUM('active','closed','archived') NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    closed_at DATETIME NULL,
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_leasing_contract_number (contract_number),
    INDEX idx_leasing_contracts_vehicle (vehicle_id),
    INDEX idx_leasing_contracts_status (status),
    INDEX idx_leasing_contracts_period (start_date, end_date),
    CONSTRAINT fk_leasing_contracts_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE RESTRICT,
    CONSTRAINT fk_leasing_contracts_user FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leasing_installments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id INT UNSIGNED NOT NULL,
    installment_number INT UNSIGNED NOT NULL,
    due_date DATE NOT NULL,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(12) NOT NULL DEFAULT 'lei',
    status ENUM('unpaid','partial','paid','cancelled') NOT NULL DEFAULT 'unpaid',
    payment_date DATE NULL,
    amount_paid DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    notes TEXT NULL,
    payment_proof_original VARCHAR(255) NULL,
    payment_proof_stored VARCHAR(255) NULL,
    paid_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_leasing_installment_number (contract_id, installment_number),
    INDEX idx_leasing_installments_due (due_date),
    INDEX idx_leasing_installments_status_due (status, due_date),
    CONSTRAINT fk_leasing_installments_contract FOREIGN KEY (contract_id) REFERENCES leasing_contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_leasing_installments_paid_by FOREIGN KEY (paid_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leasing_payment_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id INT UNSIGNED NOT NULL,
    installment_id INT UNSIGNED NOT NULL,
    payment_date DATE NOT NULL,
    amount_paid DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    notes TEXT NULL,
    proof_original VARCHAR(255) NULL,
    proof_stored VARCHAR(255) NULL,
    registered_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_leasing_payment_history_contract (contract_id, payment_date),
    INDEX idx_leasing_payment_history_installment (installment_id),
    CONSTRAINT fk_leasing_payment_history_contract FOREIGN KEY (contract_id) REFERENCES leasing_contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_leasing_payment_history_installment FOREIGN KEY (installment_id) REFERENCES leasing_installments(id) ON DELETE CASCADE,
    CONSTRAINT fk_leasing_payment_history_user FOREIGN KEY (registered_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leasing_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id INT UNSIGNED NOT NULL,
    document_type VARCHAR(80) NOT NULL,
    original_name VARCHAR(255) NULL,
    stored_name VARCHAR(255) NULL,
    mime_type VARCHAR(150) NULL,
    file_size INT UNSIGNED NULL,
    notes TEXT NULL,
    uploaded_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_leasing_documents_contract (contract_id),
    CONSTRAINT fk_leasing_documents_contract FOREIGN KEY (contract_id) REFERENCES leasing_contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_leasing_documents_user FOREIGN KEY (uploaded_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leasing_notification_settings (
    contract_id INT UNSIGNED NOT NULL PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    days_before_7 TINYINT(1) NOT NULL DEFAULT 1,
    days_before_3 TINYINT(1) NOT NULL DEFAULT 1,
    days_before_1 TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_leasing_notification_settings_contract FOREIGN KEY (contract_id) REFERENCES leasing_contracts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leasing_notification_recipients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id INT UNSIGNED NOT NULL,
    email VARCHAR(190) NOT NULL,
    name VARCHAR(160) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_leasing_notification_recipient (contract_id, email),
    INDEX idx_leasing_notification_recipients_email (email),
    CONSTRAINT fk_leasing_notification_recipients_contract FOREIGN KEY (contract_id) REFERENCES leasing_contracts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leasing_notification_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id INT UNSIGNED NOT NULL,
    installment_id INT UNSIGNED NOT NULL,
    recipient_email VARCHAR(190) NOT NULL,
    reminder_interval_days TINYINT UNSIGNED NOT NULL,
    notification_type VARCHAR(60) NOT NULL DEFAULT 'leasing_installment_due',
    sent_at DATETIME NULL,
    status ENUM('sent','failed','skipped') NOT NULL DEFAULT 'sent',
    provider_response TEXT NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_leasing_notification_lookup (installment_id, recipient_email, reminder_interval_days, notification_type, status),
    INDEX idx_leasing_notification_contract (contract_id, created_at),
    CONSTRAINT fk_leasing_notification_log_contract FOREIGN KEY (contract_id) REFERENCES leasing_contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_leasing_notification_log_installment FOREIGN KEY (installment_id) REFERENCES leasing_installments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
