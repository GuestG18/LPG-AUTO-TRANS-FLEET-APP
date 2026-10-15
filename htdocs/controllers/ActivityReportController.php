<?php
declare(strict_types=1);

/**
 * Pagina "Rapoarte activitate utilizatori" (?page=rapoarte_activitate).
 * Doar administratori: garda din router (modul admin_only) + verificarea de aici,
 * aplicata identic pe pagina, pe datele JSON, pe detalii si pe exporturi.
 * Pagina doar citeste jurnalele; nu exista nicio actiune de modificare a lor.
 */
class ActivityReportController
{
    private const PDF_MAX_ROWS = 1500;

    private ActivityReportModel $model;

    public function __construct(PDO $db)
    {
        $this->model = new ActivityReportModel($db);
    }

    public function handle(string $action): void
    {
        if (!is_admin()) {
            access_deny_403();
        }

        try {
            $this->model->ensureSchema();
        } catch (Throwable $exception) {
            error_log('[ActivityReportController][schema] ' . $exception->getMessage());
        }

        switch ($action) {
            case 'data':
                $this->dataAction();
                return;
            case 'detail':
                $this->detailAction();
                return;
            case 'export':
                $this->exportAction();
                return;
            case 'views':
                $this->json(['ok' => true, 'views' => $this->model->listViews($this->userId())]);
                return;
            case 'view_save':
                $this->saveViewAction();
                return;
            case 'view_delete':
                $this->deleteViewAction();
                return;
            default:
                $this->indexAction();
        }
    }

    private function indexAction(): void
    {
        $query = $_GET;
        $views = [];
        try {
            $views = $this->model->listViews($this->userId());
        } catch (Throwable $exception) {
            error_log('[ActivityReportController][views] ' . $exception->getMessage());
        }

        // ?view=ID deschide o vizualizare salvata (filtrele ei, peste valorile implicite).
        $activeView = null;
        $viewId = (int) ($query['view'] ?? 0);
        foreach ($views as $view) {
            if ($view['id'] === $viewId) {
                $activeView = $view;
                $query = array_merge($view['config'], ['view' => $viewId]);
                if (!empty($view['config']['period']) && $view['config']['period'] !== 'custom') {
                    [$query['from'], $query['to']] = $this->presetRange((string) $view['config']['period']);
                }
            }
        }

        $report = null;
        $error = null;
        try {
            $report = $this->model->buildReport($query);
        } catch (Throwable $exception) {
            error_log('[ActivityReportController][index] ' . $exception->getMessage());
            $error = 'Nu am putut încărca raportul de activitate.';
        }

        render('rapoarte_activitate/index.php', [
            'pageTitle'   => 'Rapoarte activitate utilizatori',
            'currentPage' => 'rapoarte_activitate',
            'report'      => $report,
            'error'       => $error,
            'views'       => $views,
            'activeView'  => $activeView,
            'state'       => [
                'period'  => (string) ($query['period'] ?? ($report !== null && !isset($query['from']) ? '30' : 'custom')),
                'mode'    => in_array((string) ($query['mode'] ?? 'table'), ['table', 'chart', 'pivot'], true) ? (string) ($query['mode'] ?? 'table') : 'table',
                'columns' => isset($query['columns']) ? array_values(array_filter(array_map('strval', (array) (is_string($query['columns']) ? explode(',', $query['columns']) : $query['columns'])))) : null,
                'view'    => $activeView['id'] ?? 0,
            ],
            'canExport'   => !function_exists('can') || can('rapoarte_activitate', 'export'),
        ]);
    }

    private function dataAction(): void
    {
        try {
            $this->json(['ok' => true, 'report' => $this->model->buildReport($_GET)]);
        } catch (Throwable $exception) {
            error_log('[ActivityReportController][data] ' . $exception->getMessage());
            $this->json(['ok' => false, 'message' => 'Nu am putut încărca datele raportului.'], 500);
        }
    }

    private function detailAction(): void
    {
        $detail = null;
        try {
            $detail = $this->model->detail((string) ($_GET['uid'] ?? ''));
        } catch (Throwable $exception) {
            error_log('[ActivityReportController][detail] ' . $exception->getMessage());
            $this->json(['ok' => false, 'message' => 'Nu am putut încărca detaliile acțiunii.'], 500);
        }
        if ($detail === null) {
            $this->json(['ok' => false, 'message' => 'Înregistrarea de jurnal nu a fost găsită.'], 404);
        }
        $this->json(['ok' => true, 'detail' => $detail]);
    }

    private function saveViewAction(): void
    {
        $this->requirePostWithCsrf();
        $config = json_decode((string) ($_POST['config'] ?? ''), true);
        if (!is_array($config)) {
            $this->json(['ok' => false, 'message' => 'Configurația vizualizării lipsește.'], 422);
        }
        try {
            $id = $this->model->saveView($this->userId(), (string) ($_POST['name'] ?? ''), $config);
            $this->json(['ok' => true, 'id' => $id, 'views' => $this->model->listViews($this->userId())]);
        } catch (InvalidArgumentException $exception) {
            $this->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            error_log('[ActivityReportController][view_save] ' . $exception->getMessage());
            $this->json(['ok' => false, 'message' => 'Vizualizarea nu a putut fi salvată.'], 500);
        }
    }

