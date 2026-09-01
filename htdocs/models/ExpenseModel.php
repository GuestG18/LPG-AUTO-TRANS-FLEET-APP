<?php
declare(strict_types=1);

/**
 * Modelul paginii unificate "Cheltuieli".
 *
 * Structura: cheltuieli (documentul) + cheltuieli_alocari (1..n randuri de
 * alocare pe vehicul / sofer / companie a caror suma este exact valoarea
 * cheltuielii) + cheltuieli_documente (fisiere) + cheltuieli_tipuri
 * (nomenclator per categorie). Inlocuieste modulele legacy Cheltuieli Birou
 * si Cheltuieli Administrative; datele legacy sunt importate idempotent prin
 * perechea (legacy_source, legacy_id), iar tabelele vechi raman ca arhiva.
 */
class ExpenseModel extends BaseModel
{
    public const CATEGORIES = [
        'administrativa' => 'Administrativă',
        'operationala' => 'Operațională',
    ];

    public const ALLOCATION_TYPES = [
        'vehicul' => 'Vehicul',
        'sofer' => 'Șofer',
        // Fara vehicul/sofer, cheltuiala ramane la nivel de firma.
        'companie' => 'Birou / Administrativ',
        'mixt' => 'Mixt',
    ];

    public const DOCUMENT_TYPES = [
        'factura' => 'Factură',
        'bon_fiscal' => 'Bon fiscal',
        'chitanta' => 'Chitanță',
        'alt_document' => 'Alt document',
    ];

    // Aceleasi chei ca in modulele legacy (office/administrative expenses).
    public const PAYMENT_METHODS = [
        'cash' => 'Numerar',
        'card' => 'Card',
        'transfer_bancar' => 'Transfer bancar',
        'alte' => 'Altă metodă',
    ];

    public const PAYMENT_STATUSES = [
        'platita' => 'Plătită',
        'neplatita' => 'Neplătită',
        'partial' => 'Parțial plătită',
    ];

    public const SOURCES = [
        'manual' => 'Manual',
        'spv' => 'SPV',
        'ocr' => 'OCR',
        'import' => 'Import',
    ];

