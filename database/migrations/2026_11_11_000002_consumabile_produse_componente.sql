-- Consumabile: piesele din Catalog componente pe care se foloseste produsul (lista de component_key, separate prin virgula).
ALTER TABLE consumabile_produse ADD COLUMN componente_vehicul TEXT NULL AFTER sistem_mecanic;
