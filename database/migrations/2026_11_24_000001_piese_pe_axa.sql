-- Pagina Auto, „Piese pe axă”: componentele se bifeaza pe axa (A1) sau pe roata ei (2-LO = axa 2 stanga exterior), nu pe tipul axei.
-- NULL = dupa axle_kinds (vechi) / toate axele. Aplicat si la runtime de VehicleStructureService::ensureSchema().
ALTER TABLE mentenanta_auto_configurari ADD COLUMN axle_nos VARCHAR(255) NULL AFTER axle_kinds;
ALTER TABLE mentenanta_auto_structuri_componente ADD COLUMN axle_nos VARCHAR(255) NULL AFTER axle_kinds;
