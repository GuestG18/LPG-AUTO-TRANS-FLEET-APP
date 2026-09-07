-- ============================================================================
-- Echipamente TESA: acelasi inventar, alt tip de detinator.
--
-- Modulul avea un singur tip de detinator (sofer). Personalul de birou (TESA,
-- adica staff_members cu staff_types.category = 'office') primeste laptopuri,
-- monitoare, badge-uri de acces etc. din ACELASI catalog si ACELASI stoc.
--
-- Regula ramane: un articol e ori in stoc, ori la un detinator. Se schimba doar
-- cine poate fi detinatorul.
-- ============================================================================

SET NAMES utf8mb4;

-- 1. Alocarea stie acum ce fel de detinator are.
ALTER TABLE `echipamente_alocari`
    ADD COLUMN `detinator_tip` ENUM('sofer','tesa') NOT NULL DEFAULT 'sofer' AFTER `id`,
    ADD COLUMN `staff_id` INT UNSIGNED DEFAULT NULL AFTER `driver_id`,
    MODIFY COLUMN `driver_id` INT UNSIGNED DEFAULT NULL,
    ADD KEY `idx_echip_alocari_detinator` (`detinator_tip`),
    ADD KEY `idx_echip_alocari_staff` (`staff_id`);

ALTER TABLE `echipamente_alocari`
    ADD CONSTRAINT `fk_echip_alocari_staff` FOREIGN KEY (`staff_id`)
        REFERENCES `staff_members` (`id`) ON DELETE CASCADE;

-- 2. Istoricul miscarilor pastreaza si detinatorul TESA.
ALTER TABLE `echipamente_miscari`
    ADD COLUMN `detinator_tip` ENUM('sofer','tesa') DEFAULT NULL AFTER `driver_id`,
    ADD COLUMN `staff_id` INT UNSIGNED DEFAULT NULL AFTER `detinator_tip`;

-- 3. Catalogul spune cui i se preda de obicei articolul. Este o sugestie pentru
--    formularul de predare, NU un inventar separat: stocul ramane comun.
ALTER TABLE `echipamente_catalog`
    ADD COLUMN `destinatie` ENUM('sofer','tesa','ambele') NOT NULL DEFAULT 'ambele' AFTER `grupa`;

-- 4. Gruparea articolelor primeste sectiunile de birou.
--    Randare: soferii vad "Echipamente fizice" (tot ce nu e comunicatii) si
--    "Comunicatii"; TESA vede "IT & Birou", "Comunicatii" si "Acces & alte active".
ALTER TABLE `echipamente_catalog`
    MODIFY COLUMN `grupa` ENUM('fizic','comunicatii','it_birou','acces') NOT NULL DEFAULT 'fizic';

-- 5. Destinatia implicita pentru articolele existente, dupa specificul lor.
UPDATE `echipamente_catalog` SET `destinatie` = 'sofer'
    WHERE `categorie` IN ('PPE', 'Îmbrăcăminte');

UPDATE `echipamente_catalog` SET `destinatie` = 'ambele'
    WHERE `grupa` = 'comunicatii' OR `categorie` IN ('Comunicații', 'Carduri', 'Electronică');
