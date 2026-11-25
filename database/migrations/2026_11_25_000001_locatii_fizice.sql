-- Locatii fizice pentru rapoarte (Rentabilitate Operationala: Origine / Destinatie / Depozit).
-- Locurile de incarcare si zonele de distributie sunt definite per beneficiar (acelasi loc apare cu mai multe id-uri);
-- aici fiecare rand din configurare_locuri_incarcare / configurare_zone_distributie se leaga de UN loc fizic.
-- Doar strat de raportare: nu schimba tarifele, facturarea sau configurarea transportului.
-- Tabelele se creeaza si automat la prima folosire (LocationDimensionService::ensureSchema).

CREATE TABLE IF NOT EXISTS locatii_fizice (
    id INT NOT NULL AUTO_INCREMENT,
    nume VARCHAR(120) NOT NULL,
    tip ENUM('punct','regiune') NOT NULL DEFAULT 'punct',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS locatii_fizice_surse (
    sursa ENUM('loc','zona') NOT NULL,
    sursa_id INT NOT NULL,
    locatie_id INT NOT NULL,
    manual TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (sursa, sursa_id),
    KEY idx_lfs_locatie (locatie_id),
    CONSTRAINT fk_lfs_locatie FOREIGN KEY (locatie_id) REFERENCES locatii_fizice (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
