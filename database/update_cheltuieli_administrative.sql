-- Cheltuieli Administrative module
-- NOTE: These tables are also created AUTOMATICALLY on the first load of the
-- Cheltuieli Administrative page. This file exists only for reference / manual setup.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS administrative_expense_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(140) NOT NULL,
    status ENUM('activ', 'inactiv') NOT NULL DEFAULT 'activ',
    color VARCHAR(20) NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_admin_expense_categories_slug (slug),
    INDEX idx_admin_expense_categories_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS administrative_expenses (
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
    added_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_admin_expenses_date (expense_date),
    INDEX idx_admin_expenses_category_date (category_id, expense_date),
    INDEX idx_admin_expenses_payment_method (payment_method),
    INDEX idx_admin_expenses_added_by (added_by),
    CONSTRAINT fk_admin_expenses_category FOREIGN KEY (category_id) REFERENCES administrative_expense_categories(id) ON DELETE RESTRICT,
    CONSTRAINT fk_admin_expenses_added_by FOREIGN KEY (added_by) REFERENCES utilizatori(id) ON DELETE SET NULL,
    CONSTRAINT fk_admin_expenses_updated_by FOREIGN KEY (updated_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS administrative_expense_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    expense_id INT UNSIGNED NOT NULL,
    document_type ENUM('factura', 'bon_fiscal', 'chitanta', 'contract', 'alt_document') NOT NULL DEFAULT 'factura',
    original_name VARCHAR(255) NULL,
    stored_name VARCHAR(255) NULL,
    uploaded_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_admin_expense_documents_expense (expense_id),
    CONSTRAINT fk_admin_expense_documents_expense FOREIGN KEY (expense_id) REFERENCES administrative_expenses(id) ON DELETE CASCADE,
    CONSTRAINT fk_admin_expense_documents_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO administrative_expense_categories
    (name, slug, status, color, sort_order, created_at, updated_at)
SELECT seed.name, seed.slug, 'activ', seed.color, seed.sort_order, NOW(), NOW()
FROM (
    SELECT 'Taxe și impozite' AS name, 'taxe-impozite' AS slug, '#2a78d6' AS color, 10 AS sort_order UNION ALL
    SELECT 'Asigurări firmă', 'asigurari-firma', '#1baf7a', 20 UNION ALL
    SELECT 'Contabilitate / Audit', 'contabilitate-audit', '#eda100', 30 UNION ALL
    SELECT 'Consultanță juridică', 'consultanta-juridica', '#008300', 40 UNION ALL
    SELECT 'Licențe și autorizații', 'licente-autorizatii', '#4a3aa7', 50 UNION ALL
    SELECT 'Deplasări / Protocol', 'deplasari-protocol', '#e34948', 60 UNION ALL
    SELECT 'Marketing / Publicitate', 'marketing-publicitate', '#e87ba4', 70 UNION ALL
    SELECT 'Comisioane bancare', 'comisioane-bancare-admin', '#eb6834', 80 UNION ALL
    SELECT 'Resurse umane / Training', 'resurse-umane-training', '#184f95', 90 UNION ALL
    SELECT 'Alte cheltuieli administrative', 'alte-cheltuieli-administrative', '#9a6b1f', 100
) AS seed
WHERE NOT EXISTS (
    SELECT 1
    FROM administrative_expense_categories existing
    WHERE existing.slug = seed.slug
);