    private function deleteViewAction(): void
    {
        $this->requirePostWithCsrf();
        $this->model->deleteView($this->userId(), (int) ($_POST['id'] ?? 0));
        $this->json(['ok' => true, 'views' => $this->model->listViews($this->userId())]);
    }

    // ================================================================ export

    private function exportAction(): void
    {
        if (function_exists('can') && !can('rapoarte_activitate', 'export')) {
            access_deny_403();
        }
        $format = (string) ($_GET['format'] ?? 'csv');

        try {
            $export = $this->model->exportRows($_GET);
        } catch (Throwable $exception) {
            error_log('[ActivityReportController][export] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut genera exportul.');
            redirect(build_query_url(['page' => 'rapoarte_activitate']));
        }

        $f = $export['filters'];
        $base = 'rapoarte_activitate_' . ($f['module'] !== '' ? $f['module'] . '_' : '') . $f['from'] . '_' . $f['to'];
        $headers = ['Data', 'Ora', 'Utilizator', 'Rol', 'Modul / Pagină', 'Secțiune', 'Tip acțiune', 'Entitate / ID', 'Câmp modificat', 'Valoare veche', 'Valoare nouă', 'Toate modificările', 'Descriere', 'IP', 'Dispozitiv'];
        $line = static fn (array $r): array => [
            date('d.m.Y', (int) strtotime($r['ts'])), substr($r['ts'], 11, 8), $r['user_name'], $r['role_label'], $r['module_label'], $r['section'],
            $r['action_label'], $r['entity'] . ($r['record_id'] !== null && !str_contains($r['entity'], '#' . $r['record_id']) ? ' (#' . $r['record_id'] . ')' : ''),
            $r['fields_known'] ? ($r['field'] ?? '') : 'Informație indisponibilă în jurnal',
            $r['old'] ?? '', $r['new'] ?? '', $r['fields_known'] ? $r['changes_text'] : '', $r['description'], $r['ip'] ?? '', $r['device'],
        ];

        if ($format === 'xlsx') {
            $this->exportXlsx($export, $base, $headers, $line);
        } elseif ($format === 'pdf') {
            $this->exportPdf($export, $base, $headers, $line);
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $base . '.csv"');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers);
        foreach ($export['rows'] as $row) {
            fputcsv($out, $line($row));
        }
        fclose($out);
        exit;
    }

    private function exportXlsx(array $export, string $base, array $headers, callable $line): void
    {
        require_once __DIR__ . '/../services/SimpleXlsxWriter.php';
        $xlsx = new SimpleXlsxWriter();
        $sheet = $xlsx->addSheet('Activitate');
        $xlsx->setColumnWidths($sheet, [1 => 12, 2 => 9, 3 => 22, 4 => 14, 5 => 20, 6 => 18, 7 => 15, 8 => 24, 9 => 20, 10 => 20, 11 => 20, 12 => 50, 13 => 50, 14 => 15, 15 => 16]);
        $xlsx->addRow($sheet, [['v' => 'Rapoarte activitate utilizatori', 's' => 'title']]);
        $xlsx->addRow($sheet, [['v' => 'Generat la ' . date('d.m.Y H:i') . ' · ' . count($export['rows']) . ' acțiuni', 's' => 'muted']]);
        foreach ($this->model->describeFilters($export['filters']) as [$label, $value]) {
            $xlsx->addRow($sheet, [['v' => $label, 's' => 'kpi_label'], ['v' => $value, 's' => 'text']]);
        }
        $xlsx->addRow($sheet, []);
        $headerRow = $xlsx->addRow($sheet, array_map(static fn (string $h): array => ['v' => $h, 's' => 'th'], $headers));
        $xlsx->freezeRows($sheet, $headerRow);
        foreach ($export['rows'] as $row) {
            $xlsx->addRow($sheet, array_map(static fn ($v): array => ['v' => (string) $v, 's' => 'text'], $line($row)));
        }
        $last = $xlsx->nextRowNumber($sheet) - 1;
        if ($last > $headerRow) {
            $xlsx->setAutoFilter($sheet, 'A' . $headerRow . ':' . SimpleXlsxWriter::columnLetter(count($headers)) . $last);
        }

        $path = tempnam(sys_get_temp_dir(), 'rap');
        try {
            $xlsx->save($path);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $base . '.xlsx"');
            header('Content-Length: ' . (string) filesize($path));
            readfile($path);
        } finally {
            @unlink($path);
        }
        exit;
    }

    private function exportPdf(array $export, string $base, array $headers, callable $line): void
    {
        if (!class_exists('\\Dompdf\\Dompdf')) {
            flash_set('danger', 'Generatorul PDF (dompdf) nu este instalat.');
            redirect(build_query_url(['page' => 'rapoarte_activitate']));
        }
        $rows = $export['rows'];
        if (count($rows) > self::PDF_MAX_ROWS) {
            flash_set('warning', sprintf('Selecția are %d acțiuni; PDF-ul este limitat la %d. Restrânge filtrele sau folosește exportul Excel (fără limită).', count($rows), self::PDF_MAX_ROWS));
            redirect(build_query_url(['page' => 'rapoarte_activitate']));
        }

        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $counts = array_count_values(array_column($rows, 'action'));
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>
            @page { margin: 30px 24px 38px; }
            body { font-family: "DejaVu Sans", sans-serif; font-size: 7.6px; color: #0f172a; }
            h1 { font-size: 16px; margin: 0 0 2px; } .muted { color: #64748b; }
            .grid { width: 100%; border-collapse: collapse; margin: 8px 0; }
            .grid td { border: 1px solid #e2e8f0; padding: 3px 6px; } .grid td.k { background: #f8fafc; color: #475569; width: 14%; }
            .kpis td { text-align: center; border: 1px solid #e2e8f0; padding: 5px; } .kpis strong { display: block; font-size: 13px; }
            table.list { width: 100%; border-collapse: collapse; } table.list thead { display: table-header-group; }
            table.list th { background: #1d4ed8; color: #fff; padding: 4px 3px; text-align: left; font-size: 7px; }
            table.list td { border-bottom: 1px solid #e2e8f0; padding: 3px; vertical-align: top; } table.list tr { page-break-inside: avoid; }
            .b { font-weight: bold; } .a-create { color: #15803d; } .a-update { color: #1d4ed8; } .a-delete { color: #dc2626; } .a-status { color: #c2410c; } .a-login { color: #7c3aed; } .a-restore { color: #0e7490; }
        </style></head><body>';
        $html .= '<h1>Rapoarte activitate utilizatori</h1><div class="muted">Generat la ' . date('d.m.Y H:i') . ' · ' . count($rows) . ' acțiuni</div>';
        $html .= '<table class="grid">';
        foreach (array_chunk($this->model->describeFilters($export['filters']), 3) as $chunk) {
            $html .= '<tr>';
            foreach ($chunk as [$label, $value]) {
                $html .= '<td class="k">' . $e($label) . '</td><td>' . $e($value) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table><table class="grid kpis"><tr><td><strong>' . count($rows) . '</strong>Acțiuni totale</td>';
        foreach (ActivityReportModel::ACTIONS as $key => $label) {
            $html .= '<td><strong>' . (int) ($counts[$key] ?? 0) . '</strong>' . $e($label) . '</td>';
        }
        $html .= '</tr></table>';

        $columns = [0, 1, 2, 4, 6, 7, 8, 9, 10, 12, 13];
        $html .= '<table class="list"><thead><tr>';
        foreach ($columns as $i) {
            $html .= '<th>' . $e($headers[$i]) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $cells = $line($row);
            $html .= '<tr>';
            foreach ($columns as $i) {
                $cls = $i === 6 ? ' class="b a-' . $e($row['action']) . '"' : '';
                $html .= '<td' . $cls . '>' . $e($cells[$i]) . '</td>';
            }
            $html .= '</tr>';
        }
        if ($rows === []) {
            $html .= '<tr><td colspan="' . count($columns) . '" class="muted">Nicio acțiune pentru filtrele alese.</td></tr>';
        }
        $html .= '</tbody></table></body></html>';

        $dompdf = new \Dompdf\Dompdf(['defaultFont' => 'DejaVu Sans']);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 24, 'Pagina {PAGE_NUM} din {PAGE_COUNT}', $font, 7, [0.39, 0.45, 0.55]);

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $base . '.pdf"');
        echo $dompdf->output();
        exit;
    }

    // ============================================================ utilitare

    /** @return array{0:string,1:string} */
    private function presetRange(string $preset): array
    {
        $today = new DateTimeImmutable('today');

        return match ($preset) {
            '7' => [$today->modify('-6 days')->format('Y-m-d'), $today->format('Y-m-d')],
            'today' => [$today->format('Y-m-d'), $today->format('Y-m-d')],
            'yesterday' => [$today->modify('-1 day')->format('Y-m-d'), $today->modify('-1 day')->format('Y-m-d')],
            'month' => [$today->modify('first day of this month')->format('Y-m-d'), $today->format('Y-m-d')],
            'last_month' => [$today->modify('first day of last month')->format('Y-m-d'), $today->modify('last day of last month')->format('Y-m-d')],
            'year' => [$today->format('Y') . '-01-01', $today->format('Y-m-d')],
            default => [$today->modify('-30 days')->format('Y-m-d'), $today->format('Y-m-d')],
        };
    }

    private function requirePostWithCsrf(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['ok' => false, 'message' => 'Metodă nepermisă.'], 405);
        }
        if (!verify_csrf_token($_POST['_token'] ?? null)) {
            $this->json(['ok' => false, 'message' => 'Token CSRF invalid. Reîncarcă pagina.'], 419);
        }
    }

    private function userId(): int
    {
        return (int) (current_user()['id'] ?? 0);
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}
