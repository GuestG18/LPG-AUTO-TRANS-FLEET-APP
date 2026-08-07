-- Former employees lifecycle support for Contabilitate Personal
SET NAMES utf8mb4;

SET @driver_status_col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'soferi' AND COLUMN_NAME = 'employment_status'
);
SET @driver_status_col_sql := IF(
    @driver_status_col_exists = 0,
    "ALTER TABLE soferi ADD COLUMN employment_status ENUM('active','temporarily_inactive','suspended','leave','terminated') NOT NULL DEFAULT 'active' AFTER data_incetare",
    'SELECT 1'
);
PREPARE driver_status_col_stmt FROM @driver_status_col_sql;
EXECUTE driver_status_col_stmt;
DEALLOCATE PREPARE driver_status_col_stmt;

SET @staff_status_col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_members' AND COLUMN_NAME = 'employment_status'
);
SET @staff_status_col_sql := IF(
    @staff_status_col_exists = 0,
    "ALTER TABLE staff_members ADD COLUMN employment_status ENUM('active','temporarily_inactive','suspended','leave','terminated') NOT NULL DEFAULT 'active' AFTER data_incetare",
    'SELECT 1'
);
PREPARE staff_status_col_stmt FROM @staff_status_col_sql;
EXECUTE staff_status_col_stmt;
DEALLOCATE PREPARE staff_status_col_stmt;

SET @table_name := 'soferi';

SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'soferi' AND COLUMN_NAME = 'termination_date');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE soferi ADD COLUMN termination_date DATE NULL AFTER employment_status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'soferi' AND COLUMN_NAME = 'termination_reason');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE soferi ADD COLUMN termination_reason VARCHAR(120) NULL AFTER termination_date', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'soferi' AND COLUMN_NAME = 'termination_notes');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE soferi ADD COLUMN termination_notes TEXT NULL AFTER termination_reason', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'soferi' AND COLUMN_NAME = 'last_working_day');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE soferi ADD COLUMN last_working_day DATE NULL AFTER termination_notes', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'soferi' AND COLUMN_NAME = 'termination_document_original');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE soferi ADD COLUMN termination_document_original VARCHAR(255) NULL AFTER last_working_day', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'soferi' AND COLUMN_NAME = 'termination_document_path');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE soferi ADD COLUMN termination_document_path VARCHAR(255) NULL AFTER termination_document_original', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'soferi' AND COLUMN_NAME = 'rehire_eligible');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE soferi ADD COLUMN rehire_eligible TINYINT(1) NOT NULL DEFAULT 1 AFTER termination_document_path', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'soferi' AND COLUMN_NAME = 'termination_assets_returned');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE soferi ADD COLUMN termination_assets_returned TINYINT(1) NOT NULL DEFAULT 0 AFTER rehire_eligible', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'soferi' AND COLUMN_NAME = 'terminated_by');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE soferi ADD COLUMN terminated_by INT UNSIGNED NULL AFTER termination_assets_returned', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'soferi' AND COLUMN_NAME = 'terminated_at');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE soferi ADD COLUMN terminated_at DATETIME NULL AFTER terminated_by', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_members' AND COLUMN_NAME = 'termination_date');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE staff_members ADD COLUMN termination_date DATE NULL AFTER employment_status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_members' AND COLUMN_NAME = 'termination_reason');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE staff_members ADD COLUMN termination_reason VARCHAR(120) NULL AFTER termination_date', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_members' AND COLUMN_NAME = 'termination_notes');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE staff_members ADD COLUMN termination_notes TEXT NULL AFTER termination_reason', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_members' AND COLUMN_NAME = 'last_working_day');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE staff_members ADD COLUMN last_working_day DATE NULL AFTER termination_notes', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_members' AND COLUMN_NAME = 'termination_document_original');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE staff_members ADD COLUMN termination_document_original VARCHAR(255) NULL AFTER last_working_day', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_members' AND COLUMN_NAME = 'termination_document_path');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE staff_members ADD COLUMN termination_document_path VARCHAR(255) NULL AFTER termination_document_original', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_members' AND COLUMN_NAME = 'rehire_eligible');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE staff_members ADD COLUMN rehire_eligible TINYINT(1) NOT NULL DEFAULT 1 AFTER termination_document_path', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_members' AND COLUMN_NAME = 'termination_assets_returned');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE staff_members ADD COLUMN termination_assets_returned TINYINT(1) NOT NULL DEFAULT 0 AFTER rehire_eligible', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_members' AND COLUMN_NAME = 'terminated_by');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE staff_members ADD COLUMN terminated_by INT UNSIGNED NULL AFTER termination_assets_returned', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_members' AND COLUMN_NAME = 'terminated_at');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE staff_members ADD COLUMN terminated_at DATETIME NULL AFTER terminated_by', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE soferi
SET employment_status = 'terminated',
    termination_date = COALESCE(termination_date, data_incetare)
