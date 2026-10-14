<?php
declare(strict_types=1);

require_once __DIR__ . '/ReviziiService.php';

/**
 * Raportul saptamanal Revizii pe email (regula „Raport săptămânal” din page=notificari).
 *
 * Datele vin din ReviziiService::buildOverview() (aceleasi ca pagina Revizii). Trimiterea
 * o face scripts/revizii_raport_saptamanal.php din cron, prin EmailService (SMTP-ul aplicatiei);
 * worker.py ignora evenimentul. Fiecare destinatar primeste raportul o data pe saptamana ISO:
 * deduplicarea se face pe notification_deliveries (context + saptamana + email, status sent).
 */
class ReviziiWeeklyReportService
{
    public const EVENT = 'revizie_raport_saptamanal';
    public const CONTEXT = 'revizii_raport';
    public const CONTEXT_TEST = 'revizii_raport_test';

    public const DAYS = [
        1 => 'Luni',
        2 => 'Marți',
        3 => 'Miercuri',
        4 => 'Joi',
        5 => 'Vineri',
        6 => 'Sâmbătă',
        7 => 'Duminică',
    ];

    private PDO $db;

    private ?array $overview = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Setarile raportului: din metadata_json.report + filtrul de stare (document_type, ca la Revizii). */
    public static function normalizeConfig(array $report, string $statusFilter = ''): array
    {
        $categories = array_values(array_intersect(
            array_map('strval', (array) ($report['categories'] ?? [])),
            array_keys(ReviziiModel::TABS)
        ));

        return [
            'day' => max(1, min(7, (int) ($report['day'] ?? 1))),
            'hour' => max(0, min(23, (int) ($report['hour'] ?? 8))),
            'categories' => $categories,
            'history' => !empty($report['history']),
            'status' => $statusFilter === 'necesita' ? 'necesita' : '',
        ];
    }

    public static function scheduleLabel(array $config): string
    {
        return (self::DAYS[$config['day']] ?? 'Luni') . ', ora ' . sprintf('%02d:00', $config['hour']);
    }

    /** Momentul programat in saptamana ISO a lui $now. */
    public static function slotForWeek(array $config, int $now): int
    {
        $monday = (new DateTimeImmutable('@' . $now))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->modify('monday this week')
            ->setTime(0, 0);

        return $monday->modify('+' . ($config['day'] - 1) . ' days')->setTime($config['hour'], 0)->getTimestamp();
    }

    public static function weekKey(int $timestamp): string
    {
        return date('o-\WW', $timestamp);
    }

    /**
     * Construieste raportul (subiect, HTML, text) pentru setarile date.
     *
     * @return array{subject:string, html:string, text:string, stats:array}
     */
    public function build(array $config, ?int $now = null): array
    {
        $context = $this->collect($config, $now);

        return [
            'subject' => $context['subject'],
            'html' => $this->renderHtml($context),
            'text' => $this->renderText($context),
            'stats' => $context['stats'],
            // Pentru atasamentul Excel: aceleasi setari si acelasi moment ca emailul.
            'config' => $context['config'],
            'now' => $context['now'],
        ];
    }

    /** Linkul de export Excel din raport (pagina Revizii, cere autentificare). */
    public static function exportUrl(array $config): string
    {
        $query = ['page' => 'revizii', 'action' => 'raport_export'];
        if ($config['categories'] !== []) {
            $query['cat'] = implode(',', $config['categories']);
        }
        if ($config['status'] === 'necesita') {
            $query['status'] = 'necesita';
        }
        if ($config['history']) {
            $query['history'] = '1';
        }

        return absolute_url('index.php?' . http_build_query($query));
    }

    /** Setarile raportului din parametrii linkului de export (?cat=a,b&status=necesita&history=1). */
    public static function configFromQuery(array $query): array
    {
        return self::normalizeConfig([
            'categories' => array_filter(explode(',', (string) ($query['cat'] ?? ''))),
            'history' => !empty($query['history']),
        ], (string) ($query['status'] ?? ''));
    }

