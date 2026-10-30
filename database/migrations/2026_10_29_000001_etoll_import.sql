-- Importul eToll: sesiunile de taxare per km inchise devin facturi Toll in pagina Facturi.
-- Aditiv si idempotent: scripts/import_etoll.php face aceleasi modificari la runtime
-- (InvoiceModel::ensureFacturiSchema + EtollImportService::ensureSchema).
--
--   facturi.sursa      + 'etoll' (sursa_key = 'etoll:<session_id>')
--   etoll_sesiuni      o sesiune inchisa: camion, remorci, ora inchiderii (ora Romaniei),
--                      suma cu TVA, documentul CC-P din eToll si factura creata
SET NAMES utf8mb4;

ALTER TABLE facturi
    MODIFY COLUMN sursa ENUM('scan', 'manual', 'legacy_cazare', 'etoll') NOT NULL;

CREATE TABLE IF NOT EXISTS etoll_sesiuni (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(64) NOT NULL,
    nr_inmatriculare VARCHAR(20) NOT NULL,
    remorci VARCHAR(100) NULL,
    inchisa_la DATETIME NOT NULL,
    suma DECIMAL(12,2) NOT NULL,
    invoice_id VARCHAR(64) NULL,
    numar_document VARCHAR(60) NULL,
    cota_tva DECIMAL(5,2) NULL,
    factura_id INT UNSIGNED NULL,
    eroare VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_etoll_sesiuni_session (session_id),
    KEY idx_etoll_sesiuni_invoice (invoice_id),
    KEY idx_etoll_sesiuni_inchisa (inchisa_la),
    KEY idx_etoll_sesiuni_factura (factura_id),
    CONSTRAINT fk_etoll_sesiuni_factura FOREIGN KEY (factura_id) REFERENCES facturi(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
