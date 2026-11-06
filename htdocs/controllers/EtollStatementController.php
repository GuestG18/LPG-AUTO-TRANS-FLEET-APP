<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/EtollStatementModel.php';
require_once __DIR__ . '/../services/EtollStatementSyncService.php';

/**
 * Pagina "Situație eToll" (?page=etoll): sesiunile unei perioade (luna / an / interval) cu documentele
 * din ETOLL_SITUATIE_DOCUMENTE (implicit factura CC-P + anexa TA_10), alimentarile portofelului cu facturile lor, verificarea soldului si
 * exportul (Excel; ZIP cu Excel-ul si toate PDF-urile).
 *
 * Doar citeste: datele le aduce scripts/sync_etoll_situatie.php. Nu atinge importul
 * eToll -> Facturi -> cursa.
 */
class EtollStatementController
{
    private EtollStatementModel $model;
    private string $root;

    /** @var array<string, array<string, mixed>> filteredData() memorat pe perioada */
    private array $dataMemo = [];

    public function __construct(private PDO $db)
    {
        $this->model = new EtollStatementModel($db);
        $this->root = dirname(BASE_PATH);
    }

    public function handle(string $action): void
    {
        require_page_or_403('etoll');

        try {
            EtollStatementModel::ensureSchema($this->db);
        } catch (Throwable $exception) {
            error_log('[EtollStatementController][schema] ' . $exception->getMessage());
            flash_set('danger', 'Situația eToll necesită actualizarea bazei de date. Rulează database/migrations/2026_11_05_000001_etoll_situatie.sql.');
        }

        switch ($action) {
            case 'document':
                $this->documentAction();
                break;
            case 'alimentare':
                $this->topupInvoiceAction();
                break;
            case 'export_xlsx':
                $this->requireExport();
                $this->exportXlsxAction();
                break;
            case 'export_zip':
                $this->requireExport();
                $this->exportZipAction();
                break;
            case 'export_selected':
                $this->requireExport();
                $this->exportSelectedAction();
                break;
            default:
                $this->indexAction();
        }
    }

    private function indexAction(): void
    {
        $period = EtollStatementModel::period($_GET);
        $data = $this->filteredData($period);

        render('etoll/index.php', [
            'pageTitle' => 'Situație eToll',
            'currentPage' => 'etoll',
            'period' => $period,
            'filters' => $data['filters'],
            'selectedPlates' => $data['filters']['nr'],
            'vehicles' => $data['vehicles'],
            'beneficiaries' => $this->model->beneficiaries(array_merge($data['all_sessions'], $data['all_open'])),
            'summary' => $this->model->summary($period['de_la'], $period['pana_la']),
            'sessions' => $data['sessions'],
            'openSessions' => $data['open'],
            'totals' => EtollStatementModel::sessionTotals($data['sessions']),
            // Subsolul tabelului: filtrate = ce arata tabelul; toate = toata perioada, fara celelalte filtre.
            'tableTotals' => [
                'filtrate' => EtollStatementModel::tabTotals($data['sessions'], $data['open']),
                'toate' => EtollStatementModel::tabTotals($data['all_sessions'], $data['all_open']),
            ],
            'topups' => $this->model->topups($period['de_la'], $period['pana_la']),
            'balance' => $this->model->latestBalance(),
            'quietBalance' => $this->model->latestQuietBalance(),
            'lastSyncAt' => $this->model->lastSyncAt(),
            'canExport' => can('etoll', 'export'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Documente
    // -------------------------------------------------------------------------

    private function documentAction(): void
    {
        $sessionId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_GET['sesiune'] ?? '')) ?? '';
        $kind = in_array($_GET['tip'] ?? '', ['factura', 'anexa'], true) ? (string) $_GET['tip'] : 'desfasurator';
        $row = $sessionId !== '' ? $this->model->document($sessionId) : null;
        $path = $row !== null ? EtollStatementSyncService::resolvePath($this->root, $row[$kind . '_path'] ?? null) : null;

        $this->sendPdf($path, $row !== null ? $this->sessionFileName($row, $kind) : 'document.pdf');
    }

    private function topupInvoiceAction(): void
    {
        $invoiceId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_GET['id'] ?? '')) ?? '';
        $row = $invoiceId !== '' ? $this->model->topupInvoice($invoiceId) : null;
        $path = $row !== null ? EtollStatementSyncService::resolvePath($this->root, $row['pdf_path'] ?? null) : null;

        $this->sendPdf($path, $row !== null ? $this->topupFileName($row) : 'factura.pdf');
    }

