-- =====================================================================
-- Link-uri temporare de descarcare pentru Fleet Assistant
-- 2026-09-29
--
-- CONTEXT
--   GET /api/assistant/v1/vehicle-document intoarce un download_url temporar
--   (5 minute) pe care Twilio il descarca: /api/assistant/v1/download/{token}.
--   Se pastreaza doar SHA-256 al tokenului; tokenul e legat de un document.
--   AssistantDownloadTokenModel::ensureSchema creeaza tabela si singur.
--
-- SAFETY CONTRACT
--   * Additive: o tabela noua.
-- =====================================================================

CREATE TABLE IF NOT EXISTS assistant_download_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    document_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    channel_id INT UNSIGNED NULL,
    expires_at DATETIME NOT NULL,
    download_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_download_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_assistant_download_token (token_hash),
    KEY idx_assistant_download_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
