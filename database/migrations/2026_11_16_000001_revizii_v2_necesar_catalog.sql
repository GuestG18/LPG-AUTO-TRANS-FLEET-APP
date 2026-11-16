-- Revizii V2: legatura operatiune → Catalog componente (grupul / categoria) si Necesar piese & consumabile.
-- Aplicat si la rulare de ReviziiV2Model::ensureSchema (CREATE TABLE IF NOT EXISTS + ALTER doar daca lipseste coloana).

ALTER TABLE revizii_v2_operatii ADD COLUMN catalog_categorie_id SMALLINT UNSIGNED NULL AFTER component_key;

-- Piesa din Stoc Piese (mentenanta_piese.id) pe regula planului (per model) si ca exceptie pe vehicul.
ALTER TABLE revizii_v2_plan_reguli ADD COLUMN piesa_id INT UNSIGNED NULL AFTER produs_id;
ALTER TABLE revizii_v2_vehicul_reguli ADD COLUMN piesa_id INT UNSIGNED NULL AFTER cantitate;
-- Pe linia lucrarii: piesa folosita, cantitatea, sursa (stoc / direct / nu) si utilizarea scrisa in Stoc Piese la finalizare.
ALTER TABLE revizii_v2_lucrari_operatii ADD COLUMN piesa_id INT UNSIGNED NULL AFTER executie_id;
ALTER TABLE revizii_v2_lucrari_operatii ADD COLUMN piesa_cantitate DECIMAL(12,3) NULL AFTER piesa_id;
ALTER TABLE revizii_v2_lucrari_operatii ADD COLUMN piesa_sursa VARCHAR(10) NULL AFTER piesa_cantitate;
ALTER TABLE revizii_v2_lucrari_operatii ADD COLUMN piesa_utilizare_id BIGINT UNSIGNED NULL AFTER piesa_sursa;

-- Completare initiala (o singura data, editabila din Configurare → Catalog operațiuni):
UPDATE revizii_v2_operatii SET catalog_categorie_id = 18
 WHERE catalog_categorie_id IS NULL AND component_key IS NULL AND (categorie = 'fluide' OR sistem_fluid IS NOT NULL)
   AND EXISTS (SELECT 1 FROM mentenanta_auto_categorii WHERE id = 18 AND activ = 1);
UPDATE revizii_v2_operatii SET catalog_categorie_id = 21
 WHERE catalog_categorie_id IS NULL AND component_key IS NULL AND (categorie = 'filtre' OR (categorie = 'transmisie' AND sistem_fluid IS NULL))
   AND EXISTS (SELECT 1 FROM mentenanta_auto_categorii WHERE id = 21 AND activ = 1);
UPDATE revizii_v2_operatii SET catalog_categorie_id = 20
 WHERE catalog_categorie_id IS NULL AND component_key IS NULL AND categorie = 'gresare'
   AND EXISTS (SELECT 1 FROM mentenanta_auto_categorii WHERE id = 20 AND activ = 1);

CREATE TABLE IF NOT EXISTS revizii_v2_necesar (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NOT NULL,
    lucrare_id INT UNSIGNED NULL,
    plan_id INT UNSIGNED NULL,
    tip CHAR(1) NOT NULL DEFAULT 'X',
    eticheta VARCHAR(10) NULL,
    km_tinta DECIMAL(12,1) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'deschis',
    responsabil VARCHAR(120) NULL,
    data_necesara DATE NULL,
    observatii VARCHAR(1000) NULL,
    cost_estimat DECIMAL(12,2) NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_rv2_nec_vehicle (vehicle_id, status),
    KEY idx_rv2_nec_lucrare (lucrare_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revizii_v2_necesar_linii (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    necesar_id INT UNSIGNED NOT NULL,
    operatie_id INT UNSIGNED NULL,
    sectiune CHAR(1) NOT NULL DEFAULT 'X',
    componenta VARCHAR(120) NOT NULL,
    operatie VARCHAR(190) NOT NULL,
    component_key VARCHAR(10) NULL,
    produs_id INT UNSIGNED NULL,
    piesa_id INT UNSIGNED NULL,
    referinta VARCHAR(190) NULL,
    cantitate DECIMAL(12,3) NULL,
    um VARCHAR(10) NULL,
    disponibil DECIMAL(12,3) NULL,
    lipsa DECIMAL(12,3) NULL,
    pret_unitar DECIMAL(12,2) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'de_verificat',
    observatii VARCHAR(500) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_rv2_necl_necesar (necesar_id),
    KEY idx_rv2_necl_produs (produs_id),
    KEY idx_rv2_necl_piesa (piesa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
