-- Rollback pentru 2026_10_22_000002_centru_kilometraj.sql.
-- ATENTIE: sterge referintele T0, istoricul citirilor SAS si jurnalul de audit
-- ale Centrului Kilometraj. Nu atinge vehicule, fuel_fillups sau alte module.
-- Inainte: dezactiveaza ruta (altfel ensureSchema() recreeaza tabelele goale).

DROP TABLE IF EXISTS `vehicle_odometer_audit`;
DROP TABLE IF EXISTS `vehicle_odometer_baselines`;
DROP TABLE IF EXISTS `vehicle_odometer_readings`;
DROP TABLE IF EXISTS `vehicle_odometer_sources`;
DROP TABLE IF EXISTS `km_center_setari`;
