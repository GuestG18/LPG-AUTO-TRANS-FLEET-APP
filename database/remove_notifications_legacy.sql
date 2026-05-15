-- Curata sistemul vechi de notificari dintr-o baza existenta
-- Ruleaza acest script daca ai o baza deja populata si vrei sa elimini complet schema de notificari.

SET @current_db = DATABASE();

-- Sterge indexul vechi, daca exista
SET @has_old_index = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = @current_db
      AND table_name = 'utilizatori'
      AND index_name = 'idx_utilizatori_notificari'
);
SET @sql = IF(@has_old_index > 0,
    'ALTER TABLE utilizatori DROP INDEX idx_utilizatori_notificari',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Sterge coloana notificari_email, daca exista
SET @has_notif_email = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @current_db
      AND table_name = 'utilizatori'
      AND column_name = 'notificari_email'
);
SET @sql = IF(@has_notif_email > 0,
    'ALTER TABLE utilizatori DROP COLUMN notificari_email',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Sterge coloana notificari_sms, daca exista
SET @has_notif_sms = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @current_db
      AND table_name = 'utilizatori'
      AND column_name = 'notificari_sms'
);
SET @sql = IF(@has_notif_sms > 0,
    'ALTER TABLE utilizatori DROP COLUMN notificari_sms',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Asigura index simplu pe status, daca lipseste
SET @has_status_index = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = @current_db
      AND table_name = 'utilizatori'
      AND index_name = 'idx_utilizatori_status'
);
SET @sql = IF(@has_status_index = 0,
    'CREATE INDEX idx_utilizatori_status ON utilizatori(status)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DROP TABLE IF EXISTS notificari_log;
DROP TABLE IF EXISTS notificari_reguli_documente;
