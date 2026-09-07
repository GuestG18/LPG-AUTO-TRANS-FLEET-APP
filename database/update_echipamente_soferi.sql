-- ============================================================================
-- Echipamente soferi: catalog, stoc nealocat, alocari catre soferi si istoric.
--
-- Pagina "Echipamente soferi" (page=echipamente_soferi) tine evidenta a tot ce
-- primeste un sofer (echipament fizic + comunicatii) si a stocului care NU este
-- alocat momentan. Regula de baza: un articol este ori la un sofer (alocare
-- activa), ori in stoc. Inlocuirea unui articol consuma stoc daca exista.
-- ============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1. Catalog: defineste logica fiecarui tip de articol.
--    tip_logic decide cum se urmareste articolul in interfata:
--      stare          - doar starea fizica (vesta, geaca, pantaloni)
--      stare_periodic - stare + termen de inlocuire (bocanci S3)
--      periodic       - doar termen calendaristic
--      asset          - bun de inventar cu serie/IMEI (telefon, tableta, statie)
--      service_asset  - serviciu cu abonament lunar (SIM, card combustibil)
--      consumabil     - se consuma prin folosire, nu se returneaza
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `echipamente_catalog` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `denumire` VARCHAR(150) NOT NULL,
  `categorie` VARCHAR(60) NOT NULL DEFAULT 'PPE',
  `grupa` ENUM('fizic','comunicatii') NOT NULL DEFAULT 'fizic',
  `tip_logic` ENUM('stare','stare_periodic','periodic','asset','service_asset','consumabil') NOT NULL DEFAULT 'stare',
  `returnabil` TINYINT(1) NOT NULL DEFAULT 1,
  `urmareste_stare` TINYINT(1) NOT NULL DEFAULT 1,
  `inlocuire_periodica` TINYINT(1) NOT NULL DEFAULT 0,
  `durata_standard_luni` INT UNSIGNED DEFAULT NULL,
  `urmareste_expirare` TINYINT(1) NOT NULL DEFAULT 0,
  `necesita_marime` TINYINT(1) NOT NULL DEFAULT 0,
  `necesita_identificator` TINYINT(1) NOT NULL DEFAULT 0,
  `cost_implicit` DECIMAL(10,2) NOT NULL DEFAULT '0.00',
  `cost_lunar` DECIMAL(10,2) DEFAULT NULL,
  `prag_minim_stoc` INT UNSIGNED NOT NULL DEFAULT 0,
  `locatie_implicita` VARCHAR(120) NOT NULL DEFAULT 'Depozit principal',
  `observatii` VARCHAR(255) DEFAULT NULL,
  `activ` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_echip_catalog_denumire` (`denumire`),
  KEY `idx_echip_catalog_grupa` (`grupa`),
  KEY `idx_echip_catalog_categorie` (`categorie`),
  KEY `idx_echip_catalog_activ` (`activ`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. Stoc nealocat, pe articol si gestiune.
--    disponibil = bucati libere, rezervat = promise deja unei inlocuiri.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `echipamente_stoc` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `catalog_id` INT UNSIGNED NOT NULL,
  `locatie` VARCHAR(120) NOT NULL DEFAULT 'Depozit principal',
  `disponibil` INT NOT NULL DEFAULT 0,
  `rezervat` INT NOT NULL DEFAULT 0,
  `in_reparatie` INT NOT NULL DEFAULT 0,
  `prag_minim` INT NOT NULL DEFAULT 0,
  `cost_unitar` DECIMAL(10,2) NOT NULL DEFAULT '0.00',
  `utilizabil_inlocuire` TINYINT(1) NOT NULL DEFAULT 1,
  `observatii` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_echip_stoc_articol_locatie` (`catalog_id`, `locatie`),
  KEY `idx_echip_stoc_disponibil` (`disponibil`),
  CONSTRAINT `fk_echip_stoc_catalog` FOREIGN KEY (`catalog_id`) REFERENCES `echipamente_catalog` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. Alocari catre soferi (predari). O linie = un articol predat unui sofer.
--    status  - ciclul de viata al alocarii
--    stare   - starea fizica raportata la ultima verificare
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `echipamente_alocari` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `driver_id` INT UNSIGNED NOT NULL,
  `catalog_id` INT UNSIGNED NOT NULL,
  `cantitate` INT UNSIGNED NOT NULL DEFAULT 1,
  `marime` VARCHAR(20) DEFAULT NULL,
  `identificator` VARCHAR(120) DEFAULT NULL,
  `operator` VARCHAR(60) DEFAULT NULL,
  `data_predarii` DATE NOT NULL,
  `cost_unitar` DECIMAL(10,2) NOT NULL DEFAULT '0.00',
  `cost_lunar` DECIMAL(10,2) DEFAULT NULL,
  `stare` ENUM('noua','buna','uzata','deteriorata','pierduta') NOT NULL DEFAULT 'buna',
  `status` ENUM('in_uz','activ','de_inlocuit','necesita_inlocuire','returnat','pierdut','inlocuit') NOT NULL DEFAULT 'in_uz',
  `data_inlocuirii_planificata` DATE DEFAULT NULL,
  `data_expirarii` DATE DEFAULT NULL,
  `data_returnarii` DATE DEFAULT NULL,
  `inlocuieste_alocare_id` INT UNSIGNED DEFAULT NULL,
  `observatii` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_echip_alocari_driver` (`driver_id`),
  KEY `idx_echip_alocari_catalog` (`catalog_id`),
  KEY `idx_echip_alocari_status` (`status`),
  KEY `idx_echip_alocari_inlocuire` (`data_inlocuirii_planificata`),
  CONSTRAINT `fk_echip_alocari_driver` FOREIGN KEY (`driver_id`) REFERENCES `soferi` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_echip_alocari_catalog` FOREIGN KEY (`catalog_id`) REFERENCES `echipamente_catalog` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. Istoric miscari (predare / returnare / inlocuire / intrare-iesire stoc).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `echipamente_miscari` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `alocare_id` INT UNSIGNED DEFAULT NULL,
  `catalog_id` INT UNSIGNED NOT NULL,
  `driver_id` INT UNSIGNED DEFAULT NULL,
  `tip` ENUM('predare','returnare','inlocuire','deteriorare','pierdere','intrare_stoc','iesire_stoc','ajustare') NOT NULL,
  `cantitate` INT NOT NULL DEFAULT 1,
  `locatie` VARCHAR(120) DEFAULT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `observatii` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_echip_miscari_catalog` (`catalog_id`),
  KEY `idx_echip_miscari_driver` (`driver_id`),
  KEY `idx_echip_miscari_tip` (`tip`),
  CONSTRAINT `fk_echip_miscari_catalog` FOREIGN KEY (`catalog_id`) REFERENCES `echipamente_catalog` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