    public const CURRENCIES = ['RON', 'EUR', 'USD', 'HUF'];

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->ensureSchema();
    }

    // ------------------------------------------------------------------ schema

    private function ensureSchema(): void
    {
        $freshInstall = false;
        try {
            $this->db->query('SELECT 1 FROM cheltuieli LIMIT 1');
        } catch (Throwable) {
            $freshInstall = true;
        }

        if ($freshInstall) {
            $this->createTables();
        }

        // Coloanele noi trebuie sa existe inainte de importul legacy (care le refera).
        $this->ensureResponsibleDriverColumn();
        $this->ensureDocumentColumns();

        // Seed + import legacy: interogari WHERE NOT EXISTS, ieftine si idempotente.
        $this->seedOperationalTypes();
        $this->importLegacyData();
    }

    /**
     * Extinderea documentului: tip document, descriere, CUI, valori net/TVA
     * (Total ramane in coloana `valoare`), moneda, plata si sursa inregistrarii.
     */
    private function ensureDocumentColumns(): void
    {
        try {
            $column = $this->db->query("SHOW COLUMNS FROM cheltuieli LIKE 'sursa'")->fetch();
            if (is_array($column)) {
                return;
            }
            $this->db->exec('
                ALTER TABLE cheltuieli
                    ADD COLUMN tip_document ENUM("factura", "bon_fiscal", "chitanta", "alt_document") NOT NULL DEFAULT "factura" AFTER tip_id,
                    ADD COLUMN descriere VARCHAR(255) NULL AFTER furnizor,
                    ADD COLUMN cui VARCHAR(20) NULL AFTER descriere,
                    ADD COLUMN valoare_neta DECIMAL(12,2) NULL AFTER valoare,
                    ADD COLUMN tva DECIMAL(12,2) NULL AFTER valoare_neta,
                    ADD COLUMN moneda CHAR(3) NOT NULL DEFAULT "RON" AFTER tva,
                    ADD COLUMN modalitate_plata ENUM("cash", "card", "transfer_bancar", "alte") NULL AFTER moneda,
                    ADD COLUMN status_plata ENUM("platita", "neplatita", "partial") NULL AFTER modalitate_plata,
                    ADD COLUMN data_platii DATE NULL AFTER status_plata,
                    ADD COLUMN scadenta DATE NULL AFTER data_platii,
                    ADD COLUMN sursa ENUM("manual", "spv", "ocr", "import") NOT NULL DEFAULT "manual" AFTER scadenta,
                    ADD INDEX idx_cheltuieli_sursa (sursa),
                    ADD INDEX idx_cheltuieli_tip_document (tip_document)
            ');
        } catch (Throwable $exception) {
            error_log('[ExpenseModel][ensureDocumentColumns] ' . $exception->getMessage());
        }
    }

    /**
     * Soferul responsabil (informativ): cheltuiala ramane alocata integral pe
     * vehicul/companie, dar pastreaza cine a generat-o (ex. cine a dus masina
     * la spalatorie). Nu participa la sumele alocate.
     */
    private function ensureResponsibleDriverColumn(): void
    {
        try {
            $column = $this->db->query("SHOW COLUMNS FROM cheltuieli LIKE 'sofer_responsabil_id'")->fetch();
            if (is_array($column)) {
                return;
            }
            $this->db->exec('
                ALTER TABLE cheltuieli
                    ADD COLUMN sofer_responsabil_id INT UNSIGNED NULL AFTER beneficiar_id,
                    ADD INDEX idx_cheltuieli_sofer_resp (sofer_responsabil_id),
                    ADD CONSTRAINT fk_cheltuieli_sofer_resp FOREIGN KEY (sofer_responsabil_id) REFERENCES soferi(id) ON DELETE SET NULL
            ');
        } catch (Throwable $exception) {
            error_log('[ExpenseModel][ensureResponsibleDriverColumn] ' . $exception->getMessage());
        }
    }

    private function createTables(): void
    {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS cheltuieli_tipuri (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                categorie ENUM("administrativa", "operationala") NOT NULL,
                nume VARCHAR(150) NOT NULL,
                slug VARCHAR(160) NOT NULL,
                status ENUM("activ", "inactiv") NOT NULL DEFAULT "activ",
                sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
                legacy_source VARCHAR(40) NULL,
                legacy_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uk_cheltuieli_tipuri_slug (slug),
                UNIQUE KEY uk_cheltuieli_tipuri_legacy (legacy_source, legacy_id),
                INDEX idx_cheltuieli_tipuri_categorie (categorie, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS cheltuieli (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                categorie ENUM("administrativa", "operationala") NOT NULL,
                tip_id INT UNSIGNED NOT NULL,
                data_cheltuiala DATE NOT NULL,
                furnizor VARCHAR(190) NULL,
                valoare DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                numar_document VARCHAR(120) NULL,
                observatii TEXT NULL,
                beneficiar_id INT UNSIGNED NULL,
                alocare_tip ENUM("vehicul", "sofer", "companie", "mixt") NOT NULL DEFAULT "companie",
                distribuire ENUM("egal", "manual") NOT NULL DEFAULT "egal",
                legacy_source VARCHAR(40) NULL,
                legacy_id INT UNSIGNED NULL,
                added_by INT UNSIGNED NULL,
                updated_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uk_cheltuieli_legacy (legacy_source, legacy_id),
                INDEX idx_cheltuieli_data (data_cheltuiala),
                INDEX idx_cheltuieli_categorie_data (categorie, data_cheltuiala),
                INDEX idx_cheltuieli_tip (tip_id),
                INDEX idx_cheltuieli_beneficiar (beneficiar_id),
                INDEX idx_cheltuieli_alocare (alocare_tip),
                CONSTRAINT fk_cheltuieli_tip FOREIGN KEY (tip_id) REFERENCES cheltuieli_tipuri(id) ON DELETE RESTRICT,
                CONSTRAINT fk_cheltuieli_beneficiar FOREIGN KEY (beneficiar_id) REFERENCES configurare_beneficiari_transport(id) ON DELETE SET NULL,
                CONSTRAINT fk_cheltuieli_added_by FOREIGN KEY (added_by) REFERENCES utilizatori(id) ON DELETE SET NULL,
                CONSTRAINT fk_cheltuieli_updated_by FOREIGN KEY (updated_by) REFERENCES utilizatori(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS cheltuieli_alocari (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                cheltuiala_id INT UNSIGNED NOT NULL,
                tip_alocare ENUM("vehicul", "sofer", "companie") NOT NULL,
                vehicul_id INT UNSIGNED NULL,
                sofer_id INT UNSIGNED NULL,
                eticheta VARCHAR(150) NULL,
                suma DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_cheltuieli_alocari_cheltuiala (cheltuiala_id),
                INDEX idx_cheltuieli_alocari_tip (tip_alocare),
                INDEX idx_cheltuieli_alocari_vehicul (vehicul_id),
                INDEX idx_cheltuieli_alocari_sofer (sofer_id),
                CONSTRAINT fk_cheltuieli_alocari_cheltuiala FOREIGN KEY (cheltuiala_id) REFERENCES cheltuieli(id) ON DELETE CASCADE,
                CONSTRAINT fk_cheltuieli_alocari_vehicul FOREIGN KEY (vehicul_id) REFERENCES vehicule(id) ON DELETE SET NULL,
                CONSTRAINT fk_cheltuieli_alocari_sofer FOREIGN KEY (sofer_id) REFERENCES soferi(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS cheltuieli_documente (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                cheltuiala_id INT UNSIGNED NOT NULL,
                original_name VARCHAR(255) NULL,
                stored_name VARCHAR(255) NULL,
                uploaded_by INT UNSIGNED NULL,
                legacy_source VARCHAR(40) NULL,
                legacy_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uk_cheltuieli_documente_legacy (legacy_source, legacy_id),
                INDEX idx_cheltuieli_documente_cheltuiala (cheltuiala_id),
                CONSTRAINT fk_cheltuieli_documente_cheltuiala FOREIGN KEY (cheltuiala_id) REFERENCES cheltuieli(id) ON DELETE CASCADE,
                CONSTRAINT fk_cheltuieli_documente_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES utilizatori(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    private function seedOperationalTypes(): void
    {
        try {
            $this->db->exec('
                INSERT INTO cheltuieli_tipuri (categorie, nume, slug, status, sort_order, created_at, updated_at)
                SELECT seed.categorie, seed.nume, seed.slug, "activ", seed.sort_order, NOW(), NOW()
                FROM (
                    SELECT "operationala" AS categorie, "Motorină" AS nume, "motorina" AS slug, 10 AS sort_order UNION ALL
                    SELECT "operationala", "AdBlue", "adblue", 20 UNION ALL
                    SELECT "operationala", "Taxe drum", "taxe-drum", 30 UNION ALL
                    SELECT "operationala", "Diurnă", "diurna", 40 UNION ALL
                    SELECT "operationala", "Cazare", "cazare", 50 UNION ALL
                    SELECT "operationala", "Reparații", "reparatii", 60 UNION ALL
                    SELECT "operationala", "Piese auto", "piese-auto", 70 UNION ALL
                    SELECT "operationala", "Anvelope", "anvelope-operational", 80 UNION ALL
                    SELECT "operationala", "Asigurări", "asigurari-operational", 90 UNION ALL
                    SELECT "operationala", "Spălătorie", "spalatorie", 100 UNION ALL
                    SELECT "operationala", "Parcare", "parcare", 110 UNION ALL
                    SELECT "operationala", "Amenzi", "amenzi", 120 UNION ALL
                    SELECT "operationala", "Alte cheltuieli operaționale", "alte-cheltuieli-operationale", 130
                ) AS seed
                WHERE NOT EXISTS (SELECT 1 FROM cheltuieli_tipuri t WHERE t.slug = seed.slug)
            ');
        } catch (Throwable $exception) {
            error_log('[ExpenseModel][seedOperationalTypes] ' . $exception->getMessage());
        }
    }

    /**
     * Import idempotent din modulele legacy Cheltuieli Birou / Administrative.
     * Fiecare pas este in try/catch propriu: pe un mediu fara tabelele legacy
     * importul este sarit fara sa afecteze pagina.
     */
    private function importLegacyData(): void
    {
        // Nomenclatoare legacy -> tipuri administrative (categoriile automate devin inactive).
        try {
            $this->db->exec('
                INSERT INTO cheltuieli_tipuri (categorie, nume, slug, status, sort_order, legacy_source, legacy_id, created_at, updated_at)
                SELECT "administrativa", c.name, CONCAT("birou-", c.slug),
                       CASE WHEN c.is_automatic = 1 THEN "inactiv" ELSE c.status END,
                       c.sort_order, "office_cat", c.id, NOW(), NOW()
                FROM office_expense_categories c
                WHERE NOT EXISTS (
                    SELECT 1 FROM cheltuieli_tipuri t WHERE t.legacy_source = "office_cat" AND t.legacy_id = c.id
                )
            ');
        } catch (Throwable) {
            // Modulul legacy nu exista pe acest mediu.
        }

        try {
            $this->db->exec('
                INSERT INTO cheltuieli_tipuri (categorie, nume, slug, status, sort_order, legacy_source, legacy_id, created_at, updated_at)
                SELECT "administrativa", c.name, CONCAT("admin-", c.slug), c.status, c.sort_order, "admin_cat", c.id, NOW(), NOW()
                FROM administrative_expense_categories c
                WHERE NOT EXISTS (
                    SELECT 1 FROM cheltuieli_tipuri t WHERE t.legacy_source = "admin_cat" AND t.legacy_id = c.id
                )
            ');
        } catch (Throwable) {
        }

        // Inregistrarile legacy: `valoare` = totalul cu TVA (sau netul daca totalul
        // lipseste), net/TVA/plata pe coloanele dedicate; descrierea legacy pe
        // campul descriere; randul original ramane arhivat.
        try {
            $this->db->exec('
                INSERT INTO cheltuieli (categorie, tip_id, tip_document, data_cheltuiala, furnizor, descriere,
                                        valoare, valoare_neta, tva, modalitate_plata, numar_document, observatii,
                                        alocare_tip, distribuire, legacy_source, legacy_id, added_by, updated_by, created_at, updated_at)
                SELECT "administrativa", t.id, "factura", e.expense_date, e.supplier, NULLIF(e.description, ""),
                       CASE WHEN e.amount_total > 0 THEN e.amount_total ELSE e.amount_net END,
                       NULLIF(e.amount_net, 0), NULLIF(e.vat_amount, 0), e.payment_method,
                       e.invoice_number,
                       TRIM(CONCAT_WS("\n", NULLIF(e.notes, ""), "[Migrat din Cheltuieli Birou]")),
                       "companie", "egal", "office", e.id, e.added_by, e.updated_by, e.created_at, e.updated_at
                FROM office_expenses e
                INNER JOIN cheltuieli_tipuri t ON t.legacy_source = "office_cat" AND t.legacy_id = e.category_id
                WHERE NOT EXISTS (SELECT 1 FROM cheltuieli n WHERE n.legacy_source = "office" AND n.legacy_id = e.id)
            ');
        } catch (Throwable) {
        }

        try {
            $this->db->exec('
                INSERT INTO cheltuieli (categorie, tip_id, tip_document, data_cheltuiala, furnizor, descriere,
                                        valoare, valoare_neta, tva, modalitate_plata, numar_document, observatii,
                                        alocare_tip, distribuire, legacy_source, legacy_id, added_by, updated_by, created_at, updated_at)
                SELECT "administrativa", t.id, "factura", e.expense_date, e.supplier, NULLIF(e.description, ""),
                       CASE WHEN e.amount_total > 0 THEN e.amount_total ELSE e.amount_net END,
                       NULLIF(e.amount_net, 0), NULLIF(e.vat_amount, 0), e.payment_method,
                       e.invoice_number,
                       TRIM(CONCAT_WS("\n", NULLIF(e.notes, ""), "[Migrat din Cheltuieli Administrative]")),
                       "companie", "egal", "administrative", e.id, e.added_by, e.updated_by, e.created_at, e.updated_at
                FROM administrative_expenses e
                INNER JOIN cheltuieli_tipuri t ON t.legacy_source = "admin_cat" AND t.legacy_id = e.category_id
                WHERE NOT EXISTS (SELECT 1 FROM cheltuieli n WHERE n.legacy_source = "administrative" AND n.legacy_id = e.id)
            ');
        } catch (Throwable) {
        }

        // Alocarea implicita "Companie" (100%) pentru cheltuielile migrate.
        try {
            $this->db->exec('
                INSERT INTO cheltuieli_alocari (cheltuiala_id, tip_alocare, vehicul_id, sofer_id, eticheta, suma, created_at, updated_at)
                SELECT n.id, "companie", NULL, NULL, "Companie", n.valoare, NOW(), NOW()
                FROM cheltuieli n
                WHERE n.legacy_source IN ("office", "administrative")
                  AND NOT EXISTS (SELECT 1 FROM cheltuieli_alocari a WHERE a.cheltuiala_id = n.id)
            ');
        } catch (Throwable $exception) {
            error_log('[ExpenseModel][importLegacyAllocations] ' . $exception->getMessage());
        }

        // Documentele legacy (fisierele fizice raman in uploads/documente).
        try {
            $this->db->exec('
                INSERT INTO cheltuieli_documente (cheltuiala_id, original_name, stored_name, uploaded_by, legacy_source, legacy_id, created_at, updated_at)
                SELECT n.id, d.original_name, d.stored_name, d.uploaded_by, "office_doc", d.id, d.created_at, d.updated_at
                FROM office_expense_documents d
                INNER JOIN cheltuieli n ON n.legacy_source = "office" AND n.legacy_id = d.expense_id
                WHERE NOT EXISTS (SELECT 1 FROM cheltuieli_documente x WHERE x.legacy_source = "office_doc" AND x.legacy_id = d.id)
            ');
        } catch (Throwable) {
        }

        try {
            $this->db->exec('
                INSERT INTO cheltuieli_documente (cheltuiala_id, original_name, stored_name, uploaded_by, legacy_source, legacy_id, created_at, updated_at)
                SELECT n.id, d.original_name, d.stored_name, d.uploaded_by, "admin_doc", d.id, d.created_at, d.updated_at
                FROM administrative_expense_documents d
                INNER JOIN cheltuieli n ON n.legacy_source = "administrative" AND n.legacy_id = d.expense_id
                WHERE NOT EXISTS (SELECT 1 FROM cheltuieli_documente x WHERE x.legacy_source = "admin_doc" AND x.legacy_id = d.id)
            ');
        } catch (Throwable) {
        }

        // Drepturile de acces de pe paginile legacy devin drepturi pe pagina unificata.
        try {
            $this->db->exec('
                INSERT IGNORE INTO access_permissions (user_id, page_key, action_key, created_at)
                SELECT user_id, "cheltuieli", action_key, NOW()
                FROM access_permissions
                WHERE page_key IN ("cheltuieli_birou", "cheltuieli_administrative")
            ');
            $this->db->exec('DELETE FROM access_permissions WHERE page_key IN ("cheltuieli_birou", "cheltuieli_administrative")');
            $this->db->exec('
                INSERT IGNORE INTO access_template_permissions (template_id, page_key, action_key)
                SELECT template_id, "cheltuieli", action_key
                FROM access_template_permissions
                WHERE page_key IN ("cheltuieli_birou", "cheltuieli_administrative")
            ');
            $this->db->exec('DELETE FROM access_template_permissions WHERE page_key IN ("cheltuieli_birou", "cheltuieli_administrative")');
        } catch (Throwable) {
        }
    }

    // ------------------------------------------------------------- nomenclatoare

    public function getTypes(?string $categorie = null, bool $onlyActive = true): array
    {
        $sql = 'SELECT * FROM cheltuieli_tipuri';
        $conditions = [];
        $params = [];

        if ($categorie !== null && isset(self::CATEGORIES[$categorie])) {
            $conditions[] = 'categorie = :categorie';
            $params[':categorie'] = $categorie;
        }
        if ($onlyActive) {
            $conditions[] = "status = 'activ'";
        }
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY categorie ASC, sort_order ASC, nume ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function findType(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM cheltuieli_tipuri WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function getVehicles(): array
    {
        return $this->db->query("
            SELECT id, nr_inmatriculare, marca, model
            FROM vehicule
            WHERE status = 'activ'
            ORDER BY nr_inmatriculare ASC
        ")->fetchAll();
    }

    public function getDrivers(): array
    {
        return $this->db->query("
            SELECT id, nume
            FROM soferi
            WHERE status = 'activ'
            ORDER BY nume ASC
        ")->fetchAll();
    }

    public function getBeneficiaries(): array
    {
        return $this->db->query('
            SELECT id, nume
            FROM configurare_beneficiari_transport
            WHERE activ = 1
            ORDER BY nume ASC
        ')->fetchAll();
    }

    /**
     * Numarul total de cheltuieli inregistrate (fara filtre) si intervalul lor
     * de date - folosit ca hint cand perioada selectata nu contine nimic.
     *
     * @return array{count:int,min_date:?string,max_date:?string}
     */
    public function getOverallRange(): array
    {
        $row = $this->db->query('
            SELECT COUNT(*) AS cnt, MIN(data_cheltuiala) AS dmin, MAX(data_cheltuiala) AS dmax
            FROM cheltuieli
        ')->fetch();

        return [
            'count' => (int) ($row['cnt'] ?? 0),
            'min_date' => $row['dmin'] ?? null,
            'max_date' => $row['dmax'] ?? null,
        ];
    }

    public function getSuppliers(): array
    {
        $rows = $this->db->query("
            SELECT DISTINCT furnizor
            FROM cheltuieli
            WHERE COALESCE(TRIM(furnizor), '') <> ''
            ORDER BY furnizor ASC
            LIMIT 200
        ")->fetchAll();

        return array_values(array_map(static fn(array $row): string => (string) $row['furnizor'], $rows));
    }

    // ------------------------------------------------------------------- listare

    public function getPaginatedExpenses(array $filters, int $page, int $perPage): array
    {
        [$whereSql, $params] = $this->buildWhere($filters);

        $countStmt = $this->db->prepare('
            SELECT COUNT(*)
            FROM cheltuieli e
            INNER JOIN cheltuieli_tipuri t ON t.id = e.tip_id
            LEFT JOIN configurare_beneficiari_transport b ON b.id = e.beneficiar_id
            ' . $whereSql
        );
        $this->bindParams($countStmt, $params);
        $countStmt->execute();

        $totalRows = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($totalRows / max(1, $perPage)));
        $page = min(max(1, $page), $totalPages);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare('
            SELECT
                e.*,
                t.nume AS tip_nume,
                t.slug AS tip_slug,
                b.nume AS beneficiar_nume,
                sr.nume AS sofer_responsabil_nume,
                u.nume AS added_by_name,
                (SELECT COUNT(*) FROM cheltuieli_documente d WHERE d.cheltuiala_id = e.id) AS document_count,
                (SELECT d.id FROM cheltuieli_documente d WHERE d.cheltuiala_id = e.id ORDER BY d.id DESC LIMIT 1) AS document_id,
                (SELECT d.original_name FROM cheltuieli_documente d WHERE d.cheltuiala_id = e.id ORDER BY d.id DESC LIMIT 1) AS document_original_name
            FROM cheltuieli e
            INNER JOIN cheltuieli_tipuri t ON t.id = e.tip_id
            LEFT JOIN configurare_beneficiari_transport b ON b.id = e.beneficiar_id
            LEFT JOIN soferi sr ON sr.id = e.sofer_responsabil_id
            LEFT JOIN utilizatori u ON u.id = e.added_by
            ' . $whereSql . '
            ORDER BY e.data_cheltuiala DESC, e.id DESC
            LIMIT :limit_rows OFFSET :offset_rows
        ');
        $this->bindParams($stmt, $params);
        $stmt->bindValue(':limit_rows', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset_rows', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'rows' => $stmt->fetchAll(),
            'page' => $page,
            'total_pages' => $totalPages,
            'total_rows' => $totalRows,
            'per_page' => $perPage,
        ];
    }

    public function getExpensesForExport(array $filters): array
    {
        [$whereSql, $params] = $this->buildWhere($filters);

        $stmt = $this->db->prepare('
            SELECT
                e.*,
                t.nume AS tip_nume,
                b.nume AS beneficiar_nume,
                sr.nume AS sofer_responsabil_nume,
                u.nume AS added_by_name
            FROM cheltuieli e
            INNER JOIN cheltuieli_tipuri t ON t.id = e.tip_id
            LEFT JOIN configurare_beneficiari_transport b ON b.id = e.beneficiar_id
            LEFT JOIN soferi sr ON sr.id = e.sofer_responsabil_id
            LEFT JOIN utilizatori u ON u.id = e.added_by
            ' . $whereSql . '
            ORDER BY e.data_cheltuiala DESC, e.id DESC
        ');
        $this->bindParams($stmt, $params);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * @return array<int,array<int,array>> map cheltuiala_id => lista alocari
     */
    public function getAllocationsForRows(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare('
            SELECT
                a.*,
                v.nr_inmatriculare AS vehicul_nr,
                v.marca AS vehicul_marca,
                v.model AS vehicul_model,
                s.nume AS sofer_nume
            FROM cheltuieli_alocari a
            LEFT JOIN vehicule v ON v.id = a.vehicul_id
            LEFT JOIN soferi s ON s.id = a.sofer_id
            WHERE a.cheltuiala_id IN (' . $placeholders . ')
            ORDER BY a.cheltuiala_id ASC, a.id ASC
        ');
        foreach ($ids as $index => $id) {
            $stmt->bindValue($index + 1, $id, PDO::PARAM_INT);
        }
        $stmt->execute();

        $map = [];
        foreach ($stmt->fetchAll() as $allocation) {
            $expenseId = (int) ($allocation['cheltuiala_id'] ?? 0);
            $map[$expenseId][] = $allocation;
        }

        return $map;
    }

    // --------------------------------------------------------------------- KPI

    /**
     * KPI-urile reactioneaza la perioada + filtre. Cardurile Total /
     * Administrative / Operationale ignora filtrul de categorie (ele SUNT
     * defalcarea pe categorii); distributia pe alocare si topul tipurilor
     * respecta toate filtrele active.
     */
    public function getSummary(array $filters): array
    {
        // Defalcarea pe categorii, fara filtrul de categorie.
        [$whereSql, $params] = $this->buildWhere($filters, false);
        $stmt = $this->db->prepare('
            SELECT e.categorie, COALESCE(SUM(e.valoare), 0) AS total, COUNT(*) AS cnt
            FROM cheltuieli e
            INNER JOIN cheltuieli_tipuri t ON t.id = e.tip_id
            LEFT JOIN configurare_beneficiari_transport b ON b.id = e.beneficiar_id
            ' . $whereSql . '
            GROUP BY e.categorie
        ');
        $this->bindParams($stmt, $params);
        $stmt->execute();

        $byCategory = ['administrativa' => 0.0, 'operationala' => 0.0];
        $countByCategory = ['administrativa' => 0, 'operationala' => 0];
        $count = 0;
        foreach ($stmt->fetchAll() as $row) {
            $byCategory[(string) $row['categorie']] = (float) $row['total'];
            $countByCategory[(string) $row['categorie']] = (int) $row['cnt'];
            $count += (int) $row['cnt'];
        }
        $grandTotal = $byCategory['administrativa'] + $byCategory['operationala'];

        // Distributia pe alocare: strict sumele alocate (o cheltuiala multi-vehicul
        // contribuie doar cu partile alocate, nu cu totalul la fiecare entitate).
        [$whereSqlAll, $paramsAll] = $this->buildWhere($filters);
        $stmt = $this->db->prepare('
            SELECT a.tip_alocare, COALESCE(SUM(a.suma), 0) AS total
            FROM cheltuieli_alocari a
            INNER JOIN cheltuieli e ON e.id = a.cheltuiala_id
            INNER JOIN cheltuieli_tipuri t ON t.id = e.tip_id
            LEFT JOIN configurare_beneficiari_transport b ON b.id = e.beneficiar_id
            ' . $whereSqlAll . '
            GROUP BY a.tip_alocare
        ');
        $this->bindParams($stmt, $paramsAll);
        $stmt->execute();

        $byAllocation = ['vehicul' => 0.0, 'sofer' => 0.0, 'companie' => 0.0];
        foreach ($stmt->fetchAll() as $row) {
            $byAllocation[(string) $row['tip_alocare']] = (float) $row['total'];
        }

        // Top tipuri de cheltuieli dupa valoare (toate filtrele active).
        $stmt = $this->db->prepare('
            SELECT t.nume, COALESCE(SUM(e.valoare), 0) AS total
            FROM cheltuieli e
            INNER JOIN cheltuieli_tipuri t ON t.id = e.tip_id
            LEFT JOIN configurare_beneficiari_transport b ON b.id = e.beneficiar_id
            ' . $whereSqlAll . '
            GROUP BY t.id, t.nume
            ORDER BY total DESC
        ');
        $this->bindParams($stmt, $paramsAll);
        $stmt->execute();

        $topTypes = [];
        $othersTotal = 0.0;
        foreach ($stmt->fetchAll() as $index => $row) {
            if ($index < 4) {
                $topTypes[] = ['nume' => (string) $row['nume'], 'total' => (float) $row['total']];
            } else {
                $othersTotal += (float) $row['total'];
            }
        }
        if ($othersTotal > 0) {
            $topTypes[] = ['nume' => 'Altele', 'total' => $othersTotal];
        }

        return [
            'total' => $grandTotal,
            'count' => $count,
            'count_administrativa' => $countByCategory['administrativa'],
            'count_operationala' => $countByCategory['operationala'],
            'administrativa' => $byCategory['administrativa'],
            'operationala' => $byCategory['operationala'],
            'procent_administrativa' => $grandTotal > 0 ? ($byCategory['administrativa'] / $grandTotal) * 100 : 0,
            'procent_operationala' => $grandTotal > 0 ? ($byCategory['operationala'] / $grandTotal) * 100 : 0,
            'alocare' => $byAllocation,
            'alocare_total' => array_sum($byAllocation),
            'top_tipuri' => $topTypes,
        ];
    }

    // --------------------------------------------------------------------- CRUD

    /**
     * @param array $allocations lista de randuri deja validate:
     *        [['tip_alocare' => ..., 'vehicul_id' => ?, 'sofer_id' => ?, 'eticheta' => ?, 'suma' => float], ...]
     */
    public function createExpense(array $data, array $allocations, ?array $documentData, ?int $userId): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare('
                INSERT INTO cheltuieli (
                    categorie, tip_id, tip_document, data_cheltuiala, furnizor, descriere, cui,
                    valoare, valoare_neta, tva, moneda, modalitate_plata, status_plata, data_platii, scadenta, sursa,
                    numar_document, observatii,
                    beneficiar_id, sofer_responsabil_id, alocare_tip, distribuire, added_by, updated_by, created_at, updated_at
                ) VALUES (
                    :categorie, :tip_id, :tip_document, :data_cheltuiala, :furnizor, :descriere, :cui,
                    :valoare, :valoare_neta, :tva, :moneda, :modalitate_plata, :status_plata, :data_platii, :scadenta, :sursa,
                    :numar_document, :observatii,
                    :beneficiar_id, :sofer_responsabil_id, :alocare_tip, :distribuire, :added_by, :updated_by, :created_at, :updated_at
                )
            ');
            $this->bindExpenseStatement($stmt, $data, $userId, $now);
            $stmt->execute();
            $expenseId = (int) $this->db->lastInsertId();

            $this->insertAllocations($expenseId, $allocations, $now);

            if ($documentData !== null) {
                $this->insertDocument($expenseId, $documentData, $userId, $now);
            }

            $this->db->commit();
            return $expenseId;
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function updateExpense(int $id, array $data, array $allocations, ?array $documentData, ?int $userId): bool
    {
        if ($id <= 0 || $this->findExpense($id) === null) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare('
                UPDATE cheltuieli
                SET categorie = :categorie,
                    tip_id = :tip_id,
                    tip_document = :tip_document,
                    data_cheltuiala = :data_cheltuiala,
                    furnizor = :furnizor,
                    descriere = :descriere,
                    cui = :cui,
                    valoare = :valoare,
                    valoare_neta = :valoare_neta,
                    tva = :tva,
                    moneda = :moneda,
                    modalitate_plata = :modalitate_plata,
                    status_plata = :status_plata,
                    data_platii = :data_platii,
                    scadenta = :scadenta,
                    sursa = :sursa,
                    numar_document = :numar_document,
                    observatii = :observatii,
                    beneficiar_id = :beneficiar_id,
                    sofer_responsabil_id = :sofer_responsabil_id,
                    alocare_tip = :alocare_tip,
                    distribuire = :distribuire,
                    updated_by = :updated_by,
                    updated_at = :updated_at
                WHERE id = :id
            ');
            $this->bindExpenseStatement($stmt, $data, $userId, $now, false);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            $delete = $this->db->prepare('DELETE FROM cheltuieli_alocari WHERE cheltuiala_id = :id');
            $delete->bindValue(':id', $id, PDO::PARAM_INT);
            $delete->execute();

            $this->insertAllocations($id, $allocations, $now);

            if ($documentData !== null) {
                $this->insertDocument($id, $documentData, $userId, $now);
            }

            $this->db->commit();
            return true;
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    /** @return array documentele cheltuielii, pentru stergerea fisierelor fizice */
    public function deleteExpense(int $id): array
    {
        if ($id <= 0) {
            return [];
        }

        $documents = $this->getDocumentsForExpense($id);
        $stmt = $this->db->prepare('DELETE FROM cheltuieli WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $documents;
    }

    public function findExpense(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('
            SELECT e.*, t.nume AS tip_nume
            FROM cheltuieli e
            INNER JOIN cheltuieli_tipuri t ON t.id = e.tip_id
            WHERE e.id = :id
            LIMIT 1
        ');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function getDocumentsForExpense(int $expenseId): array
    {
        if ($expenseId <= 0) {
            return [];
        }

        $stmt = $this->db->prepare('
            SELECT *
            FROM cheltuieli_documente
            WHERE cheltuiala_id = :id
            ORDER BY id DESC
        ');
        $stmt->bindValue(':id', $expenseId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function findDocument(int $documentId): ?array
    {
        if ($documentId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM cheltuieli_documente WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $documentId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    // ------------------------------------------------------------------ interne

    private function insertAllocations(int $expenseId, array $allocations, string $now): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO cheltuieli_alocari (
                cheltuiala_id, tip_alocare, vehicul_id, sofer_id, eticheta, suma, created_at, updated_at
            ) VALUES (
                :cheltuiala_id, :tip_alocare, :vehicul_id, :sofer_id, :eticheta, :suma, :created_at, :updated_at
            )
        ');

        foreach ($allocations as $allocation) {
            $this->bindParams($stmt, [
                ':cheltuiala_id' => $expenseId,
                ':tip_alocare' => (string) ($allocation['tip_alocare'] ?? 'companie'),
                ':vehicul_id' => isset($allocation['vehicul_id']) && (int) $allocation['vehicul_id'] > 0 ? (int) $allocation['vehicul_id'] : null,
                ':sofer_id' => isset($allocation['sofer_id']) && (int) $allocation['sofer_id'] > 0 ? (int) $allocation['sofer_id'] : null,
                ':eticheta' => $this->nullableString($allocation['eticheta'] ?? null),
                ':suma' => round((float) ($allocation['suma'] ?? 0), 2),
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $stmt->execute();
        }
    }

    private function insertDocument(int $expenseId, array $documentData, ?int $userId, string $now): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO cheltuieli_documente (
                cheltuiala_id, original_name, stored_name, uploaded_by, created_at, updated_at
            ) VALUES (
                :cheltuiala_id, :original_name, :stored_name, :uploaded_by, :created_at, :updated_at
            )
        ');
        $this->bindParams($stmt, [
            ':cheltuiala_id' => $expenseId,
            ':original_name' => $this->nullableString($documentData['original_name'] ?? null),
            ':stored_name' => $this->nullableString($documentData['stored_name'] ?? null),
            ':uploaded_by' => $userId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $stmt->execute();
    }

    private function bindExpenseStatement(PDOStatement $stmt, array $data, ?int $userId, string $now, bool $includeCreated = true): void
    {
        $tipDocument = (string) ($data['tip_document'] ?? 'factura');
        if (!isset(self::DOCUMENT_TYPES[$tipDocument])) {
            $tipDocument = 'factura';
        }
        $moneda = strtoupper(trim((string) ($data['moneda'] ?? 'RON')));
        if (!in_array($moneda, self::CURRENCIES, true)) {
            $moneda = 'RON';
        }
        $sursa = (string) ($data['sursa'] ?? 'manual');
        if (!isset(self::SOURCES[$sursa])) {
            $sursa = 'manual';
        }

        $params = [
            ':categorie' => (string) ($data['categorie'] ?? 'administrativa'),
            ':tip_id' => (int) ($data['tip_id'] ?? 0),
            ':tip_document' => $tipDocument,
            ':data_cheltuiala' => (string) ($data['data_cheltuiala'] ?? date('Y-m-d')),
            ':furnizor' => $this->nullableString($data['furnizor'] ?? null),
            ':descriere' => $this->nullableString($data['descriere'] ?? null),
            ':cui' => $this->nullableString($data['cui'] ?? null),
            ':valoare' => round((float) ($data['valoare'] ?? 0), 2),
            ':valoare_neta' => isset($data['valoare_neta']) && $data['valoare_neta'] !== null && (float) $data['valoare_neta'] > 0 ? round((float) $data['valoare_neta'], 2) : null,
            ':tva' => isset($data['tva']) && $data['tva'] !== null && (float) $data['tva'] > 0 ? round((float) $data['tva'], 2) : null,
            ':moneda' => $moneda,
            ':modalitate_plata' => isset(self::PAYMENT_METHODS[(string) ($data['modalitate_plata'] ?? '')]) ? (string) $data['modalitate_plata'] : null,
            ':status_plata' => isset(self::PAYMENT_STATUSES[(string) ($data['status_plata'] ?? '')]) ? (string) $data['status_plata'] : null,
            ':data_platii' => $this->nullableString($data['data_platii'] ?? null),
            ':scadenta' => $this->nullableString($data['scadenta'] ?? null),
            ':sursa' => $sursa,
            ':numar_document' => $this->nullableString($data['numar_document'] ?? null),
            ':observatii' => $this->nullableString($data['observatii'] ?? null),
            ':beneficiar_id' => isset($data['beneficiar_id']) && (int) $data['beneficiar_id'] > 0 ? (int) $data['beneficiar_id'] : null,
            ':sofer_responsabil_id' => isset($data['sofer_responsabil_id']) && (int) $data['sofer_responsabil_id'] > 0 ? (int) $data['sofer_responsabil_id'] : null,
            ':alocare_tip' => (string) ($data['alocare_tip'] ?? 'companie'),
            ':distribuire' => (string) ($data['distribuire'] ?? 'egal'),
            ':updated_by' => $userId,
            ':updated_at' => $now,
        ];

        if ($includeCreated) {
            $params[':added_by'] = $userId;
            $params[':created_at'] = $now;
        }

        $this->bindParams($stmt, $params);
    }

    /**
     * @return array{0:string,1:array} [whereSql, params]
     */
    private function buildWhere(array $filters, bool $includeCategorie = true): array
    {
        $conditions = [];
        $params = [];

        $dateStart = trim((string) ($filters['date_start'] ?? ''));
        if ($dateStart !== '') {
            $conditions[] = 'e.data_cheltuiala >= :date_start';
            $params[':date_start'] = $dateStart;
        }

        $dateEnd = trim((string) ($filters['date_end'] ?? ''));
        if ($dateEnd !== '') {
            $conditions[] = 'e.data_cheltuiala <= :date_end';
            $params[':date_end'] = $dateEnd;
        }

        $categorie = trim((string) ($filters['categorie'] ?? ''));
        if ($includeCategorie && isset(self::CATEGORIES[$categorie])) {
            $conditions[] = 'e.categorie = :categorie_f';
            $params[':categorie_f'] = $categorie;
        }

        $tipId = (int) ($filters['tip_id'] ?? 0);
        if ($tipId > 0) {
            $conditions[] = 'e.tip_id = :tip_id_f';
            $params[':tip_id_f'] = $tipId;
        }

        $alocare = trim((string) ($filters['alocare'] ?? ''));
        if (in_array($alocare, ['vehicul', 'sofer', 'companie'], true)) {
            $conditions[] = 'EXISTS (SELECT 1 FROM cheltuieli_alocari fa WHERE fa.cheltuiala_id = e.id AND fa.tip_alocare = :alocare_f)';
            $params[':alocare_f'] = $alocare;
        }

        $beneficiarId = (int) ($filters['beneficiar_id'] ?? 0);
        if ($beneficiarId > 0) {
            $conditions[] = 'e.beneficiar_id = :beneficiar_f';
            $params[':beneficiar_f'] = $beneficiarId;
        }

        $vehiculId = (int) ($filters['vehicul_id'] ?? 0);
        if ($vehiculId > 0) {
            $conditions[] = 'EXISTS (SELECT 1 FROM cheltuieli_alocari fv WHERE fv.cheltuiala_id = e.id AND fv.vehicul_id = :vehicul_f)';
            $params[':vehicul_f'] = $vehiculId;
        }

        $soferId = (int) ($filters['sofer_id'] ?? 0);
        if ($soferId > 0) {
            // Soferul se potriveste fie prin alocare (poarta o parte din cost),
            // fie ca sofer responsabil (informativ, ex. cine a dus masina la spalat).
            $conditions[] = '(
                EXISTS (SELECT 1 FROM cheltuieli_alocari fs WHERE fs.cheltuiala_id = e.id AND fs.sofer_id = :sofer_f)
                OR e.sofer_responsabil_id = :sofer_resp_f
            )';
            $params[':sofer_f'] = $soferId;
            $params[':sofer_resp_f'] = $soferId;
        }

        $furnizor = trim((string) ($filters['furnizor'] ?? ''));
        if ($furnizor !== '') {
            $conditions[] = 'COALESCE(e.furnizor, "") LIKE :furnizor_f';
            $params[':furnizor_f'] = '%' . $furnizor . '%';
        }

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $conditions[] = '(
                COALESCE(e.observatii, "") LIKE :q1
                OR COALESCE(e.numar_document, "") LIKE :q2
                OR COALESCE(e.furnizor, "") LIKE :q3
                OR t.nume LIKE :q4
                OR COALESCE(b.nume, "") LIKE :q5
                OR COALESCE(e.descriere, "") LIKE :q6
            )';
            $like = '%' . $search . '%';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
            $params[':q4'] = $like;
            $params[':q5'] = $like;
            $params[':q6'] = $like;
        }

        return [
            $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions),
            $params,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }

    private function bindParams(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            if (is_int($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } elseif ($value === null) {
                $stmt->bindValue($key, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
            }
        }
    }
}
