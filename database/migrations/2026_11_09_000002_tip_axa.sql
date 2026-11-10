-- Tipul axei ales din fisa vehiculului (directie / tractiune / portanta / liftanta).
-- Aplicata si la rulare de VehicleComponentModel::ensureSchema().
ALTER TABLE vehicule_axe_atribute ADD COLUMN tip_axa VARCHAR(12) NULL AFTER axle_no;
