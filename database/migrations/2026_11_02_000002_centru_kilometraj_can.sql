-- Centru Kilometraj: odometrul CAN (de bord) ca sursa separata ('can').
-- Vine din raportul web SAS „Foaie de parcurs cu distanțe din CAN” (SasCanKmService):
-- Index CAN la sfarsitul fiecarui tronson. Nu se aduna niciodata cu SAS (GPS) sau CardOil.
--
-- Se aplica si automat la deschiderea paginii (OdometerCenterModel::ensureSchema).

ALTER TABLE `vehicle_odometer_sources`
  MODIFY `provider_code` enum('sas','cardoil','can') COLLATE utf8mb4_unicode_ci NOT NULL;

ALTER TABLE `vehicle_odometer_readings`
  MODIFY `provider_code` enum('sas','cardoil','can') COLLATE utf8mb4_unicode_ci NOT NULL;

ALTER TABLE `vehicle_odometer_baselines`
  MODIFY `provider_code` enum('sas','cardoil','manual','can') COLLATE utf8mb4_unicode_ci NOT NULL;
