-- Rebuilds the notification system from zero.
-- Removes the old notificari_* schema and creates a clean queue-backed system.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS notificari_queue;
DROP TABLE IF EXISTS notificari_reguli_destinatari;
DROP TABLE IF EXISTS notificari_reguli;
DROP TABLE IF EXISTS notificari_log;
DROP TABLE IF EXISTS notificari_reguli_documente;

DROP TABLE IF EXISTS notification_queue;
DROP TABLE IF EXISTS notification_rule_recipients;
DROP TABLE IF EXISTS notification_rules;
DROP TABLE IF EXISTS notification_deliveries;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE notification_rules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(190) NOT NULL,
    entity_type VARCHAR(40) NOT NULL DEFAULT 'vehicle',
    event_type VARCHAR(80) NOT NULL,
    document_type VARCHAR(120) NULL,
    days_before INT UNSIGNED NOT NULL DEFAULT 30,
    threshold_km INT UNSIGNED NULL,
    threshold_tread_depth DECIMAL(5,2) NULL,
    channel VARCHAR(30) NOT NULL DEFAULT 'email',
    recipient_mode VARCHAR(40) NOT NULL DEFAULT 'admins',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    repeat_until_resolved TINYINT(1) NOT NULL DEFAULT 1,
    daily_limit_enabled TINYINT(1) NOT NULL DEFAULT 1,
    metadata_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_notification_rules_enabled (enabled, entity_type, event_type),
    KEY idx_notification_rules_document_type (document_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_rule_recipients (
    rule_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (rule_id, user_id),
    KEY idx_notification_rule_recipients_user (user_id),
    CONSTRAINT fk_notification_rule_recipients_rule
        FOREIGN KEY (rule_id) REFERENCES notification_rules(id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_rule_recipients_user
        FOREIGN KEY (user_id) REFERENCES utilizatori(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    context VARCHAR(80) NOT NULL,
    context_id VARCHAR(160) NULL,
    channel ENUM('email') NOT NULL DEFAULT 'email',
    recipient_email VARCHAR(190) NOT NULL,
    recipient_name VARCHAR(190) NULL,
    subject VARCHAR(255) NOT NULL,
    message MEDIUMTEXT NOT NULL,
    status ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
    provider VARCHAR(100) NOT NULL DEFAULT 'smtp',
    provider_response TEXT NULL,
    error_message TEXT NULL,
    diagnostics_json LONGTEXT NULL,
    metadata_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_notification_deliveries_context (context, context_id),
    KEY idx_notification_deliveries_status (status, created_at),
    KEY idx_notification_deliveries_recipient (recipient_email, created_at),
    KEY idx_notification_deliveries_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_queue (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    delivery_id BIGINT UNSIGNED NOT NULL,
    dedupe_key VARCHAR(191) NOT NULL,
    status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
    scheduled_for DATETIME NOT NULL,
    locked_at DATETIME NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_notification_queue_dedupe (dedupe_key),
    KEY idx_notification_queue_pending (status, scheduled_for, id),
    KEY idx_notification_queue_delivery (delivery_id),
    CONSTRAINT fk_notification_queue_delivery
        FOREIGN KEY (delivery_id) REFERENCES notification_deliveries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO notification_rules (
    name,
    entity_type,
    event_type,
    document_type,
    days_before,
    channel,
    recipient_mode,
    enabled,
    repeat_until_resolved,
    daily_limit_enabled,
    created_at,
    updated_at
) VALUES
    ('Documente vehicule - 30 zile', 'vehicle', 'vehicle_document_expiry', NULL, 30, 'email', 'admins', 1, 1, 1, NOW(), NOW()),
    ('Documente soferi - 30 zile', 'driver', 'driver_document_expiry', NULL, 30, 'email', 'admins', 1, 1, 1, NOW(), NOW());
