<?php
declare(strict_types=1);

class DriverActivityHistoryController
{
    private DriverActivityHistoryModel $model;

    public function __construct(PDO $db)
    {
        $this->model = new DriverActivityHistoryModel($db);
    }

    public function handle(string $action): void
    {
        match ($action) {
            'export_excel' => $this->exportExcel(),
            'export_pdf' => $this->exportPdf(),
            default => $this->index(),
        };
    }

    private function index(): void
    {
        $filters = $this->resolveFilters($_GET);
        $driverId = (int) ($filters['driver_id'] ?? 0);
        if ($driverId <= 0) {
            $driverId = $this->model->getDefaultDriverId();
            $filters['driver_id'] = $driverId;
        }

        $dashboard = $driverId > 0
            ? $this->model->getDashboard($driverId, $filters)
            : $this->model->getDashboard(0, $filters);

        render('driver_activity_history/index.php', [
            'pageTitle' => 'Istoric Activitati Sofer',
            'currentPage' => 'istoric_activitati_sofer',
            'dashboard' => $dashboard,
            'filters' => $filters,
            'driverOptions' => $this->model->getDriverOptions(),
            'transportLabels' => DriverActivityHistoryModel::TRANSPORT_LABELS,
        ]);
    }

    private function exportExcel(): void
    {
        $filters = $this->resolveFilters($_GET);
        $driverId = (int) ($filters['driver_id'] ?? 0);
        if ($driverId <= 0) {
            $driverId = $this->model->getDefaultDriverId();
            $filters['driver_id'] = $driverId;
        }

        $dashboard = $this->model->getDashboard($driverId, $filters);
        $driverName = $this->safeFilePart((string) ($dashboard['driver']['nume'] ?? 'sofer'));
        $fileName = 'istoric_activitati_sofer_' . $driverName . '_' . date('Ymd_His') . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        $out = fopen('php://output', 'wb');
        if ($out === false) {
            exit;
        }
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, ['Istoric Activitati Sofer', (string) ($dashboard['driver']['nume'] ?? '-')], ';');
        fputcsv($out, ['Perioada', (string) $filters['date_start'], (string) $filters['date_end']], ';');
        fputcsv($out, [], ';');

        fputcsv($out, ['KPI', 'Valoare'], ';');
        foreach ($this->kpiExportRows((array) ($dashboard['kpis'] ?? [])) as $row) {
            fputcsv($out, $row, ';');
        }
        fputcsv($out, [], ';');

        fputcsv($out, ['Curse'], ';');
        fputcsv($out, ['Data', 'Beneficiar', 'Vehicul', 'Tip transport', 'Total KM', 'KM nefacturabili', 'Tone incarcate', 'Tone livrate', 'Durata minute', 'Cost cursa'], ';');
        foreach ((array) ($dashboard['trips'] ?? []) as $row) {
            fputcsv($out, [
                $row['data_inceput'] ?? '',
                $row['beneficiary_label'] ?? '',
                $row['nr_inmatriculare'] ?? '',
                $row['transport_label'] ?? '',
                $row['effective_km'] ?? 0,
                $row['non_billable_km'] ?? 0,
                $row['loaded_tons'] ?? 0,
                $row['delivered_tons'] ?? 0,
                $row['duration_minutes_effective'] ?? 0,
                $row['total_cheltuieli'] ?? 0,
            ], ';');
        }
        fputcsv($out, [], ';');

        fputcsv($out, ['Alimentari'], ';');
        fputcsv($out, ['Data', 'Vehicul', 'Sofer', 'Litri', 'Pret litru', 'Cost', 'Kilometraj', 'Tip combustibil', 'Consum calculat', 'Observatii'], ';');
        foreach ((array) ($dashboard['fuelRows'] ?? []) as $row) {
            fputcsv($out, [
                $row['data_alimentare'] ?? '',
                $row['nr_inmatriculare'] ?? '',
                $row['record_sofer_nume'] ?? '',
                $row['litri'] ?? 0,
                $row['pret_litru_calculat'] ?? '',
                $row['cost_total'] ?? 0,
                $row['km_bord'] ?? '',
                $row['fuel_type'] ?? '',
                $row['calculated_consumption'] ?? '',
                $row['observatii'] ?? '',
            ], ';');
        }
        fputcsv($out, [], ';');

        fputcsv($out, ['Reparatii'], ';');
        fputcsv($out, ['Data', 'Vehicul', 'Categorie principala', 'Subcategorie', 'Componenta', 'Tip', 'Furnizor piese', 'Furnizor manopera', 'Cost piese', 'Cost manopera', 'Cost total', 'Factura', 'Observatii'], ';');
        foreach ((array) ($dashboard['repairs'] ?? []) as $row) {
            fputcsv($out, [
                $row['data_interventie'] ?? '',
                $row['nr_inmatriculare'] ?? '',
                $row['main_category'] ?? '',
                $row['subcategory'] ?? '',
                $row['component_label'] ?? '',
                $row['tip_interventie'] ?? '',
                $row['furnizor_piesa'] ?? '',
                $row['atelier'] ?? '',
                $row['cost_piese'] ?? 0,
                $row['cost_manopera'] ?? 0,
                $row['cost'] ?? 0,
                $row['fisier_original'] ?? '',
                $row['observatii'] ?? '',
            ], ';');
        }
        fputcsv($out, [], ';');