    private function sendPdf(?string $path, string $name): void
    {
        if ($path === null) {
            http_response_code(404);
            render('errors/404.php', ['pageTitle' => 'Document negăsit', 'currentPage' => 'etoll']);

            return;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', $name) . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }

    /** eToll_B437NET_2026-10-29_CC-P2601612676_anexa.pdf */
    private function sessionFileName(array $row, string $kind): string
    {
        return sprintf(
            'eToll_%s_%s_%s_%s.pdf',
            preg_replace('/[^A-Z0-9]+/', '', strtoupper((string) ($row['nr_inmatriculare'] ?? ''))) ?: 'sesiune',
            substr((string) ($row['inchisa_la'] ?? $row['sfarsit'] ?? ''), 0, 10),
            preg_replace('/[^A-Za-z0-9-]+/', '', (string) ($row['numar_document'] ?? '')) ?: substr((string) $row['session_id'], 0, 8),
            $kind
        );
    }

    private function topupFileName(array $row): string
    {
        return sprintf('eToll_alimentare_%s_%s.pdf', substr((string) $row['data'], 0, 10), preg_replace('/[^A-Za-z0-9-]+/', '', (string) ($row['numar_document'] ?? '')) ?: substr((string) $row['invoice_id'], 0, 8));
    }

    // -------------------------------------------------------------------------
    // Export
    // -------------------------------------------------------------------------

    private function exportXlsxAction(): void
    {
        [$period, $path] = $this->buildXlsx();
        try {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $this->exportBaseName($period) . '.xlsx"');
            header('Content-Length: ' . (string) filesize($path));
            readfile($path);
        } finally {
            @unlink($path);
        }
        exit;
    }

    /**
     * Arhiva perioadei: Excel-ul + un folder pe document activ (facturi/, anexe/...) + alimentari/. PDF-urile se adauga
     * necomprimate (sunt deja comprimate), ca arhiva unui an sa se faca repede.
     */
    private function exportZipAction(): void
    {
        if (!class_exists(ZipArchive::class)) {
            flash_set('danger', 'Extensia PHP zip lipsește pe server: arhiva nu poate fi creată.');
            redirect(build_query_url(['page' => 'etoll']));
        }
        @set_time_limit(900);

        // Intai numele fisierelor din arhiva, ca Excel-ul sa aiba link-uri (HYPERLINK relativ) catre ele.
        $period = EtollStatementModel::period($_GET);
        $files = [];
        $links = ['sesiune' => [], 'alimentare' => []];
        $reserve = function (?string $relative, string $name) use (&$files): ?string {
            $path = EtollStatementSyncService::resolvePath($this->root, $relative);
            if ($path === null) {
                return null;
            }
            // Doua sesiuni pot avea acelasi nume (acelasi camion, aceeasi zi, document CC-P lipsa).
            $final = $name;
            for ($i = 2; isset($files[$final]); $i++) {
                $final = preg_replace('/\.pdf$/', '_' . $i . '.pdf', $name);
            }
            $files[$final] = $path;

            return $final;
        };
        foreach ($this->filteredData($period)['sessions'] as $row) {
            $row['session_id'] = (string) $row['session_id'];
            foreach (EtollStatementModel::documentKinds() as $kind => [, $folder]) {
                $links['sesiune'][$row['session_id']][$kind] = $reserve($row[$kind . '_path'], $folder . '/' . $this->sessionFileName($row, $kind));
            }
        }
        foreach ($this->model->topups($period['de_la'], $period['pana_la']) as $row) {
            if ($row['factura'] !== null) {
                $links['alimentare'][(string) $row['factura']['invoice_id']] = $reserve($row['factura']['pdf_path'], 'alimentari/' . $this->topupFileName($row['factura']));
            }
        }

        [$period, $xlsxPath] = $this->buildXlsx($links);
        $base = $this->exportBaseName($period);
        $zipPath = (string) tempnam(sys_get_temp_dir(), 'etz');
        $zip = new ZipArchive();
        try {
            if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Arhiva nu a putut fi creată.');
            }
            $zip->addFile($xlsxPath, $base . '.xlsx');
            foreach ($files as $name => $path) {
                $zip->addFile($path, $name);
                $zip->setCompressionName($name, ZipArchive::CM_STORE);
            }
            $zip->close();

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $base . '.zip"');
            header('Content-Length: ' . (string) filesize($zipPath));
            readfile($zipPath);
        } finally {
            @unlink($xlsxPath);
            @unlink($zipPath);
        }
        exit;
    }

    /**
     * @param array{sesiune: array<string, array<string, ?string>>, alimentare: array<string, ?string>}|null $zipLinks
     *        numele PDF-urilor din arhiva (exportul ZIP): celulele devin link-uri relative catre ele.
     *        null (doar Excel): link-urile duc la documentul din aplicatie.
     * @return array{0: array<string, string>, 1: string} [perioada, fisierul xlsx temporar]
     */
    private function buildXlsx(?array $zipLinks = null): array
    {
        require_once __DIR__ . '/../services/SimpleXlsxWriter.php';

        $period = EtollStatementModel::period($_GET);
        $data = $this->filteredData($period);
        $plates = $data['filters']['nr'];
        $summary = $this->model->summary($period['de_la'], $period['pana_la']);
        $sessions = $data['sessions'];
        $topups = $this->model->topups($period['de_la'], $period['pana_la']);
        $balance = $this->model->latestBalance();
        $quiet = $this->model->latestQuietBalance();
        $at = static fn(?string $value): string => $value !== null && $value !== '' ? date('d.m.Y H:i', strtotime($value)) : '';
        $money = static fn(mixed $value): array => ['v' => $value === null ? '' : round((float) $value, 2), 's' => 'money'];
        $kinds = EtollStatementModel::documentKinds();
        // =HYPERLINK("anexe/....pdf";"Anexă"): se deschide din folderul arhivei dezarhivate.
        $link = static fn(?string $target, string $label): mixed => $target === null || $target === ''
            ? 'lipsă'
            : ['v' => $label, 's' => 'link', 'f' => 'HYPERLINK("' . str_replace('"', '""', $target) . '","' . $label . '")'];
        $sessionLink = function (array $row, string $kind, string $label) use ($zipLinks, $link): mixed {
            if (empty($row[$kind . '_path'])) {
                return 'lipsă';
            }
            if ($zipLinks !== null) {
                return $link($zipLinks['sesiune'][(string) $row['session_id']][$kind] ?? null, $label);
            }

            return $link(absolute_query_url(['page' => 'etoll', 'action' => 'document', 'sesiune' => (string) $row['session_id'], 'tip' => $kind]), $label);
        };
        $topupLink = function (?array $invoice) use ($zipLinks, $link): mixed {
            if ($invoice === null || empty($invoice['pdf_path'])) {
                return '';
            }
            if ($zipLinks !== null) {
                return $link($zipLinks['alimentare'][(string) $invoice['invoice_id']] ?? null, 'Factură');
            }

            return $link(absolute_query_url(['page' => 'etoll', 'action' => 'alimentare', 'id' => (string) $invoice['invoice_id']]), 'Factură');
        };

        $xlsx = new SimpleXlsxWriter();

        // Rezumat
        $sheet = $xlsx->addSheet('Rezumat');
        $xlsx->setColumnWidths($sheet, [44, 18, 18]);
        $xlsx->addRow($sheet, [['v' => 'Situație eToll — ' . $period['eticheta'] . ($plates !== [] ? ' — ' . implode(', ', $plates) : ''), 's' => 'title']]);
        if ($this->filterDescription($data['filters']) !== '') {
            $xlsx->addRow($sheet, [['v' => 'Filtre: ' . $this->filterDescription($data['filters']), 's' => 'muted']]);
        }
        $xlsx->addRow($sheet, [['v' => 'Generat ' . date('d.m.Y H:i') . '; date sincronizate ' . $at($this->model->lastSyncAt()), 's' => 'muted']]);
        $xlsx->addRow($sheet, [['v' => $zipLinks !== null
            ? 'Link-urile din foile Sesiuni și Alimentari deschid PDF-urile din folderele de lângă acest fișier: dezarhivați întâi arhiva.'
            : 'Link-urile din foile Sesiuni și Alimentari deschid documentele în aplicație (cu autentificare).', 's' => 'muted']]);
        $xlsx->addRow($sheet, []);
        $xlsx->addRow($sheet, [['v' => 'Portofel (toate camioanele)', 's' => 'th'], ['v' => 'Nr.', 's' => 'th_num'], ['v' => 'Lei', 's' => 'th_num']]);
        $xlsx->addRow($sheet, ['Sold la început', '', $money($summary['sold_inceput'])]);
        $xlsx->addRow($sheet, ['+ Alimentări', ['v' => $summary['alimentari']['n'], 's' => 'int'], $money($summary['alimentari']['suma'])]);
        $xlsx->addRow($sheet, ['− Consum (sesiuni închise)', ['v' => $summary['consum']['n'], 's' => 'int'], $money($summary['consum']['suma'])]);
        $xlsx->addRow($sheet, [['v' => '= Sold la final', 's' => 'total'], ['v' => '', 's' => 'total'], ['v' => $summary['sold_final'], 's' => 'total_money']]);
        $xlsx->addRow($sheet, []);
        $xlsx->addRow($sheet, [['v' => 'TVA (din documentele eToll)', 's' => 'th'], ['v' => 'Fără TVA', 's' => 'th_num'], ['v' => 'TVA', 's' => 'th_num']]);
        $xlsx->addRow($sheet, ['Alimentări', $money($summary['alimentari_fara_tva']), $money($summary['alimentari_tva'])]);
        $xlsx->addRow($sheet, ['Consum' . ($summary['consum_fara_tva_lipsa'] > 0 ? ' (fără ' . $summary['consum_fara_tva_lipsa'] . ' sesiuni cu TVA încă necitit)' : ''), $money($summary['consum_fara_tva']), $money($summary['consum_tva'])]);
        $xlsx->addRow($sheet, []);
        $xlsx->addRow($sheet, [['v' => 'Verificări', 's' => 'th'], ['v' => '', 's' => 'th'], ['v' => '', 's' => 'th']]);
        $xlsx->addRow($sheet, ['Facturi de alimentare', ['v' => $summary['facturi_alimentare']['n'], 's' => 'int'], $money($summary['facturi_alimentare']['suma'])]);
        $xlsx->addRow($sheet, ['Facturi = alimentări', $summary['alimentari_ok'] ? 'DA' : 'NU', '']);
        $xlsx->addRow($sheet, ['Sesiuni cu toate documentele (' . mb_strtolower(implode(', ', array_column($kinds, 0))) . ')', ['v' => $summary['documente_complete'], 's' => 'int'], '']);
        foreach ($kinds as $kind => [$label]) {
            $xlsx->addRow($sheet, ['Sesiuni fără ' . mb_strtolower($label), ['v' => $summary['fara_' . $kind], 's' => 'int'], '']);
        }
        $xlsx->addRow($sheet, ['Sesiuni cu suma din document diferită', ['v' => $summary['suma_diferita'], 's' => 'int'], '']);
        if ($quiet !== null) {
            $xlsx->addRow($sheet, []);
            $xlsx->addRow($sheet, [['v' => 'Sold eToll fără sesiuni în curs (' . $at($quiet['verificat_la']) . ')', 's' => 'th'], ['v' => '', 's' => 'th'], ['v' => 'Lei', 's' => 'th_num']]);
            $xlsx->addRow($sheet, ['Raportat de eToll', '', $money($quiet['sold_etoll'])]);
            $xlsx->addRow($sheet, ['Calculat (alimentări − consum)', '', $money($quiet['sold_calculat'])]);
            $xlsx->addRow($sheet, ['Diferență', '', $money($quiet['diferenta'])]);
        }
        if ($balance !== null) {
            $xlsx->addRow($sheet, []);
            $xlsx->addRow($sheet, [['v' => 'Ultima verificare (' . $at($balance['verificat_la']) . ', ' . (int) $balance['sesiuni_in_curs'] . ' sesiuni în curs)', 's' => 'th'], ['v' => '', 's' => 'th'], ['v' => 'Lei', 's' => 'th_num']]);
            $xlsx->addRow($sheet, ['Raportat de eToll', '', $money($balance['sold_etoll'])]);
            $xlsx->addRow($sheet, ['Calculat (alimentări − consum − în curs)', '', $money($balance['sold_calculat'])]);
            $xlsx->addRow($sheet, ['Diferență (normală cât timp sunt sesiuni în curs)', '', $money($balance['diferenta'])]);
        }

        // Sesiuni
        $sheet = $xlsx->addSheet('Sesiuni');
        $headers = array_merge(
            ['Închisă la', 'Nr. auto', 'Remorcă', 'Început', 'Sfârșit', 'Origine', 'Destinație', 'Km tarifabili', 'Fără TVA (lei)', 'TVA (lei)', 'Cotă TVA %', 'Cu TVA (lei)', 'Total document', 'Document CC-P', 'Beneficiar', 'Cursă'],
            array_column($kinds, 0),
            ['Verificare', 'ID sesiune']
        );
        $numeric = ['Km tarifabili', 'Fără TVA (lei)', 'TVA (lei)', 'Cotă TVA %', 'Cu TVA (lei)', 'Total document'];
        $xlsx->setColumnWidths($sheet, array_merge([17, 11, 11, 17, 17, 34, 34, 12, 13, 11, 10, 12, 13, 18, 22, 9], array_fill(0, count($kinds), 13), [30, 34]));
        $header = $xlsx->addRow($sheet, array_map(static fn(string $h): array => ['v' => $h, 's' => in_array($h, $numeric, true) ? 'th_num' : 'th'], $headers));
        $xlsx->freezeRows($sheet, $header);
        $total = $totalNet = $totalVat = 0.0;
        foreach ($sessions as $row) {
            $total += (float) $row['suma'];
            $totalNet += (float) ($row['fara_tva'] ?? 0);
            $totalVat += (float) ($row['tva'] ?? 0);
            $documents = [];
            foreach ($kinds as $kind => [$label]) {
                $documents[] = $sessionLink($row, $kind, $label);
            }
            $xlsx->addRow($sheet, array_merge([
                $at($row['inchisa_la']), (string) $row['nr_inmatriculare'], (string) ($row['remorci'] ?? ''), $at($row['inceput']), $at($row['sfarsit']),
                (string) ($row['origine'] ?? ''), (string) ($row['destinatie'] ?? ''),
                ['v' => $row['km_tarifabil'] === null ? '' : (float) $row['km_tarifabil'], 's' => 'money'],
                $money($row['fara_tva']), $money($row['tva']), ['v' => $row['cota_tva'] === null ? '' : (float) $row['cota_tva'], 's' => 'int'],
                $money($row['suma']), $money($row['total_etoll']), (string) ($row['numar_document'] ?? ''),
                $row['cursa_id'] !== null ? ((string) ($row['beneficiar'] ?? '') ?: 'Fără beneficiar') : 'neasociată',
                $row['cursa_id'] !== null ? ['v' => (int) $row['cursa_id'], 's' => 'int'] : '',
            ], $documents, [$row['verificare']['text'], (string) $row['session_id']]));
        }
        $last = $xlsx->addRow($sheet, [
            ['v' => 'Total ' . count($sessions) . ' sesiuni', 's' => 'total'], '', '', '', '', '', '', '',
            ['v' => round($totalNet, 2), 's' => 'total_money'], ['v' => round($totalVat, 2), 's' => 'total_money'], '',
            ['v' => round($total, 2), 's' => 'total_money'],
        ]);
        $xlsx->setAutoFilter($sheet, 'A' . $header . ':' . SimpleXlsxWriter::columnLetter(count($headers)) . max($header, $last - 1));

        // Alimentari
        $sheet = $xlsx->addSheet('Alimentari');
        $xlsx->setColumnWidths($sheet, [17, 12, 20, 17, 12, 12, 11, 10, 26]);
        $header = $xlsx->addRow($sheet, [['v' => 'Data plății', 's' => 'th'], ['v' => 'Sumă (lei)', 's' => 'th_num'], ['v' => 'Factură', 's' => 'th'], ['v' => 'Data facturii', 's' => 'th'], ['v' => 'Total factură', 's' => 'th_num'], ['v' => 'Fără TVA (lei)', 's' => 'th_num'], ['v' => 'TVA (lei)', 's' => 'th_num'], ['v' => 'PDF', 's' => 'th'], ['v' => 'Verificare', 's' => 'th']]);
        $xlsx->freezeRows($sheet, $header);
        foreach ($topups as $row) {
            $invoice = $row['factura'];
            $xlsx->addRow($sheet, [
                $at($row['tx_id'] !== null ? $row['data'] : null), $money($row['suma']),
                (string) ($invoice['numar_document'] ?? ''), $at($invoice['data'] ?? null), $money($invoice['total'] ?? null),
                $money($invoice['fara_tva'] ?? null), $money($invoice['tva'] ?? null),
                $topupLink($invoice),
                $row['tx_id'] === null ? 'Factură fără alimentare în registru' : ($invoice === null ? 'Lipsește factura' : 'OK'),
            ]);
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'etx');
        $xlsx->save($path);

        return [$period, $path];
    }

    private function exportBaseName(array $period): string
    {
        $suffix = match ($period['tip']) {
            'an' => $period['an'],
            'interval' => $period['de_la'] . '_' . $period['pana_la'],
            default => $period['luna'],
        };
        $filters = $this->filters();
        $plates = $filters['nr'];
        $other = array_filter(array_diff_key($filters, ['nr' => true]));

        return 'Situatie_eToll_' . $suffix . match (count($plates)) {
            0 => '',
            1 => '_' . $plates[0],
            default => '_' . count($plates) . '_camioane',
        } . ($other !== [] ? '_filtrat' : '');
    }

    /**
     * Arhiva cu documentele sesiunilor bifate in tabel (POST: id-urile sunt lungi si pot fi multe).
     * Doar PDF-urile documentelor active (ETOLL_SITUATIE_DOCUMENTE), cu aceleasi nume ca in exportul ZIP.
     */
    private function exportSelectedAction(): void
    {
        $back = build_query_url(['page' => 'etoll']);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !verify_csrf_token($_POST['_token'] ?? null)) {
            flash_set('danger', 'Token CSRF invalid. Reîncearcă descărcarea.');
            redirect($back);
        }
        if (!class_exists(ZipArchive::class)) {
            flash_set('danger', 'Extensia PHP zip lipsește pe server: arhiva nu poate fi creată.');
            redirect($back);
        }
        $ids = [];
        foreach ((array) ($_POST['sesiuni'] ?? []) as $value) {
            $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $value) ?? '';
            if ($id !== '') {
                $ids[$id] = $id;
            }
        }
        @set_time_limit(600);
        $files = [];
        foreach ($this->model->documents(array_slice(array_values($ids), 0, 5000)) as $row) {
            foreach (EtollStatementModel::documentKinds() as $kind => [, $folder]) {
                $path = EtollStatementSyncService::resolvePath($this->root, $row[$kind . '_path'] ?? null);
                if ($path === null) {
                    continue;
                }
                $name = $folder . '/' . $this->sessionFileName($row, $kind);
                $final = $name;
                for ($i = 2; isset($files[$final]); $i++) {
                    $final = preg_replace('/\.pdf$/', '_' . $i . '.pdf', $name);
                }
                $files[$final] = $path;
            }
        }
        if ($files === []) {
            flash_set('warning', 'Sesiunile selectate nu au încă documente descărcate.');
            redirect($back);
        }

        $zipPath = (string) tempnam(sys_get_temp_dir(), 'ets');
        $zip = new ZipArchive();
        try {
            if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Arhiva nu a putut fi creată.');
            }
            foreach ($files as $name => $path) {
                $zip->addFile($path, $name);
                $zip->setCompressionName($name, ZipArchive::CM_STORE);
            }
            $zip->close();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="Situatie_eToll_documente_' . count($ids) . '_sesiuni_' . date('Y-m-d_His') . '.zip"');
            header('Content-Length: ' . (string) filesize($zipPath));
            readfile($zipPath);
        } finally {
            @unlink($zipPath);
        }
        exit;
    }

    /**
     * Sesiunile perioadei (inchise si in curs) cu filtrele paginii aplicate: aceeasi selectie
     * pentru pagina, Excel si ZIP. Memorata pe request (ZIP-ul construieste si Excel-ul).
     *
     * @return array{filters: array<string, array<int, string>>, vehicles: array<int, array<string, mixed>>, all_sessions: array<int, array<string, mixed>>, all_open: array<int, array<string, mixed>>, sessions: array<int, array<string, mixed>>, open: array<int, array<string, mixed>>}
     */
    private function filteredData(array $period): array
    {
        $key = $period['de_la'] . '|' . $period['pana_la'];
        if (isset($this->dataMemo[$key])) {
            return $this->dataMemo[$key];
        }
        $filters = $this->filters();
        $vehicles = $this->model->vehicles($period['de_la'], $period['pana_la']);
        $byPlate = array_column($vehicles, null, 'etoll_plate');
        $all = $this->model->sessions($period['de_la'], $period['pana_la']);
        $open = $this->model->openSessions($period['de_la'], $period['pana_la']);

        return $this->dataMemo[$key] = [
            'filters' => $filters,
            'vehicles' => $vehicles,
            'all_sessions' => $all,
            'all_open' => $open,
            'sessions' => EtollStatementModel::applyFilters($all, $filters, $byPlate),
            'open' => EtollStatementModel::applyFilters($open, $filters, $byPlate),
        ];
    }

    /**
     * Filtrele din URL: nr[] (nr. auto eToll), cap[] (capacitate reala "18.00"), cat[] (id categorie
     * capacitate), ben[] (id beneficiar), tip[] (tip transport), status[]; "0" = fara (beneficiar...).
     *
     * @return array<string, array<int, string>>
     */
    private function filters(): array
    {
        $list = static function (string $name, string $pattern): array {
            $raw = $_GET[$name] ?? [];
            $values = [];
            foreach (is_array($raw) ? $raw : [$raw] as $value) {
                $value = trim((string) $value);
                if ($value !== '' && preg_match($pattern, $value) === 1) {
                    $values[$value] = $value;
                }
            }

            return array_values($values);
        };

        return [
            'nr' => $this->plateFilter(),
            'cap' => $list('cap', '/^\d{1,4}(\.\d{1,2})?$/'),
            'cat' => $list('cat', '/^\d{1,10}$/'),
            'ben' => $list('ben', '/^\d{1,10}$/'),
            'tip' => array_values(array_filter($list('tip', '/^[a-z_0]{1,30}$/'), static fn(string $v): bool => $v === EtollStatementModel::NONE || isset(EtollStatementModel::TRANSPORT_TYPES[$v]))),
            'status' => array_values(array_filter($list('status', '/^[a-z_]{1,20}$/'), static fn(string $v): bool => isset(EtollStatementModel::STATUSES[$v]))),
        ] + $this->closedAtFilter();
    }

    /**
     * "Închisă la" de la / pana la (inchisa_de / inchisa_pana, din <input type="datetime-local">:
     * 2026-11-05T14:00), in interiorul perioadei. Capetele sunt incluse: pana la 14:00 = pana la 14:00:59.
     *
     * @return array{inchisa_de: array<int, string>, inchisa_pana: array<int, string>}
     */
    private function closedAtFilter(): array
    {
        $parse = static function (string $name): ?DateTimeImmutable {
            $value = trim((string) ($_GET[$name] ?? ''));
            $date = preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}$/', $value) === 1
                ? DateTimeImmutable::createFromFormat('!Y-m-d H:i', str_replace('T', ' ', $value))
                : false;

            return $date !== false ? $date : null;
        };
        $from = $parse('inchisa_de');
        $to = $parse('inchisa_pana');
        if ($from !== null && $to !== null && $to < $from) {
            [$from, $to] = [$to, $from];
        }

        return [
            'inchisa_de' => $from !== null ? [$from->format('Y-m-d H:i:00')] : [],
            'inchisa_pana' => $to !== null ? [$to->format('Y-m-d H:i:59')] : [],
        ];
    }

    /** Filtrele in afara de vehicule, pe scurt (randul de sub titlu in Excel). */
    private function filterDescription(array $filters): string
    {
        $parts = [];
        if ($filters['cap'] !== []) {
            $parts[] = 'capacitate ' . implode(', ', array_map(static fn(string $v): string => $v === EtollStatementModel::NONE ? 'necunoscută' : rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',') . ' t', $filters['cap']));
        }
        if ($filters['cat'] !== []) {
            $parts[] = count($filters['cat']) . ' categorii de capacitate';
        }
        if ($filters['ben'] !== []) {
            $names = $this->model->beneficiaries();
            $parts[] = 'beneficiari ' . implode(', ', array_map(static fn(string $v): string => $v === EtollStatementModel::NONE ? 'fără' : ($names[$v] ?? '#' . $v), $filters['ben']));
        }
        if ($filters['tip'] !== []) {
            $parts[] = 'transport ' . implode(', ', array_map(static fn(string $v): string => EtollStatementModel::TRANSPORT_TYPES[$v] ?? 'fără cursă', $filters['tip']));
        }
        if ($filters['status'] !== []) {
            $parts[] = 'status ' . implode(', ', array_map(static fn(string $v): string => EtollStatementModel::STATUSES[$v], $filters['status']));
        }
        if ($filters['inchisa_de'] !== [] || $filters['inchisa_pana'] !== []) {
            $parts[] = 'închisă ' . ($filters['inchisa_de'] !== [] ? 'de la ' . date('d.m.Y H:i', strtotime($filters['inchisa_de'][0])) : '')
                . ($filters['inchisa_pana'] !== [] ? ' până la ' . date('d.m.Y H:i', strtotime($filters['inchisa_pana'][0])) : '');
        }

        return implode('; ', $parts);
    }

    /** @return array<int, string> nr. auto alese in filtru (nr[]=B652NET&nr[]=...), normalizate; gol = toate */
    private function plateFilter(): array
    {
        $raw = $_GET['nr'] ?? [];
        $plates = [];
        foreach (is_array($raw) ? $raw : [$raw] as $value) {
            $plate = preg_replace('/[^A-Z0-9]+/', '', strtoupper((string) $value)) ?? '';
            if ($plate !== '') {
                $plates[$plate] = $plate;
            }
        }

        return array_values($plates);
    }

    private function requireExport(): void
    {
        if (!can('etoll', 'export')) {
            access_deny_403();
        }
    }
}
