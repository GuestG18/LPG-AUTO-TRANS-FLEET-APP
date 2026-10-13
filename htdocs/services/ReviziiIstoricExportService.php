<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/models/ReviziiIstoricModel.php';
require_once __DIR__ . '/SimpleXlsxWriter.php';

/**
 * Exporturile din Revizii → Istoric revizii (Excel / PDF) si rapoartele generate.
 *
 * Ambele formate citesc ACELASI set filtrat ca pagina, complet (ReviziiIstoricModel::iterate),
 * nu pagina vizibila. PDF-ul are o limita de randuri: peste ea se refuza explicit (cu
 * recomandarea Excel), nu se taie pe tacute.
 */
class ReviziiIstoricExportService
{
    public const PDF_MAX_ROWS = 3000;

    private ReviziiIstoricModel $model;

    public function __construct(ReviziiIstoricModel $model)
    {
        $this->model = $model;
    }

    /**
     * Descrierea filtrelor aplicate, pentru antetul exporturilor.
     *
     * @return array<int, array{0:string, 1:string}> [eticheta, valoare]
     */
    public function describeFilters(array $filters): array
    {
        $options = $this->model->filterOptions();
        $vehicleNames = [];
        foreach ($options['vehicule'] as $vehicle) {
            $vehicleNames[$vehicle['id']] = $vehicle['nr'];
        }
        $operatorNames = [];
        foreach ($options['operatori'] as $operator) {
            $operatorNames[$operator['id']] = $operator['nume'];
        }

        $operator = 'Toți operatorii';
        if ($filters['operator'] === ReviziiIstoricModel::OPERATOR_NONE) {
            $operator = 'Nespecificat';
        } elseif ($filters['operator'] !== null) {
            $operator = $operatorNames[$filters['operator']] ?? ('#' . $filters['operator']);
        }

        return [
            ['Perioadă', $this->periodLabel($filters)],
            ['Categorii', $filters['categorii'] === [] ? 'Toate categoriile' : implode(', ', array_map(static fn (string $tab): string => ReviziiModel::TABS[$tab]['label'], $filters['categorii']))],
            ['Vehicule', $filters['vehicule'] === [] ? 'Întreaga flotă' : implode(', ', array_map(static fn (int $id): string => $vehicleNames[$id] ?? ('#' . $id), $filters['vehicule']))],
            ['Status', $filters['status'] === '' ? 'Toate statusurile' : ReviziiIstoricModel::STATUSES[$filters['status']]],
            ['Operator', $operator],
            ['Căutare', $filters['q'] !== '' ? '„' . $filters['q'] . '”' : '—'],
        ];
    }

    public function periodLabel(array $filters): string
    {
        $fmt = static fn (?string $d): string => $d !== null ? date('d.m.Y', (int) strtotime($d)) : '';
        if ($filters['de_la'] === null && $filters['pana_la'] === null) {
            return 'Tot istoricul';
        }
        if ($filters['de_la'] === null) {
            return 'Până la ' . $fmt($filters['pana_la']);
        }
        if ($filters['pana_la'] === null) {
            return 'De la ' . $fmt($filters['de_la']);
        }

        return $fmt($filters['de_la']) . ' – ' . $fmt($filters['pana_la']);
    }

    /** Valoarea Excel (numar serial) a unui moment local „Y-m-d H:i:s”. */
    private static function excelDate(string $value): float
    {
        $moment = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        $epoch = new DateTimeImmutable('1899-12-30 00:00:00', new DateTimeZone('UTC'));

        return ($moment->getTimestamp() - $epoch->getTimestamp()) / 86400;
    }

