-- ============================================================================
-- Categorii de echipamente ca listă administrabilă.
--
-- Pana acum categoria era text liber scris in formularul articolului, iar lista
-- se deducea din valorile deja folosite. Devine o tabela proprie, ca sa poata fi
-- aleasa dintr-un dropdown si gestionata dintr-un singur loc.
--
-- `zona` este optionala: NULL inseamna „automat” (potrivire pe cuvinte-cheie, ca
-- pana acum), iar o valoare fixeaza punctul de pe silueta pentru categoriile pe
-- care cuvintele-cheie nu le acopera.
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `echipamente_categorii` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nume` VARCHAR(60) NOT NULL,
  `zona` ENUM('cap','trunchi','maini','picioare','talpi','dispozitiv','legitimatie','alte') DEFAULT NULL,
  `descriere` VARCHAR(255) DEFAULT NULL,
  `activ` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_echip_categorii_nume` (`nume`),
  KEY `idx_echip_categorii_activ` (`activ`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pornim de la categoriile deja folosite in catalog, ca nimic sa nu dispara.
INSERT IGNORE INTO `echipamente_categorii` (`nume`, `zona`, `activ`, `created_at`, `updated_at`)
SELECT DISTINCT TRIM(c.categorie), NULL, 1, NOW(), NOW()
FROM `echipamente_catalog` c
WHERE TRIM(COALESCE(c.categorie, '')) <> '';
