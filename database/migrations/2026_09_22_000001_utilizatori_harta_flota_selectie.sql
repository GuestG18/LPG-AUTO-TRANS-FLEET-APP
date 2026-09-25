-- =====================================================================
-- Selectia de vehicule din Harta Flota, salvata per utilizator
-- 2026-09-22
--
-- CONTEXT
--   SAS nu permite alegerea vehiculelor afisate; utilizatorul debifeaza
--   in Harta Flota vehiculele pe care nu vrea sa le vada, iar alegerea
--   se pastreaza intre sesiuni.
--   Se tin vehiculele ASCUNSE (JSON array de id-uri SAS), ca un vehicul
--   nou aparut in SAS sa fie vizibil implicit. NULL = toate vizibile.
--
-- SAFETY CONTRACT
--   * Additive: o singura coloana noua, nullable.
-- =====================================================================

ALTER TABLE utilizatori
    ADD COLUMN harta_flota_ascunse TEXT NULL;
