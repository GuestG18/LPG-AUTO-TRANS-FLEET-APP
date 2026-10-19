-- Documente obligatorii pe tip de vehicul (?page=documente&action=required_documents).
-- Pana acum „obligatoriu” era dedus din requires_expiry = 1 (documentele fara
-- expirare, ex. Carte, nu erau verificate niciodata). Coloana noua separa cele doua:
-- un document poate fi obligatoriu si fara data de expirare.
-- Valoarea initiala = requires_expiry, deci statusurile vehiculelor nu se schimba.
-- Se aplica si automat la deschiderea tab-ului (DocumentModel::ensureVehicleRequiredDocumentSchema).

SET @has_obligatoriu := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_costuri_documente_vehicule'
      AND COLUMN_NAME = 'obligatoriu'
);

SET @sql_obligatoriu := IF(
    @has_obligatoriu = 0,
    'ALTER TABLE configurare_costuri_documente_vehicule ADD COLUMN obligatoriu TINYINT(1) NOT NULL DEFAULT 1 AFTER requires_expiry',
    'SELECT 1'
);
PREPARE stmt_obligatoriu FROM @sql_obligatoriu;
EXECUTE stmt_obligatoriu;
DEALLOCATE PREPARE stmt_obligatoriu;

SET @sql_obligatoriu_init := IF(
    @has_obligatoriu = 0,
    'UPDATE configurare_costuri_documente_vehicule SET obligatoriu = requires_expiry',
    'SELECT 1'
);
PREPARE stmt_obligatoriu_init FROM @sql_obligatoriu_init;
EXECUTE stmt_obligatoriu_init;
DEALLOCATE PREPARE stmt_obligatoriu_init;