    /**
     * Raportul ca fisier Excel: „Rezumat”, „Necesită revizie”, „Se apropie” si (optional)
     * „Efectuate 7 zile”, cu aceleasi date ca emailul.
     */
    public function writeXlsx(array $config, string $path, ?int $now = null): void
    {
        require_once __DIR__ . '/SimpleXlsxWriter.php';
        $c = $this->collect($config, $now);
        $stats = $c['stats'];
        $title = 'Raport săptămânal revizii — ' . str_replace('-W', ' / S', $stats['saptamana']);

        $xlsx = new SimpleXlsxWriter();
        $kmStyle = $xlsx->numberStyle('#,##0');
        $dateStyle = $xlsx->numberStyle('dd.mm.yyyy hh:mm');

        $summary = $xlsx->addSheet('Rezumat');
        $xlsx->setColumnWidths($summary, [1 => 30, 2 => 14, 3 => 14, 4 => 14, 5 => 14]);
        $xlsx->addRow($summary, [['v' => $title, 's' => 'title']]);
        $xlsx->addRow($summary, [['v' => 'Generat la ' . date('d.m.Y H:i', $c['now'])
            . ($c['last_sync'] ? ' · date GPS sincronizate ' . date('d.m.Y H:i', (int) strtotime((string) $c['last_sync'])) : ''), 's' => 'muted']]);
        $xlsx->addRow($summary, []);
        $xlsx->addRow($summary, [['v' => 'Rezumat', 's' => 'section'], ['v' => '', 's' => 'section']]);
        $xlsx->addRow($summary, [['v' => 'Necesită revizie', 's' => 'kpi_label'], ['v' => $stats['necesita'], 's' => 'kpi_value']]);
        if ($c['config']['status'] !== 'necesita') {
            $xlsx->addRow($summary, [['v' => 'Se apropie', 's' => 'kpi_label'], ['v' => $stats['se_apropie'], 's' => 'kpi_value']]);
        }
        if ($c['config']['history']) {
            $xlsx->addRow($summary, [['v' => 'Efectuate în ultimele 7 zile', 's' => 'kpi_label'], ['v' => $stats['efectuate'], 's' => 'kpi_value']]);
        }
        $xlsx->addRow($summary, [['v' => 'Vehicule monitorizate', 's' => 'kpi_label'], ['v' => $stats['vehicule'], 's' => 'kpi_value']]);
        $xlsx->addRow($summary, []);
        $xlsx->addRow($summary, [
            ['v' => 'Pe categorii', 's' => 'th'], ['v' => 'Necesită', 's' => 'th_num'], ['v' => 'Se apropie', 's' => 'th_num'],
            ['v' => 'Fără date', 's' => 'th_num'], ['v' => 'În regulă', 's' => 'th_num'],
        ]);
        foreach ($c['categories'] as $category) {
            $counts = $category['counts'];
            $xlsx->addRow($summary, [
                ['v' => $category['label'], 's' => 'text'],
                ['v' => (int) $counts[ReviziiCalculator::STATUS_DUE], 's' => 'int'],
                ['v' => (int) $counts[ReviziiCalculator::STATUS_SOON], 's' => 'int'],
                ['v' => (int) $counts[ReviziiCalculator::STATUS_NO_DATA], 's' => 'int'],
                ['v' => (int) $counts[ReviziiCalculator::STATUS_OK], 's' => 'int'],
            ]);
        }

        $alertSheet = function (string $name, array $rows) use ($xlsx, $kmStyle, $dateStyle, $title): void {
            $sheet = $xlsx->addSheet($name);
            $headers = [
                'Nr. auto', 'Vehicul', 'Tip vehicul', 'Categorie', 'Componentă',
                'Km efectuați (de la T0)', 'Ore motor eligibile (h)', 'Km echivalenți din ore', 'Consum inițial km', 'Km total echivalenți',
                'Interval km', 'Prag notificare km', 'Km rămași', 'Situație', 'T0', 'Km bord acum', 'Zile fără telemetrie', 'Date complete',
            ];
            $xlsx->setColumnWidths($sheet, array_combine(range(1, 18), [14, 18, 20, 16, 26, 16, 14, 16, 14, 16, 12, 14, 13, 24, 17, 13, 14, 18]));
            $xlsx->addRow($sheet, [['v' => $title . ' · ' . $name, 's' => 'title']]);
            $headerRow = $xlsx->addRow($sheet, array_map(
                static fn (string $h, int $i): array => ['v' => $h, 's' => in_array($i, [5, 6, 7, 8, 9, 10, 11, 12, 15, 16], true) ? 'th_num' : 'th'],
                $headers,
                array_keys($headers)
            ));
            $xlsx->freezeRows($sheet, $headerRow);
            $decStyle = $xlsx->numberStyle('#,##0.0');
            $hourStyle = $xlsx->numberStyle('#,##0.00');
            $dash = ['v' => '—', 's' => 'text_num'];
            foreach ($rows as $row) {
                $left = (float) $row['km_ramasi'];
                $calc = is_array($row['calc'] ?? null) ? $row['calc'] : null;
                $withHours = $calc !== null && $row['ore_aplicabile'] && $row['ore_aplicate'];
                $xlsx->addRow($sheet, [
                    ['v' => $row['nr'], 's' => 'text'],
                    ['v' => $row['marca'], 's' => 'text'],
                    ['v' => $row['vehicul_tip'], 's' => 'text'],
                    ['v' => $row['categorie_label'], 's' => 'text'],
                    ['v' => $row['componenta'], 's' => 'text'],
                    $calc !== null ? ['v' => round((float) $calc['km_efectuati'], 1), 's' => $decStyle] : $dash,
                    $withHours ? ['v' => round((float) $calc['ore_motor'], 2), 's' => $hourStyle] : $dash,
                    $withHours ? ['v' => round((float) $calc['km_din_ore'], 1), 's' => $decStyle] : $dash,
                    $calc !== null ? ['v' => round((float) ($calc['consum_initial_km'] ?? 0), 1), 's' => $decStyle] : $dash,
                    $calc !== null ? ['v' => round((float) $calc['km_total'], 1), 's' => $decStyle] : $dash,
                    ['v' => (int) $row['interval_km'], 's' => $kmStyle],
                    ['v' => (int) $row['prag_km'], 's' => $kmStyle],
                    ['v' => round($left, 1), 's' => $decStyle],
                    ['v' => (!empty($row['limita_superioara']) ? 'cel mult ' : '') . self::kmLeft($left), 's' => 'text'],
                    ($row['baseline_at'] ?? '') !== '' ? ['v' => self::excelDate((string) $row['baseline_at']), 's' => $dateStyle] : ['v' => '—', 's' => 'text'],
                    ['v' => (int) ($row['km_bord'] ?? 0), 's' => $kmStyle],
                    ['v' => count((array) ($row['zile_lipsa'] ?? [])), 's' => 'int'],
                    ['v' => !empty($row['complet']) ? 'Da' : 'Nu (date incomplete)', 's' => 'text'],
                ]);
            }
            if ($rows === []) {
                $xlsx->addRow($sheet, [['v' => 'Nicio componentă în această situație.', 's' => 'muted']]);
            } else {
                $xlsx->setAutoFilter($sheet, 'A' . $headerRow . ':R' . ($headerRow + count($rows)));
            }
        };
        $alertSheet('Necesită revizie', $c['due']);
        if ($c['config']['status'] !== 'necesita') {
            $alertSheet('Se apropie', $c['soon']);
        }

        if ($c['config']['history']) {
            $sheet = $xlsx->addSheet('Efectuate 7 zile');
            $xlsx->setColumnWidths($sheet, [1 => 18, 2 => 14, 3 => 16, 4 => 26, 5 => 18, 6 => 22, 7 => 40]);
            $xlsx->addRow($sheet, [['v' => $title . ' · revizii efectuate în ultimele 7 zile', 's' => 'title']]);
            $headerRow = $xlsx->addRow($sheet, array_map(
                static fn (string $h): array => ['v' => $h, 's' => 'th'],
                ['Dată', 'Nr. auto', 'Categorie', 'Componentă', 'Acțiune', 'Operator', 'Observații']
            ));
            $xlsx->freezeRows($sheet, $headerRow);
            foreach ($c['history'] as $row) {
                $xlsx->addRow($sheet, [
                    ['v' => self::excelDate((string) $row['data']), 's' => $dateStyle],
                    ['v' => $row['nr'], 's' => 'text'],
                    ['v' => $row['categorie_label'], 's' => 'text'],
                    ['v' => $row['componenta'], 's' => 'text'],
                    ['v' => (string) ($row['actiune'] ?? ''), 's' => 'text'],
                    ['v' => (string) ($row['operator'] ?? '—'), 's' => 'text'],
                    ['v' => (string) ($row['observatii'] ?? ''), 's' => 'text'],
                ]);
            }
            if ($c['history'] === []) {
                $xlsx->addRow($sheet, [['v' => 'Nicio revizie înregistrată în ultimele 7 zile.', 's' => 'muted']]);
            }
        }

        $xlsx->save($path);
    }