WHERE data_incetare IS NOT NULL;

UPDATE staff_members
SET employment_status = 'terminated',
    termination_date = COALESCE(termination_date, data_incetare)
WHERE data_incetare IS NOT NULL;

CREATE TABLE IF NOT EXISTS employee_employment_periods (
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

INSERT INTO employee_employment_periods (
    subject_type, driver_id, staff_member_id, source_module, personnel_type, staff_type_id,
    function_name, salary, hire_date, last_working_day, termination_date, termination_reason,
    termination_notes, status, rehire_eligible, created_by, updated_by, created_at, updated_at
)
SELECT
    'driver',
    s.id,
    NULL,
    'soferi',
    'operational',
    (SELECT st.id FROM staff_types st WHERE st.is_driver_linked = 1 ORDER BY st.id LIMIT 1),
    'Șofer',
    s.salariu,
    s.data_angajare,
    COALESCE(s.last_working_day, s.termination_date, s.data_incetare),
    COALESCE(s.termination_date, s.data_incetare),
    s.termination_reason,
    s.termination_notes,
    CASE WHEN COALESCE(s.employment_status, 'active') = 'terminated' OR s.data_incetare IS NOT NULL THEN 'terminated' ELSE 'active' END,
    COALESCE(s.rehire_eligible, 1),
    s.terminated_by,
    s.terminated_by,
    COALESCE(s.created_at, NOW()),
    COALESCE(s.updated_at, NOW())
FROM soferi s
WHERE NOT EXISTS (
    SELECT 1
    FROM employee_employment_periods ep
    WHERE ep.subject_type = 'driver'
      AND ep.driver_id = s.id
);

INSERT INTO employee_employment_periods (
    subject_type, driver_id, staff_member_id, source_module, personnel_type, staff_type_id,
    function_name, salary, hire_date, last_working_day, termination_date, termination_reason,
    termination_notes, status, rehire_eligible, created_by, updated_by, created_at, updated_at
)
SELECT
    'staff',
    NULL,
    sm.id,
    'contabilitate_personal',
    COALESCE(st.category, 'operational'),
    sm.staff_type_id,
    sm.functie,
    sm.salariu,
    sm.data_angajare,
    COALESCE(sm.last_working_day, sm.termination_date, sm.data_incetare),
    COALESCE(sm.termination_date, sm.data_incetare),
    sm.termination_reason,
    sm.termination_notes,
    CASE WHEN COALESCE(sm.employment_status, 'active') = 'terminated' OR sm.data_incetare IS NOT NULL THEN 'terminated' ELSE 'active' END,
    COALESCE(sm.rehire_eligible, 1),
    sm.created_by,
    sm.updated_by,
    COALESCE(sm.created_at, NOW()),
    COALESCE(sm.updated_at, NOW())
FROM staff_members sm
LEFT JOIN staff_types st ON st.id = sm.staff_type_id
WHERE NOT EXISTS (
    SELECT 1
    FROM employee_employment_periods ep
    WHERE ep.subject_type = 'staff'
      AND ep.staff_member_id = sm.id
);

UPDATE employee_employment_periods
SET function_name = 'Șofer'
WHERE subject_type = 'driver'
  AND function_name = 'Sofer';
