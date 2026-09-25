<?php
declare(strict_types=1);

class DriverActivityHistoryController
{
    private const MAX_COMPARE_DRIVERS = 20;

    /** Cheile KPI care nu ajung la cei fara dreptul „Date financiare”. */
    private const FINANCIAL_KPI_KEYS = [
        'salary_cost', 'salary_months', 'salary_missing',
        'diurne_value', 'diurne_value_in_total', 'diurne_value_recorded', 'diurna_policy',
        'total_costs', 'trip_value', 'refacturare_total', 'refacturare_recovered', 'profit',
    ];

    private DriverActivityHistoryModel $model;
    private bool $canFinancial;

    public function __construct(PDO $db)
    {
        $this->model = new DriverActivityHistoryModel($db);
        $this->canFinancial = can('istoric_activitati_sofer', 'view_financial');
    }

    /** Dashboard-ul individual, fara datele financiare daca utilizatorul nu are dreptul. */
    private function dashboardFor(int $driverId, array $filters): array
    {
        $dashboard = $this->model->getDashboard($driverId, $filters);

        return $this->canFinancial ? $dashboard : $this->redactDashboard($dashboard);
    }

    private function comparisonFor(array $driverIds, array $filters): array
    {
        $comparison = $this->model->getComparison($driverIds, $filters);

        return $this->canFinancial ? $comparison : $this->redactComparison($comparison);
    }

    /**
     * Scoate datele sensibile inainte de randare / export, ca sa nu ajunga nici
     * in HTML, nici in JSON-ul graficelor, nici in fisierele exportate.
     */
    private function redactDashboard(array $dashboard): array
    {
        $dashboard['kpis'] = $this->redactKpis((array) ($dashboard['kpis'] ?? []));
        $dashboard['trips'] = array_map([$this, 'redactTrip'], (array) ($dashboard['trips'] ?? []));
        $dashboard['diurneRows'] = array_map([$this, 'redactDiurnaRow'], (array) ($dashboard['diurneRows'] ?? []));

        // Distributia costurilor ramane doar cu costurile operationale.
        if (isset($dashboard['charts']['cost_distribution'])) {
            $distribution = (array) $dashboard['charts']['cost_distribution'];
            $labels = [];
            $values = [];
            foreach ((array) ($distribution['labels'] ?? []) as $index => $label) {
                if (in_array($label, ['Salariu', 'Diurne'], true)) {
                    continue;
                }
                $labels[] = $label;
                $values[] = $distribution['values'][$index] ?? 0;
            }
            $dashboard['charts']['cost_distribution'] = ['labels' => $labels, 'values' => $values];
        }

        return $dashboard;
    }

    private function redactComparison(array $comparison): array
    {
        // Cardurile KPI ale selectiei trec prin aceeasi curatare ca la un singur sofer.
        if (isset($comparison['kpis'])) {
            $comparison['kpis'] = $this->redactKpis((array) $comparison['kpis']);
        }
        foreach ((array) ($comparison['drivers'] ?? []) as $index => $driver) {
            $driver['kpis'] = $this->redactKpis((array) ($driver['kpis'] ?? []));
            $driver['cost_per_km'] = null;
            $comparison['drivers'][$index] = $driver;
        }
        $comparison['trips'] = array_map([$this, 'redactTrip'], (array) ($comparison['trips'] ?? []));
        $comparison['diurneRows'] = array_map([$this, 'redactDiurnaRow'], (array) ($comparison['diurneRows'] ?? []));
        foreach (['salary_cost', 'diurne_cost', 'trip_value', 'profit'] as $key) {
            unset($comparison['charts']['compare'][$key]);
        }

        return $comparison;
    }

    private function redactKpis(array $kpis): array
    {
        foreach (self::FINANCIAL_KPI_KEYS as $key) {
            unset($kpis[$key]);
        }
        // Si defalcarea de pe carduri: costurile, salariul si profitul sunt tot date financiare.
        // Zilele lucrate pe tip (salary_days) raman: nu sunt bani, le arata cardul "Zile lucrate".
        foreach (['costs', 'salary', 'profit', 'diurne_value'] as $metric) {
            unset($kpis['breakdown']['metrics'][$metric]);
        }
        // Costul operational (fara salariu si diurne) ramane vizibil.
        $kpis['operational_costs'] = (float) ($kpis['fuel_cost'] ?? 0) + (float) ($kpis['repair_cost'] ?? 0) + (float) ($kpis['trip_cost'] ?? 0);

        return $kpis;
    }

