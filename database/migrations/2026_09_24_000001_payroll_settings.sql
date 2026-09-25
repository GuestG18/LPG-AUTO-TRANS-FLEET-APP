-- Calculul fiscal al salariilor (BRUT/NET, contributii, cost firma) este o functie
-- OPTIONALA. Implicit este dezactivat: costul salarial al lunii = salariul configurat
-- al angajatului (din istoricul salarial), ca inainte.

CREATE TABLE IF NOT EXISTS payroll_settings (
    setting_key VARCHAR(60) NOT NULL PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_payroll_settings_user FOREIGN KEY (updated_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO payroll_settings (setting_key, setting_value, updated_at)
VALUES ('fiscal_calculation_enabled', '0', NOW());
