<?php
declare(strict_types=1);

class FuelController
{
    private FuelModel $model;

    public function __construct(PDO $db)
    {
        $this->model = new FuelModel($db);
    }

    public function handle(string $action): void
    {
        match ($action) {
            'save' => $this->saveAction(),
            'delete' => $this->deleteAction(),
            'detect_trip' => $this->detectTripAction(),
            'trips_calendar' => $this->tripsCalendarAction(),
            't0_km_suggestion' => $this->t0KmSuggestionAction(),
            'refuel_km_suggestion' => $this->refuelKmSuggestionAction(),
            'export_excel' => $this->exportExcelAction(),
            'export_pdf' => $this->exportPdfAction(),
            default => $this->indexAction(),
        };
    }

    private function indexAction(): void
    {
        $filters = $this->model->normalizeFilters($_GET);
        $dashboard = $this->model->getDashboardData($filters, 25);
        $editRecord = null;
        $editId = (int) ($_GET['edit_id'] ?? 0);
        if ($editId > 0) {
            $editRecord = $this->model->getRecordById($editId);
        }

        render('alimentari/index.php', [
            'pageTitle' => 'Alimentare',
            'currentPage' => 'alimentari',
            'filters' => $dashboard['filters'],
            'period' => $dashboard['period'],
            'rows' => $dashboard['rows'],
            'kpis' => $dashboard['kpis'],
            'transportMetrics' => $dashboard['transportMetrics'],
            'missingT0Vehicles' => $dashboard['missingT0Vehicles'],
            'pagination' => $dashboard['pagination'],
            'vehicles' => $this->model->getVehicleOptions(),
            't0Vehicles' => $this->model->getT0VehicleOptions(),
            'suppliers' => $this->model->getSupplierOptions($dashboard['filters']),
            'tripOptions' => $this->model->getTripOptions($dashboard['filters']),
            'transportLabels' => FuelModel::TRANSPORT_LABELS,
            'editRecord' => $editRecord,
        ]);
    }