    private function redactTrip(array $trip): array
    {
        unset($trip['total_facturare'], $trip['total_refacturare_facturata']);

        return $trip;
    }

    private function redactDiurnaRow(array $row): array
    {
        unset($row['diurne_value'], $row['diurna_recorded'], $row['policy']);

        return $row;
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
        $driverOptions = $this->model->getDriverOptions($filters);
        $filters = $this->ensureDriverSelection($filters, $driverOptions);
        $isCompare = count($filters['driver_ids']) > 1;

        $comparison = [];
        if ($isCompare) {
            $dashboard = $this->comparisonFor($filters['driver_ids'], $filters);
        } else {
            // Un singur sofer: acelasi tabel de sumar ca la comparatie, construit din
            // dashboard-ul deja calculat (fara a mai interoga inca o data).
            $driverId = (int) $filters['driver_id'];
            $raw = $this->model->getDashboard($driverId, $filters);
            $rawComparison = $this->model->getComparison([$driverId], $filters, [$driverId => $raw]);
            $dashboard = $this->canFinancial ? $raw : $this->redactDashboard($raw);
            $comparison = $this->canFinancial ? $rawComparison : $this->redactComparison($rawComparison);
        }

        render('driver_activity_history/index.php', [
            'pageTitle' => $isCompare ? 'Comparatie soferi' : 'Istoric Activitati Sofer',
            'currentPage' => 'istoric_activitati_sofer',
            'dashboard' => $dashboard,
            'comparison' => $comparison,
            'filters' => $filters,
            'isCompare' => $isCompare,
            'driverOptions' => $driverOptions,
            'beneficiaryOptions' => $this->model->getBeneficiaryOptions(),
            'transportLabels' => DriverActivityHistoryModel::TRANSPORT_LABELS,
            'canFinancial' => $this->canFinancial,
        ]);
    }

    /**
     * Fara sofer ales se deschide soferul implicit (cel cu cea mai recenta cursa).
     * Soferii fara activitate in perioada nu sunt in lista, deci nu raman nici in
     * selectie: altfel comparatia ar avea randuri goale (0 curse, 0 km, 0 lei).
     */
    private function ensureDriverSelection(array $filters, ?array $driverOptions = null): array
    {
        $driverOptions ??= $this->model->getDriverOptions($filters);
        $allowedIds = array_map('intval', array_column($driverOptions, 'id'));
        if ($allowedIds !== []) {
            $filters['driver_ids'] = array_values(array_intersect($filters['driver_ids'], $allowedIds));
        }

        if ($filters['driver_ids'] === []) {
            $defaultId = $allowedIds !== [] ? $this->defaultDriverFrom($allowedIds) : 0;
            $filters['driver_ids'] = $defaultId > 0 ? [$defaultId] : [];
        }
        $filters['driver_id'] = (int) ($filters['driver_ids'][0] ?? 0);

        return $filters;
    }

