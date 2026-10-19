-- Facturi: proveniența scanarilor venite pe WhatsApp + increderea citirii automate.
-- Aditiv si idempotent: InvoiceModel::ensureFacturiSchema() adauga aceleasi coloane
-- la runtime, deci fiecare pas verifica intai daca exista deja.
--
--   canal            NULL = email (Gmail, ca inainte) | 'whatsapp'
--   canal_mesaj_id   WhatsApp Message ID (deduplicare pe mesaj)
--   canal_grup       JID-ul grupului
--   canal_expeditor  JID-ul expeditorului
--   ocr_incredere    'mare' / 'medie' / 'mica' (din citirea automata)
SET NAMES utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturi' AND COLUMN_NAME = 'canal');
SET @s := IF(@c = 0, 'ALTER TABLE facturi ADD COLUMN canal VARCHAR(20) NULL AFTER email_primit_la', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturi' AND COLUMN_NAME = 'canal_mesaj_id');
SET @s := IF(@c = 0, 'ALTER TABLE facturi ADD COLUMN canal_mesaj_id VARCHAR(255) NULL AFTER canal', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturi' AND COLUMN_NAME = 'canal_grup');
SET @s := IF(@c = 0, 'ALTER TABLE facturi ADD COLUMN canal_grup VARCHAR(150) NULL AFTER canal_mesaj_id', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturi' AND COLUMN_NAME = 'canal_expeditor');
SET @s := IF(@c = 0, 'ALTER TABLE facturi ADD COLUMN canal_expeditor VARCHAR(150) NULL AFTER canal_grup', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturi' AND COLUMN_NAME = 'ocr_incredere');
SET @s := IF(@c = 0, 'ALTER TABLE facturi ADD COLUMN ocr_incredere VARCHAR(10) NULL AFTER ocr_incercari', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturi' AND INDEX_NAME = 'idx_facturi_canal');
SET @s := IF(@c = 0, 'ALTER TABLE facturi ADD INDEX idx_facturi_canal (canal, canal_mesaj_id)', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
