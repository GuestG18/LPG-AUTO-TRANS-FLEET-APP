-- Programari service pe WhatsApp (BaileyBot): cererea soferului devine o interventie Interventii V2
-- "De verificat"; operatorul o aproba / respinge din WhatsApp. Necesita 2026_10_28_000001_interventii_v2.sql.
-- Aditiv si idempotent. Aceeasi schema se aplica si la runtime: MaintenanceWhatsAppService::ensureSchema().
SET NAMES utf8mb4;

-- Starea conversatiei (pe numar de telefon): pasul curent, ciorna si versiunea butoanelor.
CREATE TABLE IF NOT EXISTS whatsapp_maintenance_sessions (
    phone VARCHAR(20) NOT NULL PRIMARY KEY,
    role VARCHAR(10) NOT NULL,
    actor_id INT UNSIGNED NOT NULL,
    flow VARCHAR(30) NOT NULL,
    step VARCHAR(30) NOT NULL,
    data_json JSON NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    expires_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Interventiile cerute din WhatsApp: soferul care le-a cerut si ce i s-a comunicat deja (status|data|ora).
CREATE TABLE IF NOT EXISTS whatsapp_maintenance_requests (
    intervention_id INT UNSIGNED NOT NULL PRIMARY KEY,
    driver_id INT UNSIGNED NOT NULL,
    source_wa_message_id VARCHAR(64) NULL,
    notified_state VARCHAR(80) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_whatsapp_maintenance_request_source (source_wa_message_id),
    KEY idx_whatsapp_maintenance_request_driver (driver_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Responsabilul tehnic notificat pe WhatsApp (o data pentru fiecare interventie + responsabil).
CREATE TABLE IF NOT EXISTS whatsapp_maintenance_assignments (
    intervention_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (intervention_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