    /** Soferul implicit, dar doar dintre cei cu activitate in perioada. */
    private function defaultDriverFrom(array $allowedIds): int
    {
        $defaultId = $this->model->getDefaultDriverId();

        return in_array($defaultId, $allowedIds, true) ? $defaultId : (int) $allowedIds[0];
    }
    private function exportExcel(): void
    {
        $filters = $this->ensureDriverSelection($this->resolveFilters($_GET));
        if (count($filters['driver_ids']) > 1) {
            $this->exportCompareExcel($filters);
        }
        $driverId = (int) $filters['driver_id'];

        $dashboard = $this->dashboardFor($driverId, $filters);
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
        fputcsv($out, $this->financialColumns(['Data', 'Beneficiar', 'Vehicul', 'Tip transport', 'Total KM', 'KM nefacturabili', 'Tone transportate', 'Tone livrate', 'Durata minute', 'Diurne', 'Valoare cursa', 'Cost cursa', 'Refacturat'], [10, 12]), ';');
        foreach ((array) ($dashboard['trips'] ?? []) as $row) {
            fputcsv($out, $this->financialColumns([
                $row['data_inceput'] ?? '',
                $row['beneficiary_label'] ?? '',
                $row['nr_inmatriculare'] ?? '',
                $row['transport_label'] ?? '',
                $row['effective_km'] ?? 0,
                $row['non_billable_km'] ?? 0,
                $row['transported_tons'] ?? 0,
                $row['delivered_tons'] ?? 0,
                $row['duration_minutes_effective'] ?? 0,
                $row['diurne'] ?? '',
                $row['total_facturare'] ?? 0,
                $row['total_cheltuieli'] ?? 0,
                $row['total_refacturare_facturata'] ?? 0,
            ], [10, 12]), ';');
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
        fputcsv($out, ['Data', 'Curse', 'Kilometri', 'Tone transportate', 'Tone livrate', 'Combustibil', 'Cost combustibil', 'Cost reparatii', 'Cost total', 'Ore condus minute'], ';');
        foreach ((array) ($dashboard['dailyRows'] ?? []) as $row) {
            fputcsv($out, [
                $row['date'] ?? '',
                $row['trips'] ?? 0,
                $row['kilometers'] ?? 0,
                $row['transported_tons'] ?? 0,
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
        $filters = $this->ensureDriverSelection($this->resolveFilters($_GET));
        if (count($filters['driver_ids']) > 1) {
            $html = $this->buildPrintableComparison($this->comparisonFor($filters['driver_ids'], $filters), $filters);
        } else {
            $html = $this->buildPrintableReport($this->dashboardFor((int) $filters['driver_id'], $filters), $filters);
        }

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
            'driver_ids' => $this->resolveDriverIds($input),
            'driver_id' => 0,
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'date_range' => $this->formatDateRangeForInput($dateStart, $dateEnd),
            // Un interval scris gresit nu trebuie sa revina tacit la luna curenta.
            'date_range_invalid' => trim((string) ($input['date_range'] ?? '')) !== '' && $rangeStart === null
                ? trim((string) $input['date_range'])
                : '',
            'vehicle_id' => max(0, (int) ($input['vehicle_id'] ?? 0)),
            'beneficiar_id' => max(0, (int) ($input['beneficiar_id'] ?? 0)),
            'transport_type' => $transportType,
            'grouping' => $grouping,
        ];
    }

    /** Soferii alesi: driver_ids[] (comparatie) sau driver_id (link-uri vechi). Maxim 20. */
    private function resolveDriverIds(array $input): array
    {
        $raw = $input['driver_ids'] ?? [];
        if (!is_array($raw)) {
            $raw = explode(',', (string) $raw);
        }
        if ($raw === [] && isset($input['driver_id'])) {
            $raw = [$input['driver_id']];
        }

        $ids = [];
        foreach ($raw as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_slice(array_values($ids), 0, self::MAX_COMPARE_DRIVERS);
    }

    private function parseDateRange(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [null, null];
        }

        // Se cauta datele in text, nu separatorul: "1.8.2026-31.8.2026",
        // "01.08.2026 – 31.08.2026" sau o singura zi sunt toate acceptate.
        preg_match_all('/\d{4}-\d{1,2}-\d{1,2}|\d{1,2}[.\/-]\d{1,2}[.\/-]\d{4}/', $value, $matches);
        $dates = array_map(fn (string $part): ?string => $this->normalizeFlexibleDate($part), $matches[0]);
        if (count($dates) === 1 && $dates[0] !== null) {
            return [$dates[0], $dates[0]];
        }
        if (count($dates) !== 2 || in_array(null, $dates, true)) {
            return [null, null];
        }

        return [$dates[0], $dates[1]];
    }

    private function normalizeFlexibleDate(string $value): ?string
    {
        $value = trim($value);
        foreach (['Y-n-j', 'j.n.Y', 'j/n/Y', 'j-n-Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date instanceof DateTimeImmutable && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
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

    /** Randurile tabelului comparativ (acelasi continut in pagina, Excel si PDF). */
    /**
     * Randurile tabelului comparativ. Beneficiarii vin din cursele fiecarui sofer
     * (in pagina sunt "N detalii"; la export ii scriem pe toti, cu numarul de curse).
     */
    private function compareTableRows(array $drivers, array $trips = []): array
    {
        $beneficiariesByDriver = [];
        foreach ($trips as $trip) {
            $label = trim((string) ($trip['beneficiary_label'] ?? ''));
            if ($label === '' || $label === '-') {
                continue;
            }
            $driverId = (int) ($trip['driver_id_compare'] ?? 0);
            $beneficiariesByDriver[$driverId][$label] = ($beneficiariesByDriver[$driverId][$label] ?? 0) + 1;
        }
        $beneficiaryCell = static function (int $driverId) use ($beneficiariesByDriver): string {
            $counts = $beneficiariesByDriver[$driverId] ?? [];
            arsort($counts);
            $parts = [];
            foreach ($counts as $label => $count) {
                $parts[] = $label . ' (' . $count . ')';
            }

            return implode(', ', $parts);
        };

        $header = ['Sofer', 'Curse', 'Beneficiar', 'Zile lucrate', 'Km', 'Tone transportate', 'Tone livrate', 'Nr. clienti', 'T livrate / client', 'Ore condus', 'Diurne', 'Diurne in cost (lei)', 'Litri motorina', 'L/100 km', 'Cost carburant', 'Cost reparatii', 'Cost curse', 'Salariu', 'Cost total', 'Cost / km', 'Valoare curse', 'Refacturat', 'Profit curse'];
        $rows = [];
        foreach ($drivers as $driver) {
            $k = (array) $driver['kpis'];
            $rows[] = [
                (string) $driver['nume'],
                (int) ($k['total_trips'] ?? 0),
                $beneficiaryCell((int) ($driver['id'] ?? 0)),
                (int) ($k['worked_days'] ?? 0),
                round((float) ($k['total_km'] ?? 0), 0),
                round((float) ($k['total_transported_tons'] ?? 0), 2),
                round((float) ($k['total_delivered_tons'] ?? 0), 2),
                (int) ($k['clients_total'] ?? 0),
                ($k['delivered_per_client'] ?? null) !== null ? round((float) $k['delivered_per_client'], 2) : '',
                round((int) ($k['driving_minutes'] ?? 0) / 60, 1),
                (int) ($k['diurne'] ?? 0),
                round((float) ($k['diurne_value_in_total'] ?? 0), 2),
                round((float) ($k['total_fuel_liters'] ?? 0), 2),
                ($k['average_consumption'] ?? null) !== null ? round((float) $k['average_consumption'], 2) : '',
                round((float) ($k['fuel_cost'] ?? 0), 2),
                round((float) ($k['repair_cost'] ?? 0), 2),
                round((float) ($k['trip_cost'] ?? 0), 2),
                round((float) ($k['salary_cost'] ?? 0), 2),
                round((float) ($k['total_costs'] ?? 0), 2),
                $driver['cost_per_km'] !== null ? round((float) $driver['cost_per_km'], 2) : '',
                round((float) ($k['trip_value'] ?? 0), 2),
                round((float) ($k['refacturare_recovered'] ?? 0), 2),
                round((float) ($k['profit'] ?? 0), 2),
            ];
        }

        // Diurne (lei), Salariu, Cost total, Cost / km, Valoare curse, Refacturat, Profit curse.
        $financial = [11, 17, 18, 19, 20, 21, 22];

        return [
            $this->financialColumns($header, $financial),
            array_map(fn (array $row): array => $this->financialColumns($row, $financial), $rows),
        ];
    }

    private function exportCompareExcel(array $filters): never
    {
        $comparison = $this->comparisonFor($filters['driver_ids'], $filters);
        $fileName = 'comparatie_soferi_' . date('Ymd_His') . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        $out = fopen('php://output', 'wb');
        if ($out === false) {
            exit;
        }
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, ['Comparatie soferi'], ';');
        fputcsv($out, ['Perioada', (string) $filters['date_start'], (string) $filters['date_end']], ';');
        fputcsv($out, [], ';');

        [$header, $rows] = $this->compareTableRows((array) $comparison['drivers'], (array) ($comparison['trips'] ?? []));
        fputcsv($out, $header, ';');
        foreach ($rows as $row) {
            fputcsv($out, $row, ';');
        }
        fputcsv($out, [], ';');

        fputcsv($out, ['Curse'], ';');
        fputcsv($out, $this->financialColumns(['Sofer', 'Data', 'Beneficiar', 'Vehicul', 'Tip transport', 'Total KM', 'Tone transportate', 'Tone livrate', 'Durata minute', 'Diurne', 'Valoare cursa', 'Cost cursa', 'Refacturat'], [10, 12]), ';');
        foreach ((array) $comparison['trips'] as $row) {
            fputcsv($out, $this->financialColumns([
                $row['driver_name_compare'] ?? '',
                $row['data_inceput'] ?? '',
                $row['beneficiary_label'] ?? '',
                $row['nr_inmatriculare'] ?? '',
                $row['transport_label'] ?? '',
                $row['effective_km'] ?? 0,
                $row['transported_tons'] ?? 0,
                $row['delivered_tons'] ?? 0,
                $row['duration_minutes_effective'] ?? 0,
                $row['diurne'] ?? '',
                $row['total_facturare'] ?? 0,
                $row['total_cheltuieli'] ?? 0,
                $row['total_refacturare_facturata'] ?? 0,
            ], [10, 12]), ';');
        }
        fputcsv($out, [], ';');

        fputcsv($out, ['Alimentari'], ';');
        fputcsv($out, ['Sofer', 'Data', 'Vehicul', 'Card', 'Litri', 'Cost', 'Kilometraj', 'Statie'], ';');
        foreach ((array) $comparison['fuelRows'] as $row) {
            fputcsv($out, [
                $row['driver_name_compare'] ?? '',
                $row['fillup_datetime'] ?? '',
                $row['nr_inmatriculare'] ?? '',
                $row['record_sofer_nume'] ?? '',
                $row['litri'] ?? 0,
                $row['cost_total'] ?? 0,
                $row['km_bord'] ?? '',
                $row['observatii'] ?? '',
            ], ';');
        }

        fclose($out);
        exit;
    }

    private function buildPrintableComparison(array $comparison, array $filters): string
    {
        [$header, $rows] = $this->compareTableRows((array) $comparison['drivers'], (array) ($comparison['trips'] ?? []));
        ob_start();
        ?>
        <!doctype html>
        <html lang="ro">
        <head>
            <meta charset="utf-8">
            <title>Comparatie soferi</title>
            <style>
                body { font-family: Arial, sans-serif; color: #0f172a; }
                .muted { color: #64748b; font-size: 12px; }
                table { width: 100%; border-collapse: collapse; margin-top: 14px; font-size: 11px; }
                th, td { border: 1px solid #cbd5e1; padding: 5px; text-align: right; }
                th:first-child, td:first-child { text-align: left; }
                th { background: #eef4ff; }
            </style>
        </head>
        <body>
            <h1>Comparatie soferi</h1>
            <div class="muted">Perioada: <?= e((string) $filters['date_start']) ?> - <?= e((string) $filters['date_end']) ?>. Generat la <?= e(date('d.m.Y H:i')) ?>.</div>
            <table>
                <thead><tr><?php foreach ($header as $cell): ?><th><?= e((string) $cell) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr><?php foreach ($row as $cell): ?><td><?= e(is_float($cell) ? format_number_ro($cell, 2) : (string) $cell) ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </body>
        </html>
        <?php
        return (string) ob_get_clean();
    }

    /** Scoate coloanele financiare (dupa pozitie) cand utilizatorul nu are dreptul. */
    private function financialColumns(array $row, array $financialIndexes): array
    {
        if ($this->canFinancial) {
            return $row;
        }

        return array_values(array_diff_key($row, array_flip($financialIndexes)));
    }

    private function kpiExportRows(array $kpis): array
    {
        if (!$this->canFinancial) {
            return [
                ['Total curse', $kpis['total_trips'] ?? 0],
                ['Total kilometri', $kpis['total_km'] ?? 0],
                ['Total tone transportate', $kpis['total_transported_tons'] ?? 0],
                ['Total tone livrate', $kpis['total_delivered_tons'] ?? 0],
                ['Minute de condus', $kpis['driving_minutes'] ?? 0],
                ['Total litri combustibil', $kpis['total_fuel_liters'] ?? 0],
                ['Consum mediu L/100km', $kpis['average_consumption'] ?? ''],
                ['Cost combustibil', $kpis['fuel_cost'] ?? 0],
                ['Cost reparatii', $kpis['repair_cost'] ?? 0],
                ['Cost curse', $kpis['trip_cost'] ?? 0],
                ['Cost operational (carburant, reparatii, curse)', round((float) ($kpis['operational_costs'] ?? 0), 2)],
                ['Zile lucrate', $kpis['worked_days'] ?? 0],
                ['Diurne', $kpis['diurne'] ?? 0],
            ];
        }

        return [
            ['Total curse', $kpis['total_trips'] ?? 0],
            ['Total kilometri', $kpis['total_km'] ?? 0],
            ['Total tone transportate', $kpis['total_transported_tons'] ?? 0],
            ['Total tone livrate', $kpis['total_delivered_tons'] ?? 0],
            ['Minute de condus', $kpis['driving_minutes'] ?? 0],
            ['Total litri combustibil', $kpis['total_fuel_liters'] ?? 0],
            ['Consum mediu L/100km', $kpis['average_consumption'] ?? ''],
            ['Cost combustibil', $kpis['fuel_cost'] ?? 0],
            ['Cost reparatii', $kpis['repair_cost'] ?? 0],
            ['Cost curse', $kpis['trip_cost'] ?? 0],
            ['Zile lucrate', $kpis['worked_days'] ?? 0],
            ['Cost salarial (zile lucrate)', round((float) ($kpis['salary_cost'] ?? 0), 2)],
            ['Costuri totale', $kpis['total_costs'] ?? 0],
            ['Valoare curse', round((float) ($kpis['trip_value'] ?? 0), 2)],
            ['Refacturari (total inregistrat)', round((float) ($kpis['refacturare_total'] ?? 0), 2)],
            ['Refacturat (recuperat)', round((float) ($kpis['refacturare_recovered'] ?? 0), 2)],
            ['Profit curse', round((float) ($kpis['profit'] ?? 0), 2)],
            ['Diurne', $kpis['diurne'] ?? 0],
            ['Valoare diurne', round((float) ($kpis['diurne_value'] ?? 0), 2)],
            ['Diurne incluse in costul total', round((float) ($kpis['diurne_value_in_total'] ?? 0), 2)],
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
                <?php if ($this->canFinancial): ?>
                <div class="kpi"><span>Total costuri</span><strong><?= e(format_number_ro((float) ($kpis['total_costs'] ?? 0), 2)) ?> lei</strong></div>
                <?php else: ?>
                <div class="kpi"><span>Cost operational</span><strong><?= e(format_number_ro((float) ($kpis['operational_costs'] ?? 0), 2)) ?> lei</strong></div>
                <?php endif; ?>
                <div class="kpi"><span>Diurne</span><strong><?= e((string) (int) ($kpis['diurne'] ?? 0)) ?></strong></div>
            </div>
            <h2>Curse</h2>
            <table>
                <thead><tr><th>Data</th><th>Beneficiar</th><th>Vehicul</th><th>Transport</th><th>KM</th><th>Tone transp.</th><th>Tone liv.</th><th>Cost</th></tr></thead>
                <tbody>
                <?php foreach ((array) ($dashboard['trips'] ?? []) as $row): ?>
                    <tr>
                        <td><?= e(format_date_ro((string) ($row['data_inceput'] ?? ''))) ?></td>
                        <td><?= e((string) ($row['beneficiary_label'] ?? '-')) ?></td>
                        <td><?= e((string) ($row['nr_inmatriculare'] ?? '-')) ?></td>
                        <td><?= e((string) ($row['transport_label'] ?? '-')) ?></td>
                        <td><?= e(format_number_ro((float) ($row['effective_km'] ?? 0), 0)) ?></td>
                        <td><?= e(format_number_ro((float) ($row['transported_tons'] ?? 0), 2)) ?></td>
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
