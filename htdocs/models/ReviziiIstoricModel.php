<?php
declare(strict_types=1);

require_once __DIR__ . '/ReviziiModel.php';

/**
 * Revizii → Istoric revizii: istoricul unificat al interventiilor (ulei, placute, anvelope).
 *
 * Sursa: revizii_istoric — un rand = o interventie reala inregistrata (T0 nou). Tabela e
 * doar INSERT si NU este modificata aici. Ce se adauga ulterior sta in tabele alaturate:
 *  - revizii_istoric_meta        referinta (ex. nr. factura / deviz) si verificarea interventiei;
 *  - revizii_istoric_documente   documentele atasate (stergere logica, fisierul ramane);
 *  - revizii_rapoarte            rapoartele generate (metadate + fisierul din storage/).
 *
 * Simularile si schimbarile de configurare (revizii_planuri) nu sunt interventii si nu apar
 * aici. Km la interventie = km_bord introdus la inregistrare (instantaneu, nu odometrul de azi).
 *
 * Toate interogarile folosesc parametri pozitionali (EMULATE_PREPARES=false nu permite
 * acelasi parametru numit de doua ori).
 */
class ReviziiIstoricModel
{
    public const STATUS_FINALIZAT = 'finalizat';
    public const STATUS_VERIFICAT = 'verificat';
    public const STATUS_INCOMPLET = 'date_incomplete';

    public const STATUSES = [
        self::STATUS_FINALIZAT => 'Finalizat',
        self::STATUS_VERIFICAT => 'Verificat',
        self::STATUS_INCOMPLET => 'Date incomplete',
    ];

    /** Operatorul „Nespecificat” (interventii inregistrate fara utilizator, ex. importuri). */
    public const OPERATOR_NONE = -1;