    private function saveAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'alimentari']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'alimentari']));

        [$invoice, $uploadError] = $this->storeUpload($_FILES['factura_upload'] ?? null);
        if ($uploadError !== null) {
            flash_set('danger', $uploadError);
            redirect($this->returnUrl());
        }

        $data = $_POST;
        if ($invoice !== null) {
            $data['invoice'] = $invoice;
        }
        $data['remove_invoice'] = isset($_POST['sterge_factura']) && (string) $_POST['sterge_factura'] === '1';

        try {
            $this->model->saveRecord($data);
            $type = (string) ($_POST['tip_inregistrare'] ?? FuelModel::RECORD_REFUEL);
            if ($type === FuelModel::RECORD_T0 && is_array($invoice)) {
                $this->deleteUploadedFile((string) ($invoice['stored'] ?? ''));
            }
            flash_set('success', $type === FuelModel::RECORD_T0 ? 'T0 a fost salvat.' : 'Alimentarea a fost salvata.');
        } catch (Throwable $exception) {
            if (is_array($invoice)) {
                $this->deleteUploadedFile((string) ($invoice['stored'] ?? ''));
            }
            flash_set('danger', $exception->getMessage());
        }

        redirect($this->returnUrl());
    }

    private function deleteAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'alimentari']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'alimentari']));
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash_set('warning', 'Inregistrare invalida.');
            redirect($this->returnUrl());
        }

        try {
            $this->model->deleteRecord($id);
            flash_set('success', 'Inregistrarea a fost stearsa.');
        } catch (Throwable $exception) {
            flash_set('danger', 'Nu s-a putut sterge inregistrarea.');
        }

        redirect($this->returnUrl());
    }

    private function detectTripAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $vehicleId = (int) ($_GET['vehicle_id'] ?? 0);
        $date = trim((string) ($_GET['date'] ?? ''));
        try {
            $trip = $this->model->detectTrip($vehicleId, $date);
            echo json_encode(['ok' => true, 'trip' => $trip], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $exception) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Nu s-a putut cauta cursa.'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    private function tripsCalendarAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $vehicleId = (int) ($_GET['vehicle_id'] ?? 0);
        $year = (int) ($_GET['year'] ?? date('Y'));
        $month = (int) ($_GET['month'] ?? date('n'));
        try {
            $trips = $this->model->getTripsForVehicleMonth($vehicleId, $year, $month);
            echo json_encode(['ok' => true, 'trips' => $trips], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $exception) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Nu s-au putut incarca intervalele Dispecer.'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    private function t0KmSuggestionAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $vehicleId = (int) ($_GET['vehicle_id'] ?? 0);
        $date = trim((string) ($_GET['date'] ?? ''));
        try {
            $suggestion = $this->model->suggestT0Km($vehicleId, $date);
            echo json_encode(['ok' => true, 'suggestion' => $suggestion], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $exception) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Nu s-a putut calcula Km Bord T0.'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    private function refuelKmSuggestionAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $vehicleId = (int) ($_GET['vehicle_id'] ?? 0);
        $date = trim((string) ($_GET['date'] ?? ''));
        $tripId = (int) ($_GET['trip_id'] ?? 0);
        try {
            $suggestion = $this->model->suggestRefuelKm($vehicleId, $date, $tripId);
            echo json_encode(['ok' => true, 'suggestion' => $suggestion], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $exception) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Nu s-a putut calcula Km Bord pentru alimentare.'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    private function exportExcelAction(): void
    {
        $filters = $this->model->normalizeFilters($_GET);
        $dashboard = $this->model->getDashboardData($filters, 100000);
        $fileName = 'alimentare_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }
        fprintf($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            'Nr Inmatriculare', 'Data', 'Tip Inregistrare', 'Interval Cursa Dispecer',
            'Tip Transport', 'Beneficiar', 'Sofer', 'Km Bord', 'Litri', 'Pret/L',
            'Total', 'Furnizor', 'Factura', 'Consum Calculat', 'Consum Normat',
            'Diferenta', 'Observatii',
        ]);
        foreach ($dashboard['rows'] as $row) {
            fputcsv($out, [
                $row['nr_inmatriculare'] ?? '',
                $row['data_alimentare'] ?? '',
                (string) ($row['tip_inregistrare'] ?? '') === FuelModel::RECORD_T0 ? 'T0' : 'Alimentare Normala',
                $row['interval_label'] ?? '',
                $row['transport_label'] ?? '',
                $row['beneficiar_label'] ?? '',
                $row['driver_label'] ?? '',
                $row['km_bord'] ?? '',
                (string) ($row['tip_inregistrare'] ?? '') === FuelModel::RECORD_T0 && !empty($row['full_flag']) ? 'FULL' : ($row['litri'] ?? ''),
                $row['pret_litru'] ?? '',
                $row['cost_total'] ?? '',
                $row['furnizor'] ?? '',
                $row['factura_original'] ?? '',
                $row['consum_calculat'] ?? '',
                $row['consum_normat'] ?? '',
                $row['diferenta_litri'] ?? '',
                $row['observatii'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }

    private function exportPdfAction(): void
    {
        $filters = $this->model->normalizeFilters($_GET);
        $dashboard = $this->model->getDashboardData($filters, 100000);
        $html = $this->buildPrintableReport($dashboard);

        if (class_exists('\\Dompdf\\Dompdf')) {
            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();
            $dompdf->stream('alimentare_' . date('Ymd_His') . '.pdf');
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    private function buildPrintableReport(array $dashboard): string
    {
        ob_start();
        ?>
        <!doctype html>
        <html lang="ro">
        <head>
            <meta charset="utf-8">
            <title>Raport Alimentare</title>
            <style>
                body { font-family: Arial, sans-serif; color: #111827; }
                table { border-collapse: collapse; width: 100%; font-size: 11px; }
                th, td { border: 1px solid #dbe3ef; padding: 6px; text-align: left; }
                th { background: #f3f6fb; }
                .muted { color: #64748b; }
            </style>
        </head>
        <body>
        <h1>Raport Alimentare</h1>
        <p class="muted">T0 este exclus din cheltuieli. Raport generat la <?= e(date('d.m.Y H:i')) ?>.</p>
        <table>
            <thead>
            <tr><th>Vehicul</th><th>Data</th><th>Tip</th><th>Interval</th><th>Transport</th><th>Litri</th><th>Total</th><th>Consum</th><th>Normat</th><th>Diferenta</th></tr>
            </thead>
            <tbody>
            <?php foreach ($dashboard['rows'] as $row): ?>
                <tr>
                    <td><?= e((string) ($row['nr_inmatriculare'] ?? '')) ?></td>
                    <td><?= e(format_date_ro((string) ($row['data_alimentare'] ?? ''))) ?></td>
                    <td><?= (string) ($row['tip_inregistrare'] ?? '') === FuelModel::RECORD_T0 ? 'T0' : 'Alimentare Normala' ?></td>
                    <td><?= e((string) ($row['interval_label'] ?? '-')) ?></td>
                    <td><?= e((string) ($row['transport_label'] ?? '-')) ?></td>
                    <td><?= e(format_number_ro((float) ($row['litri'] ?? 0), 2)) ?></td>
                    <td><?= e(format_number_ro((float) ($row['cost_total'] ?? 0), 2)) ?></td>
                    <td><?= e($row['consum_calculat'] !== null ? format_number_ro((float) $row['consum_calculat'], 2) : '-') ?></td>
                    <td><?= e($row['consum_normat'] !== null ? format_number_ro((float) $row['consum_normat'], 2) : '-') ?></td>
                    <td><?= e($row['diferenta_litri'] !== null ? format_number_ro((float) $row['diferenta_litri'], 2) : '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </body>
        </html>
        <?php
        return (string) ob_get_clean();
    }

    private function storeUpload(mixed $file): array
    {
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [null, null];
        }
        if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return [null, 'Factura nu a putut fi incarcata.'];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return [null, 'Upload invalid pentru factura.'];
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size > 5 * 1024 * 1024) {
            return [null, 'Factura este prea mare. Maxim 5 MB.'];
        }
        $original = $this->sanitizeFileName((string) ($file['name'] ?? 'factura'));
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'], true)) {
            return [null, 'Format factura neacceptat.'];
        }
        $dir = BASE_PATH . '/uploads/alimentari_facturi';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return [null, 'Nu s-a putut crea folderul pentru facturi.'];
        }
        $stored = 'alimentare_factura_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($tmp, $dir . '/' . $stored)) {
            return [null, 'Nu s-a putut salva factura.'];
        }

        return [[
            'original' => $original,
            'stored' => $stored,
            'mime' => (string) ($file['type'] ?? ''),
            'size' => $size,
        ], null];
    }

    private function sanitizeFileName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name) ?: 'factura';
        return trim($name, ' ._') !== '' ? trim($name, ' ._') : 'factura';
    }

    private function deleteUploadedFile(string $stored): void
    {
        $stored = basename(trim($stored));
        if ($stored === '') {
            return;
        }
        $path = BASE_PATH . '/uploads/alimentari_facturi/' . $stored;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function returnUrl(): string
    {
        $returnUrl = trim((string) ($_POST['return_url'] ?? ''));
        if ($returnUrl !== '' && str_starts_with($returnUrl, url(''))) {
            return $returnUrl;
        }

        return build_query_url(['page' => 'alimentari']);
    }
}