    public static function exportFileName(?int $now = null): string
    {
        return 'Raport_revizii_' . date('Y', $now ?? time()) . '_S' . date('W', $now ?? time()) . '_' . date('Ymd_Hi', $now ?? time()) . '.xlsx';
    }

    private static function excelDate(string $value): float
    {
        $moment = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        $epoch = new DateTimeImmutable('1899-12-30 00:00:00', new DateTimeZone('UTC'));

        return ($moment->getTimestamp() - $epoch->getTimestamp()) / 86400;
    }

    /** Datele comune pentru email (HTML/text) si Excel. */
    private function collect(array $config, ?int $now = null): array
    {
        $now ??= time();
        $overview = $this->overview();
        $categories = $config['categories'] !== [] ? $config['categories'] : array_keys(ReviziiModel::TABS);

        $alerts = array_values(array_filter(
            $overview['alerte'],
            static fn (array $alert): bool => in_array($alert['categorie'], $categories, true)
                && ($config['status'] !== 'necesita' || $alert['status'] === ReviziiCalculator::STATUS_DUE)
        ));
        $alerts = array_map(fn (array $alert): array => $this->withDetails($alert, $now), $alerts);
        $due = array_values(array_filter($alerts, static fn (array $alert): bool => $alert['status'] === ReviziiCalculator::STATUS_DUE));
        $soon = array_values(array_filter($alerts, static fn (array $alert): bool => $alert['status'] === ReviziiCalculator::STATUS_SOON));

        $since = strtotime('-7 days', $now);
        $history = [];
        if ($config['history']) {
            $history = array_values(array_filter(
                $overview['istoric'],
                static fn (array $row): bool => in_array($row['categorie'], $categories, true) && strtotime((string) $row['data']) >= $since
            ));
            usort($history, static fn (array $a, array $b): int => strcmp((string) $b['data'], (string) $a['data']));
        }

        $categoryRows = [];
        foreach ($categories as $tab) {
            $category = $overview['categorii'][$tab] ?? null;
            if ($category !== null) {
                $categoryRows[] = ['label' => $category['label'], 'counts' => $category['counts']];
            }
        }

        $week = self::weekKey($now);
        $stats = [
            'necesita' => count($due),
            'se_apropie' => count($soon),
            'efectuate' => count($history),
            'vehicule' => (int) ($overview['kpi']['total'] ?? 0),
            'saptamana' => $week,
        ];

        $subject = '[' . APP_NAME . '] Raport săptămânal revizii ' . str_replace('-W', ' / S', $week)
            . ' — ' . $stats['necesita'] . ' necesită'
            . ($config['status'] !== 'necesita' ? ', ' . $stats['se_apropie'] . ' se apropie' : '');

        return [
            'subject' => $subject,
            'config' => $config,
            'now' => $now,
            'since' => $since,
            'due' => $due,
            'soon' => $soon,
            'history' => $history,
            'categories' => $categoryRows,
            'stats' => $stats,
            'last_sync' => $overview['last_sync'] ?? null,
            'url' => absolute_url('index.php?page=revizii&tip=prezentare'),
            'export_url' => self::exportUrl($config),
        ];
    }

