-- Luna de facturare: a treia regula „Impartit pe faze (la miezul noptii)” si optiunea
-- per beneficiar „Refacturarile urmeaza luna cursei”. Ambele se aplica si singure
-- (BillingMonthRule::ensureSchema / saveReinvoiceFollowsTrip) la prima salvare.
ALTER TABLE configurare_beneficiari_luna_facturare
    MODIFY regula ENUM('data_inceput', 'data_sfarsit', 'faze') NOT NULL DEFAULT 'data_inceput';

-- Jurnalul schimbarilor de regula (istoricul din formular); se umple la creare cu
-- versiunile existente (BillingMonthRule::ensureSchema face asta singur).
CREATE TABLE IF NOT EXISTS configurare_beneficiari_luna_facturare_jurnal (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    beneficiar_id INT UNSIGNED NOT NULL,
    tip_transport VARCHAR(32) NOT NULL,
    componenta VARCHAR(16) NOT NULL,
    valabil_de_la DATE NOT NULL,
    regula VARCHAR(16) NOT NULL,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_jurnal_beneficiar (beneficiar_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS configurare_beneficiari_refacturari (
    beneficiar_id INT UNSIGNED NOT NULL PRIMARY KEY,
    urmeaza_cursa TINYINT(1) NOT NULL DEFAULT 0,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
