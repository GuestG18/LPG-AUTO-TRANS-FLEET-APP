-- Km service: semiremorca ramane in service, capul tractor se intoarce fara ea.
-- Perioada semiremorcii e un rand propriu in vehicule_service_km (vehicle_id = semiremorca, 0 km),
-- legat de intrarea capului tractor care a dus-o; se sterge odata cu ea.
-- Strict aditiva; aplicata si la runtime (VehicleServiceKmModel::ensureSchema).

ALTER TABLE vehicule_service_km
    ADD COLUMN parent_service_km_id BIGINT UNSIGNED NULL AFTER vehicle_id,
    ADD UNIQUE KEY uq_service_km_parent (parent_service_km_id),
    ADD CONSTRAINT fk_service_km_parent FOREIGN KEY (parent_service_km_id) REFERENCES vehicule_service_km(id) ON DELETE CASCADE;
