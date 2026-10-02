<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/TripExpenseMirrorService.php';
require_once __DIR__ . '/../services/InvoiceTripMatcher.php';
require_once __DIR__ . '/../services/InvoiceStorageService.php';
require_once __DIR__ . '/AccommodationExpenseModel.php';

/**
 * Model pentru pagina globala "Facturi".
 *
 * O factura (de orice tip de cheltuiala de cursa) intra aici din trei surse:
 *   scan           pipeline-ul de scanare / OCR (ulterior; intra cu status 'in_procesare')
 *   manual         incarcare din pagina Facturi
 *   legacy_cazare  proiectie a randurilor din cheltuieli_cazare. Acele randuri raman
 *                  ale modulului Cazare (importul din Google Sheet scrie tot acolo), iar
 *                  asocierea lor la cursa e facuta tot de AccommodationExpenseModel.
 *
 * Deduplicarea se face pe `sursa_key` (UNIQUE): 'scan:<sha256>', 'manual:<sha256>',
 * 'legacy_cazare:<id>'.
 *
 * Cand o factura are cursa, ii corespunde UN rand in curse_cheltuieli (doar partea
 * de cheltuiala; refacturarea o completeaza operatorul), legat prin
 * facturi.curse_cheltuiala_id.
 */
class InvoiceModel extends BaseModel
{
    /**
     * Tipurile de factura si maparea lor pe sistemul de cheltuieli de cursa.
     *
     *   tip_cheltuiala  valoarea din ENUM-ul legacy curse_cheltuieli.tip_cheltuiala
     *   categorie       numele din categorii_cheltuieli_curse
     *
     * Tipurile fara valoare proprie in ENUM (Cazare, Spalatorie, Vulcanizare) merg pe
     * 'alte' + categorie, la fel cum functiona deja Cazare.
     */
    public const TYPES = [
        'cazare' => ['label' => 'Cazare', 'tip_cheltuiala' => 'alte', 'categorie' => 'Cazare'],
        'diurna' => ['label' => 'Diurna', 'tip_cheltuiala' => 'diurna', 'categorie' => 'Diurna'],
        'trece' => ['label' => 'Trecere', 'tip_cheltuiala' => 'trece', 'categorie' => 'Trecere'],
        'taxa_acces' => ['label' => 'Taxa acces', 'tip_cheltuiala' => 'taxa_acces', 'categorie' => 'Taxa acces'],
        'port' => ['label' => 'Port', 'tip_cheltuiala' => 'port', 'categorie' => 'Port'],
        'service' => ['label' => 'Reparatii', 'tip_cheltuiala' => 'service', 'categorie' => 'Reparatii'],
        'spalatorie' => ['label' => 'Spalatorie', 'tip_cheltuiala' => 'alte', 'categorie' => 'Spalatorie'],
        'vulcanizare' => ['label' => 'Vulcanizare', 'tip_cheltuiala' => 'alte', 'categorie' => 'Vulcanizare'],
        'alte' => ['label' => 'Alte', 'tip_cheltuiala' => 'alte', 'categorie' => 'Alte cheltuieli'],
    ];

    /** Categoriile noi de cheltuieli de cursa (fara cheie legacy, ca si Cazare). */
    private const NEW_CATEGORIES = [
        'Spalatorie' => 'Spalare vehicul. Se introduce de regula din pagina Facturi.',
        'Vulcanizare' => 'Vulcanizare / anvelope pe cursa. Se introduce de regula din pagina Facturi.',
    ];

    public const STATUSES = [
        'in_procesare' => 'In procesare',
        'asociata_auto' => 'Asociata automat',
        'asociata_manual' => 'Asociata manual',
        'de_verificat' => 'De verificat',
        'neasociata' => 'Neasociata',
        'respinsa' => 'Respinsa',
    ];

    public const SOURCES = [
        'scan' => 'Scanare',
        'manual' => 'Manual',
        'legacy_cazare' => 'Cazare (import)',
    ];

    /** @var array<string, ?int> */
    private array $categoryIdCache = [];

    // -------------------------------------------------------------------------
    // Schema
    // -------------------------------------------------------------------------

