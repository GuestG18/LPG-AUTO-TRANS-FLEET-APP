-- Migrare: soft delete si audit pentru cursele din Dispecer curse
SET NAMES utf8mb4;

SET @column_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'deleted_at'
);
SET @duplicate_key_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'duplicate_key'
);
SET @deleted_at_position := IF(@duplicate_key_exists = 0, 'AFTER updated_at', 'AFTER duplicate_key');
SET @sql := IF(
    @column_exists = 0,
    CONCAT('ALTER TABLE curse_dispecer ADD COLUMN deleted_at DATETIME NULL ', @deleted_at_position),
    'SELECT ''deleted_at exista deja'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'deleted_by'
);
SET @sql := IF(
    @column_exists = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN deleted_by INT UNSIGNED NULL AFTER deleted_at',
    'SELECT ''deleted_by exista deja'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND INDEX_NAME = 'idx_curse_deleted_at'
);
SET @sql := IF(
    @index_exists = 0,
    'ALTER TABLE curse_dispecer ADD INDEX idx_curse_deleted_at (deleted_at)',
    'SELECT ''idx_curse_deleted_at exista deja'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND INDEX_NAME = 'idx_curse_deleted_by'
);
SET @sql := IF(
    @index_exists = 0,
    'ALTER TABLE curse_dispecer ADD INDEX idx_curse_deleted_by (deleted_by)',
    'SELECT ''idx_curse_deleted_by exista deja'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'deleted_by'
      AND REFERENCED_TABLE_NAME = 'utilizatori'
      AND REFERENCED_COLUMN_NAME = 'id'
);
SET @sql := IF(
    @fk_exists = 0,
    'ALTER TABLE curse_dispecer ADD CONSTRAINT fk_curse_deleted_by FOREIGN KEY (deleted_by) REFERENCES utilizatori(id) ON DELETE SET NULL',
    'SELECT ''fk_curse_deleted_by exista deja'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS cursa_audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cursa_id INT UNSIGNED NOT NULL,
    action ENUM('created', 'updated', 'deleted', 'restored', 'status_changed') NOT NULL,
    performed_by INT UNSIGNED NULL,
    performed_at DATETIME NOT NULL,
    details_json LONGTEXT NULL,
    INDEX idx_cursa_audit_cursa (cursa_id, performed_at),
    INDEX idx_cursa_audit_action (action),
    INDEX idx_cursa_audit_user (performed_by),
    CONSTRAINT fk_cursa_audit_cursa FOREIGN KEY (cursa_id) REFERENCES curse_dispecer(id) ON DELETE CASCADE,
    CONSTRAINT fk_cursa_audit_user FOREIGN KEY (performed_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
