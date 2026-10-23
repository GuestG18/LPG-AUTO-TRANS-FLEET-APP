-- Solicitarile de aprobare (Vehicule, Soferi, Reparatii, Diurne, Rute) trimise administratorilor pe
-- WhatsApp prin BaileyBot, cu raspuns Aprob / Resping din WhatsApp.
-- Aditiv si idempotent. Aceeasi schema se aplica si la runtime: ApprovalWhatsAppService::ensureSchema()
-- si DriverWhatsAppModel::ensureSchema() (destinatar utilizator in whatsapp_outbox).
SET NAMES utf8mb4;

-- whatsapp_outbox: un mesaj poate merge la un sofer (driver_id) sau la un utilizator (user_id).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_outbox' AND COLUMN_NAME = 'user_id');
SET @s := IF(@c = 0, 'ALTER TABLE whatsapp_outbox MODIFY driver_id INT UNSIGNED NULL, ADD COLUMN user_id INT UNSIGNED NULL AFTER driver_id', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS approval_whatsapp_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    approval_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    outbox_id INT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'sent',
    decision VARCHAR(20) NULL,
    decided_at DATETIME NULL,
    decision_wa_message_id VARCHAR(64) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_approval_whatsapp_user (approval_id, user_id),
    KEY idx_approval_whatsapp_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
