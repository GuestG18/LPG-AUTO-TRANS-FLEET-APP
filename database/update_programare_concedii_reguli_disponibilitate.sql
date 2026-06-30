-- Migrare: reguli disponibilitate soferi pentru Programare Concedii

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS concedii_reguli_disponibilitate (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    garaj VARCHAR(120) NOT NULL,
    categorie_vehicul ENUM('camion', 'ansamblu') NOT NULL,
    capacitate_transport DECIMAL(10,2) NULL,
    min_soferi_disponibili SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_concedii_reguli_lookup (activ, garaj, categorie_vehicul, capacitate_transport),
    INDEX idx_concedii_reguli_scope (garaj, categorie_vehicul)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
