-- Situatia eToll (?page=etoll): registrul portofelului, documentele sesiunilor (anexa TA_10 +
-- desfasuratorul), facturile alimentarilor si soldul. Aditiv si idempotent: scripts/sync_etoll_situatie.php
-- face aceleasi modificari la runtime (EtollStatementModel::ensureSchema).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS etoll_tranzactii (
    tx_id VARCHAR(80) NOT NULL PRIMARY KEY,
    tip ENUM('alimentare', 'sesiune') NOT NULL,
    suma DECIMAL(12,2) NOT NULL,
    data DATETIME NOT NULL,
    in_curs TINYINT(1) NOT NULL DEFAULT 0,
    nr_inmatriculare VARCHAR(20) NULL,
    remorci VARCHAR(100) NULL,
    session_id VARCHAR(64) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_etoll_tranzactii_data (tip, data),
    KEY idx_etoll_tranzactii_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS etoll_documente (
    session_id VARCHAR(64) NOT NULL PRIMARY KEY,
    invoice_id VARCHAR(64) NULL,
    annex_id VARCHAR(64) NULL,
    numar_document VARCHAR(60) NULL,
    inceput DATETIME NULL,
    sfarsit DATETIME NULL,
    origine VARCHAR(255) NULL,
    destinatie VARCHAR(255) NULL,
    km_tarifabil DECIMAL(10,2) NULL,
    total_etoll DECIMAL(12,2) NULL,
    factura_path VARCHAR(255) NULL,
    anexa_path VARCHAR(255) NULL,
    desfasurator_path VARCHAR(255) NULL,
    incercari INT UNSIGNED NOT NULL DEFAULT 0,
    eroare VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS etoll_alimentari_facturi (
    invoice_id VARCHAR(64) NOT NULL PRIMARY KEY,
    numar_document VARCHAR(60) NULL,
    data DATETIME NOT NULL,
    total DECIMAL(12,2) NOT NULL,
    pdf_path VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    KEY idx_etoll_alimentari_facturi_data (data)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS etoll_sold (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    verificat_la DATETIME NOT NULL,
    sold_etoll DECIMAL(12,2) NOT NULL,
    alimentari DECIMAL(12,2) NOT NULL,
    consum_inchis DECIMAL(12,2) NOT NULL,
    consum_in_curs DECIMAL(12,2) NOT NULL,
    sesiuni_in_curs INT UNSIGNED NOT NULL,
    KEY idx_etoll_sold_verificat (verificat_la)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

