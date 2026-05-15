ALTER TABLE mentenanta
    ADD COLUMN furnizor_piesa VARCHAR(120) NULL AFTER atelier,
    ADD COLUMN fisier_original VARCHAR(255) NULL AFTER furnizor_piesa,
    ADD COLUMN fisier_stocat VARCHAR(255) NULL AFTER fisier_original;
