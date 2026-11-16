-- =====================================================================
-- Tablou de bord configurabil: layout-ul cardurilor per utilizator
-- 2026-11-16
--
-- CONTEXT
--   ?page=dashboard afișează cardurile alese de utilizator din registrul
--   DashboardWidgetRegistry (carduri existente din modulele aplicației).
--   Se ține JSON {"v":1,"items":[{"id":"fleet.vehicles","size":"s"}, ...]}:
--   ordinea din listă = ordinea în grilă; ce lipsește e ascuns.
--   NULL = layout-ul implicit. Nu se salvează date ale cardurilor.
--   La citire, cardurile pentru care utilizatorul nu mai are drept se elimină.
--
--   Coloana se adaugă și singură la prima salvare
--   (DashboardController::ensureLayoutColumn), ca pe VPS să nu fie nevoie
--   de rularea manuală a fișierului.
--
-- SAFETY CONTRACT
--   * Additive: o singură coloană nouă, nullable.
-- =====================================================================

ALTER TABLE utilizatori
    ADD COLUMN dashboard_layout TEXT NULL;
