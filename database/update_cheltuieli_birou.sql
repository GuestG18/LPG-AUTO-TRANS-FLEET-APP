-- Cheltuieli Birou module
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS office_expense_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(140) NOT NULL,
    expense_scope ENUM('administrative', 'operational') NOT NULL DEFAULT 'administrative',
    is_automatic TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('activ', 'inactiv') NOT NULL DEFAULT 'activ',
    color VARCHAR(20) NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_office_expense_categories_slug (slug),
    INDEX idx_office_expense_categories_status (status),
    INDEX idx_office_expense_categories_automatic (is_automatic)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS office_expenses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    expense_date DATE NOT NULL,
    description VARCHAR(255) NOT NULL,
    supplier VARCHAR(190) NULL,
    amount_net DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    amount_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    payment_method ENUM('cash', 'card', 'transfer_bancar', 'alte') NOT NULL DEFAULT 'transfer_bancar',
    invoice_number VARCHAR(120) NULL,
    notes TEXT NULL,
    monthly_rent_amount DECIMAL(12,2) NULL,
    contract_number VARCHAR(120) NULL,
    rent_period_start DATE NULL,
    rent_period_end DATE NULL,
    due_date DATE NULL,
    payment_status ENUM('platit', 'neplatit', 'intarziat') NULL,
    landlord_name VARCHAR(190) NULL,
    added_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_office_expenses_date (expense_date),
    INDEX idx_office_expenses_category_date (category_id, expense_date),
    INDEX idx_office_expenses_payment_method (payment_method),
    INDEX idx_office_expenses_added_by (added_by),
    CONSTRAINT fk_office_expenses_category FOREIGN KEY (category_id) REFERENCES office_expense_categories(id) ON DELETE RESTRICT,
    CONSTRAINT fk_office_expenses_added_by FOREIGN KEY (added_by) REFERENCES utilizatori(id) ON DELETE SET NULL,
    CONSTRAINT fk_office_expenses_updated_by FOREIGN KEY (updated_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS office_expense_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    expense_id INT UNSIGNED NOT NULL,
    document_type ENUM('factura', 'bon_fiscal', 'chitanta', 'contract', 'alt_document') NOT NULL DEFAULT 'factura',
    original_name VARCHAR(255) NULL,
    stored_name VARCHAR(255) NULL,
    uploaded_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_office_expense_documents_expense (expense_id),
    CONSTRAINT fk_office_expense_documents_expense FOREIGN KEY (expense_id) REFERENCES office_expenses(id) ON DELETE CASCADE,
    CONSTRAINT fk_office_expense_documents_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO office_expense_categories
    (name, slug, expense_scope, is_automatic, status, color, sort_order, created_at, updated_at)
SELECT seed.name, seed.slug, seed.expense_scope, seed.is_automatic, 'activ', seed.color, seed.sort_order, NOW(), NOW()
FROM (
    SELECT 'Chirie birou' AS name, 'chirie-birou' AS slug, 'administrative' AS expense_scope, 0 AS is_automatic, '#3b82f6' AS color, 10 AS sort_order UNION ALL
    SELECT 'Utilități', 'utilitati', 'administrative', 0, '#22c55e', 20 UNION ALL
    SELECT 'Internet / telefonie', 'internet-telefonie', 'administrative', 0, '#8b5cf6', 30 UNION ALL
    SELECT 'Consumabile birou', 'consumabile-birou', 'administrative', 0, '#f59e0b', 40 UNION ALL
    SELECT 'Cafea / apă / protocol', 'cafea-apa-protocol', 'administrative', 0, '#fb923c', 50 UNION ALL
    SELECT 'Produse curățenie', 'produse-curatenie', 'administrative', 0, '#60a5fa', 60 UNION ALL
    SELECT 'IT și software', 'it-si-software', 'administrative', 0, '#14b8a6', 70 UNION ALL
    SELECT 'Servicii externe', 'servicii-externe', 'administrative', 0, '#ef4444', 80 UNION ALL
    SELECT 'Mobilier și echipamente', 'mobilier-echipamente', 'administrative', 0, '#64748b', 90 UNION ALL
    SELECT 'Comisioane bancare', 'comisioane-bancare', 'administrative', 0, '#a855f7', 100 UNION ALL
    SELECT 'Alte cheltuieli', 'alte-cheltuieli', 'administrative', 0, '#94a3b8', 110 UNION ALL
    SELECT 'Salarii birou', 'salarii-birou', 'administrative', 1, '#fbbf24', 25
) AS seed
WHERE NOT EXISTS (
    SELECT 1
    FROM office_expense_categories existing
    WHERE existing.slug = seed.slug
);
