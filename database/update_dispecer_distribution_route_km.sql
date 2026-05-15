SET @has_distribution_route_table := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_rute_distributie'
);

SET @has_route_km_tarifare_column := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_rute_distributie'
      AND COLUMN_NAME = 'km_tarifare'
);

SET @sql_add_route_km_tarifare_column := IF(
    @has_distribution_route_table = 0,
    'SELECT 1',
    IF(
        @has_route_km_tarifare_column = 0,
    'ALTER TABLE configurare_rute_distributie ADD COLUMN km_tarifare INT UNSIGNED NOT NULL DEFAULT 0 AFTER cost_extra_km',
        'SELECT 1'
    )
);

PREPARE stmt_add_route_km_tarifare_column FROM @sql_add_route_km_tarifare_column;
EXECUTE stmt_add_route_km_tarifare_column;
DEALLOCATE PREPARE stmt_add_route_km_tarifare_column;
