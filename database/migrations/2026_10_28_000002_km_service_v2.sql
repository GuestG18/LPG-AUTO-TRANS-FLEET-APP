-- Km service V2: cererea structurata a dispecerului catre Interventii V2.
-- Strict aditiva; aplicata si la runtime (VehicleServiceKmModel / InterventionWorkflowModel::ensureSchema).

-- Prioritatea ceruta de dispecer (cea din interventie o poate schimba coordonatorul).
ALTER TABLE vehicule_service_km
    ADD COLUMN prioritate ENUM('scazuta','normala','ridicata','critica') NOT NULL DEFAULT 'normala' AFTER observatii;

-- Lucrarile interventiei: componenta poate fi pe semiremorca cuplata (NULL = vehiculul interventiei),
-- iar origine = km_service marcheaza cererea dispecerului (modificabila de el doar cat e neatinsa).
ALTER TABLE mentenanta_interventii_lucrari
    ADD COLUMN vehicle_id INT UNSIGNED NULL AFTER intervention_id,
    ADD COLUMN origine VARCHAR(20) NULL AFTER ramura_principala;
