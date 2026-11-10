-- Consumabile & Fluide Tehnice (etapa 4): alertele (sursa notificarilor din page=notificari).
-- O alerta deschisa per conditie (cheie); se inchide automat cand conditia dispare la recalculare,
-- sau manual dupa verificare (se redeschide doar la date noi). Aplicata si la rulare de
-- ConsumabileAlertService::ensureSchema().

CREATE TABLE IF NOT EXISTS consumabile_alerte (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tip VARCHAR(40) NOT NULL,
    cheie VARCHAR(80) NOT NULL,
    amprenta VARCHAR(64) NOT NULL,
    severitate VARCHAR(10) NOT NULL,
    vehicle_id INT NULL,
    titlu VARCHAR(190) NOT NULL,
    subiect VARCHAR(190) NOT NULL,
    detalii VARCHAR(500) NULL,
    recomandare VARCHAR(255) NULL,
    link_sub VARCHAR(20) NOT NULL DEFAULT 'vehicule',
    status VARCHAR(10) NOT NULL DEFAULT 'deschisa',
    deschisa_la DATETIME NOT NULL,
    ultima_evaluare DATETIME NOT NULL,
    rezolvata_la DATETIME NULL,
    rezolvare VARCHAR(10) NULL,
    motiv_rezolvare VARCHAR(255) NULL,
    rezolvata_de INT UNSIGNED NULL,
    KEY idx_ca_status (status, tip),
    KEY idx_ca_cheie (cheie, status),
    KEY idx_ca_vehicle (vehicle_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
