-- Migrare: adauga tip vehicul Autoutilitara
-- Data: 2026-06-03

SET NAMES utf8mb4;

ALTER TABLE vehicule
    MODIFY COLUMN tip_vehicul ENUM(
        'autovehicul',
        'autoutilitara',
        'camion',
        'cap_tractor',
        'semiremorca',
        'semiremorca_primar',
        'semiremorca_distributie'
    ) NOT NULL DEFAULT 'autovehicul';