    /**
     * Fisierul Excel: foaia „Rezumat” (titlu, perioada, filtre, statistici, pe categorii) si
     * foaia „Istoric” (toate interventiile, cu filtru automat).
     *
     * @param array{observatii?:bool, documente?:bool} $options
     * @return int numarul de interventii exportate
     */
    public function writeXlsx(array $filters, string $path, string $title, array $options = []): int
    {
        $withNotes = $options['observatii'] ?? true;
        $withDocs = $options['documente'] ?? true;
        $kpi = $this->model->kpis($filters);
        $categories = $this->model->byCategory($filters);

        $xlsx = new SimpleXlsxWriter();
        $summary = $xlsx->addSheet('Rezumat');
        $xlsx->setColumnWidths($summary, [1 => 30, 2 => 60]);
        $xlsx->addRow($summary, [['v' => $title, 's' => 'title']]);
        $xlsx->addRow($summary, [['v' => 'Generat la ' . date('d.m.Y H:i'), 's' => 'muted']]);
        $xlsx->addRow($summary, []);
        $xlsx->addRow($summary, [['v' => 'Filtre aplicate', 's' => 'section'], ['v' => '', 's' => 'section']]);
        foreach ($this->describeFilters($filters) as [$label, $value]) {
            $xlsx->addRow($summary, [['v' => $label, 's' => 'kpi_label'], ['v' => $value, 's' => 'text']]);
        }
        $xlsx->addRow($summary, []);
        $xlsx->addRow($summary, [['v' => 'Rezumat', 's' => 'section'], ['v' => '', 's' => 'section']]);
        $pct = static fn (int $n): string => $kpi['total'] > 0 ? ' (' . round($n / $kpi['total'] * 100) . '%)' : '';
        $xlsx->addRow($summary, [['v' => 'Intervenții totale', 's' => 'kpi_label'], ['v' => $kpi['total'], 's' => 'kpi_value']]);
        $xlsx->addRow($summary, [['v' => 'Finalizate / verificate', 's' => 'kpi_label'], ['v' => $kpi['finalizate'] . $pct($kpi['finalizate']), 's' => 'kpi_value']]);
        $xlsx->addRow($summary, [['v' => 'Cu documente atașate', 's' => 'kpi_label'], ['v' => $kpi['cu_documente'] . $pct($kpi['cu_documente']), 's' => 'kpi_value']]);
        $xlsx->addRow($summary, [['v' => 'Vehicule cu istoric', 's' => 'kpi_label'], ['v' => $kpi['vehicule'], 's' => 'kpi_value']]);
        $xlsx->addRow($summary, []);
        $xlsx->addRow($summary, [['v' => 'Pe categorii', 's' => 'section'], ['v' => '', 's' => 'section']]);
        foreach ($categories as $tab => $count) {
            $xlsx->addRow($summary, [['v' => ReviziiModel::TABS[$tab]['label'], 's' => 'kpi_label'], ['v' => $count . $pct($count), 's' => 'kpi_value']]);
        }

        $sheet = $xlsx->addSheet('Istoric');
        $headers = ['Nr.', 'Dată și oră (T0)', 'Vehicul', 'Tip vehicul', 'Categorie', 'Componentă / Punte', 'Tip intervenție', 'Km la intervenție', 'Referință', 'Operator', 'Status', 'Documente'];
        $widths = [6, 18, 14, 16, 15, 24, 16, 16, 18, 20, 16, 11];
        if ($withDocs) {
            $headers[] = 'Documente atașate';
            $widths[] = 40;
        }
        if ($withNotes) {
            $headers[] = 'Observații';
            $widths[] = 45;
        }
        array_push($headers, 'Km consumați în ciclul încheiat', 'Interval km (la data intervenției)');
        array_push($widths, 18, 18);
        $xlsx->setColumnWidths($sheet, array_combine(range(1, count($widths)), $widths));
        $xlsx->addRow($sheet, [['v' => $title, 's' => 'title']]);
        $xlsx->addRow($sheet, [['v' => 'Perioadă: ' . $this->periodLabel($filters) . ' · generat la ' . date('d.m.Y H:i'), 's' => 'muted']]);
        $headerRow = $xlsx->addRow($sheet, array_map(static fn (string $h, int $i): array => ['v' => $h, 's' => in_array($i, [0, 7, 11], true) || $i >= count($headers) - 2 ? 'th_num' : 'th'], $headers, array_keys($headers)));
        $xlsx->freezeRows($sheet, $headerRow);
        $dateStyle = $xlsx->numberStyle('dd.mm.yyyy hh:mm');
        $kmStyle = $xlsx->numberStyle('#,##0');

        $count = 0;
        $batch = [];
        $flush = function () use (&$batch, &$count, $xlsx, $sheet, $withDocs, $withNotes, $dateStyle, $kmStyle): void {
            $names = $withDocs ? $this->model->documentNames(array_column($batch, 'id')) : [];
            foreach ($batch as $row) {
                $count++;
                $cells = [
                    ['v' => $count, 's' => 'int'],
                    ['v' => self::excelDate($row['data']), 's' => $dateStyle],
                    ['v' => $row['nr'] . ($row['vehicul_inactiv'] ? ' (inactiv)' : ''), 's' => 'text'],
                    ['v' => $row['vehicul_tip'], 's' => 'text'],
                    ['v' => $row['categorie_label'], 's' => 'text'],
                    ['v' => $row['componenta'], 's' => 'text'],
                    ['v' => $row['tip_interventie'], 's' => 'text'],
                    $row['km'] !== null ? ['v' => $row['km'], 's' => $kmStyle] : ['v' => '—', 's' => 'text_num'],
                    ['v' => $row['referinta'] ?? '—', 's' => 'text'],
                    ['v' => $row['operator'] ?? 'Nespecificat', 's' => 'text'],
                    ['v' => $row['status_label'], 's' => 'text'],
                    ['v' => $row['documente'], 's' => 'int'],
                ];
                if ($withDocs) {
                    $cells[] = ['v' => implode(', ', $names[$row['id']] ?? []), 's' => 'text'];
                }
                if ($withNotes) {
                    $cells[] = ['v' => $row['observatii'], 's' => 'text'];
                }
                $cells[] = $row['km_consumati'] !== null ? ['v' => round($row['km_consumati']), 's' => $kmStyle] : ['v' => '—', 's' => 'text_num'];
                $cells[] = ['v' => $row['interval_km'], 's' => $kmStyle];
                $xlsx->addRow($sheet, $cells);
            }
            $batch = [];
        };
        foreach ($this->model->iterate($filters) as $row) {
            $batch[] = $row;
            if (count($batch) >= 500) {
                $flush();
            }
        }
        $flush();

        if ($count === 0) {
            $xlsx->addRow($sheet, [['v' => 'Nicio intervenție pentru filtrele alese.', 's' => 'muted']]);
        } else {
            $xlsx->setAutoFilter($sheet, 'A' . $headerRow . ':' . SimpleXlsxWriter::columnLetter(count($headers)) . ($headerRow + $count));
        }
        $xlsx->save($path);

        return $count;
    }

