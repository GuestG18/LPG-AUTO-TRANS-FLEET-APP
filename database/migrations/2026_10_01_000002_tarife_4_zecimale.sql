-- Tarifele unitare din Configurare transport / Administrare tarife păstrează 4 zecimale
-- (1,239 lei/km nu se mai rotunjește la 1,24). Doar lărgire de coloane, fără pierdere de date.
--
-- Copie de referinta: schema se aplica si singura la runtime prin
-- TransportTariffModel::ensureRatePrecision().

ALTER TABLE configurare_beneficiari_transport
    MODIFY pret_tarifare DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    MODIFY pret_km DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    MODIFY pret_tona DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    MODIFY pret_distributie_km DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    MODIFY pret_distributie_tona DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    MODIFY pret_ora_aspirare DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    MODIFY pret_km_dislocare DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    MODIFY pret_tona_livrata DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    MODIFY pret_tona_aspirata_lichida DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    MODIFY pret_tona_aspirata_gazoasa DECIMAL(14,4) NOT NULL DEFAULT 0.0000;

ALTER TABLE configurare_rute_distributie
    MODIFY tarif_tona DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
    MODIFY cost_extra_km DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
    MODIFY cost_cursa DECIMAL(14,4) NOT NULL DEFAULT 0.0000;

ALTER TABLE configurare_rute_primar
    MODIFY cost_cursa DECIMAL(14,4) NOT NULL DEFAULT 0.0000;

ALTER TABLE configurare_zone_distributie
    MODIFY tarif_distributie DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
    MODIFY cost_extra_km DECIMAL(12,4) NOT NULL DEFAULT 0.0000;

ALTER TABLE configurare_locuri_incarcare
    MODIFY tarif DECIMAL(12,4) NOT NULL DEFAULT 0.0000;
