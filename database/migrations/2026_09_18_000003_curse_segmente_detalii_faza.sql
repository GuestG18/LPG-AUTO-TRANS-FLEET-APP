-- Fazele unei curse oprite si reluate au nevoie de propriile detalii, nu doar de
-- sofer si vehicul: o cursa de distributie are o faza de incarcare (alta locatie,
-- alta ora) si una sau mai multe faze de livrare la clienti, iar tonele livrate
-- pot fi mai putine decat cele incarcate.
--
-- Cursa ramane una singura si se factureaza o singura data; totalurile ei (km,
-- cantitate, tone livrate, nr. clienti, ore) devin suma fazelor.

ALTER TABLE curse_segmente
    ADD COLUMN loc_incarcare_id INT UNSIGNED NULL AFTER driver_id,
    ADD COLUMN zona_distributie_id INT UNSIGNED NULL AFTER loc_incarcare_id,
    ADD COLUMN loc_plecare VARCHAR(255) NULL AFTER zona_distributie_id,
    ADD COLUMN loc_livrare VARCHAR(255) NULL AFTER loc_plecare,
    ADD COLUMN cantitate_incarcata DECIMAL(12,2) NULL AFTER km,
    ADD COLUMN tona_livrata DECIMAL(12,2) NULL AFTER cantitate_incarcata,
    ADD COLUMN nr_clienti INT UNSIGNED NULL AFTER tona_livrata,
    ADD COLUMN ore_functionare DECIMAL(10,2) NULL AFTER nr_clienti,
    ADD KEY idx_segment_loc_incarcare (loc_incarcare_id),
    ADD KEY idx_segment_zona (zona_distributie_id);

-- Tipul fazei (incarcare / livrare / deplasare) NU se stocheaza: se deduce din ce
-- este completat, ca sa nu ramana in urma cand operatorul corecteaza cifrele.
