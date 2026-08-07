-- Passkeys (WebAuthn / FIDO2) pentru autentificare fara parola.
-- Fiecare utilizator poate avea mai multe passkey-uri. Parola ramane ca metoda de rezerva.

CREATE TABLE IF NOT EXISTS user_passkeys (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    credential_id VARCHAR(512) NOT NULL,       -- base64url al credential ID-ului
    public_key TEXT NOT NULL,                  -- cheia publica in format PEM
    sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    aaguid VARCHAR(64) NULL,                    -- base64url AAGUID (tip autentificator)
    transports VARCHAR(191) NULL,              -- usb,nfc,ble,internal,hybrid
    label VARCHAR(120) NOT NULL DEFAULT 'Passkey',
    created_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    UNIQUE KEY uq_user_passkeys_credential (credential_id(191)),
    INDEX idx_user_passkeys_user (user_id),
    CONSTRAINT fk_user_passkeys_user FOREIGN KEY (user_id) REFERENCES utilizatori(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
