-- Contabilitate Personal: calcul salarial lunar (BRUT <-> NET), reguli fiscale
-- versionate, profil de salarizare, sporuri / retineri / beneficii, stat lunar
-- cu instantaneu la confirmarea contabila si jurnal de audit.
--
-- Principii:
--  * Nicio cota fiscala nu e scrisa in cod: toate vin din payroll_fiscal_rules,
--    fiecare regula are interval de valabilitate. O regula folosita de un stat
--    confirmat nu se mai editeaza: se inchide (valid_to) si se creeaza alta.
--  * payroll_monthly pastreaza rezultatul complet + detaliile calculului; dupa
--    confirmare nu se mai recalculeaza decat dupa redeschidere explicita (audit).
--  * Profilul de salarizare nu se deduce: campurile necunoscute raman NULL si
--    calculul raspunde "Necesita configurare" cu lista exacta a lipsurilor.

CREATE TABLE IF NOT EXISTS payroll_fiscal_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    valid_from DATE NOT NULL,
    valid_to DATE NULL,
    status ENUM('activ','inactiv') NOT NULL DEFAULT 'activ',
    cas_employee_rate DECIMAL(6,3) NOT NULL,
    cass_employee_rate DECIMAL(6,3) NOT NULL,
    income_tax_rate DECIMAL(6,3) NOT NULL,
    cam_employer_rate DECIMAL(6,3) NOT NULL,
    minimum_gross_salary DECIMAL(10,2) NOT NULL,
    monthly_hours_norm DECIMAL(8,3) NOT NULL,
    minimum_hourly_salary DECIMAL(10,3) NOT NULL,
    non_taxable_minimum_salary_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    non_taxable_income_limit DECIMAL(10,2) NULL,
    non_taxable_requires_eligibility TINYINT(1) NOT NULL DEFAULT 1,
    non_taxable_excludes_cam TINYINT(1) NOT NULL DEFAULT 1,
    minimum_contribution_base_enabled TINYINT(1) NOT NULL DEFAULT 1,
    -- Deducerea personala (art. 77 Cod fiscal): procente de baza pe numar de
    -- persoane in intretinere, scaderea pe transe, deducerea suplimentara.
    personal_deduction_config JSON NOT NULL,
    rounding_mode VARCHAR(30) NOT NULL DEFAULT 'ro_salarii',
    legal_reference TEXT NULL,
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_payroll_rules_validity (status, valid_from, valid_to),
    CONSTRAINT fk_payroll_rules_created_by FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL,
    CONSTRAINT fk_payroll_rules_updated_by FOREIGN KEY (updated_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_fiscal_rule_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_id INT UNSIGNED NOT NULL,
    action VARCHAR(30) NOT NULL,
    field VARCHAR(60) NULL,
    old_value TEXT NULL,
    new_value TEXT NULL,
    valid_from DATE NULL,
    valid_to DATE NULL,
    reason TEXT NULL,
    user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    KEY idx_payroll_rule_audit_rule (rule_id, created_at),
    CONSTRAINT fk_payroll_rule_audit_rule FOREIGN KEY (rule_id) REFERENCES payroll_fiscal_rules(id) ON DELETE CASCADE,
    CONSTRAINT fk_payroll_rule_audit_user FOREIGN KEY (user_id) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_profiles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject_type ENUM('driver','staff') NOT NULL,
    driver_id INT UNSIGNED NULL,
    staff_member_id INT UNSIGNED NULL,
    subject_id INT UNSIGNED NOT NULL,
    contract_type ENUM('cim','colaborare','altul') NULL,
    norm_type ENUM('full','part') NULL,
    hours_per_day DECIMAL(4,2) NULL,
    is_basic_function TINYINT(1) NULL,
    salary_input_type ENUM('net','gross') NULL,
    dependents_count TINYINT UNSIGNED NULL,
    children_in_school TINYINT UNSIGNED NULL,
    under_26 TINYINT(1) NULL,
    work_conditions ENUM('normale','deosebite','speciale') NULL,
    tax_exemption ENUM('niciuna','handicap','it','constructii','agricol','altele') NULL,
    min_base_exemption ENUM('niciuna','elev_student','pensionar','handicap','ucenic','alt_contract','altele') NULL,
    notes TEXT NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_payroll_profile_subject (subject_type, subject_id),
    CONSTRAINT fk_payroll_profile_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE CASCADE,
    CONSTRAINT fk_payroll_profile_staff FOREIGN KEY (staff_member_id) REFERENCES staff_members(id) ON DELETE CASCADE,
    CONSTRAINT fk_payroll_profile_user FOREIGN KEY (updated_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tipuri configurabile: sporuri, retineri, beneficii/ajutoare. Tratamentul fiscal
-- e explicit pe fiecare tip (nu se presupune impozabil / neimpozabil).
CREATE TABLE IF NOT EXISTS payroll_item_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category ENUM('spor','retinere','beneficiu') NOT NULL,
    name VARCHAR(120) NOT NULL,
    calculation_type ENUM('suma_fixa','procent_din_baza','manual') NOT NULL DEFAULT 'manual',
    default_value DECIMAL(10,3) NULL,
    paid_in_cash TINYINT(1) NOT NULL DEFAULT 1,
    subject_to_cas TINYINT(1) NOT NULL DEFAULT 0,
    subject_to_cass TINYINT(1) NOT NULL DEFAULT 0,
    subject_to_income_tax TINYINT(1) NOT NULL DEFAULT 0,
    subject_to_cam TINYINT(1) NOT NULL DEFAULT 0,
    excluded_from_facility_ceiling TINYINT(1) NOT NULL DEFAULT 0,
    valid_from DATE NOT NULL,
    valid_to DATE NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    legal_reference VARCHAR(255) NULL,
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_payroll_item_types_category (category, active),
    CONSTRAINT fk_payroll_item_types_user FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sporurile / retinerile / beneficiile unui angajat pe o luna (se aplica doar daca
-- sunt adaugate explicit; existenta unui tip nu inseamna ca se acorda).
CREATE TABLE IF NOT EXISTS payroll_employee_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject_type ENUM('driver','staff') NOT NULL,
    subject_id INT UNSIGNED NOT NULL,
    perioada DATE NOT NULL,
    item_type_id INT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NULL,
    reason VARCHAR(255) NULL,
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    KEY idx_payroll_employee_items_subject (subject_type, subject_id, perioada),
    CONSTRAINT fk_payroll_employee_items_type FOREIGN KEY (item_type_id) REFERENCES payroll_item_types(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payroll_employee_items_user FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_monthly (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject_type ENUM('driver','staff') NOT NULL,
    driver_id INT UNSIGNED NULL,
    staff_member_id INT UNSIGNED NULL,
    subject_id INT UNSIGNED NOT NULL,
    perioada DATE NOT NULL,
    salary_input_type ENUM('net','gross') NULL,
    configured_salary DECIMAL(10,2) NULL,
    base_gross_salary DECIMAL(10,2) NULL,
    gross_salary DECIMAL(10,2) NULL,
    taxable_gross DECIMAL(10,2) NULL,
    cas_base DECIMAL(10,2) NULL,
    cas DECIMAL(10,2) NULL,
    cass_base DECIMAL(10,2) NULL,
    cass DECIMAL(10,2) NULL,
    personal_deduction DECIMAL(10,2) NULL,
    income_tax_base DECIMAL(10,2) NULL,
    income_tax DECIMAL(10,2) NULL,
    cam_base DECIMAL(10,2) NULL,
    cam DECIMAL(10,2) NULL,
    employer_contribution_differences DECIMAL(10,2) NULL,
    other_employer_costs DECIMAL(10,2) NULL,
    bonuses DECIMAL(10,2) NULL,
    benefits DECIMAL(10,2) NULL,
    non_taxable_additions DECIMAL(10,2) NULL,
    other_deductions DECIMAL(10,2) NULL,
    non_taxable_amount DECIMAL(10,2) NULL,
    non_taxable_facility_eligible TINYINT(1) NULL,
    eligibility_reason VARCHAR(255) NULL,
    net_salary DECIMAL(10,2) NULL,
    net_payable DECIMAL(10,2) NULL,
    total_employee_withholdings DECIMAL(10,2) NULL,
    total_employer_contributions DECIMAL(10,2) NULL,
    total_employer_cost DECIMAL(10,2) NULL,
    fiscal_rule_id INT UNSIGNED NULL,
    calculation_status ENUM('neconfigurat','calculat','de_verificat','necesita_verificare','eroare') NOT NULL,
    confirmation_status ENUM('neconfirmat','confirmat','necesita_recalculare') NOT NULL DEFAULT 'neconfirmat',
    missing_fields JSON NULL,
    warnings JSON NULL,
    calculation_details JSON NULL,
    input_fingerprint CHAR(40) NULL,
    calculated_at DATETIME NULL,
    calculated_by INT UNSIGNED NULL,
    confirmed_at DATETIME NULL,
    confirmed_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_payroll_monthly_subject (subject_type, subject_id, perioada),
    KEY idx_payroll_monthly_perioada (perioada, calculation_status, confirmation_status),
    CONSTRAINT fk_payroll_monthly_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE CASCADE,
    CONSTRAINT fk_payroll_monthly_staff FOREIGN KEY (staff_member_id) REFERENCES staff_members(id) ON DELETE CASCADE,
    CONSTRAINT fk_payroll_monthly_rule FOREIGN KEY (fiscal_rule_id) REFERENCES payroll_fiscal_rules(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payroll_monthly_calc_by FOREIGN KEY (calculated_by) REFERENCES utilizatori(id) ON DELETE SET NULL,
    CONSTRAINT fk_payroll_monthly_conf_by FOREIGN KEY (confirmed_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_monthly_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payroll_id INT UNSIGNED NOT NULL,
    action VARCHAR(30) NOT NULL,
    reason TEXT NULL,
    old_result JSON NULL,
    new_result JSON NULL,
    user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    KEY idx_payroll_monthly_audit (payroll_id, created_at),
    CONSTRAINT fk_payroll_monthly_audit_payroll FOREIGN KEY (payroll_id) REFERENCES payroll_monthly(id) ON DELETE CASCADE,
    CONSTRAINT fk_payroll_monthly_audit_user FOREIGN KEY (user_id) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Regulile fiscale 2026 (surse oficiale). Se insereaza doar daca nu exista deja o
-- regula cu aceeasi data de inceput (migrarea poate rula de mai multe ori).
SET @pd_2026 := '{"base_percent_by_dependents":[20,25,30,35,45],"income_window_above_minimum":2000,"step_lei":50,"step_percent":0.5,"youth_percent":15,"youth_max_age":26,"child_in_school_amount":100}';

INSERT INTO payroll_fiscal_rules (
    name, valid_from, valid_to, status,
    cas_employee_rate, cass_employee_rate, income_tax_rate, cam_employer_rate,
    minimum_gross_salary, monthly_hours_norm, minimum_hourly_salary,
    non_taxable_minimum_salary_amount, non_taxable_income_limit,
    non_taxable_requires_eligibility, non_taxable_excludes_cam, minimum_contribution_base_enabled,
    personal_deduction_config, rounding_mode, legal_reference, notes, created_at, updated_at
)
SELECT '2026-S1 (01.01-30.06.2026)', '2026-01-01', '2026-06-30', 'activ',
    25.000, 10.000, 10.000, 2.250,
    4050.00, 166.333, 24.349,
    300.00, 4300.00,
    1, 1, 1,
    @pd_2026, 'ro_salarii',
    'Legea nr. 227/2015 (Codul fiscal): art. 77 deducere personala (forma OG 16/2022), art. 78 impozit 10%, CAS 25%, CASS 10%, CAM 2,25%. Salariu minim 4.050 lei / 166,333 h / 24,349 lei/h: HG nr. 1506/2024 (abrogata de HG nr. 146/2026 de la 01.07.2026). Suma netaxabila 300 lei, plafon venit brut 4.300 lei inclusiv (fara tichete de masa, vouchere de vacanta, indemnizatie de hrana): OUG nr. 89/2025 art. III.',
    'Rotunjire ro_salarii: bazele de calcul la leu (fractiunile pana la 50 bani inclusiv se neglijeaza), sumele (CAS, CASS, impozit, CAM, deduceri) la leu cu 0,50 in sus - reproduce netul oficial la salariul minim (2.574 lei S1, 2.699 lei S2). Verificati la contabil inainte de prima confirmare.',
    NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM payroll_fiscal_rules WHERE valid_from = '2026-01-01');

INSERT INTO payroll_fiscal_rules (
    name, valid_from, valid_to, status,
    cas_employee_rate, cass_employee_rate, income_tax_rate, cam_employer_rate,
    minimum_gross_salary, monthly_hours_norm, minimum_hourly_salary,
    non_taxable_minimum_salary_amount, non_taxable_income_limit,
    non_taxable_requires_eligibility, non_taxable_excludes_cam, minimum_contribution_base_enabled,
    personal_deduction_config, rounding_mode, legal_reference, notes, created_at, updated_at
)
SELECT '2026-S2 (01.07-31.12.2026)', '2026-07-01', '2026-12-31', 'activ',
    25.000, 10.000, 10.000, 2.250,
    4325.00, 166.667, 25.949,
    200.00, 4600.00,
    1, 1, 1,
    @pd_2026, 'ro_salarii',
    'Legea nr. 227/2015 (Codul fiscal): art. 77 deducere personala (forma OG 16/2022), art. 78 impozit 10%, CAS 25%, CASS 10%, CAM 2,25%. Salariu minim 4.325 lei / 166,667 h / 25,949 lei/h: HG nr. 146/12.03.2026, in vigoare de la 01.07.2026. Suma netaxabila 200 lei, plafon venit brut 4.600 lei inclusiv (fara tichete de masa, vouchere de vacanta, indemnizatie de hrana): OUG nr. 89/2025 art. III.',
    'Rotunjire ro_salarii: bazele de calcul la leu (fractiunile pana la 50 bani inclusiv se neglijeaza), sumele (CAS, CASS, impozit, CAM, deduceri) la leu cu 0,50 in sus - reproduce netul oficial la salariul minim (2.574 lei S1, 2.699 lei S2). Facilitatea 200 lei expira la 31.12.2026: pentru 2027 se configureaza o regula noua.',
    NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM payroll_fiscal_rules WHERE valid_from = '2026-07-01');