    /**
     * Creeaza tabela `facturi` si categoriile noi. Doar aditiv: nu modifica
     * tabele existente. Copia de referinta: database/migrations/2026_10_01_000001_facturi.sql.
     */
    public function ensureFacturiSchema(): void
    {
        static $ensured = false;

        if ($ensured) {
            return;
        }

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS facturi (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tip VARCHAR(30) NOT NULL,
                furnizor VARCHAR(255) NULL,
                numar_document VARCHAR(100) NULL,
                data_document DATE NULL,
                valoare_fara_tva DECIMAL(12,2) NULL,
                valoare_cu_tva DECIMAL(12,2) NULL,
                moneda CHAR(3) NOT NULL DEFAULT 'RON',
                nr_inmatriculare_extras VARCHAR(30) NULL,
                sofer_extras VARCHAR(150) NULL,
                vehicle_id INT UNSIGNED NULL,
                driver_id INT UNSIGNED NULL,
                cursa_id INT UNSIGNED NULL,
                curse_cheltuiala_id INT UNSIGNED NULL,
                status ENUM('in_procesare', 'asociata_auto', 'asociata_manual', 'de_verificat', 'neasociata', 'respinsa') NOT NULL DEFAULT 'in_procesare',
                match_candidates JSON NULL,
                match_reason VARCHAR(255) NULL,
                fara_asociere_auto TINYINT(1) NOT NULL DEFAULT 0,
                sursa ENUM('scan', 'manual', 'legacy_cazare') NOT NULL,
                sursa_key VARCHAR(120) NOT NULL,
                legacy_cazare_id INT UNSIGNED NULL,
                document_path VARCHAR(255) NULL,
                document_original_name VARCHAR(255) NULL,
                document_mime VARCHAR(150) NULL,
                document_size INT UNSIGNED NULL,
                document_sha256 CHAR(64) NULL,
                ocr_raw JSON NULL,
                observatii TEXT NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                deleted_at DATETIME NULL,
                UNIQUE KEY uk_facturi_sursa_key (sursa_key),
                UNIQUE KEY uk_facturi_legacy_cazare (legacy_cazare_id),
                KEY idx_facturi_status (status, deleted_at),
                KEY idx_facturi_tip (tip),
                KEY idx_facturi_data (data_document),
                KEY idx_facturi_cursa (cursa_id),
                KEY idx_facturi_vehicle (vehicle_id),
                KEY idx_facturi_driver (driver_id),
                KEY idx_facturi_cheltuiala (curse_cheltuiala_id),
                KEY idx_facturi_sha (document_sha256),
                KEY idx_facturi_created_by (created_by),
                CONSTRAINT fk_facturi_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE SET NULL,
                CONSTRAINT fk_facturi_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE SET NULL,
                CONSTRAINT fk_facturi_cursa FOREIGN KEY (cursa_id) REFERENCES curse_dispecer(id) ON DELETE SET NULL,
                CONSTRAINT fk_facturi_cheltuiala FOREIGN KEY (curse_cheltuiala_id) REFERENCES curse_cheltuieli(id) ON DELETE SET NULL,
                CONSTRAINT fk_facturi_created_by FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->ensureColumns();
        $this->ensureCategories();

        $ensured = true;
    }

    /** Coloane adaugate dupa prima versiune a tabelei. */
    private function ensureColumns(): void
    {
        $columns = $this->db->query("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'facturi'
        ")->fetchAll(PDO::FETCH_COLUMN) ?: [];

        // Doar aditiv, pe tabela modulului. Ordinea conteaza: AFTER trimite la coloane deja create.
        $missing = [
            'fara_asociere_auto' => 'ADD COLUMN fara_asociere_auto TINYINT(1) NOT NULL DEFAULT 0 AFTER match_reason',
            // Facturile scanate (email -> OCR)
            'document_pagini' => 'ADD COLUMN document_pagini VARCHAR(20) NULL AFTER document_sha256',
            'ocr_incercari' => 'ADD COLUMN ocr_incercari TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER ocr_raw',
            'email_message_id' => 'ADD COLUMN email_message_id VARCHAR(255) NULL AFTER ocr_incercari',
            'email_subiect' => 'ADD COLUMN email_subiect VARCHAR(255) NULL AFTER email_message_id',
            'email_primit_la' => 'ADD COLUMN email_primit_la DATETIME NULL AFTER email_subiect',
        ];
        foreach ($missing as $column => $ddl) {
            if (!in_array($column, $columns, true)) {
                $this->db->exec('ALTER TABLE facturi ' . $ddl);
            }
        }
    }

    private function ensureCategories(): void
    {
        $stmt = $this->db->prepare('INSERT IGNORE INTO categorii_cheltuieli_curse (nume, descriere, activ, legacy_key, created_at, updated_at) VALUES (:nume, :descriere, 1, NULL, :created_at, :updated_at)');
        $now = date('Y-m-d H:i:s');

        foreach (self::NEW_CATEGORIES as $name => $description) {
            $stmt->bindValue(':nume', $name, PDO::PARAM_STR);
            $stmt->bindValue(':descriere', $description, PDO::PARAM_STR);
            $stmt->bindValue(':created_at', $now, PDO::PARAM_STR);
            $stmt->bindValue(':updated_at', $now, PDO::PARAM_STR);
            $stmt->execute();
        }
    }

    // -------------------------------------------------------------------------
    // Tipuri
    // -------------------------------------------------------------------------

    public static function isValidType(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /**
     * Cuvintele (normalizate: litere mici, fara diacritice) dupa care subiectul emailului
     * scris de operator pe scanner alege tipul facturii.
     */
    private const SUBJECT_KEYWORDS = [
        'cazare' => ['cazare', 'cazari', 'hotel', 'hoteluri', 'pensiune', 'pensiuni', 'motel'],
        'diurna' => ['diurna', 'diurne'],
        'trece' => ['trecere', 'treceri', 'trece', 'pod', 'poduri', 'bac'],
        'taxa_acces' => ['taxa acces', 'taxe acces', 'taxa de acces', 'taxe de acces', 'acces'],
        'port' => ['port', 'porturi', 'taxa port', 'taxe port', 'taxe portuare'],
        'service' => ['reparatii', 'reparatie', 'service', 'piese', 'revizie', 'revizii'],
        'spalatorie' => ['spalatorie', 'spalatorii', 'spalare', 'spalari'],
        'vulcanizare' => ['vulcanizare', 'vulcanizari', 'anvelope', 'cauciucuri'],
        'alte' => ['alte', 'alte cheltuieli', 'altele', 'diverse'],
    ];

    /**
     * Tipul ales de operator prin subiectul emailului ("cazari", "Vulcanizare", "hotel"...),
     * sau null daca subiectul nu numeste exact un tip (atunci decide citirea automata).
     */
    public static function typeFromSubject(?string $subject): ?string
    {
        $text = mb_strtolower(trim((string) $subject));
        if ($text === '' || str_starts_with($text, 'send data from')) {
            return null;
        }
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($ascii !== false) {
            $text = $ascii;
        }
        $text = ' ' . trim(preg_replace('/[^a-z]+/', ' ', $text) ?? '') . ' ';

        $found = [];
        foreach (self::SUBJECT_KEYWORDS as $type => $words) {
            foreach ($words as $word) {
                if (str_contains($text, ' ' . $word . ' ')) {
                    $found[$type] = true;
                    break;
                }
            }
        }
        return count($found) === 1 ? (string) array_key_first($found) : null;
    }

    /** tip_cheltuiala (ENUM legacy) pentru randul din curse_cheltuieli. */
    public static function legacyExpenseType(string $type): ?string
    {
        return self::TYPES[$type]['tip_cheltuiala'] ?? null;
    }

    /** Id-ul categoriei din categorii_cheltuieli_curse pentru un tip de factura. */
    public function getCategoryIdForType(string $type): ?int
    {
        if (!self::isValidType($type)) {
            return null;
        }

        if (array_key_exists($type, $this->categoryIdCache)) {
            return $this->categoryIdCache[$type];
        }

        $stmt = $this->db->prepare('SELECT id FROM categorii_cheltuieli_curse WHERE nume = :nume LIMIT 1');
        $stmt->bindValue(':nume', self::TYPES[$type]['categorie'], PDO::PARAM_STR);
        $stmt->execute();
        $id = $stmt->fetchColumn();

        $this->categoryIdCache[$type] = $id === false ? null : (int) $id;

        return $this->categoryIdCache[$type];
    }

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    /** Coloanele care se pot da la creare (restul au valori implicite). */
    private const CREATE_COLUMNS = [
        'tip' => PDO::PARAM_STR,
        'furnizor' => PDO::PARAM_STR,
        'numar_document' => PDO::PARAM_STR,
        'data_document' => PDO::PARAM_STR,
        'valoare_fara_tva' => PDO::PARAM_STR,
        'valoare_cu_tva' => PDO::PARAM_STR,
        'moneda' => PDO::PARAM_STR,
        'nr_inmatriculare_extras' => PDO::PARAM_STR,
        'sofer_extras' => PDO::PARAM_STR,
        'vehicle_id' => PDO::PARAM_INT,
        'driver_id' => PDO::PARAM_INT,
        'status' => PDO::PARAM_STR,
        'sursa' => PDO::PARAM_STR,
        'sursa_key' => PDO::PARAM_STR,
        'legacy_cazare_id' => PDO::PARAM_INT,
        'document_path' => PDO::PARAM_STR,
        'document_original_name' => PDO::PARAM_STR,
        'document_mime' => PDO::PARAM_STR,
        'document_size' => PDO::PARAM_INT,
        'document_sha256' => PDO::PARAM_STR,
        'ocr_raw' => PDO::PARAM_STR,
        'observatii' => PDO::PARAM_STR,
        'created_by' => PDO::PARAM_INT,
        'document_pagini' => PDO::PARAM_STR,
        'email_message_id' => PDO::PARAM_STR,
        'email_subiect' => PDO::PARAM_STR,
        'email_primit_la' => PDO::PARAM_STR,
    ];

    /**
     * Creeaza factura si, daca nu e inca in procesare (OCR), ii ruleaza asocierea.
     * Dublura pe sursa_key ridica PDOException 23000; apelantul verifica inainte
     * cu findBySourceKey().
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $type = (string) ($data['tip'] ?? '');
        if (!self::isValidType($type)) {
            throw new InvalidArgumentException('Tip de factura necunoscut: ' . $type);
        }
        if (!isset(self::SOURCES[(string) ($data['sursa'] ?? '')]) || trim((string) ($data['sursa_key'] ?? '')) === '') {
            throw new InvalidArgumentException('Factura are nevoie de sursa si sursa_key.');
        }

        $data['status'] = (string) ($data['status'] ?? '') === 'in_procesare' ? 'in_procesare' : 'neasociata';
        $data['moneda'] = strtoupper(trim((string) ($data['moneda'] ?? ''))) ?: 'RON';
        if (isset($data['ocr_raw']) && is_array($data['ocr_raw'])) {
            $data['ocr_raw'] = json_encode($data['ocr_raw'], JSON_UNESCAPED_UNICODE);
        }

        $fields = array_intersect_key($data, self::CREATE_COLUMNS);
        $columns = array_keys($fields);
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            'INSERT INTO facturi (' . implode(', ', $columns) . ', created_at, updated_at) VALUES (:'
            . implode(', :', $columns) . ', :created_at, :updated_at)'
        );
        foreach ($fields as $column => $value) {
            $this->bindNullable($stmt, ':' . $column, $value, self::CREATE_COLUMNS[$column]);
        }
        $stmt->bindValue(':created_at', $now, PDO::PARAM_STR);
        $stmt->bindValue(':updated_at', $now, PDO::PARAM_STR);
        $stmt->execute();

        $id = (int) $this->db->lastInsertId();
        if ($data['status'] !== 'in_procesare') {
            $this->runMatching($id);
        }

        return $id;
    }

    public function findBySourceKey(string $sourceKey): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM facturi WHERE sursa_key = :sursa_key LIMIT 1');
        $stmt->bindValue(':sursa_key', $sourceKey, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                f.*,
                v.nr_inmatriculare,
                s.nume AS sofer_nume,
                c.data_inceput,
                c.data_sfarsit,
                c.deleted_at AS cursa_deleted_at
            FROM facturi f
            LEFT JOIN vehicule v ON v.id = f.vehicle_id
            LEFT JOIN soferi s ON s.id = f.driver_id
            LEFT JOIN curse_dispecer c ON c.id = f.cursa_id
            WHERE f.id = :id
            LIMIT 1
        ");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** Campurile editabile din pagina de detaliu. */
    private const EDITABLE_COLUMNS = [
        'tip' => PDO::PARAM_STR,
        'furnizor' => PDO::PARAM_STR,
        'numar_document' => PDO::PARAM_STR,
        'data_document' => PDO::PARAM_STR,
        'valoare_fara_tva' => PDO::PARAM_STR,
        'valoare_cu_tva' => PDO::PARAM_STR,
        'moneda' => PDO::PARAM_STR,
        'nr_inmatriculare_extras' => PDO::PARAM_STR,
        'sofer_extras' => PDO::PARAM_STR,
        'vehicle_id' => PDO::PARAM_INT,
        'driver_id' => PDO::PARAM_INT,
        'observatii' => PDO::PARAM_STR,
    ];

    /** Schimbarea lor invalideaza asocierea (alta data / alt vehicul / alt sofer / alt tip). */
    private const MATCH_KEY_COLUMNS = ['tip', 'data_document', 'nr_inmatriculare_extras', 'sofer_extras', 'vehicle_id', 'driver_id'];

    /**
     * Salveaza datele corectate de operator.
     *
     * Daca s-a schimbat ceva ce conteaza la asociere, asocierea (si cea manuala) se
     * reface automat; o factura "in procesare" salvata de operator intra in fluxul
     * normal. Altfel doar se actualizeaza randul de cheltuiala (suma, observatii).
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        $current = $this->getById($id);
        if ($current === null || $current['deleted_at'] !== null || (string) $current['sursa'] === 'legacy_cazare') {
            return false;
        }
        if (isset($data['tip']) && !self::isValidType((string) $data['tip'])) {
            throw new InvalidArgumentException('Tip de factura necunoscut.');
        }
        if (array_key_exists('moneda', $data)) {
            $data['moneda'] = strtoupper(trim((string) $data['moneda'])) ?: 'RON';
        }

        $fields = array_intersect_key($data, self::EDITABLE_COLUMNS);
        if ($fields === []) {
            return true;
        }

        $keyChanged = false;
        foreach (self::MATCH_KEY_COLUMNS as $column) {
            if (array_key_exists($column, $fields) && $this->normalizedValue($fields[$column]) !== $this->normalizedValue($current[$column])) {
                $keyChanged = true;
                break;
            }
        }

        $assignments = [];
        foreach (array_keys($fields) as $column) {
            $assignments[] = $column . ' = :' . $column;
        }
        $assignments[] = 'updated_at = :updated_at';

        $stmt = $this->db->prepare('UPDATE facturi SET ' . implode(', ', $assignments) . ' WHERE id = :id');
        foreach ($fields as $column => $value) {
            $this->bindNullable($stmt, ':' . $column, $value, self::EDITABLE_COLUMNS[$column]);
        }
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $status = (string) $current['status'];
        if ($status === 'respinsa') {
            return true;
        }

        if ($keyChanged || $status === 'in_procesare') {
            // Premisele asocierii s-au schimbat: alegerea manuala / dezasocierea nu mai sunt valabile.
            $reset = $this->db->prepare("UPDATE facturi SET status = 'neasociata', fara_asociere_auto = 0 WHERE id = :id");
            $reset->bindValue(':id', $id, PDO::PARAM_INT);
            $reset->execute();
            $this->runMatching($id, true);

            return true;
        }

        // Valoare / furnizor / observatii: aceeasi cursa, dar randul de cheltuiala trebuie
        // actualizat. O factura care devine valida (ex. i s-a completat valoarea) se reverifica.
        if (in_array($status, ['asociata_auto', 'asociata_manual'], true)) {
            $this->syncMirror($id);
        } elseif ((int) $current['fara_asociere_auto'] === 0) {
            $this->runMatching($id);
        }

        return true;
    }

    private function normalizedValue(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        return is_numeric($value) ? (string) (float) $value : $value;
    }

    /**
     * Reverifica facturile care asteapta o cursa (neasociate / de verificat) si pe cele
     * asociate a caror cursa sau rand de cheltuiala a disparut. Cele mai vechi intai.
     *
     * @return array{procesate: int, asociate: int}
     */
    public function rematchPending(int $limit = 50): array
    {
        $stmt = $this->db->prepare("
            SELECT f.id
            FROM facturi f
            LEFT JOIN curse_dispecer c ON c.id = f.cursa_id
            WHERE f.deleted_at IS NULL
              AND f.sursa <> 'legacy_cazare'
              AND f.fara_asociere_auto = 0
              AND (
                    f.status IN ('neasociata', 'de_verificat')
                 OR (f.status IN ('asociata_auto', 'asociata_manual')
                     AND (f.cursa_id IS NULL OR c.id IS NULL OR c.deleted_at IS NOT NULL OR f.curse_cheltuiala_id IS NULL))
              )
            ORDER BY f.updated_at ASC, f.id ASC
            LIMIT :lim
        ");
        $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $matcher = new InvoiceTripMatcher($this->db);
        $associated = 0;
        foreach ($ids as $pendingId) {
            // Asocierile manuale inca valide doar isi refac randul de cheltuiala (vezi runMatching).
            $result = $this->runMatching((int) $pendingId, false, $matcher);
            if (in_array($result['status'], ['asociata_auto', 'asociata_manual'], true)) {
                $associated++;
            }
            // Il mutam la coada (si cand nu s-a schimbat nimic), ca limita sa treaca la
            // urmatoarele facturi la apelul urmator.
            $touch = $this->db->prepare('UPDATE facturi SET updated_at = :updated_at WHERE id = :id');
            $touch->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
            $touch->bindValue(':id', (int) $pendingId, PDO::PARAM_INT);
            $touch->execute();
        }

        return ['procesate' => count($ids), 'asociate' => $associated];
    }

    // -------------------------------------------------------------------------
    // Listare
    // -------------------------------------------------------------------------

    private const LIST_SELECT = "
        SELECT
            f.*,
            v.nr_inmatriculare,
            s.nume AS sofer_nume,
            c.data_inceput,
            c.data_sfarsit,
            u.nume AS creat_de
        FROM facturi f
        LEFT JOIN vehicule v ON v.id = f.vehicle_id
        LEFT JOIN soferi s ON s.id = f.driver_id
        LEFT JOIN curse_dispecer c ON c.id = f.cursa_id AND c.deleted_at IS NULL
        LEFT JOIN utilizatori u ON u.id = f.created_by
    ";

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: array<int, array<string, mixed>>, page: int, per_page: int, total_rows: int, total_pages: int}
     */
    public function getPaginated(array $filters, int $page, int $perPage): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $countStmt = $this->db->prepare('SELECT COUNT(*) FROM facturi f ' . $where);
        $this->bindFilters($countStmt, $params);
        $countStmt->execute();
        $totalRows = (int) $countStmt->fetchColumn();

        $totalPages = max(1, (int) ceil($totalRows / max(1, $perPage)));
        $page = min(max(1, $page), $totalPages);

        $stmt = $this->db->prepare(self::LIST_SELECT . ' ' . $where . '
            ORDER BY f.data_document IS NULL DESC, f.data_document DESC, f.id DESC
            LIMIT :lim OFFSET :off');
        $this->bindFilters($stmt, $params);
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'page' => $page,
            'per_page' => $perPage,
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{count: int, total_cu_tva: float, asociate: int, de_verificat: int, neasociate: int, in_procesare: int, respinse: int}
     */
    public function getSummary(array $filters): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) AS nr,
                COALESCE(SUM(CASE WHEN f.status <> 'respinsa' THEN f.valoare_cu_tva END), 0) AS total_cu_tva,
                COALESCE(SUM(f.status IN ('asociata_auto', 'asociata_manual')), 0) AS asociate,
                COALESCE(SUM(f.status = 'de_verificat'), 0) AS de_verificat,
                COALESCE(SUM(f.status = 'neasociata'), 0) AS neasociate,
                COALESCE(SUM(f.status = 'in_procesare'), 0) AS in_procesare,
                COALESCE(SUM(f.status = 'respinsa'), 0) AS respinse
            FROM facturi f
        " . $where);
        $this->bindFilters($stmt, $params);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'count' => (int) ($row['nr'] ?? 0),
            'total_cu_tva' => (float) ($row['total_cu_tva'] ?? 0),
            'asociate' => (int) ($row['asociate'] ?? 0),
            'de_verificat' => (int) ($row['de_verificat'] ?? 0),
            'neasociate' => (int) ($row['neasociate'] ?? 0),
            'in_procesare' => (int) ($row['in_procesare'] ?? 0),
            'respinse' => (int) ($row['respinse'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, array{0: mixed, 1: int}>}
     */
    private function buildWhere(array $filters): array
    {
        $conditions = ['f.deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['tip']) && self::isValidType((string) $filters['tip'])) {
            $conditions[] = 'f.tip = :tip';
            $params[':tip'] = [(string) $filters['tip'], PDO::PARAM_STR];
        }
        if (!empty($filters['status'])) {
            if ((string) $filters['status'] === 'asociata') {
                $conditions[] = "f.status IN ('asociata_auto', 'asociata_manual')";
            } elseif ((string) $filters['status'] === 'de_rezolvat') {
                $conditions[] = "f.status IN ('de_verificat', 'neasociata', 'in_procesare')";
            } elseif (isset(self::STATUSES[(string) $filters['status']])) {
                $conditions[] = 'f.status = :status';
                $params[':status'] = [(string) $filters['status'], PDO::PARAM_STR];
            }
        }
        if (!empty($filters['sursa']) && isset(self::SOURCES[(string) $filters['sursa']])) {
            $conditions[] = 'f.sursa = :sursa';
            $params[':sursa'] = [(string) $filters['sursa'], PDO::PARAM_STR];
        }
        if (!empty($filters['data_start'])) {
            $conditions[] = 'f.data_document >= :data_start';
            $params[':data_start'] = [(string) $filters['data_start'], PDO::PARAM_STR];
        }
        if (!empty($filters['data_end'])) {
            $conditions[] = 'f.data_document <= :data_end';
            $params[':data_end'] = [(string) $filters['data_end'], PDO::PARAM_STR];
        }
        if (!empty($filters['vehicle_id'])) {
            $conditions[] = 'f.vehicle_id = :vehicle_id';
            $params[':vehicle_id'] = [(int) $filters['vehicle_id'], PDO::PARAM_INT];
        }
        if (!empty($filters['driver_id'])) {
            $conditions[] = 'f.driver_id = :driver_id';
            $params[':driver_id'] = [(int) $filters['driver_id'], PDO::PARAM_INT];
        }
        if (!empty($filters['q'])) {
            // EMULATE_PREPARES=false: acelasi placeholder nu poate aparea de doua ori.
            $conditions[] = '(f.furnizor LIKE :q_furnizor OR f.numar_document LIKE :q_numar OR f.nr_inmatriculare_extras LIKE :q_numar_auto OR f.sofer_extras LIKE :q_sofer OR f.observatii LIKE :q_obs)';
            $like = '%' . (string) $filters['q'] . '%';
            foreach ([':q_furnizor', ':q_numar', ':q_numar_auto', ':q_sofer', ':q_obs'] as $placeholder) {
                $params[$placeholder] = [$like, PDO::PARAM_STR];
            }
        }

        return ['WHERE ' . implode(' AND ', $conditions), $params];
    }

    /** @param array<string, array{0: mixed, 1: int}> $params */
    private function bindFilters(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $placeholder => [$value, $type]) {
            $stmt->bindValue($placeholder, $value, $type);
        }
    }

    /**
     * Celelalte facturi citite din aceeasi scanare (acelasi fisier), in ordinea documentelor.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getScanSiblings(array $row): array
    {
        if ((string) $row['sursa'] !== 'scan' || empty($row['document_sha256'])) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT id, tip, status, furnizor, valoare_cu_tva, moneda, document_pagini
            FROM facturi
            WHERE sursa = 'scan'
              AND document_sha256 = :sha
              AND id <> :id
              AND deleted_at IS NULL
            ORDER BY id ASC
        ");
        $stmt->bindValue(':sha', (string) $row['document_sha256'], PDO::PARAM_STR);
        $stmt->bindValue(':id', (int) $row['id'], PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Ce a citit OCR-ul pentru aceasta factura: elementul ei din ocr_raw (dupa al catelea
     * document din scanare este, din sursa_key) plus datele apelului.
     *
     * @return array{citit: ?array<string, mixed>, model: ?string, citit_la: ?string, usage: ?array<string, mixed>, documente: int}|null
     */
    public function ocrDetails(array $row): ?array
    {
        $raw = json_decode((string) ($row['ocr_raw'] ?? ''), true);
        if (!is_array($raw)) {
            return null;
        }

        $documents = is_array($raw['facturi'] ?? null) ? array_values($raw['facturi']) : [];
        // scan:<sha256> = primul document; scan:<sha256>:<n> = documentul n din scanare.
        $keyParts = explode(':', (string) $row['sursa_key']);
        $index = count($keyParts) === 3 ? max(0, (int) $keyParts[2] - 1) : 0;

        return [
            'citit' => $documents[$index] ?? null,
            'model' => isset($raw['model']) ? (string) $raw['model'] : null,
            'citit_la' => isset($raw['citit_la']) ? (string) $raw['citit_la'] : null,
            'usage' => is_array($raw['usage'] ?? null) ? $raw['usage'] : null,
            'documente' => count($documents),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function getVehicles(): array
    {
        return $this->db->query("
            SELECT id, nr_inmatriculare, status
            FROM vehicule
            ORDER BY status = 'activ' DESC, nr_inmatriculare ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int, array<string, mixed>> */
    public function getDrivers(): array
    {
        return $this->db->query("
            SELECT id, nume, status
            FROM soferi
            WHERE employment_status <> 'terminated'
            ORDER BY status = 'activ' DESC, nume ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // -------------------------------------------------------------------------
    // Asocierea la cursa
    // -------------------------------------------------------------------------

    /**
     * (Re)ruleaza asocierea automata.
     *
     * Nu atinge: facturile sterse, respinse, in procesare, cele din Cazare (le asociaza
     * modulul Cazare) si, fara $force, pe cele dezasociate manual de operator.
     * O asociere manuala ramane cat timp cursa ei exista.
     *
     * @return array{status: string, cursa_id: ?int, reason: string}
     */
    public function runMatching(int $id, bool $force = false, ?InvoiceTripMatcher $matcher = null): array
    {
        $row = $this->getById($id);
        if ($row === null) {
            return ['status' => 'neasociata', 'cursa_id' => null, 'reason' => 'Factura inexistenta.'];
        }

        $unchanged = ['status' => (string) $row['status'], 'cursa_id' => $row['cursa_id'] !== null ? (int) $row['cursa_id'] : null, 'reason' => (string) ($row['match_reason'] ?? '')];

        // Cazarile importate: asocierea o face modulul Cazare (proprietarul randului de
        // cheltuiala); aici doar cerem rerularea explicita si reproiectam randul.
        if ((string) $row['sursa'] === 'legacy_cazare' && $force && $row['deleted_at'] === null && $row['legacy_cazare_id'] !== null) {
            $this->accommodation()->resolveAssociation((int) $row['legacy_cazare_id']);
            $this->syncLegacyCazare(true, false, (int) $row['legacy_cazare_id']);
            $row = $this->getById($id) ?? $row;

            return ['status' => (string) $row['status'], 'cursa_id' => $row['cursa_id'] !== null ? (int) $row['cursa_id'] : null, 'reason' => (string) ($row['match_reason'] ?? '')];
        }

        if ($row['deleted_at'] !== null
            || in_array((string) $row['status'], ['respinsa', 'in_procesare'], true)
            || (string) $row['sursa'] === 'legacy_cazare'
            || (!$force && (int) $row['fara_asociere_auto'] === 1)) {
            return $unchanged;
        }

        if ((string) $row['status'] === 'asociata_manual' && $row['cursa_id'] !== null && $this->raceIsActive((int) $row['cursa_id'])) {
            $this->syncMirror($id);

            return $unchanged;
        }

        $matcher ??= new InvoiceTripMatcher($this->db);
        $result = $matcher->match([
            'tip' => (string) $row['tip'],
            'data_document' => $row['data_document'],
            'vehicle_id' => $row['vehicle_id'] !== null ? (int) $row['vehicle_id'] : null,
            'driver_id' => $row['driver_id'] !== null ? (int) $row['driver_id'] : null,
            'nr_inmatriculare_extras' => $row['nr_inmatriculare_extras'],
            'sofer_extras' => $row['sofer_extras'],
        ]);

        $status = $result['status'];
        $raceId = $result['cursa_id'];
        $reason = $result['reason'];

        // Cursa e clara, dar randul de cheltuiala nu se poate scrie corect: ramane de verificat.
        $blocker = $this->mirrorBlocker($row);
        if ($status === 'asociata_auto' && $blocker !== null) {
            $status = 'de_verificat';
            $raceId = null;
            $reason = $blocker . ' ' . $reason;
        }

        // Aceeasi cheltuiala poate veni de doua ori (scanata si introdusa in Cazare, sau
        // scanata de doua ori): nu o legam automat, ca sa nu apara dublu pe cursa.
        $duplicate = $this->findPossibleDuplicate($row, $result['vehicle_id'], $result['driver_id']);
        if ($duplicate !== null) {
            $status = 'de_verificat';
            $raceId = null;
            $reason = 'Posibil duplicat al facturii #' . (int) $duplicate['id']
                . ' (' . (self::SOURCES[(string) $duplicate['sursa']] ?? $duplicate['sursa']) . ', aceeasi data si suma).'
                . ' Respinge-o daca e dublura sau asociaz-o manual daca e alta cheltuiala.'
                . ($result['status'] === 'de_verificat' ? ' ' . $result['reason'] : '');
        }

        $this->applyAssociation($id, $status, $raceId, $result['candidates'], $reason, $result['vehicle_id'], $result['driver_id'], false);

        return ['status' => $status, 'cursa_id' => $raceId, 'reason' => $reason];
    }

    /**
     * O alta factura (din orice sursa, inclusiv Cazare) cu acelasi tip, aceeasi data si
     * aceeasi valoare cu TVA. Nu e duplicat cand cele doua au clar soferi sau vehicule
     * diferite (doi soferi la acelasi hotel, in aceeasi noapte, la acelasi pret).
     */
    public function findPossibleDuplicate(array $row, ?int $vehicleId, ?int $driverId): ?array
    {
        if ($row['data_document'] === null || $row['valoare_cu_tva'] === null || (float) $row['valoare_cu_tva'] <= 0) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT id, sursa, vehicle_id, driver_id, numar_document
            FROM facturi
            WHERE id <> :id
              AND deleted_at IS NULL
              AND status <> 'respinsa'
              AND tip = :tip
              AND data_document = :data_document
              AND ABS(valoare_cu_tva - :valoare) < 0.01
            ORDER BY id ASC
        ");
        $stmt->bindValue(':id', (int) $row['id'], PDO::PARAM_INT);
        $stmt->bindValue(':tip', (string) $row['tip'], PDO::PARAM_STR);
        $stmt->bindValue(':data_document', (string) $row['data_document'], PDO::PARAM_STR);
        $stmt->bindValue(':valoare', number_format((float) $row['valoare_cu_tva'], 2, '.', ''), PDO::PARAM_STR);
        $stmt->execute();

        $number = mb_strtolower(trim((string) ($row['numar_document'] ?? '')));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $other) {
            $otherNumber = mb_strtolower(trim((string) ($other['numar_document'] ?? '')));
            if ($number !== '' && $otherNumber !== '' && $number !== $otherNumber) {
                continue; // numere de document diferite: alte facturi
            }
            if ($driverId !== null && $other['driver_id'] !== null && (int) $other['driver_id'] !== $driverId) {
                continue;
            }
            if ($vehicleId !== null && $other['vehicle_id'] !== null && (int) $other['vehicle_id'] !== $vehicleId) {
                continue;
            }

            return $other;
        }

        return null;
    }

    /** Asociere aleasa de operator (confirmare candidat sau alta cursa). Intoarce eroarea sau null. */
    public function linkManually(int $id, int $raceId): ?string
    {
        $row = $this->getById($id);
        if ($row === null || $row['deleted_at'] !== null) {
            return 'Factura nu exista.';
        }
        if ((string) $row['sursa'] === 'legacy_cazare') {
            // Modulul Cazare scrie randul de cheltuiala; noi doar reproiectam rezultatul.
            if ($row['legacy_cazare_id'] === null || !$this->accommodation()->linkManually((int) $row['legacy_cazare_id'], $raceId)) {
                return 'Cursa aleasa nu exista sau a fost stearsa.';
            }
            $this->syncLegacyCazare(true, false, (int) $row['legacy_cazare_id']);

            return null;
        }
        if (!$this->raceIsActive($raceId)) {
            return 'Cursa aleasa nu exista sau a fost stearsa.';
        }
        $blocker = $this->mirrorBlocker($row);
        if ($blocker !== null) {
            return $blocker;
        }

        $this->applyAssociation(
            $id,
            'asociata_manual',
            $raceId,
            $this->decodeCandidates($row['match_candidates'] ?? null),
            'Asociata manual la cursa #' . $raceId . '.',
            $row['vehicle_id'] !== null ? (int) $row['vehicle_id'] : null,
            $row['driver_id'] !== null ? (int) $row['driver_id'] : null,
            false
        );

        return null;
    }

    /**
     * Rupe asocierea; reverificarea automata o ocoleste pana la o rerulare explicita.
     * La cazarile importate se aplica regula modulului Cazare: anuleaza alegerea manuala
     * si readuce cazarea in fluxul automat (care o poate reasocia imediat).
     */
    public function unlink(int $id): bool
    {
        $row = $this->getById($id);
        if ($row === null || $row['deleted_at'] !== null) {
            return false;
        }
        if ((string) $row['sursa'] === 'legacy_cazare') {
            if ($row['legacy_cazare_id'] === null || !$this->accommodation()->unlink((int) $row['legacy_cazare_id'])) {
                return false;
            }
            $this->syncLegacyCazare(true, false, (int) $row['legacy_cazare_id']);

            return true;
        }

        $this->applyAssociation(
            $id,
            'neasociata',
            null,
            $this->decodeCandidates($row['match_candidates'] ?? null),
            'Dezasociata manual. Foloseste "Reruleaza asocierea" pentru asociere automata.',
            $row['vehicle_id'] !== null ? (int) $row['vehicle_id'] : null,
            $row['driver_id'] !== null ? (int) $row['driver_id'] : null,
            true
        );

        return true;
    }

    public function reject(int $id, ?string $reason = null): bool
    {
        $row = $this->getById($id);
        if ($row === null || (string) $row['sursa'] === 'legacy_cazare') {
            return false;
        }

        $reason = trim((string) $reason);
        $this->applyAssociation(
            $id,
            'respinsa',
            null,
            [],
            'Respinsa' . ($reason !== '' ? ': ' . mb_substr($reason, 0, 200) : '.'),
            $row['vehicle_id'] !== null ? (int) $row['vehicle_id'] : null,
            $row['driver_id'] !== null ? (int) $row['driver_id'] : null,
            true
        );

        return true;
    }

    // -------------------------------------------------------------------------
    // Facturi scanate: coada OCR
    // -------------------------------------------------------------------------

    /** Dupa atatea esecuri, factura trece la "de verificat" si o completeaza operatorul. */
    public const OCR_MAX_ATTEMPTS = 3;

    /**
     * Scanarile care asteapta citirea (cele mai vechi intai).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPendingOcr(int $limit): array
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM facturi
            WHERE sursa = 'scan'
              AND status = 'in_procesare'
              AND deleted_at IS NULL
              AND ocr_incercari < :max_incercari
            ORDER BY id ASC
            LIMIT :lim
        ");
        $stmt->bindValue(':max_incercari', self::OCR_MAX_ATTEMPTS, PDO::PARAM_INT);
        $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Raspunsul OCR (brut) si paginile din scanare ale acestei facturi. */
    public function saveOcrResult(int $id, string $rawJson, ?string $pages): void
    {
        $stmt = $this->db->prepare('
            UPDATE facturi
            SET ocr_raw = :ocr_raw, document_pagini = :document_pagini, ocr_incercari = ocr_incercari + 1, updated_at = :updated_at
            WHERE id = :id
        ');
        $stmt->bindValue(':ocr_raw', $rawJson, PDO::PARAM_STR);
        $this->bindNullable($stmt, ':document_pagini', $pages, PDO::PARAM_STR);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Aplica rezultatul citirii pe o scanare "in procesare".
     *
     * Primul document completeaza randul scanarii (care trece astfel prin asocierea
     * automata la cursa); fiecare document in plus din aceeasi scanare devine o factura
     * noua, cu acelasi fisier si paginile ei. Nicio factura gasita -> scanarea e respinsa
     * (operatorul poate anula respingerea din pagina facturii).
     *
     * @param array<int, array<string, mixed>> $invoices rezultat InvoiceOcrService::normalize()
     * @param array<string, mixed> $meta model, usage (se pastreaza in ocr_raw)
     * @return array<int, int> id-urile facturilor completate / create
     */
    public function applyOcrResult(int $id, array $invoices, array $meta = []): array
    {
        $row = $this->getById($id);
        if ($row === null || (string) $row['sursa'] !== 'scan') {
            return [];
        }

        $raw = (string) json_encode($meta + ['citit_la' => date('c'), 'facturi' => $invoices], JSON_UNESCAPED_UNICODE);

        if ($invoices === []) {
            $this->saveOcrResult($id, $raw, null);
            $this->reject($id, 'citirea automata nu a gasit nicio factura sau bon in scanare');

            return [$id];
        }

        $ids = [];
        $sha = (string) $row['document_sha256'];
        // Categoria scrisa de operator in subiectul emailului ("cazari", "vulcanizare"...)
        // are prioritate fata de ce deduce citirea automata, pentru toate documentele scanarii.
        $subjectType = self::typeFromSubject($row['email_subiect'] ?? null);
        foreach (array_values($invoices) as $index => $invoice) {
            $amount = static fn(mixed $value): ?string => $value !== null ? number_format((float) $value, 2, '.', '') : null;
            $ocrType = self::isValidType((string) ($invoice['tip'] ?? '')) ? (string) $invoice['tip'] : 'alte';
            $fields = [
                'tip' => $subjectType ?? $ocrType,
                'furnizor' => $invoice['furnizor'] ?? null,
                'numar_document' => $invoice['numar_document'] ?? null,
                'data_document' => $invoice['data_document'] ?? null,
                'valoare_fara_tva' => $amount($invoice['valoare_fara_tva'] ?? null),
                'valoare_cu_tva' => $amount($invoice['valoare_cu_tva'] ?? null),
                'moneda' => $invoice['moneda'] ?? 'RON',
                'nr_inmatriculare_extras' => $invoice['nr_inmatriculare'] ?? null,
                'sofer_extras' => $invoice['sofer'] ?? null,
                'observatii' => implode(' | ', array_filter([
                    !empty($invoice['cui_furnizor']) ? 'CUI furnizor: ' . $invoice['cui_furnizor'] : null,
                    $invoice['observatii'] ?? null,
                    $subjectType !== null
                        ? 'Tip din subiectul emailului: ' . self::TYPES[$subjectType]['label']
                            . ($subjectType !== $ocrType ? ' (citirea automata propunea ' . self::TYPES[$ocrType]['label'] . ')' : '')
                        : null,
                    'Citire automata, incredere ' . ($invoice['incredere'] ?? 'necunoscuta'),
                ])),
            ];

            if ($index === 0) {
                $this->saveOcrResult($id, $raw, $invoice['pagini'] ?? null);
                // update() muta factura din "in_procesare" in asocierea automata.
                $this->update($id, $fields);
                $ids[] = $id;
                continue;
            }

            $sourceKey = 'scan:' . $sha . ':' . ($index + 1);
            $existing = $this->findBySourceKey($sourceKey);
            if ($existing !== null) {
                // Rerulare dupa un esec partial: documentul exista deja.
                $ids[] = (int) $existing['id'];
                continue;
            }

            $newId = $this->create($fields + [
                'sursa' => 'scan',
                'sursa_key' => $sourceKey,
                'document_path' => $row['document_path'],
                'document_original_name' => $row['document_original_name'],
                'document_mime' => $row['document_mime'],
                'document_size' => $row['document_size'],
                'document_sha256' => $sha,
                'email_message_id' => $row['email_message_id'],
                'email_subiect' => $row['email_subiect'],
                'email_primit_la' => $row['email_primit_la'],
                'created_by' => $row['created_by'],
            ]);
            $this->saveOcrResult($newId, $raw, $invoice['pagini'] ?? null);
            $ids[] = $newId;
        }

        return $ids;
    }

    /**
     * Un esec la citire. Ramane "in procesare" (se reincearca la rularea urmatoare) pana la
     * OCR_MAX_ATTEMPTS sau, la o eroare definitiva, trece direct la "de verificat".
     */
    public function recordOcrFailure(int $id, string $message, bool $permanent): void
    {
        $row = $this->getById($id);
        if ($row === null) {
            return;
        }

        $attempts = (int) $row['ocr_incercari'] + 1;
        $final = $permanent || $attempts >= self::OCR_MAX_ATTEMPTS;
        $reason = $final
            ? 'Citirea automata a esuat: ' . $message . ' Completeaza datele manual.'
            : 'Citirea automata a esuat (incercarea ' . $attempts . ' din ' . self::OCR_MAX_ATTEMPTS . '), se reincearca: ' . $message;

        $stmt = $this->db->prepare('
            UPDATE facturi
            SET ocr_incercari = :incercari, status = :status, match_reason = :match_reason, updated_at = :updated_at
            WHERE id = :id
        ');
        $stmt->bindValue(':incercari', $final ? max($attempts, self::OCR_MAX_ATTEMPTS) : $attempts, PDO::PARAM_INT);
        $stmt->bindValue(':status', $final ? 'de_verificat' : 'in_procesare', PDO::PARAM_STR);
        $stmt->bindValue(':match_reason', mb_substr($reason, 0, 255), PDO::PARAM_STR);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** Anuleaza respingerea: factura revine in fluxul automat. */
    public function reopen(int $id): bool
    {
        $row = $this->getById($id);
        if ($row === null || $row['deleted_at'] !== null || (string) $row['status'] !== 'respinsa' || (string) $row['sursa'] === 'legacy_cazare') {
            return false;
        }

        $stmt = $this->db->prepare("UPDATE facturi SET status = 'neasociata', fara_asociere_auto = 0, updated_at = :updated_at WHERE id = :id");
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $this->runMatching($id, true);

        return true;
    }

    /** Stergere logica: randul ramane (sursa_key blocheaza reimportul), cheltuiala de cursa pleaca. */
    public function softDelete(int $id): bool
    {
        $row = $this->getById($id);
        if ($row === null || (string) $row['sursa'] === 'legacy_cazare') {
            return false;
        }

        $stmt = $this->db->prepare('UPDATE facturi SET deleted_at = :deleted_at, updated_at = :updated_at WHERE id = :id');
        $now = date('Y-m-d H:i:s');
        $stmt->bindValue(':deleted_at', $now, PDO::PARAM_STR);
        $stmt->bindValue(':updated_at', $now, PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $this->syncMirror($id);

        return true;
    }

    /**
     * Stergere definitiva, pentru retestare: randul (la o scanare, toate facturile din
     * aceeasi scanare, si cele sterse logic), cheltuielile lor de pe cursa si fisierul,
     * daca nu-l mai foloseste alta factura. sursa_key se elibereaza, deci aceeasi scanare
     * poate fi preluata din nou.
     *
     * @return array{facturi: int, fisier_sters: bool}|null null daca factura nu exista sau e import de cazare
     */
    public function purge(int $id, InvoiceStorageService $storage): ?array
    {
        $stmt = $this->db->prepare('SELECT id, sursa, document_path, document_sha256 FROM facturi WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || (string) $row['sursa'] === 'legacy_cazare') {
            return null;
        }

        if ((string) $row['sursa'] === 'scan' && !empty($row['document_sha256'])) {
            $stmt = $this->db->prepare("SELECT id, curse_cheltuiala_id FROM facturi WHERE sursa = 'scan' AND document_sha256 = :sha");
            $stmt->bindValue(':sha', (string) $row['document_sha256'], PDO::PARAM_STR);
        } else {
            $stmt = $this->db->prepare('SELECT id, curse_cheltuiala_id FROM facturi WHERE id = :id');
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        }
        $stmt->execute();
        $targets = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $mirror = new TripExpenseMirrorService($this->db);
        $this->db->beginTransaction();
        try {
            foreach ($targets as $target) {
                if ($target['curse_cheltuiala_id'] !== null) {
                    $this->setMirrorPointer((int) $target['id'], null);
                    $mirror->delete((int) $target['curse_cheltuiala_id']);
                }
                $delete = $this->db->prepare('DELETE FROM facturi WHERE id = :id');
                $delete->bindValue(':id', (int) $target['id'], PDO::PARAM_INT);
                $delete->execute();
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        // Fisierul pleaca doar daca nu mai e folosit (aceeasi poza poate fi si incarcare manuala).
        $fileRemoved = false;
        $path = trim((string) ($row['document_path'] ?? ''));
        if ($path !== '' && str_starts_with($path, 'storage/invoices/')) {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM facturi WHERE document_path = :path');
            $stmt->bindValue(':path', $path, PDO::PARAM_STR);
            $stmt->execute();
            $absolute = $storage->resolveReadablePath($path);
            if ((int) $stmt->fetchColumn() === 0 && $absolute !== null) {
                $fileRemoved = @unlink($absolute);
            }
        }

        return ['facturi' => count($targets), 'fisier_sters' => $fileRemoved];
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     */
    private function applyAssociation(int $id, string $status, ?int $raceId, array $candidates, string $reason, ?int $vehicleId, ?int $driverId, bool $noAutoMatch): void
    {
        $stmt = $this->db->prepare("
            UPDATE facturi
            SET status = :status,
                cursa_id = :cursa_id,
                match_candidates = :match_candidates,
                match_reason = :match_reason,
                vehicle_id = :vehicle_id,
                driver_id = :driver_id,
                fara_asociere_auto = :fara_asociere_auto,
                updated_at = :updated_at
            WHERE id = :id
        ");
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $this->bindNullable($stmt, ':cursa_id', $raceId, PDO::PARAM_INT);
        $this->bindNullable($stmt, ':match_candidates', $candidates === [] ? null : json_encode($candidates, JSON_UNESCAPED_UNICODE), PDO::PARAM_STR);
        $stmt->bindValue(':match_reason', mb_substr($reason, 0, 255), PDO::PARAM_STR);
        $this->bindNullable($stmt, ':vehicle_id', $vehicleId, PDO::PARAM_INT);
        $this->bindNullable($stmt, ':driver_id', $driverId, PDO::PARAM_INT);
        $stmt->bindValue(':fara_asociere_auto', $noAutoMatch ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $this->syncMirror($id);
    }

    // -------------------------------------------------------------------------
    // Cazarile existente (proiectie din cheltuieli_cazare)
    // -------------------------------------------------------------------------

    private ?AccommodationExpenseModel $accommodationModel = null;

    private function accommodation(): AccommodationExpenseModel
    {
        if ($this->accommodationModel === null) {
            $this->accommodationModel = new AccommodationExpenseModel($this->db);
            $this->accommodationModel->ensureSchema();
        }

        return $this->accommodationModel;
    }

    /**
     * Aduce cazarile din cheltuieli_cazare in `facturi` (sursa = legacy_cazare).
     *
     * Doar CITESTE din modulul Cazare: nu scrie in cheltuieli_cazare si nici in
     * curse_cheltuieli. Randul de cheltuiala existent al cazarii (cazare_id) se leaga
     * prin curse_cheltuiala_id, deci nu se dubleaza. Rularea repetata e sigura:
     * randurile identice raman neatinse.
     *
     *   $apply = false     doar numara ce s-ar face (dry-run)
     *   $onlyChanged       doar cazarile noi / modificate dupa ultima proiectie
     *                      (pentru deschiderea paginii); false = toate
     *   $onlyCazareId      o singura cazare (dupa o actiune din Facturi)
     *
     * @return array{total: int, noi: int, actualizate: int, neschimbate: int, legate_de_cursa: int, sterse: int, erori: array<int, string>}
     */
    public function syncLegacyCazare(bool $apply = true, bool $onlyChanged = false, ?int $onlyCazareId = null): array
    {
        $summary = ['total' => 0, 'noi' => 0, 'actualizate' => 0, 'neschimbate' => 0, 'legate_de_cursa' => 0, 'sterse' => 0, 'erori' => []];
        $accommodation = $this->accommodation();

        $conditions = [];
        if ($onlyCazareId !== null) {
            $conditions[] = 'z.id = :cazare_id';
        } elseif ($onlyChanged) {
            $conditions[] = '(f.id IS NULL
                OR f.deleted_at IS NOT NULL
                OR z.updated_at > f.updated_at
                OR NOT (f.curse_cheltuiala_id <=> (SELECT m.id FROM curse_cheltuieli m WHERE m.cazare_id = z.id LIMIT 1))
                OR NOT (f.document_path <=> (SELECT CONCAT(:docs_prefix, d.file_path) FROM cheltuieli_cazare_documente d WHERE d.cazare_id = z.id ORDER BY d.id LIMIT 1)))';
        }

        $stmt = $this->db->prepare('
            SELECT
                z.*,
                s.nume AS sofer_nume,
                c.vehicle_id AS cursa_vehicle_id,
                (SELECT m.id FROM curse_cheltuieli m WHERE m.cazare_id = z.id LIMIT 1) AS mirror_id,
                f.id AS factura_id
            FROM cheltuieli_cazare z
            INNER JOIN soferi s ON s.id = z.sofer_id
            LEFT JOIN curse_dispecer c ON c.id = z.cursa_id
            LEFT JOIN facturi f ON f.legacy_cazare_id = z.id
            ' . ($conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions)) . '
            ORDER BY z.id ASC
        ');
        if ($onlyCazareId !== null) {
            $stmt->bindValue(':cazare_id', $onlyCazareId, PDO::PARAM_INT);
        } elseif ($onlyChanged) {
            $stmt->bindValue(':docs_prefix', InvoiceStorageService::legacyCazarePath(''), PDO::PARAM_STR);
        }
        $stmt->execute();
        $cazari = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $documents = $accommodation->getDocumentsForRows(array_map(static fn(array $z): int => (int) $z['id'], $cazari));

        foreach ($cazari as $cazare) {
            $summary['total']++;
            try {
                $projected = $this->projectLegacyCazare($cazare, $documents[(int) $cazare['id']][0] ?? null);
                if ($projected['curse_cheltuiala_id'] !== null) {
                    $summary['legate_de_cursa']++;
                }

                $existing = $cazare['factura_id'] !== null ? $this->getById((int) $cazare['factura_id']) : null;
                if ($existing === null) {
                    $summary['noi']++;
                    if ($apply) {
                        $this->insertLegacyCazare($projected, $cazare);
                    }
                    continue;
                }

                $changes = [];
                foreach ($projected as $column => $value) {
                    if ($column === 'match_candidates') {
                        // MySQL reformateaza JSON-ul (si reordoneaza cheile): comparam continutul.
                        if ($this->canonicalCandidates($value) !== $this->canonicalCandidates($existing[$column] ?? null)) {
                            $changes[$column] = $value;
                        }
                        continue;
                    }
                    if ($this->normalizedValue($value) !== $this->normalizedValue($existing[$column] ?? null)) {
                        $changes[$column] = $value;
                    }
                }
                if ($existing['deleted_at'] !== null) {
                    $changes['deleted_at'] = null;
                }

                if ($changes === []) {
                    $summary['neschimbate']++;
                    continue;
                }

                $summary['actualizate']++;
                if ($apply) {
                    if (array_key_exists('document_path', $changes)) {
                        $changes['document_sha256'] = $this->legacyDocumentHash($projected['document_path']);
                    }
                    $this->updateLegacyCazare((int) $existing['id'], $changes);
                }
            } catch (Throwable $exception) {
                $summary['erori'][] = 'Cazarea #' . (int) $cazare['id'] . ': ' . $exception->getMessage();
            }
        }

        // Cazari sterse din modulul Cazare: proiectia lor dispare si din Facturi.
        if ($onlyCazareId === null) {
            $orphans = $this->db->query("
                SELECT f.id
                FROM facturi f
                LEFT JOIN cheltuieli_cazare z ON z.id = f.legacy_cazare_id
                WHERE f.sursa = 'legacy_cazare' AND f.deleted_at IS NULL AND z.id IS NULL
            ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $summary['sterse'] = count($orphans);
            if ($apply && $orphans !== []) {
                $softDelete = $this->db->prepare('UPDATE facturi SET deleted_at = :deleted_at, curse_cheltuiala_id = NULL, cursa_id = NULL, updated_at = :updated_at WHERE id = :id');
                $now = date('Y-m-d H:i:s');
                foreach ($orphans as $orphanId) {
                    $softDelete->bindValue(':deleted_at', $now, PDO::PARAM_STR);
                    $softDelete->bindValue(':updated_at', $now, PDO::PARAM_STR);
                    $softDelete->bindValue(':id', (int) $orphanId, PDO::PARAM_INT);
                    $softDelete->execute();
                }
            }
        }

        return $summary;
    }

    /**
     * Cum arata o cazare ca factura. Statusurile Cazare se traduc 1:1:
     * asociat -> asociata_auto / asociata_manual, ambiguu -> de_verificat, neasociat -> neasociata.
     *
     * @return array<string, mixed>
     */
    private function projectLegacyCazare(array $cazare, ?array $firstDocument): array
    {
        $raceId = $cazare['cursa_id'] !== null ? (int) $cazare['cursa_id'] : null;
        $candidates = [];

        switch ((string) $cazare['status']) {
            case 'asociat':
                $manual = (int) $cazare['asociere_manuala'] === 1;
                $status = $manual ? 'asociata_manual' : 'asociata_auto';
                $reason = $manual ? 'Asociata manual in Cazare.' : 'Asociata automat in Cazare (sofer + perioada).';
                break;
            case 'ambiguu':
                $status = 'de_verificat';
                $raceId = null;
                $reason = 'Mai multe curse ale soferului acopera data. Alege cursa.';
                foreach ($this->accommodation()->findMatchingRaces((int) $cazare['sofer_id'], (string) $cazare['data']) as $race) {
                    $candidates[] = [
                        'id' => (int) $race['id'],
                        'data_inceput' => (string) $race['data_inceput'],
                        'data_sfarsit' => (string) $race['data_sfarsit'],
                        'nr_inmatriculare' => (string) ($race['nr_inmatriculare'] ?? ''),
                        'sofer' => (string) $cazare['sofer_nume'],
                        'motiv' => 'sofer + perioada (Cazare)',
                    ];
                }
                break;
            default:
                $status = 'neasociata';
                $raceId = null;
                $reason = 'Nicio cursa a soferului in perioada cazarii; se reincearca automat.';
        }

        return [
            'tip' => 'cazare',
            'data_document' => (string) $cazare['data'],
            'valoare_fara_tva' => number_format((float) $cazare['total'], 2, '.', ''),
            'valoare_cu_tva' => number_format((float) $cazare['total_cu_tva'], 2, '.', ''),
            'moneda' => 'RON',
            'driver_id' => (int) $cazare['sofer_id'],
            'vehicle_id' => $raceId !== null && $cazare['cursa_vehicle_id'] !== null ? (int) $cazare['cursa_vehicle_id'] : null,
            'cursa_id' => $raceId,
            'curse_cheltuiala_id' => $cazare['mirror_id'] !== null ? (int) $cazare['mirror_id'] : null,
            'status' => $status,
            'match_reason' => $reason,
            'match_candidates' => $candidates === [] ? null : json_encode($candidates, JSON_UNESCAPED_UNICODE),
            'observatii' => $cazare['observatii'],
            'document_path' => $firstDocument !== null ? InvoiceStorageService::legacyCazarePath((string) $firstDocument['file_path']) : null,
            'document_original_name' => $firstDocument['original_name'] ?? null,
            'document_mime' => $firstDocument['mime_type'] ?? null,
            'document_size' => $firstDocument !== null ? (int) $firstDocument['file_size'] : null,
        ];
    }

    /** Coloanele scrise de proiectie (in plus fata de CREATE_COLUMNS). */
    private const LEGACY_EXTRA_COLUMNS = [
        'cursa_id' => PDO::PARAM_INT,
        'curse_cheltuiala_id' => PDO::PARAM_INT,
        'match_reason' => PDO::PARAM_STR,
        'match_candidates' => PDO::PARAM_STR,
        'deleted_at' => PDO::PARAM_STR,
    ];

    private function insertLegacyCazare(array $projected, array $cazare): void
    {
        $fields = $projected + [
            'sursa' => 'legacy_cazare',
            'sursa_key' => 'legacy_cazare:' . (int) $cazare['id'],
            'legacy_cazare_id' => (int) $cazare['id'],
            'document_sha256' => $this->legacyDocumentHash($projected['document_path']),
            'created_by' => $cazare['created_by'] !== null ? (int) $cazare['created_by'] : null,
        ];
        $types = self::CREATE_COLUMNS + self::LEGACY_EXTRA_COLUMNS;
        $columns = array_keys($fields);
        $now = date('Y-m-d H:i:s');

        // created_at = data introducerii cazarii, ca ordinea istorica sa ramana.
        $stmt = $this->db->prepare(
            'INSERT INTO facturi (' . implode(', ', $columns) . ', created_at, updated_at) VALUES (:'
            . implode(', :', $columns) . ', :created_at, :updated_at)'
        );
        foreach ($fields as $column => $value) {
            $this->bindNullable($stmt, ':' . $column, $value, $types[$column]);
        }
        $stmt->bindValue(':created_at', (string) ($cazare['created_at'] ?: $now), PDO::PARAM_STR);
        $stmt->bindValue(':updated_at', $now, PDO::PARAM_STR);
        $stmt->execute();
    }

    /** @param array<string, mixed> $changes */
    private function updateLegacyCazare(int $id, array $changes): void
    {
        $types = self::CREATE_COLUMNS + self::LEGACY_EXTRA_COLUMNS;
        $assignments = [];
        foreach (array_keys($changes) as $column) {
            $assignments[] = $column . ' = :' . $column;
        }
        $assignments[] = 'updated_at = :updated_at';

        $stmt = $this->db->prepare('UPDATE facturi SET ' . implode(', ', $assignments) . ' WHERE id = :id');
        foreach ($changes as $column => $value) {
            $this->bindNullable($stmt, ':' . $column, $value, $types[$column]);
        }
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** @return array<int, array<string, mixed>> candidatii cu cheile sortate */
    private function canonicalCandidates(mixed $json): array
    {
        return array_map(static function (array $candidate): array {
            ksort($candidate);

            return $candidate;
        }, $this->decodeCandidates($json));
    }

    private function legacyDocumentHash(?string $relativePath): ?string
    {
        $path = (new InvoiceStorageService())->resolveReadablePath($relativePath);
        if ($path === null) {
            return null;
        }
        $hash = hash_file('sha256', $path);

        return $hash === false ? null : $hash;
    }

    /** Toate facturile atasate cazarii (o cazare poate avea mai multe documente). */
    public function getLegacyCazareDocuments(int $cazareId): array
    {
        return $this->accommodation()->getDocuments($cazareId);
    }

    // -------------------------------------------------------------------------
    // Randul din curse_cheltuieli
    // -------------------------------------------------------------------------

    /** Taxele care in curse_cheltuieli se tin ca bucati x pret unitar, cu locatie. */
    private const TOLL_EXPENSE_TYPES = ['taxa_acces', 'port', 'trece'];

    /**
     * Aduce randul din curse_cheltuieli in sincron cu factura: il creeaza / muta /
     * actualizeaza cand factura e asociata, il sterge altfel.
     */
    public function syncMirror(int $id): void
    {
        $row = $this->getById($id);
        if ($row === null || (string) $row['sursa'] === 'legacy_cazare') {
            return;
        }

        $mirror = new TripExpenseMirrorService($this->db);
        $existingId = $row['curse_cheltuiala_id'] !== null ? (int) $row['curse_cheltuiala_id'] : null;
        $associated = $row['deleted_at'] === null
            && in_array((string) $row['status'], ['asociata_auto', 'asociata_manual'], true)
            && $row['cursa_id'] !== null
            && $row['cursa_deleted_at'] === null
            && $this->mirrorBlocker($row) === null;

        if (!$associated) {
            if ($existingId !== null) {
                $mirror->delete($existingId);
                $this->setMirrorPointer($id, null);
            }

            return;
        }

        $type = (string) $row['tip'];
        $legacyType = (string) self::legacyExpenseType($type);
        $amount = number_format(round((float) $row['valoare_cu_tva'], 2), 2, '.', '');

        $fields = [
            'cursa_id' => (int) $row['cursa_id'],
            'tip_cheltuiala' => $legacyType,
            'categorie_id' => $this->getCategoryIdForType($type),
            'suma' => $amount,
            'data_cheltuiala' => (string) $row['data_document'],
            'observatii' => $this->buildMirrorNotes($row),
        ];
        if (in_array($legacyType, self::TOLL_EXPENSE_TYPES, true)) {
            // Taxele au suma = bucati x pret unitar: factura e o singura bucata.
            $fields['locatie'] = mb_substr(trim((string) ($row['furnizor'] ?? '')), 0, 190) ?: null;
            $fields['bucati'] = '1.00';
            $fields['pret_unitar'] = $amount;
        }
        if ($existingId === null) {
            $fields['added_by'] = $row['created_by'] !== null ? (int) $row['created_by'] : null;
        }

        $documents = [];
        if (trim((string) ($row['document_path'] ?? '')) !== '') {
            $documents[] = [
                // Fisierul sta in storage/invoices (in afara web root-ului): Dispecer curse
                // il deschide prin ruta autentificata, vezi trip_expense_document_url().
                'file_path' => 'facturi:' . $id,
                'original_name' => (string) ($row['document_original_name'] ?: ('factura_' . $id)),
                'mime_type' => $row['document_mime'] ?? null,
                'file_size' => (int) ($row['document_size'] ?? 0),
                'created_at' => (string) $row['created_at'],
            ];
        }

        $expenseId = $mirror->upsert($existingId, $fields, $documents);
        if ($expenseId !== $existingId) {
            $this->setMirrorPointer($id, $expenseId);
        }
    }

    /** Motivul pentru care factura nu poate deveni cheltuiala de cursa, sau null. */
    private function mirrorBlocker(array $row): ?string
    {
        if ($row['valoare_cu_tva'] === null || (float) $row['valoare_cu_tva'] <= 0) {
            return 'Lipseste valoarea cu TVA.';
        }
        $currency = strtoupper((string) ($row['moneda'] ?? 'RON'));
        if ($currency !== 'RON') {
            return 'Valoare in ' . $currency . ': cheltuielile de cursa sunt in lei, introdu valoarea in RON.';
        }
        if ($row['data_document'] === null) {
            return 'Lipseste data documentului.';
        }
        if ($this->getCategoryIdForType((string) $row['tip']) === null) {
            return 'Categoria de cheltuiala pentru tipul "' . (string) $row['tip'] . '" lipseste.';
        }

        return null;
    }

    private function buildMirrorNotes(array $row): string
    {
        $parts = ['Factura #' . (int) $row['id']];
        $supplier = trim((string) ($row['furnizor'] ?? ''));
        if ($supplier !== '') {
            $parts[] = $supplier;
        }
        $number = trim((string) ($row['numar_document'] ?? ''));
        if ($number !== '') {
            $parts[] = 'nr. ' . $number;
        }
        if ($row['valoare_fara_tva'] !== null) {
            $parts[] = 'fara TVA: ' . number_format((float) $row['valoare_fara_tva'], 2, ',', '.') . ' lei';
        }

        return mb_substr(implode(' | ', $parts), 0, 5000);
    }

    private function setMirrorPointer(int $id, ?int $expenseId): void
    {
        $stmt = $this->db->prepare('UPDATE facturi SET curse_cheltuiala_id = :curse_cheltuiala_id WHERE id = :id');
        $this->bindNullable($stmt, ':curse_cheltuiala_id', $expenseId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function raceIsActive(int $raceId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM curse_dispecer WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->bindValue(':id', $raceId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /** @return array<int, array<string, mixed>> */
    public function decodeCandidates(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    private function bindNullable(PDOStatement $stmt, string $placeholder, mixed $value, int $type): void
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);

            return;
        }

        $stmt->bindValue($placeholder, $type === PDO::PARAM_INT ? (int) $value : (is_string($value) ? trim($value) : (string) $value), $type);
    }
}