    /** Destinatarii regulii, cu email: administratorii activi sau utilizatorii alesi. */
    public function resolveRecipients(string $mode, array $userIds): array
    {
        if ($mode === 'specific_users') {
            $userIds = array_values(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0));
            if ($userIds === []) {
                return [];
            }
            $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
            $stmt = $this->db->prepare("
                SELECT id, nume, email
                FROM utilizatori
                WHERE id IN ({$placeholders}) AND status = 'activ' AND email IS NOT NULL AND email <> ''
                ORDER BY nume ASC
            ");
            $stmt->execute($userIds);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $this->db->query("
            SELECT id, nume, email
            FROM utilizatori
            WHERE status = 'activ' AND rol = 'admin' AND email IS NOT NULL AND email <> ''
            ORDER BY nume ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Trimite rapoartele scadente. Folosit de cron (scripts/revizii_raport_saptamanal.php).
     *
     * Optiuni: dry_run (nu trimite), rule_id (doar o regula), force (ignora ora si deduplicarea;
     * se inregistreaza ca test, deci nu consuma trimiterea saptamanii), to (alt destinatar).
     *
     * @return array<int, array<string, mixed>> cate un rand per regula
     */
    public function runDue(array $options = [], ?int $now = null): array
    {
        $now ??= time();
        $dryRun = !empty($options['dry_run']);
        $force = !empty($options['force']) || trim((string) ($options['to'] ?? '')) !== '';
        $onlyRule = (int) ($options['rule_id'] ?? 0);

        $sql = 'SELECT * FROM notification_rules WHERE event_type = :event';
        $params = [':event' => self::EVENT];
        if ($onlyRule > 0) {
            $sql .= ' AND id = :id';
            $params[':id'] = $onlyRule;
        } else {
            $sql .= ' AND enabled = 1';
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY id ASC');
        $stmt->execute($params);

        $log = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rule) {
            $metadata = json_decode((string) ($rule['metadata_json'] ?? ''), true);
            $config = self::normalizeConfig(is_array($metadata['report'] ?? null) ? $metadata['report'] : [], (string) ($rule['document_type'] ?? ''));
            $slot = self::slotForWeek($config, $now);
            $entry = [
                'rule_id' => (int) $rule['id'],
                'rule' => (string) $rule['name'],
                'program' => self::scheduleLabel($config),
                'slot' => date('Y-m-d H:i', $slot),
                'sent' => [],
                'failed' => [],
                'skipped' => [],
                'status' => '',
            ];

            if (!$force && $now < $slot) {
                $entry['status'] = 'Nu e încă ora (programat ' . date('d.m.Y H:i', $slot) . ').';
                $log[] = $entry;
                continue;
            }
            // O regula creata dupa ora din saptamana curenta incepe de saptamana viitoare.
            if (!$force && strtotime((string) ($rule['created_at'] ?? '')) > $slot) {
                $entry['status'] = 'Regula a fost creată după ora programată; prima trimitere săptămâna viitoare.';
                $log[] = $entry;
                continue;
            }

            $override = trim((string) ($options['to'] ?? ''));
            if ($override !== '') {
                $recipients = filter_var($override, FILTER_VALIDATE_EMAIL) !== false ? [['id' => 0, 'nume' => '', 'email' => $override]] : [];
            } else {
                $recipients = $this->resolveRecipients((string) $rule['recipient_mode'], $this->ruleUserIds((int) $rule['id']));
            }

            $contextId = 'rule:' . (int) $rule['id'] . ':' . self::weekKey($slot);
            $pending = [];
            foreach ($recipients as $recipient) {
                if (!$force && $this->alreadySent($contextId, (string) $recipient['email'])) {
                    $entry['skipped'][] = (string) $recipient['email'];
                    continue;
                }
                $pending[] = $recipient;
            }

            if ($recipients === []) {
                $entry['status'] = 'Regula nu are niciun destinatar cu email.';
                $log[] = $entry;
                continue;
            }
            if ($pending === []) {
                $entry['status'] = 'Trimis deja săptămâna aceasta.';
                $log[] = $entry;
                continue;
            }

            $report = $this->build($config, $now);
            $entry['subject'] = $report['subject'];
            $entry['stats'] = $report['stats'];
            if ($dryRun) {
                $entry['status'] = 'Dry-run: s-ar trimite către ' . implode(', ', array_column($pending, 'email')) . '.';
                $log[] = $entry;
                continue;
            }

            $context = $force ? self::CONTEXT_TEST : self::CONTEXT;
            foreach ($pending as $recipient) {
                $result = $this->send($recipient, $report, $context, $contextId, (int) $rule['id']);
                if (!empty($result['sent'])) {
                    $entry['sent'][] = (string) $recipient['email'];
                } else {
                    $entry['failed'][] = (string) $recipient['email'] . ': ' . (string) ($result['error'] ?? 'eroare necunoscută');
                }
            }
            $entry['status'] = 'Trimis: ' . count($entry['sent']) . ', eșuat: ' . count($entry['failed']) . '.';
            $log[] = $entry;
        }

        return $log;
    }

    /** Trimite raportul unui destinatar si il inregistreaza in istoricul de notificari. */
    public function send(array $recipient, array $report, string $context, string $contextId, int $ruleId = 0): array
    {
        $options = [
            'html' => $report['html'],
            'context' => $context,
            'context_id' => $contextId,
            'metadata' => ['rule_id' => $ruleId, 'event_type' => self::EVENT] + $report['stats'],
        ];

        // Raportul complet in Excel, atasat; fisierul temporar se sterge dupa trimitere.
        $tmp = null;
        if (isset($report['config'])) {
            try {
                $tmp = tempnam(sys_get_temp_dir(), 'rvr');
                if ($tmp !== false) {
                    $this->writeXlsx($report['config'], $tmp, (int) ($report['now'] ?? time()));
                    $options['attachments'] = [['path' => $tmp, 'name' => self::exportFileName((int) ($report['now'] ?? time()))]];
                }
            } catch (Throwable $exception) {
                // Fara atasament, emailul pleaca oricum; eroarea ramane in log.
                error_log('[ReviziiWeeklyReport] Atasamentul Excel nu a putut fi generat: ' . $exception->getMessage());
                $options['metadata']['attachment_error'] = $exception->getMessage();
            }
        }

        try {
            return (new EmailService($this->db))->deliverTextEmail(
                (string) $recipient['email'],
                (string) ($recipient['nume'] ?? ''),
                $report['subject'],
                $report['text'],
                $options
            );
        } finally {
            if (is_string($tmp) && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private ?ReviziiService $revizii = null;

    private function overview(): array
    {
        $this->revizii ??= new ReviziiService($this->db);

        return $this->overview ??= $this->revizii->buildOverview();
    }

    /**
     * Calculul complet al componentei (ca panoul de detalii din Revizii): km efectuati, ore motor,
     * km echivalenti, consum initial, total, interval, prag, km ramasi, T0, telemetrie lipsa.
     */
    private function withDetails(array $alert, int $now): array
    {
        try {
            $component = $this->revizii->computeComponent((int) $alert['vehicle_id'], $now, (string) $alert['tip'], '');
        } catch (Throwable) {
            return $alert + ['calc' => null];
        }
        $calc = $component['calc'] ?? null;
        $detail = $component['detaliu'] ?? [];
        $sourceKey = (string) ($detail['sursa_ore'] ?? '');

        return $alert + [
            'calc' => $calc,
            'is_trailer' => (bool) $component['is_trailer'],
            'ore_aplicabile' => (bool) $component['ore_aplicabile'],
            'ore_aplicate' => !empty($detail['ore_aplicate']),
            'sursa_ore' => $sourceKey !== '' ? (string) (ReviziiModel::HOUR_SOURCES[$sourceKey] ?? $sourceKey) : '',
            'factor' => $this->revizii->factor(),
            'ore_motor_pornit' => $detail['ore_motor_pornit'] ?? null,
            'ore_pe_loc' => $detail['ore_sas_pe_loc'] ?? null,
            'baseline_at' => (string) ($component['plan']['baseline_at'] ?? $alert['baseline_at'] ?? ''),
            'km_ultima_revizie' => $component['plan']['km_ultima_revizie'] ?? null,
            'km_bord' => (int) $component['km_bord'],
            'zile_lipsa' => (array) ($component['zile_lipsa'] ?? []),
            'limita_superioara' => $calc !== null && empty($component['complet']),
        ];
    }

    private function ruleUserIds(int $ruleId): array
    {
        $stmt = $this->db->prepare('SELECT user_id FROM notification_rule_recipients WHERE rule_id = :rule_id');
        $stmt->execute([':rule_id' => $ruleId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function alreadySent(string $contextId, string $email): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM notification_deliveries
            WHERE context = :context
              AND context_id = :context_id
              AND recipient_email = :email
              AND status = 'sent'
        ");
        $stmt->execute([':context' => self::CONTEXT, ':context_id' => $contextId, ':email' => $email]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /* ---------------- Randare ---------------- */

    private static function km(float $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    private static function kmLeft(float $kmLeft): string
    {
        return $kmLeft < 0 ? 'depășit cu ' . self::km(abs($kmLeft)) . ' km' : self::km($kmLeft) . ' km';
    }

    private static function h(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    private function renderHtml(array $c): string
    {
        $stats = $c['stats'];
        $font = "font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif;";
        $cell = 'padding:8px 10px;border-bottom:1px solid #e2e8f0;font-size:13px;color:#0f172a;';
        $head = 'padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.04em;text-align:left;';

        $box = static fn (string $label, int $value, string $color, string $bg): string =>
            '<td style="padding:4px;width:25%;"><div style="background:' . $bg . ';border-radius:8px;padding:12px 14px;">'
            . '<div style="font-size:24px;font-weight:700;color:' . $color . ';">' . $value . '</div>'
            . '<div style="font-size:12px;color:#475569;">' . self::h($label) . '</div></div></td>';

        // Un rand per componenta, cu calculul complet pe coloane (ca panoul de detalii din Revizii).
        $alertTable = function (array $rows, string $title, string $accent) use ($head): string {
            if ($rows === []) {
                return '';
            }
            $dec = static fn (?float $v, int $d = 1): string => $v === null ? '—' : number_format($v, $d, ',', '.');
            $td = 'padding:7px 8px;border-bottom:1px solid #e2e8f0;font-size:12px;color:#0f172a;vertical-align:top;';
            $num = $td . 'text-align:right;white-space:nowrap;';
            $sub = 'display:block;font-size:10px;color:#64748b;font-weight:400;white-space:nowrap;';
            $th = str_replace('padding:8px 10px', 'padding:7px 8px', $head) . 'font-size:10px;';
            $thNum = $th . 'text-align:right;';

            $html = '<h3 style="margin:24px 0 8px;font-size:15px;color:' . $accent . ';">' . self::h($title) . ' (' . count($rows) . ')</h3>'
                . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;">'
                . '<tr>'
                . '<th style="' . $th . '">Nr. auto</th>'
                . '<th style="' . $th . '">Componentă</th>'
                . '<th style="' . $thNum . '">Km efectuați</th>'
                . '<th style="' . $thNum . '">Ore motor</th>'
                . '<th style="' . $thNum . '">Km din ore</th>'
                . '<th style="' . $thNum . '">Km total echivalenți</th>'
                . '<th style="' . $thNum . '">Interval</th>'
                . '<th style="' . $thNum . '">Km rămași</th>'
                . '</tr>';

            $factor = null;
            foreach ($rows as $row) {
                $calc = is_array($row['calc'] ?? null) ? $row['calc'] : null;
                $left = (float) $row['km_ramasi'];
                $withHours = $calc !== null && !empty($row['ore_aplicabile']) && !empty($row['ore_aplicate']);
                if ($withHours) {
                    $factor = (float) $row['factor'];
                }
                $initial = $calc !== null ? (float) ($calc['consum_initial_km'] ?? 0) : 0.0;
                $t0 = ($row['baseline_at'] ?? '') !== '' ? 'T0 ' . date('d.m.Y H:i', (int) strtotime((string) $row['baseline_at'])) : '';
                $missing = count((array) ($row['zile_lipsa'] ?? []));

                $html .= '<tr>'
                    . '<td style="' . $td . 'font-weight:700;white-space:nowrap;">' . self::h($row['nr'])
                    . '<span style="' . $sub . '">' . self::h(trim($row['marca'] . ' · ' . $row['vehicul_tip'], ' ·')) . '</span></td>'
                    . '<td style="' . $td . '">' . self::h($row['componenta'])
                    . '<span style="' . $sub . '">' . self::h($row['categorie_label'] . ($t0 !== '' ? ' · ' . $t0 : '')) . '</span></td>'
                    . '<td style="' . $num . '">' . ($calc !== null ? self::h($dec((float) $calc['km_efectuati'])) : '—')
                    . ($initial > 0 ? '<span style="' . $sub . '">+ ' . self::h($dec($initial)) . ' consum inițial</span>' : '') . '</td>'
                    . '<td style="' . $num . '">' . ($withHours ? self::h($dec((float) $calc['ore_motor'], 2)) . ' h' : '—') . '</td>'
                    . '<td style="' . $num . '">' . ($withHours ? self::h($dec((float) $calc['km_din_ore'])) : '—') . '</td>'
                    . '<td style="' . $num . 'font-weight:700;">' . ($calc !== null ? self::h($dec((float) $calc['km_total'])) : '—') . '</td>'
                    . '<td style="' . $num . '">' . self::h(self::km((float) $row['interval_km']))
                    . '<span style="' . $sub . '">prag ' . self::h(self::km((float) $row['prag_km'])) . '</span></td>'
                    . '<td style="' . $num . 'font-weight:700;color:' . ($left < 0 ? '#b91c1c' : '#b45309') . ';">'
                    . self::h((!empty($row['limita_superioara']) ? '≤ ' : '') . $dec($left))
                    . ($missing > 0 ? '<span style="' . $sub . 'color:#b45309;">⚠ ' . self::h($missing === 1 ? '1 zi' : $missing . ' zile') . ' fără telemetrie</span>' : '')
                    . '</td>'
                    . '</tr>';
            }

            $legend = 'Km total echivalenți = Km efectuați (SasFleet, de la T0)'
                . ($factor !== null ? ' + Km din ore (ore motor eligibile × ' . $dec($factor, 0) . ' km/oră)' : '')
                . ' [+ consum inițial]. Km rămași = Interval − Km total echivalenți; negativ = interval depășit. '
                . '„≤” = zile fără telemetrie: km efectuați sunt minimi, deci km rămași pot fi mai puțini.';

            return $html . '</table><p style="margin:6px 0 0;font-size:10px;color:#94a3b8;line-height:1.4;">' . self::h($legend) . '</p>';
        };

        $html = '<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . self::h('Raport săptămânal revizii') . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f1f5f9;' . $font . '">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f1f5f9;"><tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:980px;background:#ffffff;border-radius:12px;"><tr><td style="padding:24px 28px;' . $font . '">'
            . '<div style="font-size:12px;color:#64748b;">' . self::h(APP_NAME) . ' · Revizii</div>'
            . '<h1 style="margin:4px 0 2px;font-size:22px;color:#0f172a;">Raport săptămânal revizii</h1>'
            . '<div style="font-size:13px;color:#475569;">Săptămâna ' . self::h(str_replace('-W', ' / S', $stats['saptamana'])) . ' · generat ' . self::h(date('d.m.Y H:i', $c['now']))
            . ($c['last_sync'] ? ' · date GPS sincronizate ' . self::h(date('d.m.Y H:i', (int) strtotime((string) $c['last_sync']))) : '') . '</div>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-top:18px;"><tr>'
            . $box('Necesită revizie', $stats['necesita'], '#b91c1c', '#fef2f2')
            . ($c['config']['status'] !== 'necesita' ? $box('Se apropie', $stats['se_apropie'], '#b45309', '#fffbeb') : '')
            . ($c['config']['history'] ? $box('Efectuate (7 zile)', $stats['efectuate'], '#15803d', '#f0fdf4') : '')
            . $box('Vehicule monitorizate', $stats['vehicule'], '#1d4ed8', '#eff6ff')
            . '</tr></table>';

        if ($c['categories'] !== []) {
            $html .= '<h3 style="margin:24px 0 8px;font-size:15px;color:#0f172a;">Pe categorii</h3>'
                . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;">'
                . '<tr><th style="' . $head . '">Categorie</th><th style="' . $head . 'text-align:right;">Necesită</th><th style="' . $head . 'text-align:right;">Se apropie</th>'
                . '<th style="' . $head . 'text-align:right;">Fără date</th><th style="' . $head . 'text-align:right;">În regulă</th></tr>';
            foreach ($c['categories'] as $category) {
                $counts = $category['counts'];
                $html .= '<tr><td style="' . $cell . 'font-weight:600;">' . self::h($category['label']) . '</td>'
                    . '<td style="' . $cell . 'text-align:right;color:#b91c1c;">' . (int) $counts[ReviziiCalculator::STATUS_DUE] . '</td>'
                    . '<td style="' . $cell . 'text-align:right;color:#b45309;">' . (int) $counts[ReviziiCalculator::STATUS_SOON] . '</td>'
                    . '<td style="' . $cell . 'text-align:right;color:#64748b;">' . (int) $counts[ReviziiCalculator::STATUS_NO_DATA] . '</td>'
                    . '<td style="' . $cell . 'text-align:right;color:#15803d;">' . (int) $counts[ReviziiCalculator::STATUS_OK] . '</td></tr>';
            }
            $html .= '</table>';
        }

        $html .= $alertTable($c['due'], 'Necesită revizie', '#b91c1c');
        if ($c['config']['status'] !== 'necesita') {
            $html .= $alertTable($c['soon'], 'Se apropie', '#b45309');
        }
        if ($c['due'] === [] && $c['soon'] === []) {
            $html .= '<p style="margin:24px 0 0;padding:12px 14px;background:#f0fdf4;border-radius:8px;color:#15803d;font-size:14px;">Nicio componentă nu necesită revizie și niciuna nu se apropie de prag.</p>';
        }

        if ($c['config']['history']) {
            $html .= '<h3 style="margin:24px 0 8px;font-size:15px;color:#15803d;">Revizii efectuate în ultimele 7 zile (' . count($c['history']) . ')</h3>';
            if ($c['history'] === []) {
                $html .= '<p style="margin:0;font-size:13px;color:#64748b;">Nicio revizie înregistrată în ultimele 7 zile.</p>';
            } else {
                $html .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;">'
                    . '<tr><th style="' . $head . '">Data</th><th style="' . $head . '">Nr. auto</th><th style="' . $head . '">Categorie</th><th style="' . $head . '">Componentă</th><th style="' . $head . '">Operator</th></tr>';
                foreach ($c['history'] as $row) {
                    $html .= '<tr><td style="' . $cell . 'white-space:nowrap;">' . self::h(date('d.m.Y H:i', (int) strtotime((string) $row['data']))) . '</td>'
                        . '<td style="' . $cell . 'font-weight:600;">' . self::h($row['nr']) . '</td>'
                        . '<td style="' . $cell . '">' . self::h($row['categorie_label']) . '</td>'
                        . '<td style="' . $cell . '">' . self::h($row['componenta']) . ($row['actiune'] ? ' · ' . self::h($row['actiune']) : '') . '</td>'
                        . '<td style="' . $cell . '">' . self::h($row['operator'] ?? '—') . '</td></tr>';
                }
                $html .= '</table>';
            }
        }

        $html .= '<p style="margin:28px 0 0;">'
            . '<a href="' . self::h($c['url']) . '" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:10px 18px;border-radius:8px;margin:0 8px 8px 0;">Deschide Revizii în aplicație</a>'
            . '</p>'
            . '<p style="margin:14px 0 0;font-size:12px;color:#475569;">&#128206; Raportul complet în Excel este atașat acestui email.</p>'
            . '<p style="margin:18px 0 0;font-size:11px;color:#94a3b8;">Raport generat automat de regula „Raport săptămânal revizii” (' . self::h(self::scheduleLabel($c['config'])) . '). Pragurile de km sunt cele configurate în Revizii.</p>'
            . '</td></tr></table></td></tr></table></body></html>';

        return $html;
    }

    private function renderText(array $c): string
    {
        $stats = $c['stats'];
        $lines = [
            'Raport săptămânal revizii — săptămâna ' . str_replace('-W', ' / S', $stats['saptamana']),
            'Generat ' . date('d.m.Y H:i', $c['now']),
            '',
            'Necesită revizie: ' . $stats['necesita'],
        ];
        if ($c['config']['status'] !== 'necesita') {
            $lines[] = 'Se apropie: ' . $stats['se_apropie'];
        }
        if ($c['config']['history']) {
            $lines[] = 'Efectuate în ultimele 7 zile: ' . $stats['efectuate'];
        }
        $lines[] = 'Vehicule monitorizate: ' . $stats['vehicule'];

        $section = static function (array $rows, string $title) use (&$lines): void {
            if ($rows === []) {
                return;
            }
            $lines[] = '';
            $lines[] = $title . ' (' . count($rows) . '):';
            foreach ($rows as $row) {
                $lines[] = '- ' . $row['nr'] . ' · ' . $row['categorie_label'] . ' · ' . $row['componenta'] . ' · ' . self::kmLeft((float) $row['km_ramasi']);
                $calc = $row['calc'] ?? null;
                if (!is_array($calc)) {
                    continue;
                }
                $dec = static fn (float $v, int $d = 1): string => number_format($v, $d, ',', '.');
                $lines[] = '    Km efectuați: ' . $dec((float) $calc['km_efectuati']);
                if ($row['ore_aplicabile'] && $row['ore_aplicate']) {
                    $lines[] = '    Ore motor eligibile: ' . $dec((float) $calc['ore_motor'], 2) . ' h × ' . $dec((float) $row['factor'], 0) . ' km/oră = ' . $dec((float) $calc['km_din_ore']) . ' km';
                }
                if ((float) ($calc['consum_initial_km'] ?? 0) > 0) {
                    $lines[] = '    Consum inițial: ' . $dec((float) $calc['consum_initial_km']);
                }
                $lines[] = '    Km total echivalenți: ' . $dec((float) $calc['km_total']) . ' / interval ' . self::km((float) $calc['interval_km']) . ' (prag notificare ' . self::km((float) $row['prag_km']) . ')';
                $lines[] = '    Km rămași: ' . ($row['limita_superioara'] ? '≤ ' : '') . $dec((float) $calc['km_ramasi'])
                    . ($row['baseline_at'] !== '' ? ' · T0 ' . date('d.m.Y H:i', (int) strtotime($row['baseline_at'])) : '');
            }
        };
        $section($c['due'], 'NECESITĂ REVIZIE');
        if ($c['config']['status'] !== 'necesita') {
            $section($c['soon'], 'SE APROPIE');
        }
        if ($c['config']['history'] && $c['history'] !== []) {
            $lines[] = '';
            $lines[] = 'EFECTUATE ÎN ULTIMELE 7 ZILE:';
            foreach ($c['history'] as $row) {
                $lines[] = '- ' . date('d.m.Y', (int) strtotime((string) $row['data'])) . ' · ' . $row['nr'] . ' · ' . $row['categorie_label'] . ' · ' . $row['componenta'];
            }
        }
        $lines[] = '';
        $lines[] = 'Deschide în aplicație: ' . $c['url'];
        $lines[] = 'Raportul complet în Excel este atașat acestui email.';
        $lines[] = '';
        $lines[] = APP_NAME;

        return implode("\n", $lines);
    }
}
