-- Migrare: adauga transport type Primar tone in curse_dispecer.tip_transport
-- Data: 2026-05-07

SET NAMES utf8mb4;

SET @sql_update_tip_transport_enum := (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'curse_dispecer'
              AND COLUMN_NAME = 'tip_transport'
        ),
        'ALTER TABLE curse_dispecer MODIFY COLUMN tip_transport ENUM(''primar'', ''primar_tona'', ''distributie'', ''primar_distributie'', ''compresor'') NOT NULL',
        'SELECT 1'
    )
);
PREPARE stmt_update_tip_transport_enum FROM @sql_update_tip_transport_enum;
EXECUTE stmt_update_tip_transport_enum;
DEALLOCATE PREPARE stmt_update_tip_transport_enum;
