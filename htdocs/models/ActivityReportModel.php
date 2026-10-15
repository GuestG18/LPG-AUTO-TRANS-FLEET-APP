<?php
declare(strict_types=1);

/**
 * Pagina "Rapoarte activitate utilizatori" (?page=rapoarte_activitate): rapoarte
 * de investigatie pe actiunile utilizatorilor, pe module si pagini.
 *
 * Complementeaza cronologia din "Activitate utilizatori" (UserActivityModel) si
 * citeste ACELEASI jurnale, fara sa le modifice:
 *   - audit_log                 : Documente, Concedii, Leasing, Revizii (stergeri) si,
 *                                 din 2026-10-15, Vehicule / Soferi / Utilizatori
 *   - cursa_audit_log           : Dispecer curse (din 2026-10-15 cu campurile modificate)
 *   - vehicule_capacitate_audit : schimbarile manuale de capacitate / categorie
 *   - revizii_istoric           : interventiile inregistrate (created_by)
 *   - user_login_events         : autentificari reusite / esuate (din 2026-10-15)
 *   - login_email_codes         : autentificari istorice, doar inainte de user_login_events
 *
 * Fiecare sursa e normalizata intr-un "eveniment" comun; filtrarea, agregarile si
 * paginarea se fac pe server, browserul primeste doar pagina curenta + agregatele.
 * Ce nu a fost capturat la momentul actiunii ramane necunoscut (changes = null),
 * nu se reconstruieste.
 */
class ActivityReportModel extends BaseModel
{
    public const MODULES = [
        'dispecer_curse'    => 'Dispecer curse',
        'vehicule'          => 'Vehicule',
        'soferi'            => 'Șoferi',
        'revizii'           => 'Revizii',
        'documente'         => 'Documente vehicule',
        'documente_soferi'  => 'Documente șoferi',
        'concedii'          => 'Programare concedii',
        'scadentar_leasing' => 'Scadentar leasing',
        'utilizatori'       => 'Utilizatori',
        'autentificare'     => 'Autentificare',
    ];

    public const ACTIONS = [
        'create'  => 'Creare',
        'update'  => 'Modificare',
        'delete'  => 'Ștergere',
        'status'  => 'Status',
        'login'   => 'Autentificare',
        'restore' => 'Restaurare',
    ];

    public const SORTS = ['ts', 'user', 'role', 'action', 'module', 'section', 'entity', 'field', 'ip'];
    public const GROUPS = ['none', 'user', 'action', 'module', 'section', 'entity', 'day'];
    public const PER_PAGE = [10, 25, 50, 100];

    /** Plafon per sursa (o perioada foarte lunga nu incarca un set nelimitat). */
    private const SOURCE_LIMIT = 25000;

    private const FIELD_LABELS = [
        'vehicle_id' => 'Vehicul', 'driver_id' => 'Șofer', 'tip_transport' => 'Tip transport',
        'data_cursa' => 'Data cursei', 'data_incarcare' => 'Data încărcării', 'data_inceput' => 'Data început',
        'data_sfarsit' => 'Data sfârșit', 'ora_inceput' => 'Ora început', 'ora_sfarsit' => 'Ora sfârșit',
        'loc_incarcare_id' => 'Loc încărcare', 'loc_plecare' => 'Loc plecare', 'loc_intoarcere' => 'Loc întoarcere',
        'loc_aspirare' => 'Loc aspirare', 'loc_livrare' => 'Loc livrare', 'loc_livrare_cursa' => 'Loc livrare cursă',
        'beneficiar_id' => 'Beneficiar', 'tip_marfa' => 'Tip marfă', 'capacitate_transport' => 'Capacitate transport',
        'capacitate_transport_confirmata' => 'Capacitate confirmată', 'cantitate_incarcata' => 'Cantitate',
        'cantitate_prelevata' => 'Cantitate prelevată', 'nr_clienti' => 'Nr. clienți', 'km_cursa' => 'Km cursă',
        'ore_functionare' => 'Ore funcționare', 'km_totali' => 'Km totali', 'ore_aspirare' => 'Ore aspirare',
        'km_dislocare' => 'Km dislocare', 'tona_livrata' => 'Tonaj livrat', 'tona_aspirata_lichida' => 'Tonaj aspirat lichid',
        'tona_aspirata_gazoasa' => 'Tonaj aspirat gazos', 'zona_distributie_id' => 'Zonă distribuție',
        'status_facturare' => 'Status facturare', 'cheltuieli_status' => 'Status cheltuieli',
        'pret_tarifare' => 'Preț tarifare', 'total_facturare' => 'Total facturare', 'observatii' => 'Observații',
        'categorie_capacitate_id' => 'Categorie capacitate', 'nr_inmatriculare' => 'Nr. înmatriculare',
        'tip_vehicul' => 'Tip vehicul', 'marca' => 'Marcă', 'model' => 'Model', 'status' => 'Status',
        'nume' => 'Nume', 'email' => 'Email', 'telefon' => 'Telefon', 'rol' => 'Rol', 'parola' => 'Parolă',
        'vehicul' => 'Vehicul', 'tip_document' => 'Tip document', 'numar_document' => 'Număr document',
        'data_expirare' => 'Data expirării', 'fisier_original' => 'Fișier', 'tip_concediu' => 'Tip concediu',
        'inlocuitor_id' => 'Înlocuitor', 'note' => 'Note', 'segment' => 'Fază cursă',
    ];

    private const VALUE_LABELS = [
        'status_facturare'  => ['in_curs_facturare' => 'În curs', 'facturat' => 'Facturat', 'nefacturat' => 'Nefacturat'],
        'cheltuieli_status' => ['pending' => 'În așteptare', 'not_applicable' => 'Nu se aplică'],
        'tip_transport'     => ['primar' => 'Primar', 'distributie' => 'Distribuție', 'primar_distributie' => 'Primar + Distribuție', 'compresor' => 'Compresor'],
        'status'            => ['in_asteptare' => 'În așteptare', 'aprobat' => 'Aprobat', 'respins' => 'Respins', 'anulat' => 'Anulat', 'activ' => 'Activ', 'inactiv' => 'Inactiv'],
        'tip_concediu'      => ['odihna' => 'Odihnă', 'medical' => 'Medical', 'fara_plata' => 'Fără plată'],
        'rol'               => ['admin' => 'Administrator', 'contabilitate' => 'Contabilitate', 'utilizator' => 'Utilizator'],
        'capacitate_transport_confirmata' => ['0' => 'Nu', '1' => 'Da'],
    ];

    /** Campuri a caror modificare marcheaza actiunea drept "sensibila". */
    private const SENSITIVE_FIELDS = [
        'status_facturare', 'pret_tarifare', 'total_facturare', 'rol', 'parola', 'email', 'status',
        'cost_km_primar', 'cost_km_distributie', 'cost_km_mixt', 'cost_km_compresor',
    ];

    /** Chei ignorate la diferenta before/after (tehnice sau derivate). */
    private const DIFF_IGNORED = ['id', 'created_at', 'updated_at', 'duplicate_key', 'created_by'];

    private const MONTHS_SHORT = [1 => 'Ian', 'Feb', 'Mar', 'Apr', 'Mai', 'Iun', 'Iul', 'Aug', 'Sep', 'Oct', 'Noi', 'Dec'];

    private array $tableCache = [];
    private array $columnCache = [];
    private ?array $users = null;
    private array $lookups = [];
    private array $truncated = [];

    // =============================================================== schema

