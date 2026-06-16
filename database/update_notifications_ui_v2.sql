-- Adds the richer notification rule fields used by the configurable Notificari UI.
SET NAMES utf8mb4;

ALTER TABLE notification_rules
    MODIFY event_type VARCHAR(80) NOT NULL;

ALTER TABLE notification_rules
    MODIFY recipient_mode VARCHAR(40) NOT NULL DEFAULT 'admins';

SET @has_entity_type := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'notification_rules'
      AND COLUMN_NAME = 'entity_type'
);
SET @sql_add_entity_type := IF(
    @has_entity_type = 0,
    "ALTER TABLE notification_rules ADD COLUMN entity_type VARCHAR(40) NOT NULL DEFAULT 'vehicle' AFTER name",
    "SELECT 1"
);
PREPARE stmt_add_entity_type FROM @sql_add_entity_type;
EXECUTE stmt_add_entity_type;
DEALLOCATE PREPARE stmt_add_entity_type;

SET @has_threshold_km := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'notification_rules'
      AND COLUMN_NAME = 'threshold_km'
);
SET @sql_add_threshold_km := IF(
    @has_threshold_km = 0,
    "ALTER TABLE notification_rules ADD COLUMN threshold_km INT UNSIGNED NULL AFTER days_before",
    "SELECT 1"
);
PREPARE stmt_add_threshold_km FROM @sql_add_threshold_km;
EXECUTE stmt_add_threshold_km;
DEALLOCATE PREPARE stmt_add_threshold_km;

SET @has_threshold_tread := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'notification_rules'
      AND COLUMN_NAME = 'threshold_tread_depth'
);
SET @sql_add_threshold_tread := IF(
    @has_threshold_tread = 0,
    "ALTER TABLE notification_rules ADD COLUMN threshold_tread_depth DECIMAL(5,2) NULL AFTER threshold_km",
    "SELECT 1"
);
PREPARE stmt_add_threshold_tread FROM @sql_add_threshold_tread;
EXECUTE stmt_add_threshold_tread;
DEALLOCATE PREPARE stmt_add_threshold_tread;

SET @has_channel := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'notification_rules'
      AND COLUMN_NAME = 'channel'
);
SET @sql_add_channel := IF(
    @has_channel = 0,
    "ALTER TABLE notification_rules ADD COLUMN channel VARCHAR(30) NOT NULL DEFAULT 'email' AFTER threshold_tread_depth",
    "SELECT 1"
);
PREPARE stmt_add_channel FROM @sql_add_channel;
EXECUTE stmt_add_channel;
DEALLOCATE PREPARE stmt_add_channel;

SET @has_repeat_until_resolved := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'notification_rules'
      AND COLUMN_NAME = 'repeat_until_resolved'
);
SET @sql_add_repeat_until_resolved := IF(
    @has_repeat_until_resolved = 0,
    "ALTER TABLE notification_rules ADD COLUMN repeat_until_resolved TINYINT(1) NOT NULL DEFAULT 1 AFTER enabled",
    "SELECT 1"
);
PREPARE stmt_add_repeat_until_resolved FROM @sql_add_repeat_until_resolved;
EXECUTE stmt_add_repeat_until_resolved;
DEALLOCATE PREPARE stmt_add_repeat_until_resolved;

SET @has_daily_limit := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'notification_rules'
      AND COLUMN_NAME = 'daily_limit_enabled'
);
SET @sql_add_daily_limit := IF(
    @has_daily_limit = 0,
    "ALTER TABLE notification_rules ADD COLUMN daily_limit_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER repeat_until_resolved",
    "SELECT 1"
);
PREPARE stmt_add_daily_limit FROM @sql_add_daily_limit;
EXECUTE stmt_add_daily_limit;
DEALLOCATE PREPARE stmt_add_daily_limit;

SET @has_metadata_json := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'notification_rules'
      AND COLUMN_NAME = 'metadata_json'
);
SET @sql_add_metadata_json := IF(
    @has_metadata_json = 0,
    "ALTER TABLE notification_rules ADD COLUMN metadata_json LONGTEXT NULL AFTER daily_limit_enabled",
    "SELECT 1"
);
PREPARE stmt_add_metadata_json FROM @sql_add_metadata_json;
EXECUTE stmt_add_metadata_json;
DEALLOCATE PREPARE stmt_add_metadata_json;

UPDATE notification_rules
SET entity_type = CASE
    WHEN event_type LIKE 'driver_%' THEN 'driver'
    WHEN event_type LIKE 'tire_%' THEN 'tire'
    WHEN event_type LIKE 'equipment_%' THEN 'equipment'
    WHEN event_type LIKE 'leave_%' THEN 'leave'
    ELSE 'vehicle'
END
WHERE entity_type IS NULL
   OR entity_type = ''
   OR event_type IN ('vehicle_document_expiry', 'driver_document_expiry');

ALTER TABLE notification_rules
    DROP INDEX idx_notification_rules_enabled,
    ADD INDEX idx_notification_rules_enabled (enabled, entity_type, event_type);