        fputcsv($out, ['Activitati zilnice'], ';');
        fputcsv($out, ['Data', 'Curse', 'Kilometri', 'Tone incarcate', 'Tone livrate', 'Combustibil', 'Cost combustibil', 'Cost reparatii', 'Cost total', 'Ore condus minute'], ';');
        foreach ((array) ($dashboard['dailyRows'] ?? []) as $row) {
            fputcsv($out, [
                $row['date'] ?? '',
                $row['trips'] ?? 0,
                $row['kilometers'] ?? 0,
                $row['loaded_tons'] ?? 0,
                $row['delivered_tons'] ?? 0,
                $row['fuel_used'] ?? 0,
                $row['fuel_cost'] ?? 0,
                $row['repair_cost'] ?? 0,
                $row['total_daily_cost'] ?? 0,
                $row['driving_minutes'] ?? 0,
            ], ';');
        }

        fclose($out);
        exit;
    }

    private function exportPdf(): void
    {
        $filters = $this->resolveFilters($_GET);
        $driverId = (int) ($filters['driver_id'] ?? 0);
        if ($driverId <= 0) {
            $driverId = $this->model->getDefaultDriverId();
            $filters['driver_id'] = $driverId;
        }

        $dashboard = $this->model->getDashboard($driverId, $filters);
        $html = $this->buildPrintableReport($dashboard, $filters);

        if (class_exists('\\Dompdf\\Dompdf')) {
            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();
            $dompdf->stream('istoric_activitati_sofer_' . date('Ymd_His') . '.pdf');
            exit;
        }

        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
        exit;
    }

    private function resolveFilters(array $input): array
    {
        $today = new DateTimeImmutable('today');
        $defaultStart = $today->modify('first day of this month')->format('Y-m-d');
        $defaultEnd = $today->format('Y-m-d');

        [$rangeStart, $rangeEnd] = $this->parseDateRange((string) ($input['date_range'] ?? ''));
        $dateStart = $this->normalizeDate((string) ($input['date_start'] ?? '')) ?? $rangeStart ?? $defaultStart;
        $dateEnd = $this->normalizeDate((string) ($input['date_end'] ?? '')) ?? $rangeEnd ?? $defaultEnd;
        if ($dateStart > $dateEnd) {
            [$dateStart, $dateEnd] = [$dateEnd, $dateStart];
        }

        $transportType = $this->normalizeTransportType((string) ($input['transport_type'] ?? ''));
        $grouping = (string) ($input['grouping'] ?? 'daily');
        if (!in_array($grouping, ['daily', 'weekly', 'monthly'], true)) {
            $grouping = 'daily';
        }

        return [
            'driver_id' => max(0, (int) ($input['driver_id'] ?? 0)),
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'date_range' => $this->formatDateRangeForInput($dateStart, $dateEnd),
            'vehicle_id' => max(0, (int) ($input['vehicle_id'] ?? 0)),
            'transport_type' => $transportType,
            'grouping' => $grouping,
        ];
    }

    private function parseDateRange(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [null, null];
        }

        $parts = preg_split('/\s+-\s+/', $value) ?: [];
        if (count($parts) !== 2) {
            return [null, null];
        }

        return [$this->normalizeFlexibleDate((string) $parts[0]), $this->normalizeFlexibleDate((string) $parts[1])];
    }

    private function normalizeFlexibleDate(string $value): ?string
    {
        $value = trim($value);
        foreach (['Y-m-d', 'd.m.Y', 'd/m/Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date instanceof DateTimeImmutable && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    private function normalizeTransportType(string $value): string
    {
        $value = trim($value);
        return array_key_exists($value, DriverActivityHistoryModel::TRANSPORT_LABELS) ? $value : '';
    }

    private function formatDateRangeForInput(string $start, string $end): string
    {
        try {
            return (new DateTimeImmutable($start))->format('d.m.Y') . ' - ' . (new DateTimeImmutable($end))->format('d.m.Y');
        } catch (Throwable) {
            return $start . ' - ' . $end;
        }
    }

    private function kpiExportRows(array $kpis): array
    {
        return [
            ['Total curse', $kpis['total_trips'] ?? 0],
            ['Total kilometri', $kpis['total_km'] ?? 0],
            ['Total tone incarcate', $kpis['total_loaded_tons'] ?? 0],
            ['Total tone livrate', $kpis['total_delivered_tons'] ?? 0],
            ['Minute de condus', $kpis['driving_minutes'] ?? 0],
            ['Total litri combustibil', $kpis['total_fuel_liters'] ?? 0],
            ['Consum mediu L/100km', $kpis['average_consumption'] ?? ''],
            ['Cost combustibil', $kpis['fuel_cost'] ?? 0],
            ['Cost reparatii', $kpis['repair_cost'] ?? 0],
            ['Cost curse', $kpis['trip_cost'] ?? 0],
            ['Costuri totale', $kpis['total_costs'] ?? 0],
        ];
    }

    private function buildPrintableReport(array $dashboard, array $filters): string
    {
        $kpis = (array) ($dashboard['kpis'] ?? []);
        ob_start();
        ?>
        <!doctype html>
        <html lang="ro">
        <head>
            <meta charset="utf-8">
            <title>Istoric Activitati Sofer</title>
            <style>
                body { font-family: Arial, sans-serif; color: #0f172a; }
                h1 { margin-bottom: 4px; }
                .muted { color: #64748b; font-size: 12px; }
                .grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin: 14px 0; }
                .kpi { border: 1px solid #dbe3ef; padding: 8px; }
                .kpi span { display: block; color: #64748b; font-size: 11px; }
                .kpi strong { font-size: 16px; }
                table { border-collapse: collapse; width: 100%; font-size: 10.5px; margin-top: 12px; }
                th, td { border: 1px solid #dbe3ef; padding: 5px; text-align: left; }
                th { background: #f3f6fb; }
            </style>
        </head>
        <body>
            <h1>Istoric Activitati Sofer - <?= e((string) ($dashboard['driver']['nume'] ?? '-')) ?></h1>
            <div class="muted">Perioada: <?= e((string) $filters['date_start']) ?> - <?= e((string) $filters['date_end']) ?>. Generat la <?= e(date('d.m.Y H:i')) ?>.</div>
            <div class="grid">
                <div class="kpi"><span>Total curse</span><strong><?= e((string) ($kpis['total_trips'] ?? 0)) ?></strong></div>
                <div class="kpi"><span>Total km</span><strong><?= e(format_number_ro((float) ($kpis['total_km'] ?? 0), 0)) ?></strong></div>
                <div class="kpi"><span>Total combustibil</span><strong><?= e(format_number_ro((float) ($kpis['total_fuel_liters'] ?? 0), 2)) ?> L</strong></div>
                <div class="kpi"><span>Total costuri</span><strong><?= e(format_number_ro((float) ($kpis['total_costs'] ?? 0), 2)) ?> lei</strong></div>
            </div>
            <h2>Curse</h2>
            <table>
                <thead><tr><th>Data</th><th>Beneficiar</th><th>Vehicul</th><th>Transport</th><th>KM</th><th>Tone inc.</th><th>Tone liv.</th><th>Cost</th></tr></thead>
                <tbody>
                <?php foreach ((array) ($dashboard['trips'] ?? []) as $row): ?>
                    <tr>
                        <td><?= e(format_date_ro((string) ($row['data_inceput'] ?? ''))) ?></td>
                        <td><?= e((string) ($row['beneficiary_label'] ?? '-')) ?></td>
                        <td><?= e((string) ($row['nr_inmatriculare'] ?? '-')) ?></td>
                        <td><?= e((string) ($row['transport_label'] ?? '-')) ?></td>
                        <td><?= e(format_number_ro((float) ($row['effective_km'] ?? 0), 0)) ?></td>
                        <td><?= e(format_number_ro((float) ($row['loaded_tons'] ?? 0), 2)) ?></td>
                        <td><?= e(format_number_ro((float) ($row['delivered_tons'] ?? 0), 2)) ?></td>
                        <td><?= e(format_number_ro((float) ($row['total_cheltuieli'] ?? 0), 2)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <h2>Reparatii</h2>
            <table>
                <thead><tr><th>Data</th><th>Vehicul</th><th>Categorie</th><th>Subcategorie</th><th>Componenta</th><th>Piese</th><th>Manopera</th><th>Total</th></tr></thead>
                <tbody>
                <?php foreach ((array) ($dashboard['repairs'] ?? []) as $row): ?>
                    <tr>
                        <td><?= e(format_date_ro((string) ($row['data_interventie'] ?? ''))) ?></td>
                        <td><?= e((string) ($row['nr_inmatriculare'] ?? '-')) ?></td>
                        <td><?= e((string) ($row['main_category'] ?? '-')) ?></td>
                        <td><?= e((string) ($row['subcategory'] ?? '-')) ?></td>
                        <td><?= e((string) ($row['component_label'] ?? '-')) ?></td>
                        <td><?= e(format_number_ro((float) ($row['cost_piese'] ?? 0), 2)) ?></td>
                        <td><?= e(format_number_ro((float) ($row['cost_manopera'] ?? 0), 2)) ?></td>
                        <td><?= e(format_number_ro((float) ($row['cost'] ?? 0), 2)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </body>
        </html>
        <?php
        return (string) ob_get_clean();
    }

    private function safeFilePart(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim($value)) ?: 'sofer';
        return trim($value, '_') !== '' ? trim($value, '_') : 'sofer';
    }
}
