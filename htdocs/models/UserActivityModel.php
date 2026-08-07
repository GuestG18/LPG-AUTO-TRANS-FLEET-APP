<?php
declare(strict_types=1);

/**
 * Agregarea activitatii utilizatorilor aplicatiei pentru pagina
 * "Activitate utilizatori" (jurnal de audit).
 *
 * Uneste sursele de audit deja existente in aplicatie:
 *   - audit_log          : module Documente si Programare concedii
 *                          (modul, actiune, descriere, before/after, user_id)
 *   - cursa_audit_log    : Dispecer curse (created/updated/deleted/restored/status_changed)
 *   - login_email_codes  : autentificari reusite (used_at)
 *
 * Nu creeaza tabele noi; daca o sursa lipseste este ignorata elegant.
 * Modulele care nu scriu inca in audit_log vor aparea automat aici pe masura
 * ce li se adauga un hook de logare.
 */
class UserActivityModel extends BaseModel
{
    /** Cheile de modul -> eticheta afisata. */
    private const MODULE_LABELS = [
        'documente'        => 'Documente',
        'documente_soferi' => 'Documente soferi',
        'concedii'         => 'Programare concedii',
        'dispecer_curse'   => 'Dispecer curse',
        'cont'             => 'Autentificare',
    ];

    /** Numar maxim de randuri normalizate returnate pentru cronologie. */
    private const TIMELINE_LIMIT = 400;

    /** Plafon de siguranta per sursa, ca sa nu incarcam seturi uriase. */
    private const SOURCE_LIMIT = 5000;

    public function getDashboard(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);

        $periodRows = $this->collectRaw($filters['date_start'], $filters['date_end']);
        $rows = $this->applyFilters($periodRows, $filters);

