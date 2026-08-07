-- Fleet Management MVP local reset SQL
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS curse_cheltuieli_documente;
DROP TABLE IF EXISTS curse_cheltuieli;
DROP TABLE IF EXISTS curse_dispecer;
DROP TABLE IF EXISTS concedii_reguli_disponibilitate;
DROP TABLE IF EXISTS concedii;
DROP TABLE IF EXISTS configurare_zone_distributie_vehicule;
DROP TABLE IF EXISTS configurare_zone_distributie;
DROP TABLE IF EXISTS configurare_beneficiari_transport;
DROP TABLE IF EXISTS configurare_locuri_incarcare_vehicule;
DROP TABLE IF EXISTS configurare_locuri_incarcare;
DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS salary_history;
DROP TABLE IF EXISTS employee_employment_periods;
DROP TABLE IF EXISTS staff_documents;
DROP TABLE IF EXISTS staff_document_requirements;
DROP TABLE IF EXISTS staff_members;
DROP TABLE IF EXISTS staff_types;
DROP TABLE IF EXISTS documente_soferi;
DROP TABLE IF EXISTS documente;
DROP TABLE IF EXISTS configurare_costuri_documente_soferi;
DROP TABLE IF EXISTS configurare_documente_obligatorii_soferi;
DROP TABLE IF EXISTS inventar_dotari_vehicule;
DROP TABLE IF EXISTS inventar_dotari_reguli;
DROP TABLE IF EXISTS inventar_dotari_catalog;
DROP TABLE IF EXISTS mentenanta_grupe_componente;
DROP TABLE IF EXISTS mentenanta;
DROP TABLE IF EXISTS alimentari;
DROP TABLE IF EXISTS vehicule_cuplaje;
DROP TABLE IF EXISTS soferi_vehicule;
DROP TABLE IF EXISTS soferi;
DROP TABLE IF EXISTS vehicule;
DROP TABLE IF EXISTS login_email_codes;
DROP TABLE IF EXISTS utilizatori;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE utilizatori (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nume VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    telefon VARCHAR(20) NULL,
    parola VARCHAR(255) NOT NULL,
    rol ENUM('admin', 'contabilitate', 'utilizator') NOT NULL DEFAULT 'utilizator',
    status ENUM('activ', 'inactiv') NOT NULL DEFAULT 'activ',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_utilizatori_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_email_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    email VARCHAR(190) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    sent_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_login_email_codes_user_active (user_id, used_at, expires_at),
    INDEX idx_login_email_codes_email_active (email, used_at, expires_at),
    INDEX idx_login_email_codes_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vehicule (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nr_inmatriculare VARCHAR(20) NOT NULL UNIQUE,
    marca VARCHAR(50) NOT NULL,
    model VARCHAR(50) NOT NULL,
    tip_vehicul ENUM('autovehicul', 'camion', 'cap_tractor', 'semiremorca') NOT NULL DEFAULT 'autovehicul',
    an_fabricatie SMALLINT UNSIGNED NOT NULL,
    km_bord INT UNSIGNED NOT NULL DEFAULT 0,
    km_revizie INT UNSIGNED NOT NULL DEFAULT 0,
    serie_sasiu VARCHAR(17) NOT NULL,
    nr_fabricatie VARCHAR(100) NULL,
    capacitate_transport DECIMAL(10,2) NULL,
    formula_axelor VARCHAR(20) NULL,
    capacitate_rezervor DECIMAL(10,2) NULL,
    mma DECIMAL(10,2) NULL,
    organism_notificat VARCHAR(150) NULL,
    garaj VARCHAR(120) NULL,
    poza_original VARCHAR(255) NULL,
    poza_stocata VARCHAR(255) NULL,
    consum_mediu DECIMAL(5,2) NULL,
    status ENUM('activ', 'inactiv') NOT NULL DEFAULT 'activ',
    observatii TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_vehicule_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vehicule_cuplaje (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tractor_id INT UNSIGNED NOT NULL,
    semiremorca_id INT UNSIGNED NOT NULL,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    data_start DATETIME NOT NULL,
    data_end DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_vehicule_cuplaje_tractor_activ (tractor_id, activ),
    INDEX idx_vehicule_cuplaje_semiremorca_activ (semiremorca_id, activ),
    INDEX idx_vehicule_cuplaje_start (data_start),
    CONSTRAINT fk_vehicule_cuplaje_tractor FOREIGN KEY (tractor_id) REFERENCES vehicule(id) ON DELETE CASCADE,
    CONSTRAINT fk_vehicule_cuplaje_semiremorca FOREIGN KEY (semiremorca_id) REFERENCES vehicule(id) ON DELETE CASCADE,
    CONSTRAINT fk_vehicule_cuplaje_created_by FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE soferi (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nume VARCHAR(100) NOT NULL,
    data_nasterii DATE NULL,
    data_angajare DATE NULL,
    data_incetare DATE NULL,
    employment_status ENUM('active','temporarily_inactive','suspended','leave','terminated') NOT NULL DEFAULT 'active',
    termination_date DATE NULL,
    termination_reason VARCHAR(120) NULL,
    termination_notes TEXT NULL,
    last_working_day DATE NULL,
    termination_document_original VARCHAR(255) NULL,
    termination_document_path VARCHAR(255) NULL,
    rehire_eligible TINYINT(1) NOT NULL DEFAULT 1,
    termination_assets_returned TINYINT(1) NOT NULL DEFAULT 0,
    terminated_by INT UNSIGNED NULL,
    terminated_at DATETIME NULL,
    poza_original VARCHAR(255) NULL,
    poza_stocata VARCHAR(255) NULL,
    telefon VARCHAR(20) NOT NULL,
    salariu DECIMAL(10,2) NULL,
    vehicle_id INT UNSIGNED NULL,
    permis_expira_la DATE NOT NULL,
    status ENUM('activ', 'inactiv') NOT NULL DEFAULT 'activ',
    observatii TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_soferi_status (status),
    INDEX idx_soferi_employment_status (employment_status, termination_date),
    INDEX idx_soferi_vehicle (vehicle_id),
    CONSTRAINT fk_soferi_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE soferi_vehicule (
    driver_id INT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (driver_id, vehicle_id),
    INDEX idx_soferi_vehicule_vehicle (vehicle_id),
    INDEX idx_soferi_vehicule_driver_primary (driver_id, is_primary),
    CONSTRAINT fk_soferi_vehicule_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE CASCADE,
    CONSTRAINT fk_soferi_vehicule_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE concedii (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_id INT UNSIGNED NOT NULL,
    tip_concediu ENUM('odihna', 'personal', 'medical', 'fara_plata') NOT NULL,
    data_inceput DATE NOT NULL,
    data_sfarsit DATE NOT NULL,
    inlocuitor_id INT UNSIGNED NULL,
    note TEXT NULL,
    status ENUM('aprobat', 'respins', 'in_asteptare', 'in_asteptare_aprobare') NOT NULL DEFAULT 'in_asteptare',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_concedii_driver (driver_id),
    INDEX idx_concedii_inlocuitor (inlocuitor_id),
    INDEX idx_concedii_status (status),
    INDEX idx_concedii_perioada (data_inceput, data_sfarsit),
    CONSTRAINT fk_concedii_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE RESTRICT,
    CONSTRAINT fk_concedii_inlocuitor FOREIGN KEY (inlocuitor_id) REFERENCES soferi(id) ON DELETE SET NULL,
    CONSTRAINT fk_concedii_created_by FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE concedii_reguli_disponibilitate (
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

CREATE TABLE alimentari (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NOT NULL,
    driver_id INT UNSIGNED NULL,
    data_alimentare DATE NOT NULL,
    litri DECIMAL(8,2) NOT NULL,
    cost_total DECIMAL(10,2) NOT NULL,
    km_bord INT UNSIGNED NOT NULL,
    km_alimentare INT UNSIGNED NOT NULL,
    observatii TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_alimentari_vehicle (vehicle_id),
    INDEX idx_alimentari_driver (driver_id),
    INDEX idx_alimentari_data (data_alimentare),
    CONSTRAINT fk_alimentari_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE,
    CONSTRAINT fk_alimentari_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mentenanta (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NOT NULL,
    tip_interventie VARCHAR(150) NOT NULL,
    data_interventie DATE NOT NULL,
    cost DECIMAL(10,2) NOT NULL,
    atelier VARCHAR(120) NULL,
    furnizor_piesa VARCHAR(120) NULL,
    fisier_original VARCHAR(255) NULL,
    fisier_stocat VARCHAR(255) NULL,
    observatii TEXT NULL,
    custom_fields_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_mentenanta_vehicle (vehicle_id),
    INDEX idx_mentenanta_data (data_interventie),
    CONSTRAINT fk_mentenanta_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mentenanta_grupe_componente (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_type VARCHAR(40) NOT NULL DEFAULT 'universal',
    nume VARCHAR(120) NOT NULL,
    componente TEXT NULL,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_ment_grupe_vehicle_name (vehicle_type, nume),
    INDEX idx_ment_grupe_vehicle_active (vehicle_type, activ)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO mentenanta_grupe_componente (vehicle_type, nume, componente, activ, created_at, updated_at) VALUES
('universal', 'Motor', NULL, 1, NOW(), NOW()),
('universal', 'Transmisie', NULL, 1, NOW(), NOW()),
('universal', 'Sistem franare', NULL, 1, NOW(), NOW()),
('universal', 'Sistem electric', NULL, 1, NOW(), NOW()),
('universal', 'Suspensie', NULL, 1, NOW(), NOW()),
('universal', 'Sistem pneumatic', NULL, 1, NOW(), NOW()),
('universal', 'Sistem hidraulic', NULL, 1, NOW(), NOW()),
('universal', 'Sistem racire', NULL, 1, NOW(), NOW()),
('universal', 'Caroserie', NULL, 1, NOW(), NOW()),
('universal', 'Consumabile', NULL, 1, NOW(), NOW()),
('universal', 'Altele', NULL, 1, NOW(), NOW());

CREATE TABLE documente (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NOT NULL,
    tip_document VARCHAR(100) NOT NULL,
    numar_document VARCHAR(100) NOT NULL,
    data_expirare DATE NULL,
    fisier_original VARCHAR(255) NULL,
    fisier_stocat VARCHAR(255) NULL,
    observatii TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_documente_vehicle (vehicle_id),
    INDEX idx_documente_expirare (data_expirare),
    CONSTRAINT fk_documente_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE configurare_costuri_documente_soferi (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_id INT UNSIGNED NOT NULL,
    document_type VARCHAR(100) NOT NULL,
    document_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    validity_days INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_config_doc_driver_document (driver_id, document_type),
    INDEX idx_config_doc_driver_driver (driver_id),
    INDEX idx_config_doc_driver_type (document_type),
    CONSTRAINT fk_config_doc_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE configurare_documente_obligatorii_soferi (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_type VARCHAR(100) NOT NULL,
    requires_expiry TINYINT(1) NOT NULL DEFAULT 1,
    custom_fields_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_required_driver_document_type (document_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventar_dotari_catalog (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nume VARCHAR(150) NOT NULL,
    categorie VARCHAR(120) NOT NULL,
    equipment_type ENUM('mandatory', 'optional') NOT NULL DEFAULT 'mandatory',
    poza_original VARCHAR(255) NULL,
    poza_stocata VARCHAR(255) NULL,
    cost_implicit DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    necesita_data_fabricatie TINYINT(1) NOT NULL DEFAULT 0,
    necesita_inspectie TINYINT(1) NOT NULL DEFAULT 0,
    interval_implicit_inspectie_luni INT UNSIGNED NULL,
    necesita_data_expirarii TINYINT(1) NOT NULL DEFAULT 0,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_inventar_catalog_nume (nume),
    INDEX idx_inventar_catalog_activ (activ),
    INDEX idx_inventar_catalog_categorie (categorie),
    INDEX idx_inventar_catalog_equipment_type (equipment_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventar_dotari_reguli (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_type ENUM('autovehicul', 'autoutilitara', 'camion', 'cap_tractor', 'semiremorca', 'semiremorca_primar', 'semiremorca_distributie') NOT NULL,
    catalog_id INT UNSIGNED NOT NULL,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_inventar_reguli_type_catalog (vehicle_type, catalog_id),
    INDEX idx_inventar_reguli_type_active (vehicle_type, activ),
    INDEX idx_inventar_reguli_catalog (catalog_id),
    CONSTRAINT fk_inventar_reguli_catalog FOREIGN KEY (catalog_id) REFERENCES inventar_dotari_catalog(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventar_dotari_vehicule (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NOT NULL,
    catalog_id INT UNSIGNED NOT NULL,
    poza_original VARCHAR(255) NULL,
    poza_stocata VARCHAR(255) NULL,
    cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    data_achizitiei DATE NULL,
    data_fabricatiei DATE NULL,
    data_ultimei_inspectii DATE NULL,
    interval_inspectie_luni INT UNSIGNED NULL,
    data_urmatoarei_inspectii DATE NULL,
    data_expirarii DATE NULL,
    serie_cod_produs VARCHAR(120) NULL,
    observatii TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_inventar_dotari_vehicle (vehicle_id),
    INDEX idx_inventar_dotari_catalog (catalog_id),
    INDEX idx_inventar_dotari_expirare (data_expirarii),
    INDEX idx_inventar_dotari_inspectie (data_urmatoarei_inspectii),
    CONSTRAINT fk_inventar_dotari_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE,
    CONSTRAINT fk_inventar_dotari_catalog FOREIGN KEY (catalog_id) REFERENCES inventar_dotari_catalog(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE documente_soferi (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_id INT UNSIGNED NOT NULL,
    tip_document VARCHAR(100) NOT NULL,
    numar_document VARCHAR(100) NULL,
    data_emitere DATE NULL,
    data_expirare DATE NULL,
    fisier_original VARCHAR(255) NULL,
    fisier_stocat VARCHAR(255) NULL,
    observatii TEXT NULL,
    custom_fields_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_documente_soferi_driver (driver_id),
    INDEX idx_documente_soferi_expirare (data_expirare),
    UNIQUE KEY uk_documente_soferi_driver_type (driver_id, tip_document),
    CONSTRAINT fk_documente_soferi_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(140) NOT NULL,
    category ENUM('operational', 'office') NOT NULL DEFAULT 'operational',
    description TEXT NULL,
    status ENUM('activ', 'inactiv') NOT NULL DEFAULT 'activ',
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    is_driver_linked TINYINT(1) NOT NULL DEFAULT 0,
    salary_required TINYINT(1) NOT NULL DEFAULT 0,
    vehicle_required TINYINT(1) NOT NULL DEFAULT 0,
    mandatory_documents_enabled TINYINT(1) NOT NULL DEFAULT 1,
    can_create_employees TINYINT(1) NOT NULL DEFAULT 1,
    can_delete_employees TINYINT(1) NOT NULL DEFAULT 1,
    document_warning_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_staff_types_slug (slug),
    INDEX idx_staff_types_category_status (category, status),
    INDEX idx_staff_types_driver_linked (is_driver_linked),
    CONSTRAINT fk_staff_types_created_by FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL,
    CONSTRAINT fk_staff_types_updated_by FOREIGN KEY (updated_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_type_id INT UNSIGNED NOT NULL,
    nume_complet VARCHAR(160) NOT NULL,
    telefon VARCHAR(20) NULL,
    email VARCHAR(190) NULL,
    functie VARCHAR(120) NOT NULL,
    salariu DECIMAL(10,2) NULL,
    data_angajare DATE NULL,
    data_incetare DATE NULL,
    employment_status ENUM('active','temporarily_inactive','suspended','leave','terminated') NOT NULL DEFAULT 'active',
    termination_date DATE NULL,
    termination_reason VARCHAR(120) NULL,
    termination_notes TEXT NULL,
    last_working_day DATE NULL,
    termination_document_original VARCHAR(255) NULL,
    termination_document_path VARCHAR(255) NULL,
    rehire_eligible TINYINT(1) NOT NULL DEFAULT 1,
    termination_assets_returned TINYINT(1) NOT NULL DEFAULT 0,
    terminated_by INT UNSIGNED NULL,
    terminated_at DATETIME NULL,
    status ENUM('activ', 'inactiv') NOT NULL DEFAULT 'activ',
    observatii TEXT NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_staff_members_type_status (staff_type_id, status),
    INDEX idx_staff_members_employment_status (employment_status, termination_date),
    INDEX idx_staff_members_name (nume_complet),
    CONSTRAINT fk_staff_members_type FOREIGN KEY (staff_type_id) REFERENCES staff_types(id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_members_created_by FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL,
    CONSTRAINT fk_staff_members_updated_by FOREIGN KEY (updated_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_member_id INT UNSIGNED NOT NULL,
    tip_document VARCHAR(120) NOT NULL,
    numar_document VARCHAR(120) NULL,
    data_emitere DATE NULL,
    data_expirare DATE NULL,
    fisier_original VARCHAR(255) NULL,
    fisier_stocat VARCHAR(255) NULL,
    observatii TEXT NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_staff_documents_member (staff_member_id),
    INDEX idx_staff_documents_expirare (data_expirare),
    INDEX idx_staff_documents_type (tip_document),
    CONSTRAINT fk_staff_documents_member FOREIGN KEY (staff_member_id) REFERENCES staff_members(id) ON DELETE CASCADE,
    CONSTRAINT fk_staff_documents_created_by FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL,
    CONSTRAINT fk_staff_documents_updated_by FOREIGN KEY (updated_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff_document_requirements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_type_id INT UNSIGNED NOT NULL,
    document_type VARCHAR(120) NOT NULL,
    requires_expiry TINYINT(1) NOT NULL DEFAULT 1,
    warning_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_staff_doc_req_type_document (staff_type_id, document_type),
    INDEX idx_staff_doc_req_type (staff_type_id),
    CONSTRAINT fk_staff_doc_req_type FOREIGN KEY (staff_type_id) REFERENCES staff_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE salary_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject_type ENUM('driver', 'staff') NOT NULL,
    driver_id INT UNSIGNED NULL,
    staff_member_id INT UNSIGNED NULL,
    previous_salary DECIMAL(10,2) NULL,
    current_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    effective_date DATE NOT NULL,
    updated_by INT UNSIGNED NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_salary_history_driver (driver_id, effective_date),
    INDEX idx_salary_history_staff (staff_member_id, effective_date),
    INDEX idx_salary_history_subject (subject_type, effective_date),
    CONSTRAINT fk_salary_history_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE CASCADE,
    CONSTRAINT fk_salary_history_staff FOREIGN KEY (staff_member_id) REFERENCES staff_members(id) ON DELETE CASCADE,
    CONSTRAINT fk_salary_history_user FOREIGN KEY (updated_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE employee_employment_periods (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject_type ENUM('driver', 'staff') NOT NULL,
    driver_id INT UNSIGNED NULL,
    staff_member_id INT UNSIGNED NULL,
    source_module ENUM('soferi', 'contabilitate_personal') NOT NULL,
    personnel_type ENUM('operational', 'office') NOT NULL DEFAULT 'operational',
    staff_type_id INT UNSIGNED NULL,
    function_name VARCHAR(120) NULL,
    salary DECIMAL(10,2) NULL,
    hire_date DATE NULL,
    last_working_day DATE NULL,
    termination_date DATE NULL,
    termination_reason VARCHAR(120) NULL,
    termination_notes TEXT NULL,
    status ENUM('active', 'terminated') NOT NULL DEFAULT 'active',
    rehire_eligible TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_employee_period_driver (driver_id, status, hire_date),
    INDEX idx_employee_period_staff (staff_member_id, status, hire_date),
    INDEX idx_employee_period_subject (subject_type, status),
    CONSTRAINT fk_employee_period_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE CASCADE,
    CONSTRAINT fk_employee_period_staff FOREIGN KEY (staff_member_id) REFERENCES staff_members(id) ON DELETE CASCADE,
    CONSTRAINT fk_employee_period_staff_type FOREIGN KEY (staff_type_id) REFERENCES staff_types(id) ON DELETE SET NULL,
    CONSTRAINT fk_employee_period_created_by FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL,
    CONSTRAINT fk_employee_period_updated_by FOREIGN KEY (updated_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE configurare_locuri_incarcare (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    beneficiar_id INT UNSIGNED NOT NULL,
    nume VARCHAR(120) NOT NULL,
    tarif DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_config_locuri_beneficiar_nume (beneficiar_id, nume),
    INDEX idx_config_locuri_beneficiar (beneficiar_id),
    INDEX idx_config_locuri_activ (activ)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE configurare_zone_distributie (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    beneficiar_id INT UNSIGNED NOT NULL,
    nume VARCHAR(120) NOT NULL,
    tarif_distributie DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    cost_extra_km DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_config_zone_beneficiar_nume (beneficiar_id, nume),
    INDEX idx_config_zone_beneficiar (beneficiar_id),
    INDEX idx_config_zone_activ (activ)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE configurare_locuri_incarcare_vehicule (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    beneficiar_id INT UNSIGNED NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    loc_incarcare_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_config_locuri_beneficiar_vehicle (beneficiar_id, vehicle_id),
    INDEX idx_config_locuri_vehicle_beneficiar (beneficiar_id),
    INDEX idx_config_locuri_vehicle_vehicle (vehicle_id),
    INDEX idx_config_locuri_vehicle_loc (loc_incarcare_id),
    CONSTRAINT fk_config_locuri_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE,
    CONSTRAINT fk_config_locuri_loc FOREIGN KEY (loc_incarcare_id) REFERENCES configurare_locuri_incarcare(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE configurare_zone_distributie_vehicule (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    beneficiar_id INT UNSIGNED NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    zona_distributie_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_config_zone_beneficiar_vehicle (beneficiar_id, vehicle_id),
    INDEX idx_config_zone_vehicle_beneficiar (beneficiar_id),
    INDEX idx_config_zone_vehicle_vehicle (vehicle_id),
    INDEX idx_config_zone_vehicle_zona (zona_distributie_id),
    CONSTRAINT fk_config_zone_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE,
    CONSTRAINT fk_config_zone_zona FOREIGN KEY (zona_distributie_id) REFERENCES configurare_zone_distributie(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE configurare_beneficiari_transport (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nume VARCHAR(150) NOT NULL,
    tip_marfa VARCHAR(50) NULL,
    pret_tarifare DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    suporta_primar TINYINT(1) NOT NULL DEFAULT 1,
    suporta_distributie TINYINT(1) NOT NULL DEFAULT 1,
    suporta_primar_distributie TINYINT(1) NOT NULL DEFAULT 0,
    suporta_compresor TINYINT(1) NOT NULL DEFAULT 0,
    pret_km DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pret_tona DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pret_distributie_km DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pret_distributie_tona DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pret_ora_aspirare DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pret_km_dislocare DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pret_tona_livrata DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_config_beneficiari_nume (nume),
    INDEX idx_config_beneficiari_activ (activ)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE configurare_locuri_incarcare
    ADD CONSTRAINT fk_config_locuri_beneficiar FOREIGN KEY (beneficiar_id) REFERENCES configurare_beneficiari_transport(id) ON DELETE CASCADE;

ALTER TABLE configurare_zone_distributie
    ADD CONSTRAINT fk_config_zone_beneficiar FOREIGN KEY (beneficiar_id) REFERENCES configurare_beneficiari_transport(id) ON DELETE CASCADE;

ALTER TABLE configurare_locuri_incarcare_vehicule
    ADD CONSTRAINT fk_config_locuri_vehicle_beneficiar FOREIGN KEY (beneficiar_id) REFERENCES configurare_beneficiari_transport(id) ON DELETE CASCADE;

ALTER TABLE configurare_zone_distributie_vehicule
    ADD CONSTRAINT fk_config_zone_vehicle_beneficiar FOREIGN KEY (beneficiar_id) REFERENCES configurare_beneficiari_transport(id) ON DELETE CASCADE;

CREATE TABLE curse_dispecer (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NOT NULL,
    tip_transport ENUM('primar', 'distributie', 'compresor') NOT NULL,
    data_cursa DATE NOT NULL,
    loc_incarcare_id INT UNSIGNED NULL,
    loc_plecare VARCHAR(255) NULL,
    loc_aspirare VARCHAR(255) NULL,
    loc_livrare VARCHAR(255) NULL,
    loc_livrare_cursa VARCHAR(255) NULL,
    beneficiar_id INT UNSIGNED NULL,
    tip_marfa VARCHAR(50) NULL,
    cantitate_incarcata DECIMAL(12,2) NULL,
    cantitate_prelevata DECIMAL(12,2) NULL,
    nr_clienti INT UNSIGNED NULL,
    km_cursa INT UNSIGNED NULL,
    ore_functionare DECIMAL(10,2) NULL,
    ore_aspirare DECIMAL(12,2) NULL,
    km_dislocare DECIMAL(12,2) NULL,
    tona_livrata DECIMAL(12,2) NULL,
    zona_distributie_id INT UNSIGNED NULL,
    status_facturare ENUM('in_curs_facturare', 'facturat', 'nefacturat') NOT NULL DEFAULT 'in_curs_facturare',
    pret_tarifare DECIMAL(12,2) NOT NULL,
    total_facturare DECIMAL(12,2) NOT NULL,
    cost_km_primar DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cost_km_distributie DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cost_km_mixt DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    observatii TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    duplicate_key CHAR(64) NULL,
    INDEX idx_curse_vehicle (vehicle_id),
    INDEX idx_curse_tip_transport (tip_transport),
    INDEX idx_curse_data (data_cursa),
    INDEX idx_curse_loc (loc_incarcare_id),
    INDEX idx_curse_beneficiar (beneficiar_id),
    INDEX idx_curse_zona (zona_distributie_id),
    UNIQUE KEY uk_curse_dispecer_duplicate_key (duplicate_key),
    CONSTRAINT fk_curse_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE RESTRICT,
    CONSTRAINT fk_curse_loc FOREIGN KEY (loc_incarcare_id) REFERENCES configurare_locuri_incarcare(id) ON DELETE RESTRICT,
    CONSTRAINT fk_curse_beneficiar FOREIGN KEY (beneficiar_id) REFERENCES configurare_beneficiari_transport(id) ON DELETE RESTRICT,
    CONSTRAINT fk_curse_zona FOREIGN KEY (zona_distributie_id) REFERENCES configurare_zone_distributie(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE curse_cheltuieli (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cursa_id INT UNSIGNED NOT NULL,
    tip_cheltuiala ENUM('motorina', 'taxe_drum', 'diurna', 'service', 'alte') NOT NULL,
    refacturare_tip_cheltuiala ENUM('motorina', 'taxe_drum', 'diurna', 'service', 'alte') NULL,
    refacturare_detalii TEXT NULL,
    refacturare_suma DECIMAL(12,2) NULL,
    refacturare_data DATE NULL,
    refacturare_observatii TEXT NULL,
    refacturare_document_path VARCHAR(255) NULL,
    refacturare_document_original_name VARCHAR(255) NULL,
    refacturare_document_mime_type VARCHAR(150) NULL,
    refacturare_document_file_size INT UNSIGNED NULL,
    suma DECIMAL(12,2) NOT NULL,
    data_cheltuiala DATE NOT NULL,
    observatii TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_curse_cheltuieli_cursa (cursa_id),
    INDEX idx_curse_cheltuieli_data (data_cheltuiala),
    CONSTRAINT fk_curse_cheltuieli_cursa FOREIGN KEY (cursa_id) REFERENCES curse_dispecer(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE curse_cheltuieli_documente (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cheltuiala_id INT UNSIGNED NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(150) NULL,
    file_size INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_curse_doc_cheltuiala (cheltuiala_id),
    CONSTRAINT fk_curse_doc_cheltuiala FOREIGN KEY (cheltuiala_id) REFERENCES curse_cheltuieli(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    modul VARCHAR(50) NOT NULL,
    record_id INT UNSIGNED NOT NULL,
    actiune VARCHAR(50) NOT NULL,
    descriere VARCHAR(255) NOT NULL,
    before_data LONGTEXT NULL,
    after_data LONGTEXT NULL,
    user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_audit_modul_record (modul, record_id),
    INDEX idx_audit_created_at (created_at),
    CONSTRAINT fk_audit_log_user FOREIGN KEY (user_id) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO utilizatori (nume, email, telefon, parola, rol, status, created_at, updated_at) VALUES
('Administrator Sistem', 'admin@example.com', '0722000001', '$2y$10$GWugLSIk7.dnwlxTjcT0Dec4JrE0QSLHSsW59JvT2sBIw9YFlUzhu', 'admin', 'activ', NOW(), NOW()),
('Operator Flota', 'user@example.com', '0722000002', '$2y$10$GWugLSIk7.dnwlxTjcT0Dec4JrE0QSLHSsW59JvT2sBIw9YFlUzhu', 'utilizator', 'activ', NOW(), NOW());

INSERT INTO vehicule (nr_inmatriculare, marca, model, tip_vehicul, an_fabricatie, km_bord, km_revizie, serie_sasiu, garaj, poza_original, poza_stocata, consum_mediu, status, observatii, created_at, updated_at) VALUES
('B-101-FLT', 'Dacia', 'Duster', 'autovehicul', 2021, 85420, 90000, 'UU1HSDACIA0001001', 'Garaj Bucuresti', NULL, NULL, NULL, 'activ', 'Vehicul pentru livrari locale', NOW(), NOW()),
('B-202-FLT', 'Ford', 'Transit', 'camion', 2020, 120300, 130000, 'WF0XXXTTGXLA02021', 'Garaj Ilfov', NULL, NULL, NULL, 'activ', 'Autoutilitara transport marfa', NOW(), NOW()),
('B-303-FLT', 'Renault', 'Clio', 'autovehicul', 2019, 142210, 150000, 'VF1RCLIOFLEET3030', 'Garaj Brasov', NULL, NULL, NULL, 'inactiv', 'In service prelungit', NOW(), NOW());

INSERT INTO soferi (nume, telefon, salariu, vehicle_id, permis_expira_la, status, observatii, created_at, updated_at) VALUES
('Ionescu Mihai', '0722000001', 5200.00, 1, DATE_ADD(CURDATE(), INTERVAL 300 DAY), 'activ', 'Disponibil full-time', NOW(), NOW()),
('Popescu Andrei', '0722000002', 5000.00, 2, DATE_ADD(CURDATE(), INTERVAL 120 DAY), 'activ', 'Route urban', NOW(), NOW()),
('Marin Elena', '0722000003', 4700.00, NULL, DATE_ADD(CURDATE(), INTERVAL 45 DAY), 'inactiv', 'Concediu medical', NOW(), NOW());

INSERT INTO soferi_vehicule (driver_id, vehicle_id, is_primary, created_at, updated_at)
SELECT id, vehicle_id, 1, COALESCE(created_at, NOW()), COALESCE(updated_at, NOW())
FROM soferi
WHERE vehicle_id IS NOT NULL;

INSERT INTO concedii (
    driver_id,
    tip_concediu,
    data_inceput,
    data_sfarsit,
    inlocuitor_id,
    note,
    status,
    created_by,
    created_at,
    updated_at
) VALUES
(1, 'odihna', DATE_ADD(CURDATE(), INTERVAL 2 DAY), DATE_ADD(CURDATE(), INTERVAL 5 DAY), 2, 'Concediu planificat pentru odihnă.', 'aprobat', 1, NOW(), NOW()),
(2, 'personal', DATE_ADD(CURDATE(), INTERVAL 10 DAY), DATE_ADD(CURDATE(), INTERVAL 12 DAY), 1, 'Eveniment personal.', 'in_asteptare', 1, NOW(), NOW()),
(1, 'medical', DATE_ADD(CURDATE(), INTERVAL 16 DAY), DATE_ADD(CURDATE(), INTERVAL 18 DAY), NULL, 'Consultații și recuperare.', 'in_asteptare_aprobare', 2, NOW(), NOW()),
(2, 'fara_plata', DATE_ADD(CURDATE(), INTERVAL 25 DAY), DATE_ADD(CURDATE(), INTERVAL 26 DAY), NULL, 'Rezolvare urgentă familie.', 'respins', 2, NOW(), NOW());

INSERT INTO alimentari (vehicle_id, driver_id, data_alimentare, litri, cost_total, km_bord, km_alimentare, observatii, created_at, updated_at) VALUES
(1, 1, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 45.50, 355.40, 85420, 85420, 'Motorina statia A', NOW(), NOW()),
(2, 2, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 60.00, 468.00, 120300, 120300, 'Motorina statia B', NOW(), NOW()),
(1, 1, DATE_SUB(CURDATE(), INTERVAL 15 DAY), 42.00, 327.60, 84920, 84920, 'Alimentare traseu Brasov', NOW(), NOW()),
(3, NULL, DATE_SUB(CURDATE(), INTERVAL 20 DAY), 30.00, 240.00, 142210, 142210, 'Alimentare inainte de intrare service', NOW(), NOW());

INSERT INTO mentenanta (vehicle_id, tip_interventie, data_interventie, cost, atelier, furnizor_piesa, fisier_original, fisier_stocat, observatii, created_at, updated_at) VALUES
(1, 'Schimb ulei si filtre', DATE_SUB(CURDATE(), INTERVAL 12 DAY), 780.00, 'Service Rapid SRL', 'Piese Auto Nord', NULL, NULL, 'Revizie periodica', NOW(), NOW()),
(2, 'Placute frana fata', DATE_SUB(CURDATE(), INTERVAL 5 DAY), 540.00, 'AutoFix', 'Auto Partener SRL', NULL, NULL, 'Uzura normala', NOW(), NOW()),
(3, 'Diagnoza electrica', DATE_SUB(CURDATE(), INTERVAL 1 MONTH), 320.00, 'ElectroCar', 'Electro Parts', NULL, NULL, 'Investigatie martor bord', NOW(), NOW());

INSERT INTO documente (vehicle_id, tip_document, numar_document, data_expirare, observatii, created_at, updated_at) VALUES
(1, 'RCA', 'RCA-001-2026', DATE_ADD(CURDATE(), INTERVAL 15 DAY), 'Prioritate mare pentru reinnoire', NOW(), NOW()),
(1, 'ITP', 'ITP-001-2026', DATE_ADD(CURDATE(), INTERVAL 55 DAY), 'Programare deja facuta', NOW(), NOW()),
(2, 'Rovinieta', 'ROV-7788', DATE_ADD(CURDATE(), INTERVAL 8 DAY), 'Verificare online recomandata', NOW(), NOW()),
(3, 'RCA', 'RCA-003-2026', DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'Expirat - nu circula', NOW(), NOW());

INSERT INTO documente_soferi (driver_id, tip_document, numar_document, data_expirare, observatii, created_at, updated_at) VALUES
(1, 'Carte identitate', 'CI-ION-2026', DATE_ADD(CURDATE(), INTERVAL 420 DAY), 'Document personal incarcat pentru evidenta interna', NOW(), NOW()),
(1, 'Atestat profesional', 'ATP-101', DATE_ADD(CURDATE(), INTERVAL 18 DAY), 'Necesita verificare pentru reinnoire', NOW(), NOW()),
(2, 'Aviz medical', 'MED-2026-02', DATE_ADD(CURDATE(), INTERVAL 65 DAY), 'Valabil pentru cursele curente', NOW(), NOW());

INSERT INTO configurare_costuri_documente_soferi (driver_id, document_type, document_cost, validity_days, created_at, updated_at)
SELECT
    driver_id,
    tip_document,
    0.00,
    GREATEST(1, DATEDIFF(MAX(data_expirare), CURDATE())),
    NOW(),
    NOW()
FROM documente_soferi
GROUP BY driver_id, tip_document;

INSERT INTO configurare_documente_obligatorii_soferi
    (document_type, created_at, updated_at)
VALUES
    ('Carte identitate', NOW(), NOW()),
    ('Atestat profesional', NOW(), NOW()),
    ('Aviz medical', NOW(), NOW());

INSERT INTO staff_types
    (name, slug, category, description, status, is_system, is_driver_linked, salary_required, vehicle_required, mandatory_documents_enabled, can_create_employees, can_delete_employees, document_warning_days, created_at, updated_at)
VALUES
    ('Șofer', 'sofer', 'operational', 'Conectat la modulul Șoferi. Importă automat șoferii existenți.', 'activ', 1, 1, 1, 1, 1, 0, 0, 30, NOW(), NOW()),
    ('Ajutor Șofer', 'ajutor-sofer', 'operational', 'Personal operațional auxiliar.', 'activ', 0, 0, 0, 0, 1, 1, 1, 30, NOW(), NOW()),
    ('Mecanic', 'mecanic', 'operational', 'Personal operațional pentru mentenanță.', 'activ', 0, 0, 0, 0, 1, 1, 1, 30, NOW(), NOW()),
    ('Dispecer', 'dispecer', 'operational', 'Personal operațional de coordonare curse.', 'activ', 0, 0, 0, 0, 1, 1, 1, 30, NOW(), NOW()),
    ('Spălător', 'spalator', 'operational', 'Personal operațional de curățenie vehicule.', 'activ', 0, 0, 0, 0, 1, 1, 1, 30, NOW(), NOW()),
    ('Contabil', 'contabil', 'office', 'Personal birou pentru contabilitate.', 'activ', 0, 0, 0, 0, 1, 1, 1, 30, NOW(), NOW()),
    ('Administrator', 'administrator', 'office', 'Personal birou administrativ.', 'activ', 0, 0, 0, 0, 1, 1, 1, 30, NOW(), NOW()),
    ('Manager', 'manager', 'office', 'Personal birou management.', 'activ', 0, 0, 0, 0, 1, 1, 1, 30, NOW(), NOW()),
    ('HR', 'hr', 'office', 'Personal birou resurse umane.', 'activ', 0, 0, 0, 0, 1, 1, 1, 30, NOW(), NOW()),
    ('Operator', 'operator', 'office', 'Personal birou operațional.', 'activ', 0, 0, 0, 0, 1, 1, 1, 30, NOW(), NOW()),
    ('Personal Curățenie', 'personal-curatenie', 'office', 'Personal birou curățenie.', 'activ', 0, 0, 0, 0, 1, 1, 1, 30, NOW(), NOW());

INSERT INTO staff_document_requirements
    (staff_type_id, document_type, requires_expiry, warning_days, created_at, updated_at)
SELECT st.id, req.document_type, req.requires_expiry, 30, NOW(), NOW()
FROM staff_types st
INNER JOIN (
    SELECT 'sofer' AS slug, 'CI / Buletin' AS document_type, 1 AS requires_expiry UNION ALL
    SELECT 'sofer', 'Permis conducere', 1 UNION ALL
    SELECT 'sofer', 'Medicina muncii', 1 UNION ALL
    SELECT 'sofer', 'Aviz medical', 1 UNION ALL
    SELECT 'sofer', 'Contract de muncă', 0 UNION ALL
    SELECT 'contabil', 'CI / Buletin', 1 UNION ALL
    SELECT 'contabil', 'Contract de muncă', 0 UNION ALL
    SELECT 'contabil', 'Act adițional', 0 UNION ALL
    SELECT 'hr', 'CI / Buletin', 1 UNION ALL
    SELECT 'hr', 'Contract de muncă', 0 UNION ALL
    SELECT 'mecanic', 'CI / Buletin', 1 UNION ALL
    SELECT 'mecanic', 'Medicina muncii', 1
) req ON req.slug = st.slug;

INSERT INTO inventar_dotari_catalog
    (nume, categorie, equipment_type, cost_implicit, necesita_data_fabricatie, necesita_inspectie, interval_implicit_inspectie_luni, necesita_data_expirarii, activ, created_at, updated_at)
VALUES
    ('Extinctor', 'Siguranță', 'mandatory', 120.00, 1, 1, 12, 1, 1, NOW(), NOW()),
    ('Trusă ADR', 'ADR', 'mandatory', 250.00, 0, 1, 12, 1, 1, NOW(), NOW()),
    ('Trusă Medicală', 'Siguranță', 'mandatory', 90.00, 0, 0, NULL, 1, 1, NOW(), NOW()),
    ('Apă Ochi', 'ADR', 'mandatory', 45.00, 0, 0, NULL, 1, 1, NOW(), NOW()),
    ('Mască', 'Protecție', 'mandatory', 80.00, 0, 0, NULL, 0, 1, NOW(), NOW()),
    ('Filtru Mască', 'Protecție', 'mandatory', 35.00, 0, 0, NULL, 1, 1, NOW(), NOW()),
    ('Baterii', 'Consumabile', 'mandatory', 20.00, 0, 0, NULL, 1, 1, NOW(), NOW()),
    ('Lanternă', 'Siguranță', 'mandatory', 65.00, 0, 1, 12, 0, 1, NOW(), NOW()),
    ('Vestă reflectorizantă', 'Siguranță', 'mandatory', 30.00, 0, 0, NULL, 0, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    categorie = VALUES(categorie),
    cost_implicit = VALUES(cost_implicit),
    necesita_data_fabricatie = VALUES(necesita_data_fabricatie),
    necesita_inspectie = VALUES(necesita_inspectie),
    interval_implicit_inspectie_luni = VALUES(interval_implicit_inspectie_luni),
    necesita_data_expirarii = VALUES(necesita_data_expirarii),
    activ = VALUES(activ),
    updated_at = NOW();

INSERT INTO inventar_dotari_reguli
    (vehicle_type, catalog_id, activ, created_at, updated_at)
SELECT required.vehicle_type, c.id, 1, NOW(), NOW()
FROM (
    SELECT 'cap_tractor' AS vehicle_type, 'Extinctor' AS nume UNION ALL
    SELECT 'cap_tractor', 'Trusă Medicală' UNION ALL
    SELECT 'cap_tractor', 'Trusă ADR' UNION ALL
    SELECT 'semiremorca_primar', 'Extinctor' UNION ALL
    SELECT 'semiremorca_primar', 'Trusă ADR' UNION ALL
    SELECT 'semiremorca_distributie', 'Extinctor' UNION ALL
    SELECT 'semiremorca_distributie', 'Trusă ADR' UNION ALL
    SELECT 'camion', 'Extinctor' UNION ALL
    SELECT 'camion', 'Trusă Medicală' UNION ALL
    SELECT 'camion', 'Trusă ADR' UNION ALL
    SELECT 'camion', 'Apă Ochi'
) required
INNER JOIN inventar_dotari_catalog c ON c.nume = required.nume
ON DUPLICATE KEY UPDATE
    activ = VALUES(activ),
    updated_at = NOW();

INSERT INTO configurare_beneficiari_transport (
    nume,
    tip_marfa,
    pret_tarifare,
    suporta_primar,
    suporta_distributie,
    suporta_compresor,
    pret_km,
    pret_tona,
    pret_distributie_km,
    pret_distributie_tona,
    pret_ora_aspirare,
    pret_km_dislocare,
    pret_tona_livrata,
    activ,
    created_at,
    updated_at
) VALUES
('LPG AUTO', 'gpl_vrac', 5.50, 1, 1, 0, 5.50, 2.85, 0.00, 0.00, 0.00, 0.00, 0.00, 1, NOW(), NOW()),
('Retail Client SRL', 'butelii', 5.20, 1, 1, 0, 5.20, 2.60, 0.00, 0.00, 0.00, 0.00, 0.00, 1, NOW(), NOW()),
('Distrib Logistic SA', 'carburant', 5.80, 1, 1, 0, 5.80, 3.20, 0.00, 0.00, 0.00, 0.00, 0.00, 1, NOW(), NOW());

INSERT INTO configurare_locuri_incarcare (beneficiar_id, nume, tarif, activ, created_at, updated_at) VALUES
(1, 'Depozit Central Bucuresti', 0.00, 1, NOW(), NOW()),
(2, 'Terminal Brasov', 0.00, 1, NOW(), NOW()),
(1, 'Hub Cluj', 0.00, 1, NOW(), NOW());

INSERT INTO configurare_zone_distributie (beneficiar_id, nume, tarif_distributie, cost_extra_km, activ, created_at, updated_at) VALUES
(2, 'Bucuresti', 2.60, 0.00, 1, NOW(), NOW()),
(1, 'Ilfov', 2.85, 0.00, 1, NOW(), NOW()),
(1, 'Regional', 3.20, 0.00, 1, NOW(), NOW());

INSERT INTO curse_dispecer (
    vehicle_id,
    tip_transport,
    data_cursa,
    loc_incarcare_id,
    beneficiar_id,
    tip_marfa,
    cantitate_incarcata,
    nr_clienti,
    km_cursa,
    zona_distributie_id,
    status_facturare,
    pret_tarifare,
    total_facturare,
    observatii,
    created_at,
    updated_at
) VALUES
(1, 'primar', DATE_SUB(CURDATE(), INTERVAL 2 DAY), 1, 1, 'gpl_vrac', NULL, 1, 320, NULL, 'in_curs_facturare', 5.10, 1632.00, 'Cursa primara catre depozit regional.', NOW(), NOW()),
(2, 'distributie', DATE_SUB(CURDATE(), INTERVAL 1 DAY), 2, 2, 'butelii', 12.50, 6, NULL, 1, 'nefacturat', 2.60, 32.50, 'Distributie urbana pentru clienti retail.', NOW(), NOW()),
(1, 'distributie', CURDATE(), 1, 1, 'gpl_vrac', 18.20, 9, NULL, 2, 'facturat', 2.85, 51.87, 'Runda zilnica zona Ilfov.', NOW(), NOW());

INSERT INTO curse_cheltuieli (cursa_id, tip_cheltuiala, suma, data_cheltuiala, observatii, created_at, updated_at) VALUES
(1, 'motorina', 520.00, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'Alimentare pentru cursa primara.', NOW(), NOW()),
(1, 'taxe_drum', 130.00, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'Taxe pod si autostrada.', NOW(), NOW()),
(2, 'diurna', 90.00, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Diurna sofer distributie.', NOW(), NOW());

