-- ============================================================================
-- Catalog echipamente: fotografia articolului.
--
-- Numele fișierului salvat de EquipmentPhotoService în uploads/echipamente
-- (32 caractere hex + .jpg/.png). NULL = fără fotografie. Fotografia apare în
-- catalog, în tabele și în bulele de lângă punctele de pe siluetă.
--
-- DriverEquipmentModel adaugă coloana singur dacă lipsește (ensurePhotoColumn),
-- deci rularea manuală a acestui fișier e opțională.
-- ============================================================================

SET NAMES utf8mb4;

ALTER TABLE `echipamente_catalog`
    ADD COLUMN `imagine` VARCHAR(64) DEFAULT NULL AFTER `observatii`;