        return [
            'filters'       => $filters,
            'rows'          => array_slice($rows, 0, self::TIMELINE_LIMIT),
            'rowsTotal'     => count($rows),
            'timelineLimit' => self::TIMELINE_LIMIT,
            'kpis'          => $this->buildKpis($periodRows, $filters),
            'topUsers'      => $this->buildTopUsers($periodRows, $filters),
            'distribution'  => $this->buildDistribution($periodRows),
            'userOptions'   => $this->getUserOptions(),
            'moduleOptions' => $this->getModuleOptions(),
            'updatedAt'     => date('Y-m-d H:i:s'),
        ];
    }

    /** @return array<int,array<string,mixed>> randuri filtrate, fara limita, pentru export. */
    public function getExportRows(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        $periodRows = $this->collectRaw($filters['date_start'], $filters['date_end']);

        return $this->applyFilters($periodRows, $filters);
    }

    // --------------------------------------------------------------- normalizare

    private function normalizeFilters(array $filters): array
    {
        $today = new DateTimeImmutable('today');

        $start = $this->parseDate((string) ($filters['date_start'] ?? ''));
        $end = $this->parseDate((string) ($filters['date_end'] ?? ''));
        if ($start === null) {
            $start = $today->modify('-30 days');
        }
        if ($end === null) {
            $end = $today;
        }
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        return [
            'date_start'  => $start->format('Y-m-d'),
            'date_end'    => $end->format('Y-m-d'),
            'user_id'     => (int) ($filters['user_id'] ?? 0),
            'module'      => trim((string) ($filters['module'] ?? '')),
            'action'      => trim((string) ($filters['action'] ?? '')),
            'search'      => trim((string) ($filters['search'] ?? '')),
        ];
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Aduna randurile normalizate din toate sursele intr-un interval de date.
     *
     * @return array<int,array<string,mixed>> sortate descrescator dupa data
     */
    private function collectRaw(string $start, string $end): array
    {
        $startDt = $start . ' 00:00:00';
        $endDt = $end . ' 23:59:59';
        $users = $this->userMap();
        $rows = [];

        foreach ($this->fetchAuditRows($startDt, $endDt) as $row) {
            $rows[] = $this->normalizeAuditRow($row, $users);
        }
        foreach ($this->fetchRaceRows($startDt, $endDt) as $row) {
            $rows[] = $this->normalizeRaceRow($row, $users);
        }
        foreach ($this->fetchLoginRows($startDt, $endDt) as $row) {
            $rows[] = $this->normalizeLoginRow($row, $users);
        }

        usort($rows, static function (array $a, array $b): int {
            return strcmp((string) $b['ts'], (string) $a['ts']);
        });

        return $rows;
    }

    private function fetchAuditRows(string $startDt, string $endDt): array
    {
        if (!$this->tableExists('audit_log')) {
            return [];
        }
        $stmt = $this->db->prepare('
            SELECT id, modul, record_id, actiune, descriere, before_data, after_data, user_id, created_at
            FROM audit_log
            WHERE created_at BETWEEN :s AND :e
            ORDER BY created_at DESC
            LIMIT ' . self::SOURCE_LIMIT . '
        ');
        $stmt->execute([':s' => $startDt, ':e' => $endDt]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function fetchRaceRows(string $startDt, string $endDt): array
    {
        if (!$this->tableExists('cursa_audit_log')) {
            return [];
        }
        $stmt = $this->db->prepare('
            SELECT id, cursa_id, action, performed_by, performed_at, details_json
            FROM cursa_audit_log
            WHERE performed_at BETWEEN :s AND :e
            ORDER BY performed_at DESC
            LIMIT ' . self::SOURCE_LIMIT . '
        ');
        $stmt->execute([':s' => $startDt, ':e' => $endDt]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function fetchLoginRows(string $startDt, string $endDt): array
    {
        if (!$this->tableExists('login_email_codes')) {
            return [];
        }
        $stmt = $this->db->prepare('
            SELECT id, user_id, email, used_at
            FROM login_email_codes
            WHERE used_at IS NOT NULL AND used_at BETWEEN :s AND :e
            ORDER BY used_at DESC
            LIMIT ' . self::SOURCE_LIMIT . '
        ');
        $stmt->execute([':s' => $startDt, ':e' => $endDt]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function normalizeAuditRow(array $row, array $users): array
    {
        $userId = (int) ($row['user_id'] ?? 0);
        $moduleKey = (string) ($row['modul'] ?? '');

        return $this->makeRow([
            'ts'           => (string) ($row['created_at'] ?? ''),
            'user_id'      => $userId,
            'module_key'   => $moduleKey,
            'module_label' => $this->moduleLabel($moduleKey),
            'action_key'   => $this->mapAuditAction((string) ($row['actiune'] ?? '')),
            'description'  => trim((string) ($row['descriere'] ?? '')),
            'record_id'    => (int) ($row['record_id'] ?? 0) ?: null,
            'before'       => $this->decodeJson((string) ($row['before_data'] ?? '')),
            'after'        => $this->decodeJson((string) ($row['after_data'] ?? '')),
            'source'       => 'audit',
        ], $users);
    }

    private function normalizeRaceRow(array $row, array $users): array
    {
        $action = (string) ($row['action'] ?? '');
        $actionKey = [
            'created'        => 'create',
            'updated'        => 'update',
            'deleted'        => 'delete',
            'restored'       => 'restore',
            'status_changed' => 'status',
        ][$action] ?? 'update';

        $raceId = (int) ($row['cursa_id'] ?? 0);
        $details = $this->decodeJson((string) ($row['details_json'] ?? '')) ?? [];

        return $this->makeRow([
            'ts'           => (string) ($row['performed_at'] ?? ''),
            'user_id'      => (int) ($row['performed_by'] ?? 0),
            'module_key'   => 'dispecer_curse',
            'module_label' => 'Dispecer curse',
            'action_key'   => $actionKey,
            'description'  => $this->raceDescription($actionKey, $raceId, $details),
            'record_id'    => $raceId ?: null,
            'before'       => null,
            'after'        => $details !== [] ? $details : null,
            'source'       => 'cursa',
        ], $users);
    }

    private function normalizeLoginRow(array $row, array $users): array
    {
        return $this->makeRow([
            'ts'           => (string) ($row['used_at'] ?? ''),
            'user_id'      => (int) ($row['user_id'] ?? 0),
            'module_key'   => 'cont',
            'module_label' => 'Autentificare',
            'action_key'   => 'login',
            'description'  => 'S-a autentificat cu succes (cod pe email)',
            'record_id'    => null,
            'before'       => null,
            'after'        => null,
            'source'       => 'login',
        ], $users);
    }

    private function makeRow(array $row, array $users): array
    {
        $userId = (int) ($row['user_id'] ?? 0);
        $user = $users[$userId] ?? null;
        $row['user_id'] = $userId;
        $row['user_name'] = $user['nume'] ?? 'Utilizator șters';
        $row['user_role'] = $user['rol'] ?? '';

        return $row;
    }

    private function raceDescription(string $actionKey, int $raceId, array $details): string
    {
        $ref = $raceId > 0 ? ('cursa #' . $raceId) : 'o cursă';
        $extra = '';
        if (isset($details['nr_inmatriculare']) && trim((string) $details['nr_inmatriculare']) !== '') {
            $extra = ' — ' . (string) $details['nr_inmatriculare'];
        } elseif (isset($details['status_facturare'])) {
            $extra = ' — status facturare: ' . (string) $details['status_facturare'];
        } elseif (isset($details['cheltuieli_status'])) {
            $extra = ' — status cheltuieli: ' . (string) $details['cheltuieli_status'];
        }

        $verb = [
            'create'  => 'A creat ',
            'update'  => 'A modificat ',
            'delete'  => 'A șters ',
            'restore' => 'A restaurat ',
            'status'  => 'A schimbat statusul pentru ',
        ][$actionKey] ?? 'A actualizat ';

        return $verb . $ref . $extra;
    }

    private function mapAuditAction(string $actiune): string
    {
        $a = mb_strtolower(trim($actiune), 'UTF-8');
        if ($a === '') {
            return 'update';
        }
        if (str_contains($a, 'status')) {
            return 'status';
        }
        if (str_contains($a, 'creat') || str_contains($a, 'create') || str_contains($a, 'add') || str_contains($a, 'adaug')) {
            return 'create';
        }
        if (str_contains($a, 'sterg') || str_contains($a, 'delete') || str_contains($a, 'remov')) {
            return 'delete';
        }
        if (str_contains($a, 'restaur') || str_contains($a, 'restore')) {
            return 'restore';
        }

        return 'update';
    }

    private function moduleLabel(string $key): string
    {
        if (isset(self::MODULE_LABELS[$key])) {
            return self::MODULE_LABELS[$key];
        }

        return $key !== '' ? ucfirst(str_replace('_', ' ', $key)) : 'General';
    }

    // ------------------------------------------------------------------ filtrare

    /** @param array<int,array<string,mixed>> $rows */
    private function applyFilters(array $rows, array $filters): array
    {
        $userId = (int) $filters['user_id'];
        $module = (string) $filters['module'];
        $action = (string) $filters['action'];
        $search = mb_strtolower((string) $filters['search'], 'UTF-8');

        return array_values(array_filter($rows, static function (array $row) use ($userId, $module, $action, $search): bool {
            if ($userId > 0 && (int) $row['user_id'] !== $userId) {
                return false;
            }
            if ($module !== '' && (string) $row['module_key'] !== $module) {
                return false;
            }
            if ($action !== '' && (string) $row['action_key'] !== $action) {
                return false;
            }
            if ($search !== '') {
                $haystack = mb_strtolower(
                    (string) $row['user_name'] . ' ' . (string) $row['module_label'] . ' '
                    . (string) $row['description'] . ' #' . (string) ($row['record_id'] ?? ''),
                    'UTF-8'
                );
                if (!str_contains($haystack, $search)) {
                    return false;
                }
            }

            return true;
        }));
    }

    // ---------------------------------------------------------------------- KPI

    private function buildKpis(array $periodRows, array $filters): array
    {
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $yesterday = (new DateTimeImmutable('yesterday'))->format('Y-m-d');
        $weekAgo = (new DateTimeImmutable('today'))->modify('-6 days')->format('Y-m-d');

        $todayRows = $this->collectRaw($today, $today);
        $weekRows = $this->collectRaw($weekAgo, $today);

        $todayCount = count($todayRows);
        $yesterdayCount = count(array_filter(
            $this->collectRaw($yesterday, $yesterday),
            static fn (array $r): bool => true
        ));

        $delta = null;
        if ($yesterdayCount > 0) {
            $delta = (int) round((($todayCount - $yesterdayCount) / $yesterdayCount) * 100);
        }

        $activeUsers = [];
        $logins7d = 0;
        foreach ($weekRows as $r) {
            if ((int) $r['user_id'] > 0) {
                $activeUsers[(int) $r['user_id']] = true;
            }
            if ((string) $r['action_key'] === 'login') {
                $logins7d++;
            }
        }

        $top = $this->topOfPeriod($periodRows);

        return [
            'today'            => $todayCount,
            'yesterday'        => $yesterdayCount,
            'delta_pct'        => $delta,
            'active_users'     => count($activeUsers),
            'total_users'      => count($this->userMap()),
            'logins_7d'        => $logins7d,
            'failed_logins_7d' => $this->countFailedLogins($weekAgo, $today),
            'top_user_name'    => $top['name'],
            'top_user_count'   => $top['count'],
        ];
    }

    private function countFailedLogins(string $start, string $end): int
    {
        if (!$this->tableExists('login_email_codes')) {
            return 0;
        }
        $stmt = $this->db->prepare('
            SELECT COUNT(*)
            FROM login_email_codes
            WHERE used_at IS NULL
              AND attempts >= max_attempts
              AND sent_at BETWEEN :s AND :e
        ');
        $stmt->execute([':s' => $start . ' 00:00:00', ':e' => $end . ' 23:59:59']);

        return (int) $stmt->fetchColumn();
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function topOfPeriod(array $rows): array
    {
        $counts = [];
        $names = [];
        foreach ($rows as $r) {
            $uid = (int) $r['user_id'];
            if ($uid <= 0) {
                continue;
            }
            $counts[$uid] = ($counts[$uid] ?? 0) + 1;
            $names[$uid] = (string) $r['user_name'];
        }
        if ($counts === []) {
            return ['name' => '—', 'count' => 0];
        }
        arsort($counts);
        $topId = (int) array_key_first($counts);

        return ['name' => $names[$topId] ?? '—', 'count' => $counts[$topId]];
    }

    // ---------------------------------------------------------------- top users

    /** @param array<int,array<string,mixed>> $rows */
    private function buildTopUsers(array $rows, array $filters): array
    {
        $end = new DateTimeImmutable($filters['date_end']);
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $days[] = $end->modify('-' . $i . ' days')->format('Y-m-d');
        }
        $dayIndex = array_flip($days);

        $agg = [];
        foreach ($rows as $r) {
            $uid = (int) $r['user_id'];
            if ($uid <= 0) {
                continue;
            }
            if (!isset($agg[$uid])) {
                $agg[$uid] = [
                    'user_id' => $uid,
                    'name'    => (string) $r['user_name'],
                    'role'    => (string) $r['user_role'],
                    'count'   => 0,
                    'spark'   => array_fill(0, 7, 0),
                ];
            }
            $agg[$uid]['count']++;
            $day = substr((string) $r['ts'], 0, 10);
            if (isset($dayIndex[$day])) {
                $agg[$uid]['spark'][$dayIndex[$day]]++;
            }
        }

        usort($agg, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice(array_values($agg), 0, 5);
    }

    // -------------------------------------------------------------- distributie

    /** @param array<int,array<string,mixed>> $rows */
    private function buildDistribution(array $rows): array
    {
        $order = ['update', 'create', 'login', 'delete', 'restore', 'status'];
        $counts = array_fill_keys($order, 0);
        foreach ($rows as $r) {
            $key = (string) $r['action_key'];
            if (isset($counts[$key])) {
                $counts[$key]++;
            }
        }
        $total = array_sum($counts);

        $out = [];
        foreach ($order as $key) {
            if ($counts[$key] === 0) {
                continue;
            }
            $out[] = [
                'action' => $key,
                'count'  => $counts[$key],
                'pct'    => $total > 0 ? (int) round($counts[$key] / $total * 100) : 0,
            ];
        }

        return ['total' => $total, 'items' => $out];
    }

    // --------------------------------------------------------------- optiuni UI

    /** @return array<int,array<string,mixed>> */
    private function getUserOptions(): array
    {
        return $this->db->query('
            SELECT id, nume, rol
            FROM utilizatori
            ORDER BY nume ASC
        ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int,array{key:string,label:string}> */
    private function getModuleOptions(): array
    {
        $keys = ['dispecer_curse', 'documente', 'concedii', 'cont'];
        if ($this->tableExists('audit_log')) {
            $extra = $this->db->query('SELECT DISTINCT modul FROM audit_log')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($extra as $m) {
                $m = (string) $m;
                if ($m !== '' && !in_array($m, $keys, true)) {
                    $keys[] = $m;
                }
            }
        }

        $options = [];
        foreach ($keys as $key) {
            $options[] = ['key' => $key, 'label' => $this->moduleLabel($key)];
        }
        usort($options, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $options;
    }

    /** @return array<int,array{nume:string,rol:string}> */
    private function userMap(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        foreach ($this->db->query('SELECT id, nume, rol FROM utilizatori')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cache[(int) $row['id']] = ['nume' => (string) $row['nume'], 'rol' => (string) $row['rol']];
        }

        return $cache;
    }

    // ------------------------------------------------------------------ utilitare

    private function decodeJson(string $json): ?array
    {
        $json = trim($json);
        if ($json === '') {
            return null;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function tableExists(string $table): bool
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }
        $stmt = $this->db->prepare('
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
        ');
        $stmt->execute([':t' => $table]);

        return $cache[$table] = (int) $stmt->fetchColumn() > 0;
    }
}
