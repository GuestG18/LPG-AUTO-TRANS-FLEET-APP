-- =====================================================================
-- Vehicule excluse de la importul CardOil (Carburanti)
-- 2026-09-22
--
-- CONTEXT
--   API-ul CardOil intoarce alimentarile pentru toate cardurile, fara filtru
--   pe vehicul. In Carburanti -> "Vehicule sincronizate" se debifeaza
--   vehiculele ale caror alimentari nu se mai importa. Lista e comuna.
--   Cheia = numarul fara spatii, uppercase (ca REPLACE(UPPER(x),' ','')).
--   FuelModel::ensureSchema creeaza tabela si singur; migrarea o face explicita.
--
-- SAFETY CONTRACT
--   * Additive: o tabela noua.
-- =====================================================================

CREATE TABLE IF NOT EXISTS fuel_vehicle_exclusions (
    vehicle_key VARCHAR(40) NOT NULL PRIMARY KEY,
    vehicle_registration VARCHAR(40) NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
