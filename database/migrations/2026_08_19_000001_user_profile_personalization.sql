-- =====================================================================
-- Profilul meu — personalizare avatar + status de prezenta
-- 2026-08-19
--
-- SAFETY CONTRACT
--   * Additive only: five nullable / default-safe columns on `utilizatori`.
--   * No existing column is dropped or altered; no row is touched.
--   * `utilizatori.status` (enum activ/inactiv) is the SECURITY/authorization
--     state and is deliberately left untouched. The new `profile_status` is a
--     separate PRESENCE state and must never gate authentication.
-- =====================================================================

ALTER TABLE utilizatori
    ADD COLUMN avatar_type ENUM('none','image','emoji') NOT NULL DEFAULT 'none' AFTER telefon;

ALTER TABLE utilizatori
    ADD COLUMN avatar_value VARCHAR(255) NULL AFTER avatar_type;

ALTER TABLE utilizatori
    ADD COLUMN avatar_color VARCHAR(20) NULL AFTER avatar_value;

-- Presence status shown to colleagues. NOT an authorization flag.
ALTER TABLE utilizatori
    ADD COLUMN profile_status ENUM('activ','ocupat','indisponibil') NOT NULL DEFAULT 'activ' AFTER avatar_color;

ALTER TABLE utilizatori
    ADD COLUMN status_message VARCHAR(255) NULL AFTER profile_status;