    /**
     * Schema aditionala, compatibila inapoi (coloane NULL, tabele noi). Se apeleaza
     * din controller, in afara oricarei tranzactii. Idempotent.
     */
    public function ensureSchema(): void
    {
        foreach (['audit_log', 'cursa_audit_log', 'login_email_codes'] as $table) {
            if (!$this->tableExists($table) || $this->hasColumn($table, 'ip_address')) {
                continue;
            }
            try {
                $this->db->exec('ALTER TABLE ' . $table . ' ADD COLUMN ip_address VARCHAR(45) NULL, ADD COLUMN user_agent VARCHAR(255) NULL');
            } catch (Throwable $exception) {
                error_log('[ActivityReportModel][schema] ' . $table . ': ' . $exception->getMessage());
            }
            unset($this->columnCache[$table]);
        }
        if ($this->tableExists('cursa_audit_log') && !$this->hasIndex('cursa_audit_log', 'idx_cursa_audit_performed')) {
            try {
                $this->db->exec('ALTER TABLE cursa_audit_log ADD INDEX idx_cursa_audit_performed (performed_at)');
            } catch (Throwable $exception) {
                error_log('[ActivityReportModel][schema] index: ' . $exception->getMessage());
            }
        }

        if (function_exists('audit_ensure_login_events_table')) {
            audit_ensure_login_events_table($this->db);
            unset($this->tableCache['user_login_events']);
        }

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS activity_report_views (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                name VARCHAR(120) NOT NULL,
                config_json TEXT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_activity_report_view (user_id, name),
                CONSTRAINT fk_activity_report_views_user FOREIGN KEY (user_id) REFERENCES utilizatori(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // ============================================================== filtre

    public function normalizeFilters(array $in): array
    {
        $today = new DateTimeImmutable('today');
        $start = $this->parseDate((string) ($in['from'] ?? '')) ?? $today->modify('-30 days');
        $end = $this->parseDate((string) ($in['to'] ?? '')) ?? $today;
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
        // O perioada de peste 3 ani nu are sens aici si ar incarca tot jurnalul.
        if ($start < $end->modify('-1100 days')) {
            $start = $end->modify('-1100 days');
        }

        $actions = $in['actions'] ?? [];
        if (is_string($actions)) {
            $actions = $actions === '' ? [] : explode(',', $actions);
        }
        $actions = array_values(array_intersect(array_keys(self::ACTIONS), array_map('strval', (array) $actions)));

        $sort = (string) ($in['sort'] ?? 'ts');
        $perPage = (int) ($in['pp'] ?? 10);
        $group = (string) ($in['group'] ?? 'none');
        $trend = (int) ($in['trend'] ?? 14);

        return [
            'module'    => mb_substr(trim((string) ($in['module'] ?? '')), 0, 50),
            'section'   => mb_substr(trim((string) ($in['section'] ?? '')), 0, 120),
            'user'      => max(0, (int) ($in['user'] ?? 0)),
            'role'      => mb_substr(trim((string) ($in['role'] ?? '')), 0, 30),
            'actions'   => $actions,
            'from'      => $start->format('Y-m-d'),
            'to'        => $end->format('Y-m-d'),
            'entity'    => mb_substr(trim((string) ($in['entity'] ?? '')), 0, 120),
            'field'     => mb_substr(trim((string) ($in['field'] ?? '')), 0, 80),
            'sensitive' => in_array((string) ($in['sensitive'] ?? '0'), ['1', 'true', 'on'], true),
            'logins'    => !in_array((string) ($in['logins'] ?? '1'), ['0', 'false', 'off'], true),
            'q'         => mb_substr(trim((string) ($in['q'] ?? '')), 0, 120),
            'tq'        => mb_substr(trim((string) ($in['tq'] ?? '')), 0, 120),
            'sort'      => in_array($sort, self::SORTS, true) ? $sort : 'ts',
            'dir'       => strtolower((string) ($in['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
            'p'         => max(1, (int) ($in['p'] ?? 1)),
            'pp'        => in_array($perPage, self::PER_PAGE, true) ? $perPage : 10,
            'group'     => in_array($group, self::GROUPS, true) ? $group : 'none',
            'trend'     => in_array($trend, [0, 7, 14, 30], true) ? $trend : 14,
            'heat'      => (string) ($in['heat'] ?? 'auto') === 'modules' ? 'modules' : 'auto',
        ];
    }

    // ============================================================ raport

    public function buildReport(array $filters): array
    {
        $f = $this->normalizeFilters($filters);
        $start = new DateTimeImmutable($f['from']);
        $end = new DateTimeImmutable($f['to']);
        $days = (int) $start->diff($end)->days + 1;
        $prevEnd = $start->modify('-1 day');
        $prevStart = $prevEnd->modify('-' . ($days - 1) . ' days');
        $trendDays = $f['trend'] > 0 ? $f['trend'] : $days;
        $trendStart = $end->modify('-' . ($trendDays - 1) . ' days');
        $loadStart = min($prevStart, $trendStart);

        $all = $this->collect($loadStart->format('Y-m-d'), $f['to']);

        $inRange = static fn (array $r, string $a, string $b): bool => substr($r['ts'], 0, 10) >= $a && substr($r['ts'], 0, 10) <= $b;
        $current = array_values(array_filter($all, static fn (array $r): bool => $inRange($r, $f['from'], $f['to'])));
        $previous = array_values(array_filter($all, static fn (array $r): bool => $inRange($r, $prevStart->format('Y-m-d'), $prevEnd->format('Y-m-d'))));
        $trendRows = array_values(array_filter($all, static fn (array $r): bool => $inRange($r, $trendStart->format('Y-m-d'), $f['to'])));

        $noModule = $this->applyFilters($current, $f, ['module', 'section']);
        $filtered = $this->applyFilters($noModule, $f, [], true);
        $prevFiltered = $this->applyFilters($previous, $f);
        $trendFiltered = $this->applyFilters($trendRows, $f);

        $tableRows = $f['tq'] !== '' ? $this->applySearch($filtered, $f['tq']) : $filtered;

        return [
            'filters'    => $f,
            'period'     => ['from' => $f['from'], 'to' => $f['to'], 'days' => $days, 'prev_from' => $prevStart->format('Y-m-d'), 'prev_to' => $prevEnd->format('Y-m-d')],
            'tabs'       => $this->buildTabs($noModule),
            'kpis'       => $this->buildKpis($filtered, $prevFiltered, $f['from'], $days),
            'modules'    => $this->buildModuleBars($noModule, $filtered, $f['module']),
            'topPages'   => $this->buildModuleBars($noModule, $noModule, '')['items'],
            'actions'    => $this->buildDistribution($filtered),
            'heatmap'    => $this->buildHeatmap($filtered, $noModule, $f),
            'trend'      => $this->buildTrend($trendFiltered, $trendStart, $end),
            'topUsers'   => $this->buildTopUsers($filtered),
            'fields'     => $this->buildFieldStats($filtered),
            'risk'       => $this->buildRisk($filtered),
            'pivot'      => $this->buildPivot($tableRows),
            'series'     => $this->buildResultSeries($tableRows, $start, $end),
            'table'      => $this->buildTable($tableRows, $f),
            'options'    => $this->buildOptions($current, $f),
            'truncated'  => array_keys(array_filter($this->truncated)),
            'generated'  => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Randurile pentru export (toate cele filtrate, sortate ca in tabel).
     *
     * @return array{rows: array<int,array>, filters: array, kpis: array}
     */
    public function exportRows(array $filters): array
    {
        $f = $this->normalizeFilters($filters);
        $rows = $this->collect($f['from'], $f['to']);
        $rows = $this->applyFilters($rows, $f);
        if ($f['tq'] !== '') {
            $rows = $this->applySearch($rows, $f['tq']);
        }
        $rows = $this->sortRows($rows, $f);
        $this->resolveCourses($rows);

        return ['rows' => array_map(fn (array $r): array => $this->presentRow($r), $rows), 'filters' => $f];
    }

    /** Detaliul unui eveniment (uid = "sursa:id"). */
    public function detail(string $uid): ?array
    {
        if (!preg_match('/^(audit|cursa|cap|rev|login|code):(\d+)$/', $uid, $m)) {
            return null;
        }
        $id = (int) $m[2];
        $rows = match ($m[1]) {
            'audit' => $this->fetchAudit(null, null, $id),
            'cursa' => $this->fetchCourse(null, null, $id),
            'cap'   => $this->fetchCapacity(null, null, $id),
            'rev'   => $this->fetchRevisions(null, null, $id),
            'login' => $this->fetchLoginEvents(null, null, $id),
            'code'  => $this->fetchLoginCodes(null, null, $id),
        };
        if ($rows === []) {
            return null;
        }
        $this->resolveCourses($rows);
        $row = $rows[0];
        $present = $this->presentRow($row);

        $snapshot = static function (?array $data): ?array {
            if ($data === null) {
                return null;
            }
            $out = [];
            foreach ($data as $key => $value) {
                $out[] = ['key' => (string) $key, 'value' => is_scalar($value) || $value === null ? $value : json_encode($value, JSON_UNESCAPED_UNICODE)];
            }

            return $out;
        };

        $present['changes_full'] = $row['changes'] === null ? null : array_map(fn (array $c): array => [
            'field' => $c['label'],
            'old'   => $this->displayValue($c['field'], $c['old'], $c['old_known']),
            'new'   => $this->displayValue($c['field'], $c['new'], true),
        ], $row['changes']);
        $present['before'] = $snapshot($this->presentSnapshot($row['before']));
        $present['after'] = $snapshot($this->presentSnapshot($row['after']));
        $present['user_agent'] = $row['ua'];
        $present['source_label'] = [
            'audit' => 'audit_log', 'cursa' => 'cursa_audit_log', 'cap' => 'vehicule_capacitate_audit',
            'rev' => 'revizii_istoric', 'login' => 'user_login_events', 'code' => 'login_email_codes',
        ][$row['source']] ?? $row['source'];
        [$present['link'], $present['link_label']] = $this->recordLink($row);

        return $present;
    }

    // ===================================================== surse -> evenimente

    /** @return array<int,array<string,mixed>> evenimentele normalizate, descrescator dupa data */
    private function collect(string $from, string $to): array
    {
        $s = $from . ' 00:00:00';
        $e = $to . ' 23:59:59';

        $rows = array_merge(
            $this->fetchAudit($s, $e),
            $this->fetchCourse($s, $e),
            $this->fetchCapacity($s, $e),
            $this->fetchRevisions($s, $e),
            $this->fetchLoginEvents($s, $e),
            $this->fetchLoginCodes($s, $e)
        );
        $this->resolveCourses($rows);

        usort($rows, static fn (array $a, array $b): int => strcmp($b['ts'], $a['ts']) ?: strcmp($b['uid'], $a['uid']));

        return $rows;
    }

    private function contextColumns(string $table, string $alias = ''): string
    {
        $p = $alias !== '' ? $alias . '.' : '';

        return $this->hasColumn($table, 'ip_address') ? ", {$p}ip_address, {$p}user_agent" : ', NULL AS ip_address, NULL AS user_agent';
    }

    private function runSource(string $key, string $sql, array $params): array
    {
        try {
            $stmt = $this->db->prepare($sql . ' LIMIT ' . (self::SOURCE_LIMIT + 1));
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            error_log('[ActivityReportModel][' . $key . '] ' . $exception->getMessage());
            return [];
        }
        if (count($rows) > self::SOURCE_LIMIT) {
            $this->truncated[$key] = true;
            array_pop($rows);
        }

        return $rows;
    }

    private function fetchAudit(?string $s, ?string $e, ?int $id = null): array
    {
        if (!$this->tableExists('audit_log')) {
            return [];
        }
        [$where, $params] = $id !== null ? ['id = :id', [':id' => $id]] : ['created_at BETWEEN :s AND :e', [':s' => $s, ':e' => $e]];
        $raw = $this->runSource('audit_log', '
            SELECT id, modul, record_id, actiune, descriere, before_data, after_data, user_id, created_at' . $this->contextColumns('audit_log') . '
            FROM audit_log WHERE ' . $where . ' ORDER BY created_at DESC', $params);

        $out = [];
        foreach ($raw as $row) {
            $rawModule = (string) $row['modul'];
            $module = $rawModule === 'revizii_istoric' ? 'revizii' : $rawModule;
            $action = $this->mapAuditAction((string) $row['actiune']);
            $before = $this->decode($row['before_data']);
            $after = $this->decode($row['after_data']);
            $changes = in_array($action, ['update', 'status'], true) ? $this->diff($before, $after) : [];
            if (in_array($action, ['update', 'status'], true) && $before === null && $after === null) {
                $changes = null;
            }
            $recordId = (int) $row['record_id'] ?: null;

            $out[] = $this->event([
                'uid'         => 'audit:' . $row['id'],
                'source'      => 'audit',
                'ts'          => (string) $row['created_at'],
                'user_id'     => (int) $row['user_id'],
                'module'      => $module,
                'section'     => $this->auditSection($rawModule, $before, $after),
                'action'      => $action,
                'record_id'   => $recordId,
                'entity'      => $this->auditEntity($module, $recordId, $before, $after),
                'description' => $this->fixText(trim((string) $row['descriere'])),
                'changes'     => $changes,
                'before'      => $before,
                'after'       => $after,
                'ip'          => $row['ip_address'],
                'ua'          => $row['user_agent'],
            ]);
        }

        return $out;
    }

    private function fetchCourse(?string $s, ?string $e, ?int $id = null): array
    {
        if (!$this->tableExists('cursa_audit_log')) {
            return [];
        }
        [$where, $params] = $id !== null ? ['id = :id', [':id' => $id]] : ['performed_at BETWEEN :s AND :e', [':s' => $s, ':e' => $e]];
        $raw = $this->runSource('cursa_audit_log', '
            SELECT id, cursa_id, action, performed_by, performed_at, details_json' . $this->contextColumns('cursa_audit_log') . '
            FROM cursa_audit_log WHERE ' . $where . ' ORDER BY performed_at DESC', $params);

        $out = [];
        foreach ($raw as $row) {
            $raceId = (int) $row['cursa_id'];
            $details = $this->decode($row['details_json']) ?? [];
            $action = ['created' => 'create', 'updated' => 'update', 'deleted' => 'delete', 'restored' => 'restore', 'status_changed' => 'status'][(string) $row['action']] ?? 'update';
            $ref = 'cursa #' . $raceId;
            $section = 'Fișa cursei';
            $changes = [];
            $description = '';

            if ($action === 'update') {
                if (isset($details['changes']) && is_array($details['changes'])) {
                    foreach ($details['changes'] as $field => $pair) {
                        $changes[] = $this->change((string) $field, $pair['old'] ?? null, $pair['new'] ?? null);
                    }
                    $description = count($changes) === 1
                        ? 'A modificat ' . mb_strtolower($changes[0]['label']) . ' pentru ' . $ref
                        : 'A modificat ' . count($changes) . ' câmpuri pentru ' . $ref;
                } elseif (isset($details['segment_adaugat']) || isset($details['segment_modificat']) || isset($details['segment_sters'])) {
                    $section = 'Faze cursă';
                    $verb = isset($details['segment_adaugat']) ? 'A adăugat' : (isset($details['segment_sters']) ? 'A șters' : 'A modificat');
                    $segment = (int) ($details['segment_adaugat'] ?? $details['segment_modificat'] ?? $details['segment_sters']);
                    $description = $verb . ' faza #' . $segment . ' pentru ' . $ref;
                } else {
                    // Editare inregistrata inainte de 2026-10-15: campurile nu au fost capturate.
                    $changes = null;
                    $description = 'A modificat ' . $ref;
                }
            } elseif ($action === 'status') {
                if (array_key_exists('status_facturare', $details)) {
                    $section = 'Facturare';
                    $known = array_key_exists('status_facturare_anterior', $details);
                    $changes[] = $this->change('status_facturare', $details['status_facturare_anterior'] ?? null, $details['status_facturare'], $known);
                    $description = 'A schimbat statusul de facturare pentru ' . $ref . (!empty($details['bulk']) ? ' (acțiune în bloc)' : '');
                } elseif (array_key_exists('cheltuieli_status', $details)) {
                    $section = 'Cheltuieli';
                    $known = array_key_exists('cheltuieli_status_anterior', $details);
                    $changes[] = $this->change('cheltuieli_status', $details['cheltuieli_status_anterior'] ?? null, $details['cheltuieli_status'], $known);
                    $description = 'A schimbat statusul cheltuielilor pentru ' . $ref;
                } else {
                    $changes = null;
                    $description = 'A schimbat statusul pentru ' . $ref;
                }
            } else {
                $section = in_array($action, ['delete', 'restore'], true) ? 'Curse șterse' : 'Fișa cursei';
                $description = ['create' => 'A creat ', 'delete' => 'A șters ', 'restore' => 'A restaurat '][$action] . $ref
                    . ($action === 'delete' ? ' (mutată în curse șterse)' : '');
            }

            $out[] = $this->event([
                'uid'         => 'cursa:' . $row['id'],
                'source'      => 'cursa',
                'ts'          => (string) $row['performed_at'],
                'user_id'     => (int) $row['performed_by'],
                'module'      => 'dispecer_curse',
                'section'     => $section,
                'action'      => $action,
                'record_id'   => $raceId ?: null,
                'entity'      => 'Cursa #' . $raceId,
                'description' => $description,
                'changes'     => $changes,
                'before'      => null,
                'after'       => $details !== [] ? $details : null,
                'ip'          => $row['ip_address'],
                'ua'          => $row['user_agent'],
                'bulk'        => !empty($details['bulk']),
            ]);
        }

        return $out;
    }

    private function fetchCapacity(?string $s, ?string $e, ?int $id = null): array
    {
        if (!$this->tableExists('vehicule_capacitate_audit')) {
            return [];
        }
        // Randurile 'migrare' au fost scrise de migrarea initiala, nu de un utilizator.
        [$where, $params] = $id !== null ? ['id = :id', [':id' => $id]] : ["sursa = 'manual' AND created_at BETWEEN :s AND :e", [':s' => $s, ':e' => $e]];
        $raw = $this->runSource('vehicule_capacitate_audit', '
            SELECT id, vehicle_id, capacitate_veche, capacitate_noua, categorie_veche_id, categorie_noua_id,
                   confirmata_veche, confirmata_noua, motiv, user_id, created_at
            FROM vehicule_capacitate_audit WHERE ' . $where . ' ORDER BY created_at DESC', $params);

        $out = [];
        foreach ($raw as $row) {
            $changes = [];
            $pairs = [
                'capacitate_transport' => ['capacitate_veche', 'capacitate_noua'],
                'categorie_capacitate_id' => ['categorie_veche_id', 'categorie_noua_id'],
                'capacitate_transport_confirmata' => ['confirmata_veche', 'confirmata_noua'],
            ];
            foreach ($pairs as $field => [$oldKey, $newKey]) {
                $old = $this->normalizeScalar($row[$oldKey]);
                $new = $this->normalizeScalar($row[$newKey]);
                if ($old !== $new) {
                    $changes[] = $this->change($field, $old, $new);
                }
            }
            $vehicle = $this->lookup('vehicles')[(int) $row['vehicle_id']] ?? ('Vehicul #' . (int) $row['vehicle_id']);

            $out[] = $this->event([
                'uid'         => 'cap:' . $row['id'],
                'source'      => 'cap',
                'ts'          => (string) $row['created_at'],
                'user_id'     => (int) $row['user_id'],
                'module'      => 'vehicule',
                'section'     => 'Capacitate',
                'action'      => 'update',
                'record_id'   => (int) $row['vehicle_id'] ?: null,
                'entity'      => $vehicle,
                'description' => 'A modificat capacitatea vehiculului ' . $vehicle . (trim((string) $row['motiv']) !== '' ? ' — ' . trim((string) $row['motiv']) : ''),
                'changes'     => $changes,
                'before'      => null,
                'after'       => null,
                'ip'          => null,
                'ua'          => null,
            ]);
        }

        return $out;
    }

    private function fetchRevisions(?string $s, ?string $e, ?int $id = null): array
    {
        if (!$this->tableExists('revizii_istoric')) {
            return [];
        }
        [$where, $params] = $id !== null ? ['id = :id', [':id' => $id]] : ['created_at BETWEEN :s AND :e', [':s' => $s, ':e' => $e]];
        $raw = $this->runSource('revizii_istoric', '
            SELECT id, vehicle_id, tip_revizie, data_revizie, km_bord, interval_km, observatii, created_by, created_at
            FROM revizii_istoric WHERE ' . $where . ' ORDER BY created_at DESC', $params);

        $out = [];
        foreach ($raw as $row) {
            $vehicle = $this->lookup('vehicles')[(int) $row['vehicle_id']] ?? ('Vehicul #' . (int) $row['vehicle_id']);
            $type = $this->revisionLabel((string) $row['tip_revizie']);
            $out[] = $this->event([
                'uid'         => 'rev:' . $row['id'],
                'source'      => 'rev',
                'ts'          => (string) $row['created_at'],
                'user_id'     => (int) $row['created_by'],
                'module'      => 'revizii',
                'section'     => $type,
                'action'      => 'create',
                'record_id'   => (int) $row['id'],
                'entity'      => $vehicle,
                'description' => 'A înregistrat intervenția ' . mb_strtolower($type) . ' pentru ' . $vehicle
                    . ' din ' . date('d.m.Y', (int) strtotime((string) $row['data_revizie'])),
                'changes'     => [],
                'before'      => null,
                'after'       => [
                    'tip_revizie' => $type, 'data_revizie' => (string) $row['data_revizie'],
                    'km_bord' => $row['km_bord'], 'interval_km' => $row['interval_km'], 'observatii' => $row['observatii'],
                ],
                'ip'          => null,
                'ua'          => null,
            ]);
        }

        return $out;
    }

    private function fetchLoginEvents(?string $s, ?string $e, ?int $id = null): array
    {
        if (!$this->tableExists('user_login_events')) {
            return [];
        }
        [$where, $params] = $id !== null ? ['id = :id', [':id' => $id]] : ['created_at BETWEEN :s AND :e', [':s' => $s, ':e' => $e]];
        $raw = $this->runSource('user_login_events', '
            SELECT id, user_id, email, metoda, reusit, ip_address, user_agent, created_at
            FROM user_login_events WHERE ' . $where . ' ORDER BY created_at DESC', $params);

        $methods = ['cod_email' => 'cod pe email', 'passkey' => 'passkey', 'parola' => 'parolă'];
        $out = [];
        foreach ($raw as $row) {
            $ok = (int) $row['reusit'] === 1;
            $method = $methods[(string) $row['metoda']] ?? (string) $row['metoda'];
            $out[] = $this->event([
                'uid'         => 'login:' . $row['id'],
                'source'      => 'login',
                'ts'          => (string) $row['created_at'],
                'user_id'     => (int) $row['user_id'],
                'module'      => 'autentificare',
                'section'     => $ok ? 'Reușite' : 'Eșuate',
                'action'      => 'login',
                'record_id'   => null,
                'entity'      => 'Cont',
                'description' => $ok ? 'S-a autentificat cu succes (' . $method . ')' : 'Autentificare eșuată (' . $method . ')'
                    . ((int) $row['user_id'] <= 0 && (string) $row['email'] !== '' ? ' pentru ' . $this->maskEmail((string) $row['email']) : ''),
                'changes'     => [],
                'before'      => null,
                'after'       => null,
                'ip'          => $row['ip_address'],
                'ua'          => $row['user_agent'],
                'failed'      => !$ok,
            ]);
        }

        return $out;
    }

    /** Autentificari istorice (cod pe email), doar pana la primul eveniment din user_login_events. */
    private function fetchLoginCodes(?string $s, ?string $e, ?int $id = null): array
    {
        if (!$this->tableExists('login_email_codes')) {
            return [];
        }
        $cutoff = null;
        if ($this->tableExists('user_login_events')) {
            $cutoff = $this->db->query('SELECT MIN(created_at) FROM user_login_events')->fetchColumn() ?: null;
        }
        if ($id !== null) {
            [$where, $params] = ['id = :id', [':id' => $id]];
        } else {
            // Codurile expirate sau blocate dupa prea multe incercari nu sunt autentificari.
            $where = 'used_at IS NOT NULL AND used_at BETWEEN :s AND :e AND used_at < expires_at AND attempts < max_attempts';
            $params = [':s' => $s, ':e' => $e];
            if ($cutoff !== null) {
                $where .= ' AND used_at < :cutoff';
                $params[':cutoff'] = $cutoff;
            }
        }
        $raw = $this->runSource('login_email_codes', '
            SELECT id, user_id, used_at' . $this->contextColumns('login_email_codes') . '
            FROM login_email_codes WHERE ' . $where . ' ORDER BY used_at DESC', $params);

        $out = [];
        foreach ($raw as $row) {
            $out[] = $this->event([
                'uid'         => 'code:' . $row['id'],
                'source'      => 'code',
                'ts'          => (string) $row['used_at'],
                'user_id'     => (int) $row['user_id'],
                'module'      => 'autentificare',
                'section'     => 'Reușite',
                'action'      => 'login',
                'record_id'   => null,
                'entity'      => 'Cont',
                'description' => 'S-a autentificat cu succes (cod pe email)',
                'changes'     => [],
                'before'      => null,
                'after'       => null,
                'ip'          => $row['ip_address'],
                'ua'          => $row['user_agent'],
            ]);
        }

        return $out;
    }

    /** Completeaza eticheta comuna a unui eveniment (utilizator, sensibil, text de cautare). */
    private function event(array $r): array
    {
        $user = $this->users()[(int) $r['user_id']] ?? null;
        $r['user_name'] = $user['nume'] ?? ((int) $r['user_id'] > 0 ? 'Utilizator șters' : 'Sistem / necunoscut');
        $r['user_role'] = $user['rol'] ?? '';
        $r['module_label'] = self::MODULES[$r['module']] ?? ($r['module'] !== '' ? ucfirst(str_replace('_', ' ', $r['module'])) : 'General');
        $r['failed'] = (bool) ($r['failed'] ?? false);
        $r['bulk'] = (bool) ($r['bulk'] ?? false);
        $r['ip'] = $r['ip'] !== null && $r['ip'] !== '' ? (string) $r['ip'] : null;
        $r['ua'] = $r['ua'] !== null && $r['ua'] !== '' ? (string) $r['ua'] : null;

        $fields = array_column($r['changes'] ?? [], 'field');
        $r['sensitive'] = in_array($r['action'], ['delete', 'restore'], true)
            || $r['failed']
            || $r['bulk']
            || $r['module'] === 'utilizatori'
            || array_intersect($fields, self::SENSITIVE_FIELDS) !== [];

        return $r;
    }

    private function change(string $field, mixed $old, mixed $new, bool $oldKnown = true): array
    {
        return [
            'field'     => $field,
            'label'     => self::FIELD_LABELS[$field] ?? ucfirst(str_replace('_', ' ', $field)),
            'old'       => $this->normalizeScalar($old),
            'new'       => $this->normalizeScalar($new),
            'old_known' => $oldKnown,
        ];
    }

    /** Diferenta campurilor scalare dintre doua instantanee (null = sursa nu le are). */
    private function diff(?array $before, ?array $after): array
    {
        $before ??= [];
        $after ??= [];
        $changes = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $key = (string) $key;
            if (in_array($key, self::DIFF_IGNORED, true) || str_ends_with($key, '_nume') || str_ends_with($key, '_telefon') || str_ends_with($key, '_nr_inmatriculare')) {
                continue;
            }
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            $oldText = is_array($old) ? json_encode($old, JSON_UNESCAPED_UNICODE) : $this->normalizeScalar($old);
            $newText = is_array($new) ? json_encode($new, JSON_UNESCAPED_UNICODE) : $this->normalizeScalar($new);
            if ($oldText !== $newText) {
                $changes[] = $this->change($key, $oldText, $newText);
            }
        }

        return $changes;
    }

    private function normalizeScalar(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (is_numeric($text) && preg_match('/^-?\d+\.\d+$/', $text)) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return $text;
    }

    private function mapAuditAction(string $actiune): string
    {
        $a = mb_strtolower(trim($actiune), 'UTF-8');

        return match (true) {
            $a === '' => 'update',
            str_contains($a, 'status') => 'status',
            str_contains($a, 'restaur') || str_contains($a, 'restore') => 'restore',
            str_contains($a, 'creat') || str_contains($a, 'create') || str_contains($a, 'add') || str_contains($a, 'adaug') => 'create',
            str_contains($a, 'sterg') || str_contains($a, 'ster') || str_contains($a, 'delete') || str_contains($a, 'remov') => 'delete',
            str_contains($a, 'login') => 'login',
            default => 'update',
        };
    }

    private function auditSection(string $rawModule, ?array $before, ?array $after): string
    {
        $data = $after ?? $before ?? [];

        return match ($rawModule) {
            'documente', 'documente_soferi' => trim((string) ($data['tip_document'] ?? '')) ?: 'Documente',
            'concedii' => 'Cereri concediu',
            'revizii_istoric' => 'Istoric revizii',
            'scadentar_leasing' => 'Contracte leasing',
            'vehicule' => 'Fișa vehiculului',
            'soferi' => 'Fișa șoferului',
            'utilizatori' => 'Conturi',
            default => 'General',
        };
    }

    private function auditEntity(string $module, ?int $recordId, ?array $before, ?array $after): string
    {
        $data = $after ?? $before ?? [];
        $ref = $recordId !== null ? ' #' . $recordId : '';

        return match ($module) {
            'documente' => trim((string) ($data['vehicul'] ?? '')) !== '' ? (string) $data['vehicul'] . ' · ' . trim((string) ($data['tip_document'] ?? 'Document')) : 'Document' . $ref,
            'concedii' => trim((string) ($data['sofer_nume'] ?? '')) !== '' ? 'Concediu · ' . $data['sofer_nume'] : 'Cerere' . $ref,
            'vehicule' => trim((string) ($data['nr_inmatriculare'] ?? '')) ?: ($this->lookup('vehicles')[(int) $recordId] ?? 'Vehicul' . $ref),
            'soferi' => trim((string) ($data['nume'] ?? '')) ?: 'Șofer' . $ref,
            'utilizatori' => trim((string) ($data['nume'] ?? '')) ?: 'Utilizator' . $ref,
            'revizii' => isset($data['istoric']['vehicle_id'])
                ? ($this->lookup('vehicles')[(int) $data['istoric']['vehicle_id']] ?? 'Intervenție' . $ref)
                : 'Intervenție' . $ref,
            'scadentar_leasing' => trim((string) ($data['contract_number'] ?? '')) ?: 'Contract' . $ref,
            default => ucfirst(str_replace('_', ' ', $module)) . $ref,
        };
    }

    /** Numarul de inmatriculare al curselor (pentru afisare si cautare). */
    private function resolveCourses(array &$rows): void
    {
        $ids = [];
        foreach ($rows as $row) {
            if ($row['source'] === 'cursa' && (int) $row['record_id'] > 0) {
                $ids[(int) $row['record_id']] = true;
            }
        }
        $missing = array_diff(array_keys($ids), array_keys($this->lookups['courses'] ?? []));
        if ($missing !== [] && $this->tableExists('curse_dispecer')) {
            try {
                foreach (array_chunk(array_values($missing), 500) as $chunk) {
                    $stmt = $this->db->prepare('SELECT id, vehicle_id, data_cursa, deleted_at FROM curse_dispecer WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
                    $stmt->execute($chunk);
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $course) {
                        $this->lookups['courses'][(int) $course['id']] = $course;
                    }
                }
            } catch (Throwable $exception) {
                error_log('[ActivityReportModel][courses] ' . $exception->getMessage());
            }
        }

        foreach ($rows as &$row) {
            if ($row['source'] !== 'cursa') {
                continue;
            }
            $course = $this->lookups['courses'][(int) $row['record_id']] ?? null;
            $row['entity_sub'] = $course !== null ? trim(($this->lookup('vehicles')[(int) $course['vehicle_id']] ?? '') . ' · ' . date('d.m.Y', (int) strtotime((string) $course['data_cursa'])), ' ·') : '';
        }
        unset($row);
    }

    // ============================================================ filtrare

    /** @param array<int,string> $except filtrele ignorate (ex. modulul, pentru numerele din tab-uri) */
    private function applyFilters(array $rows, array $f, array $except = [], bool $onlyModule = false): array
    {
        $use = static fn (string $key): bool => !in_array($key, $except, true);
        $needle = $f['q'] !== '' ? mb_strtolower($f['q'], 'UTF-8') : '';
        $entity = mb_strtolower(ltrim($f['entity'], '#'), 'UTF-8');
        $entityId = ctype_digit($entity) ? (int) $entity : 0;

        return array_values(array_filter($rows, function (array $r) use ($f, $use, $needle, $entity, $entityId, $onlyModule): bool {
            if ($use('module') && $f['module'] !== '' && $r['module'] !== $f['module']) {
                return false;
            }
            if ($use('section') && $f['section'] !== '' && $r['section'] !== $f['section']) {
                return false;
            }
            if ($onlyModule) {
                return true;
            }
            if (!$f['logins'] && $r['action'] === 'login') {
                return false;
            }
            if ($f['user'] > 0 && (int) $r['user_id'] !== $f['user']) {
                return false;
            }
            if ($f['role'] !== '' && $r['user_role'] !== $f['role']) {
                return false;
            }
            if ($f['actions'] !== [] && !in_array($r['action'], $f['actions'], true)) {
                return false;
            }
            if ($f['sensitive'] && !$r['sensitive']) {
                return false;
            }
            if ($f['field'] !== '' && !in_array($f['field'], array_column($r['changes'] ?? [], 'field'), true)) {
                return false;
            }
            if ($entity !== '') {
                $matchesId = $entityId > 0 && (int) $r['record_id'] === $entityId;
                $matchesText = str_contains(mb_strtolower($r['entity'] . ' ' . ($r['entity_sub'] ?? ''), 'UTF-8'), $entity);
                if (!$matchesId && !$matchesText) {
                    return false;
                }
            }
            if ($needle !== '' && !str_contains($this->haystack($r), $needle)) {
                return false;
            }

            return true;
        }));
    }

    private function applySearch(array $rows, string $query): array
    {
        $needle = mb_strtolower($query, 'UTF-8');

        return array_values(array_filter($rows, fn (array $r): bool => str_contains($this->haystack($r), $needle)));
    }

    private function haystack(array $r): string
    {
        $parts = [$r['user_name'], $r['module_label'], $r['section'], $r['description'], $r['entity'], $r['entity_sub'] ?? '', '#' . ($r['record_id'] ?? ''), $r['ip'] ?? '', self::ACTIONS[$r['action']] ?? ''];
        foreach ($r['changes'] ?? [] as $c) {
            $parts[] = $c['label'];
            $parts[] = $this->displayValue($c['field'], $c['old'], true);
            $parts[] = $this->displayValue($c['field'], $c['new'], true);
        }

        return mb_strtolower(implode(' ', $parts), 'UTF-8');
    }

    // ============================================================ agregate

    private function buildTabs(array $rows): array
    {
        $counts = [];
        foreach ($rows as $r) {
            $counts[$r['module']] = ($counts[$r['module']] ?? 0) + 1;
        }
        arsort($counts);
        $tabs = [];
        foreach ($counts as $key => $count) {
            $tabs[] = ['key' => (string) $key, 'label' => self::MODULES[$key] ?? ucfirst(str_replace('_', ' ', (string) $key)), 'count' => $count];
        }
        // Modulele auditabile fara activitate in perioada raman vizibile (cu 0) in "Mai multe".
        foreach (self::MODULES as $key => $label) {
            if (!isset($counts[$key])) {
                $tabs[] = ['key' => $key, 'label' => $label, 'count' => 0];
            }
        }

        return ['all' => count($rows), 'items' => $tabs];
    }

    private function buildKpis(array $rows, array $prev, string $from, int $days): array
    {
        $buckets = min(12, max(1, $days));
        $startTs = strtotime($from . ' 00:00:00');
        $span = $days * 86400;
        $keys = ['total', 'create', 'update', 'delete', 'status'];
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = ['value' => 0, 'prev' => 0, 'delta' => null, 'spark' => array_fill(0, $buckets, 0)];
        }
        foreach ($rows as $r) {
            $bucket = min($buckets - 1, max(0, (int) floor((strtotime($r['ts']) - $startTs) / $span * $buckets)));
            foreach (['total', $r['action']] as $key) {
                if (isset($out[$key])) {
                    $out[$key]['value']++;
                    $out[$key]['spark'][$bucket]++;
                }
            }
        }
        foreach ($prev as $r) {
            foreach (['total', $r['action']] as $key) {
                if (isset($out[$key])) {
                    $out[$key]['prev']++;
                }
            }
        }
        foreach ($out as &$kpi) {
            // Procentul are sens doar daca perioada anterioara are activitate.
            $kpi['delta'] = $kpi['prev'] > 0 ? (int) round(($kpi['value'] - $kpi['prev']) / $kpi['prev'] * 100) : null;
        }
        unset($kpi);

        return $out;
    }

    /** Bare pe module (sau pe sectiuni, cand un modul e selectat). */
    private function buildModuleBars(array $noModule, array $filtered, string $module): array
    {
        $bySection = $module !== '';
        $source = $bySection ? $filtered : $noModule;
        $counts = [];
        $labels = [];
        foreach ($source as $r) {
            $key = $bySection ? $r['section'] : $r['module'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $labels[$key] = $bySection ? $r['section'] : $r['module_label'];
        }
        arsort($counts);
        $total = array_sum($counts);
        $items = [];
        foreach ($counts as $key => $count) {
            $items[] = ['key' => (string) $key, 'label' => $labels[$key], 'count' => $count, 'pct' => $total > 0 ? round($count / $total * 100, 1) : 0];
        }

        return ['by' => $bySection ? 'section' : 'module', 'module_label' => $bySection ? (self::MODULES[$module] ?? $module) : '', 'total' => $total, 'items' => $items];
    }

    private function buildDistribution(array $rows): array
    {
        $counts = array_fill_keys(['update', 'create', 'status', 'delete', 'restore', 'login'], 0);
        foreach ($rows as $r) {
            $counts[$r['action']] = ($counts[$r['action']] ?? 0) + 1;
        }
        $total = array_sum($counts);
        $items = [];
        foreach ($counts as $key => $count) {
            if ($count > 0) {
                $items[] = ['key' => $key, 'label' => self::ACTIONS[$key] ?? $key, 'count' => $count, 'pct' => $total > 0 ? round($count / $total * 100, 1) : 0];
            }
        }

        return ['total' => $total, 'items' => $items];
    }

    private function buildHeatmap(array $filtered, array $noModule, array $f): array
    {
        // Cu un modul selectat, coloanele devin sectiunile lui (pagini din modul).
        $bySection = $f['module'] !== '' && $f['heat'] !== 'modules';
        $rows = $bySection ? $filtered : $noModule;
        $colCounts = [];
        $userCounts = [];
        $cells = [];
        $colLabels = [];
        foreach ($rows as $r) {
            if ((int) $r['user_id'] <= 0) {
                continue;
            }
            $col = $bySection ? $r['section'] : $r['module'];
            $colLabels[$col] = $bySection ? $r['section'] : $r['module_label'];
            $colCounts[$col] = ($colCounts[$col] ?? 0) + 1;
            $userCounts[$r['user_id']] = ($userCounts[$r['user_id']] ?? 0) + 1;
            $cells[$r['user_id']][$col] = ($cells[$r['user_id']][$col] ?? 0) + 1;
        }
        arsort($colCounts);
        arsort($userCounts);
        $cols = array_slice(array_keys($colCounts), 0, 6);
        $users = array_slice(array_keys($userCounts), 0, 5, true);
        $max = 0;
        $matrix = [];
        foreach ($users as $uid) {
            $line = [];
            foreach ($cols as $col) {
                $value = $cells[$uid][$col] ?? 0;
                $max = max($max, $value);
                $line[] = $value;
            }
            $matrix[] = ['user_id' => (int) $uid, 'name' => $this->users()[(int) $uid]['nume'] ?? 'Utilizator șters', 'values' => $line];
        }

        return [
            'by'      => $bySection ? 'section' : 'module',
            'columns' => array_map(static fn ($c): array => ['key' => (string) $c, 'label' => $colLabels[$c]], $cols),
            'rows'    => $matrix,
            'max'     => $max,
        ];
    }

    private function buildTrend(array $rows, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $days = (int) $start->diff($end)->days + 1;
        $step = $days > 62 ? 7 : 1;
        $labels = [];
        $index = [];
        for ($i = 0, $d = $start; $d <= $end; $i++, $d = $d->modify('+' . $step . ' days')) {
            $labels[] = $d->format('Y-m-d');
            $index[] = $d->format('Y-m-d');
        }
        $series = [];
        foreach (['create', 'update', 'delete', 'status'] as $key) {
            $series[$key] = array_fill(0, count($labels), 0);
        }
        foreach ($rows as $r) {
            if (!isset($series[$r['action']])) {
                continue;
            }
            $day = substr($r['ts'], 0, 10);
            $pos = (int) floor((strtotime($day) - $start->getTimestamp()) / 86400 / $step);
            if ($pos >= 0 && $pos < count($labels)) {
                $series[$r['action']][$pos]++;
            }
        }

        return ['labels' => $labels, 'step' => $step, 'series' => $series];
    }

    private function buildTopUsers(array $rows): array
    {
        $counts = [];
        foreach ($rows as $r) {
            if ((int) $r['user_id'] > 0) {
                $counts[$r['user_id']] = ($counts[$r['user_id']] ?? 0) + 1;
            }
        }
        arsort($counts);
        $total = count($rows);
        $out = [];
        foreach (array_slice($counts, 0, 5, true) as $uid => $count) {
            $user = $this->users()[(int) $uid] ?? ['nume' => 'Utilizator șters', 'rol' => ''];
            $out[] = ['user_id' => (int) $uid, 'name' => $user['nume'], 'role' => $user['rol'], 'count' => $count, 'pct' => $total > 0 ? round($count / $total * 100) : 0];
        }

        return $out;
    }

    private function buildFieldStats(array $rows): array
    {
        $counts = [];
        $labels = [];
        $withFields = 0;
        $unknown = 0;
        foreach ($rows as $r) {
            if (!in_array($r['action'], ['update', 'status'], true)) {
                continue;
            }
            if ($r['changes'] === null) {
                $unknown++;
                continue;
            }
            foreach ($r['changes'] as $c) {
                $counts[$c['field']] = ($counts[$c['field']] ?? 0) + 1;
                $labels[$c['field']] = $c['label'];
                $withFields++;
            }
        }
        arsort($counts);
        $out = [];
        foreach ($counts as $field => $count) {
            $out[] = ['field' => (string) $field, 'label' => $labels[$field], 'count' => $count, 'pct' => $withFields > 0 ? round($count / $withFields * 100) : 0];
        }

        return ['total' => $withFields, 'unknown' => $unknown, 'items' => $out];
    }

    /**
     * Scor de risc 0-100 (100 = fara semnale). Penalizari transparente, returnate ca factori:
     * stergeri/restaurari, autentificari esuate, modificari sensibile, actiuni in afara orelor 06-22.
     */
    private function buildRisk(array $rows): array
    {
        $total = count($rows);
        $deletes = $restores = $failed = $sensitive = $offHours = 0;
        foreach ($rows as $r) {
            $hour = (int) substr($r['ts'], 11, 2);
            $deletes += $r['action'] === 'delete' ? 1 : 0;
            $restores += $r['action'] === 'restore' ? 1 : 0;
            $failed += $r['failed'] ? 1 : 0;
            $sensitive += $r['sensitive'] && !in_array($r['action'], ['delete', 'restore'], true) && !$r['failed'] ? 1 : 0;
            $offHours += ($hour < 6 || $hour >= 22) && $r['action'] !== 'login' ? 1 : 0;
        }
        $share = static fn (int $n): float => $total > 0 ? $n / $total : 0.0;
        $factors = [
            ['key' => 'delete', 'label' => 'Ștergeri', 'count' => $deletes, 'penalty' => (int) round(min(25, $share($deletes) * 250))],
            ['key' => 'failed', 'label' => 'Autentificări eșuate', 'count' => $failed, 'penalty' => min(20, $failed * 2)],
            ['key' => 'sensitive', 'label' => 'Modificări sensibile (facturare, prețuri, conturi)', 'count' => $sensitive, 'penalty' => (int) round(min(20, $share($sensitive) * 60))],
            ['key' => 'offhours', 'label' => 'Acțiuni între 22:00 și 06:00', 'count' => $offHours, 'penalty' => (int) round(min(15, $share($offHours) * 100))],
            ['key' => 'restore', 'label' => 'Restaurări', 'count' => $restores, 'penalty' => min(5, $restores)],
        ];
        $score = max(0, 100 - array_sum(array_column($factors, 'penalty')));
        $level = $score >= 80 ? 'low' : ($score >= 60 ? 'medium' : 'high');
        $major = array_values(array_filter($factors, static fn (array $f): bool => $f['penalty'] >= 5));
        if ($total === 0) {
            $message = 'Nicio acțiune în perioada selectată.';
        } elseif ($major === []) {
            $message = 'Nu au fost detectate acțiuni majore în perioada selectată.';
        } else {
            $message = implode(', ', array_map(static fn (array $f): string => $f['count'] . ' ' . mb_strtolower($f['label']), array_slice($major, 0, 2))) . '.';
        }

        return [
            'score'   => $score,
            'level'   => $level,
            'label'   => ['low' => 'Risc scăzut', 'medium' => 'Risc moderat', 'high' => 'Risc ridicat'][$level],
            'message' => $message,
            'factors' => $factors,
        ];
    }

    private function buildPivot(array $rows): array
    {
        $cols = array_keys(self::ACTIONS);
        $data = [];
        foreach ($rows as $r) {
            $key = (int) $r['user_id'];
            $data[$key] ??= ['user_id' => $key, 'name' => $r['user_name'], 'role' => $r['user_role'], 'values' => array_fill_keys($cols, 0), 'total' => 0];
            $data[$key]['values'][$r['action']]++;
            $data[$key]['total']++;
        }
        usort($data, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
        $totals = array_fill_keys($cols, 0);
        foreach ($data as $line) {
            foreach ($cols as $c) {
                $totals[$c] += $line['values'][$c];
            }
        }

        return ['columns' => array_map(static fn (string $c): array => ['key' => $c, 'label' => self::ACTIONS[$c]], $cols), 'rows' => array_values($data), 'totals' => $totals, 'total' => count($rows)];
    }

    private function buildResultSeries(array $rows, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return $this->buildTrend($rows, $start, $end);
    }

    private function buildOptions(array $current, array $f): array
    {
        $sections = [];
        $fields = [];
        foreach ($current as $r) {
            if ($f['module'] === '' || $r['module'] === $f['module']) {
                $sections[$r['section']] = true;
                foreach ($r['changes'] ?? [] as $c) {
                    $fields[$c['field']] = $c['label'];
                }
            }
        }
        ksort($sections);
        asort($fields);

        $modules = [];
        foreach (self::MODULES as $key => $label) {
            $modules[] = ['key' => $key, 'label' => $label];
        }
        foreach ($current as $r) {
            if (!isset(self::MODULES[$r['module']]) && !in_array($r['module'], array_column($modules, 'key'), true)) {
                $modules[] = ['key' => $r['module'], 'label' => $r['module_label']];
            }
        }

        $roles = [];
        foreach ($this->users() as $user) {
            if ($user['rol'] !== '') {
                $roles[$user['rol']] = function_exists('role_display_name') ? role_display_name($user['rol']) : $user['rol'];
            }
        }

        $users = [];
        foreach ($this->users() as $id => $user) {
            $users[] = ['id' => $id, 'name' => $user['nume'], 'role' => $user['rol']];
        }
        usort($users, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return [
            'modules'  => $modules,
            'sections' => array_keys($sections),
            'fields'   => array_map(static fn ($k, $v): array => ['key' => (string) $k, 'label' => $v], array_keys($fields), $fields),
            'users'    => $users,
            'roles'    => array_map(static fn ($k, $v): array => ['key' => (string) $k, 'label' => $v], array_keys($roles), $roles),
            'actions'  => array_map(static fn ($k, $v): array => ['key' => $k, 'label' => $v], array_keys(self::ACTIONS), self::ACTIONS),
        ];
    }

    // =============================================================== tabel

    private function sortRows(array $rows, array $f): array
    {
        $key = function (array $r, string $sort): string {
            return match ($sort) {
                'user'    => mb_strtolower($r['user_name'], 'UTF-8'),
                'role'    => $r['user_role'],
                'action'  => self::ACTIONS[$r['action']] ?? $r['action'],
                'module'  => $r['module_label'],
                'section' => mb_strtolower($r['section'], 'UTF-8'),
                'entity'  => $r['record_id'] !== null ? str_pad((string) $r['record_id'], 10, '0', STR_PAD_LEFT) : mb_strtolower($r['entity'], 'UTF-8'),
                'field'   => mb_strtolower($r['changes'][0]['label'] ?? '~', 'UTF-8'),
                'ip'      => (string) ($r['ip'] ?? '~'),
                'day'     => substr($r['ts'], 0, 10),
                default   => $r['ts'],
            };
        };
        $dir = $f['dir'] === 'asc' ? 1 : -1;
        $group = $f['group'] !== 'none' ? $f['group'] : null;
        usort($rows, function (array $a, array $b) use ($key, $f, $dir, $group): int {
            if ($group !== null) {
                $ga = $group === 'day' ? substr($a['ts'], 0, 10) : $this->groupKey($a, $group);
                $gb = $group === 'day' ? substr($b['ts'], 0, 10) : $this->groupKey($b, $group);
                if ($ga !== $gb) {
                    return $group === 'day' ? strcmp($gb, $ga) : strcmp($ga, $gb);
                }
            }
            $cmp = strcmp($key($a, $f['sort']), $key($b, $f['sort']));
            if ($cmp === 0 && $f['sort'] !== 'ts') {
                return strcmp($b['ts'], $a['ts']);
            }

            return $cmp * $dir;
        });

        return $rows;
    }

    private function groupKey(array $r, string $group): string
    {
        return match ($group) {
            'user'    => mb_strtolower($r['user_name'], 'UTF-8'),
            'action'  => self::ACTIONS[$r['action']] ?? $r['action'],
            'module'  => $r['module_label'],
            'section' => $r['module_label'] . ' › ' . $r['section'],
            'entity'  => $r['entity'],
            'day'     => substr($r['ts'], 0, 10),
            default   => '',
        };
    }

    private function groupLabel(array $r, string $group): string
    {
        return match ($group) {
            'user'    => $r['user_name'],
            'action'  => self::ACTIONS[$r['action']] ?? $r['action'],
            'module'  => $r['module_label'],
            'section' => $r['module_label'] . ' › ' . $r['section'],
            'entity'  => $r['entity'],
            'day'     => $this->dateLabel($r['ts']),
            default   => '',
        };
    }

    private function buildTable(array $rows, array $f): array
    {
        $total = count($rows);
        $pages = max(1, (int) ceil($total / $f['pp']));
        $page = min($f['p'], $pages);
        $sorted = $this->sortRows($rows, $f);

        $groups = [];
        if ($f['group'] !== 'none') {
            foreach ($sorted as $r) {
                $g = $this->groupKey($r, $f['group']);
                $groups[$g] = ($groups[$g] ?? 0) + 1;
            }
        }

        $slice = array_slice($sorted, ($page - 1) * $f['pp'], $f['pp']);
        $out = [];
        foreach ($slice as $r) {
            $row = $this->presentRow($r);
            if ($f['group'] !== 'none') {
                $g = $this->groupKey($r, $f['group']);
                $row['group_key'] = $g;
                $row['group_label'] = $this->groupLabel($r, $f['group']);
                $row['group_count'] = $groups[$g] ?? 0;
            }
            $out[] = $row;
        }

        return ['rows' => $out, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $f['pp']];
    }

    /** Randul pregatit pentru afisare / export (valori traduse, secrete ascunse). */
    private function presentRow(array $r): array
    {
        $first = $r['changes'][0] ?? null;
        $known = $r['changes'] !== null;
        $parts = preg_split('/\s+/', trim($r['user_name'])) ?: [];
        $initials = mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1) . mb_substr($parts[1] ?? '', 0, 1));

        return [
            'uid'          => $r['uid'],
            'ts'           => $r['ts'],
            'date'         => $this->dateLabel($r['ts']),
            'time'         => substr($r['ts'], 11, 5),
            'user_id'      => (int) $r['user_id'],
            'user_name'    => $r['user_name'],
            'initials'     => $initials !== '' ? $initials : '?',
            'role'         => $r['user_role'],
            'role_label'   => $r['user_role'] !== '' ? (function_exists('role_display_name') ? role_display_name($r['user_role']) : $r['user_role']) : '—',
            'action'       => $r['action'],
            'action_label' => $r['failed'] ? 'Autentificare eșuată' : (self::ACTIONS[$r['action']] ?? $r['action']),
            'failed'       => $r['failed'],
            'module'       => $r['module'],
            'module_label' => $r['module_label'],
            'section'      => $r['section'],
            'record_id'    => $r['record_id'],
            'entity'       => $r['entity'],
            'entity_sub'   => $r['entity_sub'] ?? '',
            'fields_known' => $known,
            'field'        => $first['label'] ?? null,
            'fields_more'  => $known ? max(0, count($r['changes']) - 1) : 0,
            'fields_all'   => $known ? implode(', ', array_column($r['changes'], 'label')) : '',
            'old'          => $first !== null ? $this->displayValue($first['field'], $first['old'], $first['old_known']) : null,
            'new'          => $first !== null ? $this->displayValue($first['field'], $first['new'], true) : null,
            'changes_text' => $known ? implode(' | ', array_map(fn (array $c): string => $c['label'] . ': ' . $this->displayValue($c['field'], $c['old'], $c['old_known']) . ' → ' . $this->displayValue($c['field'], $c['new'], true), $r['changes'])) : 'Informație indisponibilă în jurnal',
            'description'  => $r['description'],
            'ip'           => $r['ip'],
            'device'       => function_exists('audit_device_label') ? audit_device_label($r['ua']) : '',
            'sensitive'    => $r['sensitive'],
        ];
    }

    private function presentSnapshot(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }
        $out = [];
        foreach ($data as $key => $value) {
            $key = (string) $key;
            if (is_array($value)) {
                $out[self::FIELD_LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key))] = $this->presentSnapshot($value);
                continue;
            }
            $out[self::FIELD_LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key))] = $this->displayValue($key, $this->normalizeScalar($value), true);
        }

        return $out;
    }

    /** Valoarea afisata: id -> nume, cod -> eticheta, secret -> ascuns. */
    private function displayValue(string $field, ?string $value, bool $known): string
    {
        if (!$known) {
            return 'nedisponibil';
        }
        if ($value === null) {
            return '—';
        }
        if (str_starts_with($value, '[ascuns') || (function_exists('audit_is_secret_field') && audit_is_secret_field($field))) {
            return '•••••• (ascuns)';
        }
        if (isset(self::VALUE_LABELS[$field][$value])) {
            return self::VALUE_LABELS[$field][$value];
        }
        $map = match ($field) {
            'vehicle_id' => 'vehicles',
            'driver_id', 'inlocuitor_id' => 'drivers',
            'beneficiar_id' => 'beneficiaries',
            'zona_distributie_id' => 'zones',
            'loc_incarcare_id' => 'locations',
            'categorie_capacitate_id' => 'capacity_categories',
            default => null,
        };
        if ($map !== null && ctype_digit($value)) {
            return $this->lookup($map)[(int) $value] ?? ('#' . $value);
        }
        if (in_array($field, ['cantitate_incarcata', 'cantitate_prelevata', 'tona_livrata', 'capacitate_transport', 'tona_aspirata_lichida', 'tona_aspirata_gazoasa'], true) && is_numeric($value)) {
            return rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',') . ' t';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return date('d.m.Y', (int) strtotime($value));
        }

        return mb_strlen($value) > 160 ? mb_substr($value, 0, 157) . '…' : $value;
    }

    /** [url, eticheta] catre inregistrarea afectata, doar daca exista si utilizatorul are acces. */
    private function recordLink(array $r): array
    {
        $id = (int) $r['record_id'];
        if ($id <= 0) {
            return [null, null];
        }
        $can = static fn (string $page): bool => !function_exists('can') || can($page);
        $exists = function (string $table, string $columns = 'id') use ($id): ?array {
            if (!$this->tableExists($table)) {
                return null;
            }
            try {
                $stmt = $this->db->prepare('SELECT ' . $columns . ' FROM ' . $table . ' WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                return $row ?: null;
            } catch (Throwable) {
                return null;
            }
        };

        switch ($r['module']) {
            case 'dispecer_curse':
                $course = $can('dispecer_curse') ? $exists('curse_dispecer', 'id, deleted_at') : null;
                if ($course === null) {
                    return [null, null];
                }
                if (!empty($course['deleted_at'])) {
                    $day = substr((string) $course['deleted_at'], 0, 10);

                    return [build_query_url(['page' => 'dispecer_curse', 'action' => 'curse_sterse', 'deleted_start' => $day, 'deleted_end' => $day]), 'Vezi în Curse șterse'];
                }

                return [build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $id]), 'Deschide cursa #' . $id];
            case 'vehicule':
                return $can('vehicule') && $exists('vehicule') !== null ? [build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $id]), 'Deschide fișa vehiculului'] : [null, null];
            case 'soferi':
                return $can('soferi') && $exists('soferi') !== null ? [build_query_url(['page' => 'soferi', 'action' => 'show', 'id' => $id]), 'Deschide fișa șoferului'] : [null, null];
            case 'utilizatori':
                return $can('utilizatori') && $exists('utilizatori') !== null ? [build_query_url(['page' => 'utilizatori', 'action' => 'edit', 'id' => $id]), 'Deschide contul'] : [null, null];
            case 'documente':
                return $can('documente') && $exists('documente') !== null ? [build_query_url(['page' => 'documente', 'action' => 'show', 'id' => $id]), 'Deschide documentul'] : [null, null];
            case 'concedii':
                return $can('programare_concedii') && $exists('concedii') !== null ? [build_query_url(['page' => 'programare_concedii', 'tab' => 'cereri', 'edit_id' => $id]), 'Deschide cererea de concediu'] : [null, null];
            case 'scadentar_leasing':
                $contract = $can('scadentar_leasing') ? $exists('leasing_contracts', 'id, contract_number') : null;

                return $contract !== null ? [build_query_url(['page' => 'scadentar_leasing', 'q' => (string) $contract['contract_number']]), 'Deschide contractul de leasing'] : [null, null];
            case 'revizii':
                return $can('revizii') ? [build_query_url(['page' => 'revizii', 'tip' => 'istoric']), 'Deschide Istoric revizii'] : [null, null];
        }

        return [null, null];
    }

    // ======================================================= vizualizari salvate

    public function listViews(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT id, name, config_json, updated_at FROM activity_report_views WHERE user_id = :u ORDER BY name');
        $stmt->execute([':u' => $userId]);

        return array_map(static fn (array $v): array => [
            'id' => (int) $v['id'], 'name' => (string) $v['name'],
            'config' => json_decode((string) $v['config_json'], true) ?: [], 'updated_at' => (string) $v['updated_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function saveView(int $userId, string $name, array $config): int
    {
        $name = mb_substr(trim($name), 0, 120);
        if ($name === '') {
            throw new InvalidArgumentException('Numele vizualizării este obligatoriu.');
        }
        $allowed = ['module', 'section', 'user', 'role', 'actions', 'from', 'to', 'period', 'entity', 'field', 'sensitive', 'logins', 'q', 'sort', 'dir', 'pp', 'group', 'trend', 'heat', 'mode', 'columns'];
        $config = array_intersect_key($config, array_flip($allowed));
        $json = json_encode($config, JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) > 8000) {
            throw new InvalidArgumentException('Configurația vizualizării nu este validă.');
        }
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare('
            INSERT INTO activity_report_views (user_id, name, config_json, created_at, updated_at)
            VALUES (:u, :n, :c, :now, :now2)
            ON DUPLICATE KEY UPDATE config_json = VALUES(config_json), updated_at = VALUES(updated_at)
        ');
        $stmt->execute([':u' => $userId, ':n' => $name, ':c' => $json, ':now' => $now, ':now2' => $now]);
        $find = $this->db->prepare('SELECT id FROM activity_report_views WHERE user_id = :u AND name = :n');
        $find->execute([':u' => $userId, ':n' => $name]);

        return (int) $find->fetchColumn();
    }

    public function deleteView(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM activity_report_views WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $id, ':u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    // ============================================================ utilitare

    public function describeFilters(array $f): array
    {
        $users = $this->users();
        $out = [
            ['Perioadă', date('d.m.Y', (int) strtotime($f['from'])) . ' – ' . date('d.m.Y', (int) strtotime($f['to']))],
            ['Modul / Pagină', $f['module'] !== '' ? (self::MODULES[$f['module']] ?? $f['module']) : 'Toate paginile'],
        ];
        if ($f['section'] !== '') {
            $out[] = ['Secțiune', $f['section']];
        }
        $out[] = ['Utilizator', $f['user'] > 0 ? ($users[$f['user']]['nume'] ?? '#' . $f['user']) : 'Toți utilizatorii'];
        if ($f['role'] !== '') {
            $out[] = ['Rol', function_exists('role_display_name') ? role_display_name($f['role']) : $f['role']];
        }
        $out[] = ['Tip acțiune', $f['actions'] !== [] ? implode(', ', array_map(static fn (string $a): string => self::ACTIONS[$a], $f['actions'])) : 'Toate'];
        foreach (['entity' => 'Entitate', 'field' => 'Câmp modificat', 'q' => 'Căutare', 'tq' => 'Căutare în rezultate'] as $key => $label) {
            if ($f[$key] !== '') {
                $out[] = [$label, $key === 'field' ? (self::FIELD_LABELS[$f[$key]] ?? $f[$key]) : $f[$key]];
            }
        }
        if ($f['sensitive']) {
            $out[] = ['Filtru', 'Doar acțiuni sensibile'];
        }
        $out[] = ['Autentificări', $f['logins'] ? 'Incluse' : 'Excluse'];

        return $out;
    }

    public function dateLabel(string $ts): string
    {
        $time = strtotime($ts);

        return $time ? date('d', $time) . ' ' . self::MONTHS_SHORT[(int) date('n', $time)] . ' ' . date('Y', $time) : $ts;
    }

    /** @return array<int,array{nume:string,rol:string}> */
    private function users(): array
    {
        if ($this->users === null) {
            $this->users = [];
            foreach ($this->db->query('SELECT id, nume, rol FROM utilizatori')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $u) {
                $this->users[(int) $u['id']] = ['nume' => (string) $u['nume'], 'rol' => (string) $u['rol']];
            }
        }

        return $this->users;
    }

    /** Dictionare id -> eticheta (tabele mici, incarcate o data per cerere). */
    private function lookup(string $name): array
    {
        if (isset($this->lookups[$name])) {
            return $this->lookups[$name];
        }
        [$table, $label] = match ($name) {
            'vehicles' => ['vehicule', 'nr_inmatriculare'],
            'drivers' => ['soferi', 'nume'],
            'beneficiaries' => ['configurare_beneficiari_transport', 'nume'],
            'zones' => ['configurare_zone_distributie', 'nume'],
            'locations' => ['configurare_locuri_incarcare', 'nume'],
            'capacity_categories' => ['vehicule_categorii_capacitate', 'nume'],
            default => [null, null],
        };
        $this->lookups[$name] = [];
        if ($table !== null && $this->tableExists($table)) {
            try {
                foreach ($this->db->query('SELECT id, ' . $label . ' AS label FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $this->lookups[$name][(int) $row['id']] = (string) $row['label'];
                }
            } catch (Throwable $exception) {
                error_log('[ActivityReportModel][lookup] ' . $name . ': ' . $exception->getMessage());
            }
        }

        return $this->lookups[$name];
    }

    private function revisionLabel(string $type): string
    {
        return [
            'schimb_ulei' => 'Schimb ulei', 'placute_frana' => 'Plăcuțe frână', 'placute_fata' => 'Plăcuțe față',
            'placute_spate' => 'Plăcuțe spate', 'placute_semi' => 'Plăcuțe semiremorcă', 'anvelope' => 'Anvelope',
        ][$type] ?? ucfirst(str_replace('_', ' ', $type));
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 2) . '***' . ($domain !== '' ? '@' . $domain : '');
    }

    private function fixText(string $text): string
    {
        return function_exists('repair_utf8_mojibake') ? repair_utf8_mojibake($text) : $text;
    }

    private function decode(mixed $json): ?array
    {
        if (!is_string($json) || trim($json) === '') {
            return null;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date ?: null;
    }

    private function tableExists(string $table): bool
    {
        if (!isset($this->tableCache[$table])) {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
            $stmt->execute([':t' => $table]);
            $this->tableCache[$table] = (int) $stmt->fetchColumn() > 0;
        }

        return $this->tableCache[$table];
    }

    private function hasColumn(string $table, string $column): bool
    {
        if (!isset($this->columnCache[$table])) {
            $stmt = $this->db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
            $stmt->execute([':t' => $table]);
            $this->columnCache[$table] = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        }

        return isset($this->columnCache[$table][$column]);
    }

    private function hasIndex(string $table, string $index): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i');
        $stmt->execute([':t' => $table, ':i' => $index]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
