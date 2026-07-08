-- Contabilitate Personal module
SET NAMES utf8mb4;

ALTER TABLE utilizatori
    MODIFY rol ENUM('admin', 'contabilitate', 'utilizator') NOT NULL DEFAULT 'utilizator';

SET @staff_col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'soferi'
      AND COLUMN_NAME = 'data_angajare'
);
SET @staff_col_sql := IF(
    @staff_col_exists = 0,
    'ALTER TABLE soferi ADD COLUMN data_angajare DATE NULL AFTER data_nasterii',
    'SELECT 1'
);
PREPARE staff_col_stmt FROM @staff_col_sql;
EXECUTE staff_col_stmt;
DEALLOCATE PREPARE staff_col_stmt;

SET @driver_end_col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'soferi'
      AND COLUMN_NAME = 'data_incetare'
);
SET @driver_end_col_sql := IF(
    @driver_end_col_exists = 0,
    'ALTER TABLE soferi ADD COLUMN data_incetare DATE NULL AFTER data_angajare',
    'SELECT 1'
);
PREPARE driver_end_col_stmt FROM @driver_end_col_sql;
EXECUTE driver_end_col_stmt;
DEALLOCATE PREPARE driver_end_col_stmt;

SET @driver_doc_col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'documente_soferi'
      AND COLUMN_NAME = 'data_emitere'
);
SET @driver_doc_col_sql := IF(
    @driver_doc_col_exists = 0,
    'ALTER TABLE documente_soferi ADD COLUMN data_emitere DATE NULL AFTER numar_document',
    'SELECT 1'
);
PREPARE driver_doc_col_stmt FROM @driver_doc_col_sql;
EXECUTE driver_doc_col_stmt;
DEALLOCATE PREPARE driver_doc_col_stmt;

CREATE TABLE IF NOT EXISTS staff_types (
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

CREATE TABLE IF NOT EXISTS staff_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_type_id INT UNSIGNED NOT NULL,
    nume_complet VARCHAR(160) NOT NULL,
    telefon VARCHAR(20) NULL,
    email VARCHAR(190) NULL,
    functie VARCHAR(120) NOT NULL,
    salariu DECIMAL(10,2) NULL,
    data_angajare DATE NULL,
    data_incetare DATE NULL,
    status ENUM('activ', 'inactiv') NOT NULL DEFAULT 'activ',
    observatii TEXT NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_staff_members_type_status (staff_type_id, status),
    INDEX idx_staff_members_name (nume_complet),
    CONSTRAINT fk_staff_members_type FOREIGN KEY (staff_type_id) REFERENCES staff_types(id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_members_created_by FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL,
    CONSTRAINT fk_staff_members_updated_by FOREIGN KEY (updated_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @staff_member_end_col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'staff_members'
      AND COLUMN_NAME = 'data_incetare'
);
SET @staff_member_end_col_sql := IF(
    @staff_member_end_col_exists = 0,
    'ALTER TABLE staff_members ADD COLUMN data_incetare DATE NULL AFTER data_angajare',
    'SELECT 1'
);
PREPARE staff_member_end_col_stmt FROM @staff_member_end_col_sql;
EXECUTE staff_member_end_col_stmt;
DEALLOCATE PREPARE staff_member_end_col_stmt;

CREATE TABLE IF NOT EXISTS staff_documents (
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

CREATE TABLE IF NOT EXISTS staff_document_requirements (
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

CREATE TABLE IF NOT EXISTS salary_history (
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

INSERT INTO staff_types
    (name, slug, category, description, status, is_system, is_driver_linked, salary_required, vehicle_required, mandatory_documents_enabled, can_create_employees, can_delete_employees, document_warning_days, created_at, updated_at)
SELECT 'Șofer', 'sofer', 'operational', 'Conectat la modulul Șoferi. Importă automat șoferii existenți.', 'activ', 1, 1, 1, 1, 1, 0, 0, 30, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM staff_types WHERE slug = 'sofer');

INSERT INTO staff_types
    (name, slug, category, description, status, is_system, is_driver_linked, salary_required, vehicle_required, mandatory_documents_enabled, can_create_employees, can_delete_employees, document_warning_days, created_at, updated_at)
SELECT seed.name, seed.slug, seed.category, seed.description, 'activ', 0, 0, 0, 0, 1, 1, 1, 30, NOW(), NOW()
FROM (
    SELECT 'Ajutor Șofer' AS name, 'ajutor-sofer' AS slug, 'operational' AS category, 'Personal operațional auxiliar.' AS description UNION ALL
    SELECT 'Mecanic', 'mecanic', 'operational', 'Personal operațional pentru mentenanță.' UNION ALL
    SELECT 'Dispecer', 'dispecer', 'operational', 'Personal operațional de coordonare curse.' UNION ALL
    SELECT 'Spălător', 'spalator', 'operational', 'Personal operațional de curățenie vehicule.' UNION ALL
    SELECT 'Contabil', 'contabil', 'office', 'Personal birou pentru contabilitate.' UNION ALL
    SELECT 'Administrator', 'administrator', 'office', 'Personal birou administrativ.' UNION ALL
    SELECT 'Manager', 'manager', 'office', 'Personal birou management.' UNION ALL
    SELECT 'HR', 'hr', 'office', 'Personal birou resurse umane.' UNION ALL
    SELECT 'Operator', 'operator', 'office', 'Personal birou operațional.' UNION ALL
    SELECT 'Personal Curățenie', 'personal-curatenie', 'office', 'Personal birou curățenie.'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM staff_types st WHERE st.slug = seed.slug);

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
) AS req ON req.slug = st.slug
WHERE NOT EXISTS (
    SELECT 1
    FROM staff_document_requirements existing
    WHERE existing.staff_type_id = st.id
      AND existing.document_type = req.document_type
);

INSERT INTO staff_document_requirements
    (staff_type_id, document_type, requires_expiry, warning_days, created_at, updated_at)
SELECT st.id, 'Contract de muncă', 0, 30, NOW(), NOW()
FROM staff_types st
ON DUPLICATE KEY UPDATE
    requires_expiry = 0;

INSERT INTO configurare_documente_obligatorii_soferi
    (document_type, requires_expiry, created_at, updated_at)
SELECT req.document_type, req.requires_expiry, NOW(), NOW()
FROM (
    SELECT 'CI / Buletin' AS document_type, 1 AS requires_expiry UNION ALL
    SELECT 'Permis conducere', 1 UNION ALL
    SELECT 'Medicina muncii', 1 UNION ALL
    SELECT 'Aviz medical', 1
) AS req
WHERE NOT EXISTS (
    SELECT 1
    FROM configurare_documente_obligatorii_soferi existing
    WHERE existing.document_type = req.document_type
);

DELETE FROM configurare_documente_obligatorii_soferi
WHERE document_type IN ('Contract de muncă', 'Contract de munca', 'Contract de angajare');