    /**
     * Statusul calculat in SQL. „Date incomplete” = ciclul anterior masurat avea zile fara
     * telemetrie (aceeasi regula ca in Prezentare generală); la primul T0 nu exista ciclu
     * anterior (km_echivalenti NULL), deci inregistrarea e completa.
     */
    private const STATUS_SQL = "(CASE WHEN m.verificat_at IS NOT NULL THEN 'verificat'
        WHEN h.date_complete = 1 OR h.km_echivalenti IS NULL THEN 'finalizat'
        ELSE 'date_incomplete' END)";

    private const FROM_SQL = 'FROM revizii_istoric h
        JOIN vehicule v ON v.id = h.vehicle_id
        LEFT JOIN utilizatori u ON u.id = h.created_by
        LEFT JOIN revizii_istoric_meta m ON m.istoric_id = h.id
        LEFT JOIN (SELECT istoric_id, COUNT(*) AS n FROM revizii_istoric_documente WHERE deleted_at IS NULL GROUP BY istoric_id) d
               ON d.istoric_id = h.id';

    /** Coloanele dupa care se poate sorta (cheie din UI => expresie SQL). */
    private const SORTS = [
        'data' => 'h.data_revizie',
        'vehicul' => 'v.nr_inmatriculare',
        'categorie' => "FIELD(h.tip_revizie, 'schimb_ulei', 'placute_fata', 'placute_spate', 'placute_semi', 'anvelope')",
        'componenta' => 'h.tip_revizie',
        'km' => 'h.km_bord',
        'referinta' => 'm.referinta',
        'operator' => 'u.nume',
        'status' => self::STATUS_SQL,
        'documente' => 'COALESCE(d.n, 0)',
    ];

    public const DOCS_DIR = 'storage/revizii/documente';
    public const REPORTS_DIR = 'storage/revizii/rapoarte';

    private PDO $db;
    private static bool $schemaReady = false;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        new ReviziiModel($db); // revizii_istoric trebuie sa existe inainte de tabelele alaturate
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        $statements = [
            "CREATE TABLE IF NOT EXISTS revizii_istoric_meta (
                istoric_id INT UNSIGNED NOT NULL PRIMARY KEY,
                referinta VARCHAR(80) NULL,
                verificat_by INT UNSIGNED NULL,
                verificat_at DATETIME NULL,
                updated_by INT UNSIGNED NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS revizii_istoric_documente (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                istoric_id INT UNSIGNED NOT NULL,
                nume_original VARCHAR(190) NOT NULL,
                fisier_path VARCHAR(255) NOT NULL,
                mime VARCHAR(80) NOT NULL,
                marime INT UNSIGNED NOT NULL,
                sha256 CHAR(64) NOT NULL,
                uploaded_by INT UNSIGNED NULL,
                uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                deleted_by INT UNSIGNED NULL,
                deleted_at DATETIME NULL,
                KEY idx_revizii_doc_istoric (istoric_id, deleted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS revizii_rapoarte (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                titlu VARCHAR(190) NOT NULL,
                format VARCHAR(10) NOT NULL,
                perioada_de_la DATE NULL,
                perioada_pana_la DATE NULL,
                filtre_json TEXT NULL,
                nr_inregistrari INT UNSIGNED NOT NULL DEFAULT 0,
                fisier_path VARCHAR(255) NOT NULL,
                fisier_marime INT UNSIGNED NOT NULL DEFAULT 0,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_revizii_rapoarte_user (created_by, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
        foreach ($statements as $sql) {
            $this->db->exec($sql);
        }
        // Filtrul de perioada + sortarea implicita pe data, pe tot istoricul.
        $stmt = $this->db->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'revizii_istoric' AND INDEX_NAME = 'idx_revizii_istoric_data'"
        );
        if ((int) $stmt->fetchColumn() === 0) {
            $this->db->exec('ALTER TABLE revizii_istoric ADD KEY idx_revizii_istoric_data (data_revizie)');
        }

        self::$schemaReady = true;
    }

    // ---------------------------------------------------------------- filtre

    /**
     * Normalizeaza filtrele venite din UI (GET) sau din formularul de raport (POST).
     * Categoriile / vehiculele pot fi un singur element (selectoarele din pagina)
     * sau liste (raportul).
     *
     * @return array{q:string, de_la:?string, pana_la:?string, categorii:array<int,string>, vehicule:array<int,int>, status:string, operator:?int}
     */
    public static function normalizeFilters(array $input): array
    {
        $date = static function (mixed $value): ?string {
            $value = trim((string) $value);
            if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $m)) {
                $value = $m[3] . '-' . $m[2] . '-' . $m[1];
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return null;
            }
            [$y, $mo, $d] = array_map('intval', explode('-', $value));

            return checkdate($mo, $d, $y) ? $value : null;
        };
        $list = static fn (mixed $value): array => is_array($value) ? $value : (trim((string) $value) === '' ? [] : [$value]);

        $categories = array_values(array_unique(array_filter(
            array_map('strval', $list($input['categorie'] ?? $input['categorii'] ?? [])),
            static fn (string $tab): bool => isset(ReviziiModel::TABS[$tab])
        )));
        $vehicles = array_values(array_unique(array_filter(array_map('intval', $list($input['vehicul'] ?? $input['vehicule'] ?? [])), static fn (int $id): bool => $id > 0)));
        $status = (string) ($input['status'] ?? '');
        $operator = trim((string) ($input['operator'] ?? ''));

        $from = $date($input['de_la'] ?? '');
        $to = $date($input['pana_la'] ?? '');
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [
            'q' => mb_substr(trim((string) ($input['q'] ?? '')), 0, 100),
            'de_la' => $from,
            'pana_la' => $to,
            'categorii' => $categories,
            'vehicule' => $vehicles,
            'status' => isset(self::STATUSES[$status]) ? $status : '',
            'operator' => $operator !== '' && is_numeric($operator) && (int) $operator !== 0 ? (int) $operator : null,
        ];
    }

    /** Toate tipurile de componenta ale unei categorii (tab). @return array<int, string> */
    public static function tipsOfTab(string $tab): array
    {
        $groups = ReviziiModel::TAB_ITEMS[$tab] ?? [];

        return array_values(array_unique(array_merge(array_keys($groups['motor'] ?? []), array_keys($groups['trailer'] ?? []))));
    }

    private static function fold(string $value): string
    {
        $value = mb_strtolower($value);

        return strtr($value, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);
    }

    /**
     * Tipurile de componenta al caror nume / categorie / tip de interventie contine termenul
     * (cautarea „componentă” se face pe etichetele afisate, nu pe cheile din BD).
     *
     * @return array<int, string>
     */
    private static function tipsMatching(string $term): array
    {
        $term = self::fold($term);
        $tips = [];
        foreach (ReviziiModel::TABS as $tab => $meta) {
            foreach (self::tipsOfTab($tab) as $tip) {
                $labels = [$meta['label'], self::interventionType($tip)];
                foreach (['cap_tractor', 'camion', 'semiremorca'] as $type) {
                    $labels[] = ReviziiModel::componentName($tip, $type);
                }
                foreach ($labels as $label) {
                    if (str_contains(self::fold($label), $term)) {
                        $tips[] = $tip;
                        break;
                    }
                }
            }
        }

        return array_values(array_unique($tips));
    }

    /**
     * Clauza WHERE pentru filtre. $ignoreDates: graficul lunar pastreaza anul ales in locul
     * perioadei din filtre (restul filtrelor se aplica).
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function where(array $filters, bool $ignoreDates = false): array
    {
        $clauses = ['1 = 1'];
        $params = [];

        if (!$ignoreDates && $filters['de_la'] !== null) {
            $clauses[] = 'h.data_revizie >= ?';
            $params[] = $filters['de_la'] . ' 00:00:00';
        }
        if (!$ignoreDates && $filters['pana_la'] !== null) {
            $clauses[] = 'h.data_revizie < ?';
            $params[] = date('Y-m-d', (int) strtotime($filters['pana_la'] . ' +1 day')) . ' 00:00:00';
        }
        if ($filters['categorii'] !== []) {
            $tips = [];
            foreach ($filters['categorii'] as $tab) {
                $tips = array_merge($tips, self::tipsOfTab($tab));
            }
            $clauses[] = 'h.tip_revizie IN (' . implode(',', array_fill(0, count($tips), '?')) . ')';
            $params = array_merge($params, $tips);
        }
        if ($filters['vehicule'] !== []) {
            $clauses[] = 'h.vehicle_id IN (' . implode(',', array_fill(0, count($filters['vehicule']), '?')) . ')';
            $params = array_merge($params, $filters['vehicule']);
        }
        if ($filters['status'] !== '') {
            $clauses[] = self::STATUS_SQL . ' = ?';
            $params[] = $filters['status'];
        }
        if ($filters['operator'] !== null) {
            if ($filters['operator'] === self::OPERATOR_NONE) {
                $clauses[] = 'h.created_by IS NULL';
            } else {
                $clauses[] = 'h.created_by = ?';
                $params[] = $filters['operator'];
            }
        }
        if ($filters['q'] !== '') {
            $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
            $or = ['v.nr_inmatriculare LIKE ?', "REPLACE(v.nr_inmatriculare, ' ', '') LIKE ?", 'u.nume LIKE ?', 'h.observatii LIKE ?', 'm.referinta LIKE ?'];
            array_push($params, $like, '%' . addcslashes(str_replace(' ', '', $filters['q']), '%_\\') . '%', $like, $like, $like);
            $tips = self::tipsMatching($filters['q']);
            if ($tips !== []) {
                $or[] = 'h.tip_revizie IN (' . implode(',', array_fill(0, count($tips), '?')) . ')';
                $params = array_merge($params, $tips);
            }
            $clauses[] = '(' . implode(' OR ', $or) . ')';
        }

        return [implode(' AND ', $clauses), $params];
    }

    // ---------------------------------------------------------------- citire

    /**
     * KPI-uri pe filtre: un rand = o interventie (documentele vin dintr-un subselect
     * agregat, deci nu dubleaza nimic).
     *
     * @return array{total:int, finalizate:int, cu_documente:int, vehicule:int}
     */
    public function kpis(array $filters): array
    {
        [$where, $params] = $this->where($filters);
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(' . self::STATUS_SQL . " IN ('finalizat', 'verificat')), 0) AS finalizate,
                    COALESCE(SUM(COALESCE(d.n, 0) > 0), 0) AS cu_documente,
                    COUNT(DISTINCT h.vehicle_id) AS vehicule
             " . self::FROM_SQL . ' WHERE ' . $where
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'finalizate' => (int) ($row['finalizate'] ?? 0),
            'cu_documente' => (int) ($row['cu_documente'] ?? 0),
            'vehicule' => (int) ($row['vehicule'] ?? 0),
        ];
    }

    private const SELECT_SQL = 'SELECT h.id, h.vehicle_id, h.tip_revizie, h.data_revizie, h.km_bord, h.interval_km, h.km_efectuati,
            h.ore_motor, h.km_echivalenti, h.factor_km_ora, h.sursa_ore, h.date_complete, h.observatii, h.created_by, h.created_at,
            u.nume AS operator_nume, v.nr_inmatriculare, v.marca, v.model, v.tip_vehicul, v.status AS vehicul_status,
            m.referinta, m.verificat_at, m.verificat_by, COALESCE(d.n, 0) AS documente, ';

    private function orderBy(string $sort, string $dir): string
    {
        $expr = self::SORTS[$sort] ?? self::SORTS['data'];
        $dir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';

        // Valorile lipsa (km, referinta) mereu la final, indiferent de directie.
        return sprintf('ORDER BY (%1$s IS NULL), %1$s %2$s, h.data_revizie DESC, h.id DESC', $expr, $dir);
    }

    /**
     * Pagina curenta din istoric.
     *
     * @return array{rows: array<int, array<string, mixed>>, total:int, page:int, pages:int, per_page:int}
     */
    public function page(array $filters, string $sort, string $dir, int $page, int $perPage): array
    {
        [$where, $params] = $this->where($filters);
        $count = $this->db->prepare('SELECT COUNT(*) ' . self::FROM_SQL . ' WHERE ' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $perPage = max(5, min(100, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));

        $stmt = $this->db->prepare(
            self::SELECT_SQL . self::STATUS_SQL . ' AS status ' . self::FROM_SQL . ' WHERE ' . $where . ' '
            . $this->orderBy($sort, $dir) . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
        );
        $stmt->execute($params);

        return [
            'rows' => array_map([self::class, 'present'], $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * Toate interventiile filtrate, rand cu rand (exporturi): nimic nu se pierde din
     * cauza paginarii.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function iterate(array $filters, string $sort = 'data', string $dir = 'desc'): Generator
    {
        [$where, $params] = $this->where($filters);
        $stmt = $this->db->prepare(
            self::SELECT_SQL . self::STATUS_SQL . ' AS status ' . self::FROM_SQL . ' WHERE ' . $where . ' ' . $this->orderBy($sort, $dir)
        );
        $stmt->execute($params);
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            yield self::present($row);
        }
    }

    /** Numele documentelor active per interventie (pentru exporturi). @return array<int, array<int, string>> */
    public function documentNames(array $istoricIds): array
    {
        $istoricIds = array_values(array_unique(array_filter(array_map('intval', $istoricIds))));
        if ($istoricIds === []) {
            return [];
        }
        $names = [];
        foreach (array_chunk($istoricIds, 500) as $chunk) {
            $stmt = $this->db->prepare(
                'SELECT istoric_id, nume_original FROM revizii_istoric_documente
                 WHERE deleted_at IS NULL AND istoric_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')
                 ORDER BY istoric_id, id'
            );
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $names[(int) $row['istoric_id']][] = (string) $row['nume_original'];
            }
        }

        return $names;
    }

    /**
     * Interventii pe luna si categorie pentru anul ales (celelalte filtre se aplica).
     *
     * @return array<string, array<int, int>> tab => 12 valori (ian..dec)
     */
    public function monthly(array $filters, int $year): array
    {
        [$where, $params] = $this->where($filters, true);
        $stmt = $this->db->prepare(
            'SELECT h.tip_revizie, MONTH(h.data_revizie) AS luna, COUNT(*) AS n ' . self::FROM_SQL . '
             WHERE ' . $where . ' AND h.data_revizie >= ? AND h.data_revizie < ?
             GROUP BY h.tip_revizie, MONTH(h.data_revizie)'
        );
        $stmt->execute(array_merge($params, [$year . '-01-01 00:00:00', ($year + 1) . '-01-01 00:00:00']));
        $series = [];
        foreach (array_keys(ReviziiModel::TABS) as $tab) {
            $series[$tab] = array_fill(0, 12, 0);
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tab = ReviziiModel::tabOfTip((string) $row['tip_revizie']);
            if ($tab !== null) {
                $series[$tab][(int) $row['luna'] - 1] += (int) $row['n'];
            }
        }

        return $series;
    }

    /** @return array<string, int> tab => numar interventii (pe filtre) */
    public function byCategory(array $filters): array
    {
        [$where, $params] = $this->where($filters);
        $stmt = $this->db->prepare('SELECT h.tip_revizie, COUNT(*) AS n ' . self::FROM_SQL . ' WHERE ' . $where . ' GROUP BY h.tip_revizie');
        $stmt->execute($params);
        $counts = array_fill_keys(array_keys(ReviziiModel::TABS), 0);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tab = ReviziiModel::tabOfTip((string) $row['tip_revizie']);
            if ($tab !== null) {
                $counts[$tab] += (int) $row['n'];
            }
        }

        return $counts;
    }

    /**
     * Optiunile filtrelor: doar vehiculele si operatorii care apar in istoric (inclusiv
     * vehiculele inactive azi — istoricul lor ramane consultabil).
     *
     * @return array{vehicule: array<int, array<string, mixed>>, operatori: array<int, array<string, mixed>>, ani: array<int, int>, fara_operator: bool}
     */
    public function filterOptions(): array
    {
        $vehicles = $this->db->query(
            "SELECT DISTINCT v.id, v.nr_inmatriculare, v.tip_vehicul, v.status
             FROM revizii_istoric h JOIN vehicule v ON v.id = h.vehicle_id
             ORDER BY v.nr_inmatriculare"
        )->fetchAll(PDO::FETCH_ASSOC);
        $operators = $this->db->query(
            'SELECT DISTINCT u.id, u.nume FROM revizii_istoric h JOIN utilizatori u ON u.id = h.created_by ORDER BY u.nume'
        )->fetchAll(PDO::FETCH_ASSOC);
        $withoutOperator = (int) $this->db->query('SELECT COUNT(*) FROM revizii_istoric WHERE created_by IS NULL')->fetchColumn() > 0;
        $years = array_map('intval', $this->db->query('SELECT DISTINCT YEAR(data_revizie) FROM revizii_istoric ORDER BY 1 DESC')->fetchAll(PDO::FETCH_COLUMN));
        $current = (int) date('Y');
        if (!in_array($current, $years, true)) {
            array_unshift($years, $current);
        }
        rsort($years);

        return [
            'vehicule' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'nr' => (string) $r['nr_inmatriculare'],
                'tip' => vehicle_type_label((string) $r['tip_vehicul']),
                'inactiv' => (string) $r['status'] !== 'activ',
            ], $vehicles),
            'operatori' => array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'nume' => (string) $r['nume']], $operators),
            'ani' => $years,
            'fara_operator' => $withoutOperator,
        ];
    }

    /** Tipul interventiei afisat (doar ce aplicatia inregistreaza: inlocuirea componentei = T0 nou). */
    public static function interventionType(string $tip): string
    {
        return $tip === 'anvelope' ? 'Înlocuire set' : 'Înlocuire';
    }

    /**
     * Randul din BD in forma afisata (tabel, detalii, exporturi).
     *
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        $tip = (string) $row['tip_revizie'];
        $tab = ReviziiModel::tabOfTip($tip);
        $vehicleType = (string) $row['tip_vehicul'];

        return [
            'id' => (int) $row['id'],
            'data' => (string) $row['data_revizie'],
            'vehicle_id' => (int) $row['vehicle_id'],
            'nr' => (string) $row['nr_inmatriculare'],
            'marca' => trim((string) ($row['marca'] ?? '') . ' ' . (string) ($row['model'] ?? '')),
            'vehicul_tip' => vehicle_type_label($vehicleType),
            'vehicul_inactiv' => (string) ($row['vehicul_status'] ?? 'activ') !== 'activ',
            'remorca' => is_trailer_vehicle_type($vehicleType),
            'categorie' => $tab ?? 'necunoscut',
            'categorie_label' => $tab !== null ? ReviziiModel::TABS[$tab]['label'] : $tip,
            'tip' => $tip,
            'componenta' => ReviziiModel::componentName($tip, $vehicleType),
            'tip_interventie' => self::interventionType($tip),
            'km' => $row['km_bord'] !== null ? (int) $row['km_bord'] : null,
            'referinta' => $row['referinta'] !== null && $row['referinta'] !== '' ? (string) $row['referinta'] : null,
            'operator' => $row['operator_nume'] !== null ? (string) $row['operator_nume'] : null,
            'status' => (string) $row['status'],
            'status_label' => self::STATUSES[(string) $row['status']] ?? (string) $row['status'],
            'documente' => (int) $row['documente'],
            'observatii' => $row['observatii'] !== null ? (string) $row['observatii'] : '',
            // Instantaneul ciclului incheiat, salvat la inregistrare (nu se recalculeaza).
            'interval_km' => (int) $row['interval_km'],
            'km_efectuati' => $row['km_efectuati'] !== null ? (float) $row['km_efectuati'] : null,
            'ore_motor' => $row['ore_motor'] !== null ? (float) $row['ore_motor'] : null,
            'km_consumati' => $row['km_echivalenti'] !== null ? (float) $row['km_echivalenti'] : null,
            'factor_km_ora' => (float) $row['factor_km_ora'],
            'sursa_ore' => $row['sursa_ore'] !== null ? (string) $row['sursa_ore'] : null,
            'date_complete' => (int) $row['date_complete'] === 1,
            'inregistrat_la' => (string) $row['created_at'],
            'verificat_at' => $row['verificat_at'] !== null ? (string) $row['verificat_at'] : null,
        ];
    }

    /**
     * O interventie cu tot ce trebuie pentru panoul de detalii: documente, cine a verificat,
     * ansamblul (cap tractor ↔ semi-remorca) din istoricul de cuplare LA DATA interventiei.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            self::SELECT_SQL . self::STATUS_SQL . ' AS status, uv.nume AS verificat_de ' . self::FROM_SQL . '
             LEFT JOIN utilizatori uv ON uv.id = m.verificat_by
             WHERE h.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $item = self::present($row);
        $item['verificat_de'] = $row['verificat_de'] !== null ? (string) $row['verificat_de'] : null;
        $item['documente_lista'] = $this->documents($id);
        $item['ansamblu'] = $this->couplingAt((int) $row['vehicle_id'], (string) $row['data_revizie'], $item['remorca']);

        return $item;
    }

    /** Vehiculul cuplat la momentul dat (istoric, nu configuratia de azi). */
    private function couplingAt(int $vehicleId, string $at, bool $isTrailer): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT v.id, v.nr_inmatriculare, v.tip_vehicul FROM vehicule_cuplaje c
                 JOIN vehicule v ON v.id = ' . ($isTrailer ? 'c.tractor_id' : 'c.semiremorca_id') . '
                 WHERE ' . ($isTrailer ? 'c.semiremorca_id' : 'c.tractor_id') . ' = ?
                   AND c.data_start <= ? AND (c.data_end IS NULL OR c.data_end > ?)
                 ORDER BY c.data_start DESC LIMIT 1'
            );
            $stmt->execute([$vehicleId, $at, $at]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return null; // instalari fara istoric de cuplare
        }

        return is_array($row) ? ['id' => (int) $row['id'], 'nr' => (string) $row['nr_inmatriculare'], 'tip' => vehicle_type_label((string) $row['tip_vehicul'])] : null;
    }

    // ---------------------------------------------------------------- documente

    /** @return array<int, array<string, mixed>> */
    public function documents(int $istoricId): array
    {
        $stmt = $this->db->prepare(
            'SELECT d.id, d.nume_original, d.mime, d.marime, d.uploaded_at, u.nume AS uploaded_by_name
             FROM revizii_istoric_documente d LEFT JOIN utilizatori u ON u.id = d.uploaded_by
             WHERE d.istoric_id = ? AND d.deleted_at IS NULL ORDER BY d.id'
        );
        $stmt->execute([$istoricId]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'nume' => (string) $r['nume_original'],
            'mime' => (string) $r['mime'],
            'marime' => (int) $r['marime'],
            'incarcat_la' => (string) $r['uploaded_at'],
            'incarcat_de' => $r['uploaded_by_name'] !== null ? (string) $r['uploaded_by_name'] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null documentul activ */
    public function findDocument(int $documentId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM revizii_istoric_documente WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function exists(int $istoricId): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM revizii_istoric WHERE id = ?');
        $stmt->execute([$istoricId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** @param array{document_path:string, document_original_name:string, document_mime:string, document_size:int, document_sha256:string} $file */
    public function addDocument(int $istoricId, array $file, ?int $userId): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO revizii_istoric_documente (istoric_id, nume_original, fisier_path, mime, marime, sha256, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $istoricId, mb_substr($file['document_original_name'], 0, 190), $file['document_path'], $file['document_mime'],
            $file['document_size'], $file['document_sha256'], $userId,
        ]);
        $id = (int) $this->db->lastInsertId();
        $this->audit($istoricId, 'document_adaugat', 'Document atașat: ' . $file['document_original_name'], $userId, null, ['document_id' => $id, 'nume' => $file['document_original_name']]);

        return $id;
    }

    /** Eliminare logica: randul si fisierul raman pentru audit. */
    public function removeDocument(array $document, ?int $userId): void
    {
        $stmt = $this->db->prepare('UPDATE revizii_istoric_documente SET deleted_at = NOW(), deleted_by = ? WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$userId, (int) $document['id']]);
        $this->audit((int) $document['istoric_id'], 'document_eliminat', 'Document eliminat: ' . $document['nume_original'], $userId, ['document_id' => (int) $document['id'], 'nume' => $document['nume_original']], null);
    }

    // ---------------------------------------------------------------- referinta / verificare

    /** @return array{referinta:?string, verificat_at:?string, verificat_by:?int} */
    private function meta(int $istoricId): array
    {
        $stmt = $this->db->prepare('SELECT referinta, verificat_at, verificat_by FROM revizii_istoric_meta WHERE istoric_id = ?');
        $stmt->execute([$istoricId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'referinta' => isset($row['referinta']) ? (string) $row['referinta'] : null,
            'verificat_at' => isset($row['verificat_at']) ? (string) $row['verificat_at'] : null,
            'verificat_by' => isset($row['verificat_by']) ? (int) $row['verificat_by'] : null,
        ];
    }

    public function setReference(int $istoricId, ?string $reference, ?int $userId): void
    {
        $before = $this->meta($istoricId);
        $stmt = $this->db->prepare(
            'INSERT INTO revizii_istoric_meta (istoric_id, referinta, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE referinta = VALUES(referinta), updated_by = VALUES(updated_by)'
        );
        $stmt->execute([$istoricId, $reference, $userId]);
        $this->audit($istoricId, 'referinta', 'Referință: ' . ($reference ?? '—'), $userId, ['referinta' => $before['referinta']], ['referinta' => $reference]);
    }

    public function setVerified(int $istoricId, bool $verified, ?int $userId): void
    {
        $before = $this->meta($istoricId);
        $stmt = $this->db->prepare(
            'INSERT INTO revizii_istoric_meta (istoric_id, verificat_at, verificat_by, updated_by) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE verificat_at = VALUES(verificat_at), verificat_by = VALUES(verificat_by), updated_by = VALUES(updated_by)'
        );
        $at = $verified ? date('Y-m-d H:i:s') : null;
        $stmt->execute([$istoricId, $at, $verified ? $userId : null, $userId]);
        $this->audit($istoricId, $verified ? 'verificat' : 'verificare_anulata', $verified ? 'Intervenție marcată verificată' : 'Verificare anulată', $userId, $before, ['verificat_at' => $at]);
    }

    // ---------------------------------------------------------------- rapoarte

    public function createReport(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO revizii_rapoarte (titlu, format, perioada_de_la, perioada_pana_la, filtre_json, nr_inregistrari, fisier_path, fisier_marime, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['titlu'], $data['format'], $data['de_la'], $data['pana_la'],
            json_encode($data['filtre'], JSON_UNESCAPED_UNICODE), $data['nr'], $data['path'], $data['marime'], $data['created_by'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Rapoartele vizibile: ale utilizatorului curent; administratorul le vede pe toate.
     *
     * @return array<int, array<string, mixed>>
     */
    public function reports(?int $userId, bool $all, int $limit = 3): array
    {
        $sql = 'SELECT r.id, r.titlu, r.format, r.nr_inregistrari, r.fisier_marime, r.created_at, u.nume AS autor
                FROM revizii_rapoarte r LEFT JOIN utilizatori u ON u.id = r.created_by';
        $params = [];
        if (!$all) {
            $sql .= ' WHERE r.created_by = ?';
            $params[] = (int) $userId;
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY r.created_at DESC, r.id DESC LIMIT ' . max(1, min(200, $limit)));
        $stmt->execute($params);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'titlu' => (string) $r['titlu'],
            'format' => (string) $r['format'],
            'nr' => (int) $r['nr_inregistrari'],
            'marime' => (int) $r['fisier_marime'],
            'creat_la' => (string) $r['created_at'],
            'autor' => $r['autor'] !== null ? (string) $r['autor'] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function findReport(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM revizii_rapoarte WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    // ---------------------------------------------------------------- audit

    private function audit(int $istoricId, string $action, string $description, ?int $userId, ?array $before, ?array $after): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO audit_log (modul, record_id, actiune, descriere, before_data, after_data, user_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                'revizii_istoric', $istoricId, $action, mb_substr($description, 0, 255),
                $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
                $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
                $userId !== null && $userId > 0 ? $userId : null, date('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $exception) {
            // Auditul nu blocheaza operatia (ex. coloana descriere mai scurta pe instalari vechi).
            error_log('[ReviziiIstoricModel][audit] ' . $exception->getMessage());
        }
    }
}
