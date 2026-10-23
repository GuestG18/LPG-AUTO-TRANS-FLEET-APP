-- Notificari WhatsApp catre soferi (prima miscare a zilei > 1 km, din GPS-ul SAS) si suport
-- prin WhatsApp + email (tichete #SUP-000123, raspunsurile din email ajung inapoi pe WhatsApp).
-- Aditiv si idempotent: doar tabele noi (CREATE TABLE IF NOT EXISTS vehicle_movement_days (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NOT NULL,
    local_date DATE NOT NULL,
    sas_car_id INT UNSIGNED NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'below_threshold',
    movement_km DECIMAL(8,2) NOT NULL DEFAULT 0,
    first_movement_at DATETIME NULL,
    threshold_reached_at DATETIME NULL,
    trigger_json JSON NULL,
    detail VARCHAR(255) NULL,
    is_simulation TINYINT(1) NOT NULL DEFAULT 0,
    checks INT UNSIGNED NOT NULL DEFAULT 0,
    last_checked_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_vehicle_movement_day (vehicle_id, local_date),
    KEY idx_vehicle_movement_day_status (local_date, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_movement_notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_id INT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    local_date DATE NOT NULL,
    movement_day_id INT UNSIGNED NULL,
    trigger_json JSON NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'queued',
    outbox_id INT UNSIGNED NULL,
    wa_message_id VARCHAR(64) NULL,
    sent_at DATETIME NULL,
    response_action VARCHAR(40) NULL,
    response_text VARCHAR(255) NULL,
    responded_at DATETIME NULL,
    confirmed_at DATETIME NULL,
    last_error VARCHAR(255) NULL,
    is_simulation TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_driver_movement_notification (driver_id, vehicle_id, local_date),
    KEY idx_driver_movement_notification_day (driver_id, local_date),
    KEY idx_driver_movement_notification_wa (wa_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_outbox (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dedupe_key VARCHAR(120) NOT NULL,
    purpose VARCHAR(40) NOT NULL,
    driver_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    body TEXT NOT NULL,
    options_json JSON NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
    next_attempt_at DATETIME NOT NULL,
    lock_token CHAR(32) NULL,
    locked_at DATETIME NULL,
    wa_message_id VARCHAR(64) NULL,
    last_error VARCHAR(255) NULL,
    related_type VARCHAR(40) NULL,
    related_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    UNIQUE KEY uq_whatsapp_outbox_dedupe (dedupe_key),
    KEY idx_whatsapp_outbox_ready (status, next_attempt_at),
    KEY idx_whatsapp_outbox_related (related_type, related_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_inbound_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wa_message_id VARCHAR(64) NOT NULL,
    driver_id INT UNSIGNED NULL,
    kind VARCHAR(20) NOT NULL,
    action_id VARCHAR(40) NULL,
    text_content TEXT NULL,
    outcome VARCHAR(40) NOT NULL DEFAULT 'received',
    ticket_id INT UNSIGNED NULL,
    notification_id INT UNSIGNED NULL,
    received_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_whatsapp_inbound_message (wa_message_id),
    KEY idx_whatsapp_inbound_driver (driver_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_whatsapp_conversations (
    driver_id INT UNSIGNED NOT NULL PRIMARY KEY,
    state VARCHAR(40) NOT NULL DEFAULT 'idle',
    notification_id INT UNSIGNED NULL,
    expires_at DATETIME NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_support_tickets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(20) NULL,
    driver_id INT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NULL,
    notification_id INT UNSIGNED NULL,
    description TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    email_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    email_message_id VARCHAR(255) NULL,
    email_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    email_next_attempt_at DATETIME NULL,
    email_last_error VARCHAR(255) NULL,
    email_sent_at DATETIME NULL,
    source_wa_message_id VARCHAR(64) NULL,
    last_reply_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_driver_support_ticket_reference (reference),
    UNIQUE KEY uq_driver_support_ticket_source (source_wa_message_id),
    UNIQUE KEY uq_driver_support_ticket_email (email_message_id),
    KEY idx_driver_support_ticket_driver (driver_id, status),
    KEY idx_driver_support_ticket_email_status (email_status, email_next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_support_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NULL,
    direction VARCHAR(30) NOT NULL,
    source_message_id VARCHAR(255) NOT NULL,
    sender VARCHAR(255) NULL,
    content TEXT NULL,
    delivery_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    email_message_id VARCHAR(255) NULL,
    outbox_id INT UNSIGNED NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    delivered_at DATETIME NULL,
    UNIQUE KEY uq_driver_support_message_source (direction, source_message_id),
    KEY idx_driver_support_message_ticket (ticket_id, created_at),
    KEY idx_driver_support_message_status (direction, delivery_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
