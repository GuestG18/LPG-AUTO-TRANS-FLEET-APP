-- Aprobare prin email (reply-based) pentru inactive_resource_approvals.
-- Fiecare cerere trimisa pe email primeste doua token-uri: unul de aprobare, unul de respingere.
-- Token-ul circula in adresa de reply (sub-addressing) SI in subiect, ca sa supravietuiasca forward-urilor.
-- In baza de date se pastreaza doar hash-ul SHA-256 al token-ului, niciodata valoarea bruta.

CREATE TABLE IF NOT EXISTS approval_email_actions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    approval_id BIGINT UNSIGNED NOT NULL,
    action ENUM('approve', 'reject') NOT NULL,
    token_hash CHAR(64) NOT NULL,
    recipient_user_id INT UNSIGNED NULL,
    recipient_email VARCHAR(190) NOT NULL,
    reply_address VARCHAR(190) NOT NULL,
    status ENUM('active', 'used', 'expired', 'refused') NOT NULL DEFAULT 'active',
    sent_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    used_from_email VARCHAR(190) NULL,
    used_message_id VARCHAR(255) NULL,
    result_note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_approval_email_actions_token (token_hash),
    INDEX idx_approval_email_actions_approval (approval_id, status),
    INDEX idx_approval_email_actions_recipient (recipient_email, status),
    INDEX idx_approval_email_actions_expiry (status, expires_at),
    CONSTRAINT fk_approval_email_actions_approval
        FOREIGN KEY (approval_id) REFERENCES inactive_resource_approvals(id) ON DELETE CASCADE,
    CONSTRAINT fk_approval_email_actions_user
        FOREIGN KEY (recipient_user_id) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jurnal al mesajelor primite pe casuta de aprobari, inclusiv cele respinse.
-- Serveste la diagnostic ("de ce nu s-a aplicat aprobarea mea?") si la idempotenta pe Message-ID.
CREATE TABLE IF NOT EXISTS approval_email_inbox_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id VARCHAR(255) NULL,
    from_email VARCHAR(190) NULL,
    to_address VARCHAR(190) NULL,
    subject VARCHAR(255) NULL,
    token_found TINYINT(1) NOT NULL DEFAULT 0,
    action_id BIGINT UNSIGNED NULL,
    outcome VARCHAR(60) NOT NULL,
    detail VARCHAR(500) NULL,
    received_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_approval_inbox_message (message_id),
    INDEX idx_approval_inbox_outcome (outcome, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
