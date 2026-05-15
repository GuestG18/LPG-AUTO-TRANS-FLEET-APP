ALTER TABLE vehicule
    ADD COLUMN serie_sasiu VARCHAR(17) NULL AFTER an_fabricatie,
    ADD COLUMN poza_original VARCHAR(255) NULL AFTER serie_sasiu,
    ADD COLUMN poza_stocata VARCHAR(255) NULL AFTER poza_original,
    MODIFY consum_mediu DECIMAL(5,2) NULL;

UPDATE vehicule
SET serie_sasiu = CASE nr_inmatriculare
    WHEN 'B-101-FLT' THEN 'UU1HSDACIA0001001'
    WHEN 'B-202-FLT' THEN 'WF0XXXTTGXLA02021'
    WHEN 'B-303-FLT' THEN 'VF1RCLIOFLEET3030'
    ELSE CONCAT('TEMPVIN', LPAD(id, 10, '0'))
END
WHERE serie_sasiu IS NULL OR TRIM(serie_sasiu) = '';

ALTER TABLE vehicule
    MODIFY serie_sasiu VARCHAR(17) NOT NULL;