    /**
     * PDF A4 peisaj: titlu, perioada, filtre, rezumat, pe categorii si istoricul complet;
     * antetul tabelului se repeta pe fiecare pagina, paginile sunt numerotate.
     *
     * @param array{observatii?:bool, documente?:bool} $options
     */
    public function writePdf(array $filters, string $path, string $title, array $options = []): int
    {
        if (!class_exists('\\Dompdf\\Dompdf')) {
            throw new RuntimeException('Generatorul PDF (dompdf) nu este instalat.');
        }
        $kpi = $this->model->kpis($filters);
        if ($kpi['total'] > self::PDF_MAX_ROWS) {
            throw new InvalidArgumentException(sprintf(
                'Selecția are %d intervenții; PDF-ul este limitat la %d. Restrânge perioada sau folosește exportul Excel (fără limită).',
                $kpi['total'],
                self::PDF_MAX_ROWS
            ));
        }
        $withNotes = $options['observatii'] ?? true;
        $withDocs = $options['documente'] ?? true;
        $categories = $this->model->byCategory($filters);
        $rows = iterator_to_array($this->model->iterate($filters), false);
        $names = $withDocs ? $this->model->documentNames(array_column($rows, 'id')) : [];

        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $km = static fn (?float $v): string => $v !== null ? number_format($v, 0, ',', '.') : '—';
        $pct = static fn (int $n): string => $kpi['total'] > 0 ? round($n / $kpi['total'] * 100) . '%' : '—';

        $html = '<!doctype html><html><head><meta charset="utf-8"><style>
            @page { margin: 34px 28px 40px; }
            body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5px; color: #0f172a; }
            h1 { font-size: 17px; margin: 0 0 2px; }
            .muted { color: #64748b; }
            .grid { width: 100%; border-collapse: collapse; margin: 8px 0 10px; }
            .grid td { border: 1px solid #e2e8f0; padding: 4px 6px; vertical-align: top; }
            .grid td.k { background: #f8fafc; color: #475569; width: 18%; }
            .kpis td { text-align: center; border: 1px solid #e2e8f0; padding: 6px; }
            .kpis strong { display: block; font-size: 15px; }
            h2 { font-size: 11px; margin: 10px 0 4px; }
            table.list { width: 100%; border-collapse: collapse; }
            table.list thead { display: table-header-group; }
            table.list th { background: #1e3a8a; color: #fff; font-weight: bold; padding: 4px 3px; text-align: left; font-size: 7.8px; }
            table.list td { border-bottom: 1px solid #e2e8f0; padding: 3px; vertical-align: top; }
            table.list tr { page-break-inside: avoid; }
            .num { text-align: right; }
            .st-finalizat { color: #166534; } .st-verificat { color: #1d4ed8; } .st-date_incomplete { color: #92400e; }
        </style></head><body>';
        $html .= '<h1>' . $e($title) . '</h1><div class="muted">Generat la ' . date('d.m.Y H:i') . ' · Perioadă: ' . $e($this->periodLabel($filters)) . '</div>';

        $html .= '<table class="grid">';
        foreach (array_chunk($this->describeFilters($filters), 3) as $chunk) {
            $html .= '<tr>';
            foreach ($chunk as [$label, $value]) {
                $html .= '<td class="k">' . $e($label) . '</td><td>' . $e($value) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';

        $html .= '<table class="grid kpis"><tr>'
            . '<td><strong>' . $kpi['total'] . '</strong>Intervenții totale</td>'
            . '<td><strong>' . $kpi['finalizate'] . '</strong>Finalizate / verificate (' . $pct($kpi['finalizate']) . ')</td>'
            . '<td><strong>' . $kpi['cu_documente'] . '</strong>Cu documente atașate (' . $pct($kpi['cu_documente']) . ')</td>'
            . '<td><strong>' . $kpi['vehicule'] . '</strong>Vehicule cu istoric</td>';
        foreach ($categories as $tab => $count) {
            $html .= '<td><strong>' . $count . '</strong>' . $e(ReviziiModel::TABS[$tab]['label']) . ' (' . $pct($count) . ')</td>';
        }
        $html .= '</tr></table>';

        $html .= '<h2>Istoric intervenții</h2><table class="list"><thead><tr>'
            . '<th>#</th><th>Dată</th><th>Vehicul</th><th>Categorie</th><th>Componentă / Punte</th><th>Tip intervenție</th>'
            . '<th class="num">Km la interv.</th><th>Referință</th><th>Operator</th><th>Status</th><th class="num">Doc.</th>'
            . ($withDocs ? '<th>Documente atașate</th>' : '') . ($withNotes ? '<th>Observații</th>' : '') . '</tr></thead><tbody>';
        foreach ($rows as $i => $row) {
            $html .= '<tr><td>' . ($i + 1) . '</td>'
                . '<td>' . $e(date('d.m.Y H:i', (int) strtotime($row['data']))) . '</td>'
                . '<td><strong>' . $e($row['nr']) . '</strong>' . ($row['vehicul_inactiv'] ? ' <span class="muted">(inactiv)</span>' : '') . '</td>'
                . '<td>' . $e($row['categorie_label']) . '</td>'
                . '<td>' . $e($row['componenta']) . '</td>'
                . '<td>' . $e($row['tip_interventie']) . '</td>'
                . '<td class="num">' . $km($row['km'] !== null ? (float) $row['km'] : null) . '</td>'
                . '<td>' . $e($row['referinta'] ?? '—') . '</td>'
                . '<td>' . $e($row['operator'] ?? 'Nespecificat') . '</td>'
                . '<td class="st-' . $e($row['status']) . '">' . $e($row['status_label']) . '</td>'
                . '<td class="num">' . $row['documente'] . '</td>'
                . ($withDocs ? '<td>' . $e(implode(', ', $names[$row['id']] ?? [])) . '</td>' : '')
                . ($withNotes ? '<td>' . $e($row['observatii']) . '</td>' : '')
                . '</tr>';
        }
        if ($rows === []) {
            $html .= '<tr><td colspan="13" class="muted">Nicio intervenție pentru filtrele alese.</td></tr>';
        }
        $html .= '</tbody></table></body></html>';

        $dompdf = new \Dompdf\Dompdf(['defaultFont' => 'DejaVu Sans']);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 26, 'Pagina {PAGE_NUM} din {PAGE_COUNT}', $font, 7.5, [0.39, 0.45, 0.55]);
        $canvas->page_text(28, $canvas->get_height() - 26, $title, $font, 7.5, [0.39, 0.45, 0.55]);
        if (file_put_contents($path, $dompdf->output()) === false) {
            throw new RuntimeException('PDF-ul nu a putut fi scris.');
        }

        return count($rows);
    }
}
