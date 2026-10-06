-- =====================================================================
-- Cardurile din Dashboard Analitic V2, alese si ordonate per utilizator
-- 2026-10-06
--
-- CONTEXT
--   Utilizatorul alege ce carduri KPI vede si le ordoneaza prin drag & drop.
--   Se tine JSON {"order": [chei], "hidden": [chei]}; NULL = toate cardurile,
--   in ordinea implicita. Un card nou adaugat in aplicatie apare implicit
--   (la final), pentru ca se retin cele ASCUNSE, nu cele vizibile.
--
--   Coloana se adauga si singura la prima salvare
--   (DashboardAnaliticV2Controller::ensureCardsColumn), ca pe VPS sa nu fie
--   nevoie de rularea manuala a fisierului.
--
-- SAFETY CONTRACT
--   * Additive: o singura coloana noua, nullable.
-- =====================================================================

ALTER TABLE utilizatori
    ADD COLUMN dashboard_v2_carduri TEXT NULL;
