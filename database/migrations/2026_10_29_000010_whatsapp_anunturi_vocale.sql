-- Anunturile vocale ale conducerii prin WhatsApp (meniul "Inregistreaza mesaj").
-- Aceleasi tabele le creeaza si MaintenanceWhatsAppService::ensureSchema() la nevoie.

CREATE TABLE IF NOT EXISTS whatsapp_voice_broadcasts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    created_by_user_id INT UNSIGNED NOT NULL,
    category VARCHAR(20) NOT NULL,
    audio_wa_message_id VARCHAR(64) NOT NULL,
    audio_seconds INT UNSIGNED NULL,
    scheduled_at DATETIME NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    KEY idx_whatsapp_voice_broadcasts_status (status, scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_voice_broadcast_recipients (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    broadcast_id INT UNSIGNED NOT NULL,
    recipient_type VARCHAR(10) NOT NULL,
    recipient_id INT UNSIGNED NOT NULL,
    nume VARCHAR(120) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    lock_token VARCHAR(32) NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    result_detail VARCHAR(120) NULL,
    called_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_whatsapp_voice_recipient (broadcast_id, phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
