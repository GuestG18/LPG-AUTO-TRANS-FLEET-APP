<?php
declare(strict_types=1);

/**
 * Registru EXPERIMENTAL de reparatii, model factura multi-vehicul:
 * un rand parinte = O factura, cu articole unificate (piesa/manopera) alocate
 * per vehicul sau trimise in stoc; alimentat manual sau prin OCR.
 *
 * Rute (toate doar pentru admin, gate in index.php):
 *   ?page=ocr_piese                      -> registrul principal
 *   ?page=ocr_piese&action=intake        -> receptie factura: upload -> OCR -> formular
 *   ?page=ocr_piese&action=run           -> POST fisier, JSON cu antet + linii propuse
 *   ?page=ocr_piese&action=save          -> POST formular confirmat -> O factura cu articole
 *   ?page=ocr_piese&action=export        -> CSV aplatizat, cu filtrele curente
 *   ?page=ocr_piese&action=event_add     -> POST, factura goala (JSON)
 *   ?page=ocr_piese&action=event_update  -> POST, editare camp factura (JSON)
 *   ?page=ocr_piese&action=event_delete  -> POST (JSON)
 *   ?page=ocr_piese&action=item_add      -> POST, articol nou piesa/manopera (JSON)
 *   ?page=ocr_piese&action=item_update   -> POST, editare camp articol (JSON)
 *   ?page=ocr_piese&action=item_delete   -> POST (JSON)
 *   ?page=ocr_piese&action=vehicle_add   -> POST, asociaza un vehicul la factura (JSON)
 *   ?page=ocr_piese&action=mark_verified -> POST, factura scanata "De verificat" -> "Verificată" (JSON)
 *   ?page=ocr_piese&action=send_maintenance -> POST, trimite articolele netrimise in Reparatii Auto
 *                                          (interventie + montare pe componenta / intrare in stoc) si
 *                                          marcheaza factura verificata (JSON)
 *
 * Citirea facturilor (recepția manuala si scanarile venite pe email cu subiectul
 * "piese" / "reparatii" / "service") se face cu Claude, ca la pagina Facturi:
 * PartsInvoiceOcrService. Fluxul automat: OcrPartsScanService + process_invoice_inbox.php.
 *
 * Separat complet de stocul de productie (mentenanta_piese): destinatia "stoc"
 * este inregistrata pe articol, dar NU scrie inca in stocul real (decizie
 * anterioara a utilizatorului de a tine experimentul separat).
 */
class OcrPartsController
{
    private const UPLOAD_DIR = 'uploads/ocr_piese';
    private const MAX_LINES = 200;
    /** Aceeasi limita ca la Facturi (InvoiceStorageService::MAX_SIZE). */
    private const MAX_FILE_BYTES = 10 * 1024 * 1024;
    private const ALLOWED_MIME = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

    private PDO $db;
    private OcrPartsModel $model;
    private PartsInvoiceOcrService $ocrService;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->model = new OcrPartsModel($db);
        $this->ocrService = new PartsInvoiceOcrService();
        $this->model->ensureScanSchema();
    }

    public function handle(string $action): void
    {
        switch ($action) {
            case 'intake':
                $this->intake();
                return;
            case 'run':
                $this->run();
                return;
            case 'save':
                $this->save();
                return;
            case 'export':
                $this->export();
                return;
            case 'event_add':
                $this->eventAdd();
                return;
            case 'event_update':
                $this->eventUpdate();
                return;
            case 'event_delete':
                $this->eventDelete();
                return;
            case 'item_add':
                $this->itemAdd();
                return;
            case 'item_update':
                $this->itemUpdate();
                return;
            case 'item_delete':
                $this->itemDelete();
                return;
            case 'vehicle_add':
                $this->vehicleAdd();
                return;
            case 'vehicle_remove':
                $this->vehicleRemove();
                return;
            case 'mark_verified':
                $this->markVerified();
                return;
            case 'send_maintenance':
                $this->sendMaintenance();
                return;
            default:
                $this->index();
        }
    }

    /** @return array{vehicle_id:int,q:string,date_from:string,date_to:string} */
    private function currentFilters(): array
    {
        $dateFrom = trim((string) ($_GET['de_la'] ?? ''));
        $dateTo = trim((string) ($_GET['pana_la'] ?? ''));

        return [
            'vehicle_id' => (int) ($_GET['vehicul'] ?? 0),
            'q' => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 120),
            'date_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom : '',
            'date_to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? $dateTo : '',
        ];
    }

    private function index(): void
    {
        $filters = $this->currentFilters();
        $perPage = (int) ($_GET['pe_pagina'] ?? 10);
        if (!in_array($perPage, [10, 25, 50, 100], true)) {
            $perPage = 10;
        }
        $page = max(1, (int) ($_GET['pg'] ?? 1));

        $result = $this->model->getInvoiceEvents($filters, $page, $perPage);

        render('ocr_piese/index.php', [
            'pageTitle' => 'Registru piese & lucrări',
            'currentPage' => 'ocr_piese',
            'events' => $result['rows'],
            'totalCount' => $result['total_count'],
            'totals' => $result['totals'],
            'vehicles' => $this->model->getVehicleOptions(),
            'filters' => $filters,
            'perPage' => $perPage,
            'currentPageNo' => $page,
            'expandEventId' => (int) ($_GET['deschide'] ?? 0),
            'expandItemId' => (int) ($_GET['articol'] ?? 0),
            'componentCatalog' => $this->componentCatalogForView(),
        ]);
    }

    private function intake(): void
    {
        render('ocr_piese/intake.php', [
            'pageTitle' => 'Recepție factură piese (OCR)',
            'currentPage' => 'ocr_piese',
            'apiKeyConfigured' => $this->ocrService->isConfigured(),
            'ocrModel' => $this->ocrService->model(),
            'maxFileBytes' => self::MAX_FILE_BYTES,
            'maxImageBytes' => self::MAX_FILE_BYTES,
            'vehicles' => $this->model->getVehicleOptions(),
            'componentCatalog' => $this->componentCatalogForView(),
        ]);
    }

    /**
     * Categoriile din Reparatii Auto cu componentele lor, pentru selectoare.
     *
     * @return array<int,array{id:int,name:string,subcategory:?string,components:array<int,array{key:string,name:string}>}>
     */
    private function componentCatalogForView(): array
    {
        $result = [];
        foreach ((new AutoComponentCatalogService())->categories() as $category) {
            $result[] = [
                'id' => (int) $category['id'],
                'name' => (string) $category['name'],
                'subcategory' => AutoComponentCatalogService::subcategoryFor((int) $category['id']),
                'components' => array_map(
                    static fn (array $c): array => ['key' => (string) $c['id'], 'name' => (string) $c['name']],
                    $category['components']
                ),
            ];
        }

        return $result;
    }

    /** Citeste factura incarcata cu Claude si propune antetul + liniile de articole. */
    private function run(): void
    {
        $this->requirePost();
        $this->requireCsrfJson();

        $file = $_FILES['invoice'] ?? null;
        try {
            $mime = $this->validateInvoiceUpload(is_array($file) ? $file : null);
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            $this->sendJson(['ok' => false, 'error' => $exception->getMessage()]);
            return;
        }
        if (!$this->ocrService->isConfigured()) {
            http_response_code(503);
            $this->sendJson(['ok' => false, 'error' => 'ANTHROPIC_API_KEY lipsește din .env — completează formularul manual.']);
            return;
        }

        $started = microtime(true);
        try {
            $result = $this->ocrService->extract((string) $file['tmp_name'], $mime, ['flota' => $this->model->getFleetPlates()]);
        } catch (InvoiceOcrException $exception) {
            http_response_code($exception->retryable ? 503 : 422);
            $this->sendJson(['ok' => false, 'error' => $exception->getMessage()]);
            return;
        } catch (Throwable $exception) {
            error_log('[OcrPartsController][run] ' . $exception->getMessage());
            http_response_code(500);
            $this->sendJson(['ok' => false, 'error' => 'Eroare internă la citirea facturii.']);
            return;
        }
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $invoices = $result['facturi'];
        if ($invoices === []) {
            $this->sendJson(['ok' => false, 'error' => 'Nu am găsit nicio factură în fișier. Completează manual sau încearcă altă scanare.']);
            return;
        }

        // Formularul primeste primul document; restul se semnaleaza (se pot incarca separat).
        $invoice = $invoices[0];
        $plateMap = $this->model->vehicleIdsByPlateKey();
        $invoiceVehicles = [];
        $unknownPlates = [];
        foreach ($invoice['nr_inmatriculare'] as $plate) {
            $vehicleId = $plateMap[PartsInvoiceOcrService::plateKey($plate)] ?? null;
            if ($vehicleId !== null) {
                $invoiceVehicles[$vehicleId] = $vehicleId;
            } else {
                $unknownPlates[$plate] = true;
            }
        }
        $singleVehicle = count($invoiceVehicles) === 1 ? (int) reset($invoiceVehicles) : null;

        $lines = [];
        foreach ($invoice['articole'] as $item) {
            $vehicleId = null;
            if ($item['nr_inmatriculare'] !== null) {
                $vehicleId = $plateMap[PartsInvoiceOcrService::plateKey($item['nr_inmatriculare'])] ?? null;
                if ($vehicleId === null) {
                    $unknownPlates[$item['nr_inmatriculare']] = true;
                }
            }
            $lines[] = [
                'denumire' => $item['denumire'],
                'cod_piesa' => $item['cod_piesa'],
                'tip' => $item['tip'],
                'tip_lucrare' => $item['tip_lucrare'],
                'destinatie' => $item['pentru_stoc'] ? 'stoc' : 'vehicul',
                'vehicle_id' => $item['pentru_stoc'] ? null : ($vehicleId ?? $singleVehicle),
                'unitate_masura' => $item['unitate_masura'],
                'cantitate' => $item['cantitate'],
                'pret_unitar' => $item['pret_unitar'],
                'valoare' => $item['valoare'] ?? round($item['cantitate'] * $item['pret_unitar'], 2),
                'verificat' => $item['verificat'],
                'garantie_luni' => $item['garantie_luni'],
                'km_bord' => $item['km_bord'],
            // Componenta: doar din locurile confirmate anterior (fara AI, fara tokeni).
            ] + $this->model->suggestPlacement($item['denumire'], $item['cod_piesa']);
        }

        $warnings = [];
        if (count($invoices) > 1) {
            $warnings[] = 'Fișierul conține ' . count($invoices) . ' documente; formularul arată doar primul (paginile '
                . ($invoice['pagini'] ?? '?') . '). Celelalte se încarcă separat sau prin scanare pe email.';
        }
        if ($unknownPlates !== []) {
            $warnings[] = 'Nr. de pe factură care nu sunt în flotă: ' . implode(', ', array_keys($unknownPlates)) . '.';
        }
        if (count($invoiceVehicles) > 1) {
            $warnings[] = 'Factura are ' . count($invoiceVehicles) . ' vehicule — verifică vehiculul pe fiecare rând.';
        }

        $this->sendJson([
            'ok' => true,
            'duration_ms' => $durationMs,
            'engine' => $result['model'],
            'parsed_text' => json_encode($invoice, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'header' => [
                'numar_factura' => $invoice['numar_document'],
                'data_facturii' => $invoice['data_document'],
                'furnizor' => $invoice['furnizor'],
                'cui' => $invoice['cui_furnizor'],
                'moneda' => $invoice['moneda'],
                'total' => $invoice['valoare_cu_tva'],
                'vehicle_id' => $singleVehicle,
                'km_bord' => count($invoiceVehicles) <= 1 ? $invoice['km_bord'] : null,
                'observatii' => $invoice['observatii'],
            ],
            'lines' => $lines,
            'parse_warning' => $warnings !== [] ? implode(' ', $warnings) : null,
        ]);
    }

    /**
     * Validare upload (PDF / JPG / PNG, max 10 MB). Intoarce tipul MIME real.
     *
     * @throws InvalidArgumentException cu mesaj afisabil
     */
    private function validateInvoiceUpload(?array $file): string
    {
        if ($file === null || !isset($file['error']) || is_array($file['error'])) {
            throw new InvalidArgumentException('Nu a fost primit niciun fișier. Selectează o factură și reîncearcă.');
        }
        if ((int) $file['error'] === UPLOAD_ERR_INI_SIZE || (int) $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new InvalidArgumentException('Fișierul depășește limita de upload a serverului.');
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Upload-ul a eșuat (cod ' . (int) $file['error'] . '). Reîncearcă.');
        }
        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new InvalidArgumentException('Fișierul nu a ajuns pe server. Reîncearcă upload-ul.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException($size <= 0 ? 'Fișierul este gol.' : 'Fișierul depășește 10 MB.');
        }

        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string) finfo_file($finfo, $tmpPath);
        finfo_close($finfo);
        if (!isset(self::ALLOWED_MIME[$extension]) || self::ALLOWED_MIME[$extension] !== $mime) {
            throw new InvalidArgumentException('Tip de fișier neacceptat. Formate permise: PDF, JPG, JPEG, PNG.');
        }

        return $mime;
    }

    /** Salveaza formularul OCR confirmat: O factura + articole unificate (piesa/manopera). */
    private function save(): void
    {
        $this->requirePost();
        $this->requireCsrfJson();

        $lines = json_decode((string) ($_POST['lines'] ?? '[]'), true);
        if (!is_array($lines) || count($lines) > self::MAX_LINES) {
            http_response_code(422);
            $this->sendJson(['ok' => false, 'error' => 'Liniile de articole nu au putut fi citite (sau sunt prea multe).']);
            return;
        }

        $invoiceDate = trim((string) ($_POST['data_facturii'] ?? ''));
        if ($invoiceDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate)) {
            http_response_code(422);
            $this->sendJson(['ok' => false, 'error' => 'Data facturii are un format invalid.']);
            return;
        }

        $defaultVehicleId = (int) ($_POST['vehicle_id'] ?? 0);
        $items = [];
        foreach ($lines as $index => $line) {
            if (!is_array($line)) {
                continue;
            }
            $name = trim((string) ($line['denumire'] ?? ''));
            if ($name === '') {
                http_response_code(422);
                $this->sendJson(['ok' => false, 'error' => 'Linia ' . ((int) $index + 1) . ' nu are denumire. Completează sau șterge linia.']);
                return;
            }
            $quantity = (float) ($line['cantitate'] ?? 1);
            $unitPrice = (float) ($line['pret_unitar'] ?? 0);
            if (!is_finite($quantity) || $quantity < 0 || !is_finite($unitPrice) || $unitPrice < 0 || $unitPrice > 9999999999.99) {
                http_response_code(422);
                $this->sendJson(['ok' => false, 'error' => 'Linia ' . ((int) $index + 1) . ' („' . mb_substr($name, 0, 40)
                    . '") are o valoare invalidă — probabil OCR-ul a citit greșit. Corectează sau șterge linia.']);
                return;
            }

            $lineVehicle = isset($line['vehicle_id']) && (int) $line['vehicle_id'] > 0
                ? (int) $line['vehicle_id']
                : $defaultVehicleId;
            $items[] = [
                'tip' => ($line['tip'] ?? 'piesa') === 'manopera' ? 'manopera' : 'piesa',
                'denumire' => $name,
                'cod_piesa' => trim((string) ($line['cod_piesa'] ?? '')),
                'cantitate' => $quantity > 0 ? $quantity : 1,
                'pret_unitar' => $unitPrice,
                'tip_lucrare' => (string) ($line['tip_lucrare'] ?? 'reparatie'),
                'destinatie' => ($line['destinatie'] ?? 'vehicul') === 'stoc' ? 'stoc' : 'vehicul',
                'vehicle_id' => $lineVehicle,
                // Citite de pe factura (garantie; km pe articol la facturile multi-vehicul).
                'garantie_luni' => isset($line['garantie_luni']) && is_numeric($line['garantie_luni']) ? (int) $line['garantie_luni'] : null,
                'auto_component_key' => is_string($line['auto_component_key'] ?? null) ? $line['auto_component_key'] : null,
                'auto_primary' => is_string($line['auto_primary'] ?? null) ? $line['auto_primary'] : null,
                'auto_sursa' => in_array($line['auto_sursa'] ?? null, ['invatat', 'manual'], true) ? $line['auto_sursa'] : 'manual',
            ] + (isset($line['km_bord']) && is_numeric($line['km_bord']) && (int) $line['km_bord'] > 0 ? ['km_bord' => (int) $line['km_bord']] : []);
        }

        if ($items === []) {
            http_response_code(422);
            $this->sendJson(['ok' => false, 'error' => 'Adaugă cel puțin un articol înainte de salvare.']);
            return;
        }

        $storedFile = null;
        $originalFile = null;
        $file = $_FILES['invoice'] ?? null;
        if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $this->validateInvoiceUpload($file);
                [$originalFile, $storedFile] = $this->storeInvoiceFile($file);
            } catch (InvalidArgumentException $exception) {
                http_response_code(422);
                $this->sendJson(['ok' => false, 'error' => 'Fișierul facturii: ' . $exception->getMessage()]);
                return;
            }
        }

        $user = function_exists('current_user') ? current_user() : null;

        try {
            $eventId = $this->model->saveInvoiceAsEventV2([
                'numar_factura' => mb_substr(trim((string) ($_POST['numar_factura'] ?? '')), 0, 80),
                'data_facturii' => $invoiceDate,
                'furnizor' => mb_substr(trim((string) ($_POST['furnizor'] ?? '')), 0, 190),
                'cui_furnizor' => mb_substr(trim((string) ($_POST['cui_furnizor'] ?? '')), 0, 20),
                'moneda' => mb_substr(trim((string) ($_POST['moneda'] ?? 'RON')), 0, 10),
                'total_factura' => $_POST['total_factura'] ?? null,
                'fisier_original' => $originalFile,
                'fisier_stocat' => $storedFile,
                'ocr_text' => (string) ($_POST['ocr_text'] ?? ''),
                'ocr_durata_ms' => $_POST['ocr_durata_ms'] ?? null,
                'observatii' => (string) ($_POST['observatii'] ?? ''),
                'km_bord' => trim((string) ($_POST['km_bord'] ?? '')),
            ], $items, isset($user['id']) ? (int) $user['id'] : null);
        } catch (Throwable $exception) {
            error_log('[OcrPartsController][save] ' . $exception->getMessage());
            if ($storedFile !== null) {
                @unlink(BASE_PATH . '/' . self::UPLOAD_DIR . '/' . $storedFile);
            }
            http_response_code(500);
            $this->sendJson(['ok' => false, 'error' => 'Salvarea în registru a eșuat. Detalii în logul serverului.']);
            return;
        }

        // Formularul salvat = componente confirmate de operator: se tin minte pentru facturile urmatoare.
        $this->model->learnFromEvent($eventId);

        flash_set('success', 'Factura a fost salvată: ' . count($items) . ' articole.');
        $this->sendJson([
            'ok' => true,
            'redirect' => build_query_url(['page' => 'ocr_piese', 'deschide' => $eventId]),
        ]);
    }

    /** Export CSV aplatizat (o linie per articol), respectand filtrele curente. */
    private function export(): void
    {
        $rows = $this->model->getExportRowsV2($this->currentFilters());

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="registru_piese_lucrari.csv"');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Document', 'Furnizor', 'Data facturii', 'Vehicul', 'Tip articol', 'Denumire', 'Cod piesa',
            'Tip lucrare', 'Cantitate', 'Pret unitar', 'Total linie', 'Garantie (luni)', 'Garantie pana la',
            'Destinatie', 'Data montarii/receptiei', 'KM bord', 'Depozit', 'Observatii factura'], ';');
        foreach ($rows as $row) {
            $qty = (float) ($row['cantitate'] ?? 0);
            $price = (float) ($row['pret_unitar'] ?? 0);
            $hasItem = $row['tip'] !== null;
            fputcsv($out, [
                (string) ($row['document'] ?? ''),
                (string) ($row['furnizor'] ?? ''),
                $row['data_interventie'] !== null ? date('d.m.Y', strtotime((string) $row['data_interventie'])) : '',
                (string) ($row['vehicul'] ?? ''),
                $hasItem ? ($row['tip'] === 'manopera' ? 'Manopera' : 'Piesa') : '',
                (string) ($row['denumire'] ?? ''),
                (string) ($row['cod_piesa'] ?? ''),
                $hasItem ? (OcrPartsModel::TIP_LUCRARE_OPTIONS[(string) ($row['tip_lucrare'] ?? '')] ?? (string) $row['tip_lucrare']) : '',
                $hasItem ? number_format($qty, 2, ',', '') : '',
                $hasItem ? number_format($price, 2, ',', '') : '',
                $hasItem ? number_format($qty * $price, 2, ',', '') : '',
                $row['garantie_luni'] !== null ? (string) $row['garantie_luni'] : '',
                $row['garantie_pana_la'] !== null ? date('d.m.Y', strtotime((string) $row['garantie_pana_la'])) : '',
                $hasItem ? ($row['destinatie'] === 'stoc' ? 'Stoc' : 'Vehicul') : '',
                $row['data_referinta'] !== null ? date('d.m.Y', strtotime((string) $row['data_referinta'])) : '',
                $row['km_bord'] !== null ? (string) $row['km_bord'] : '',
                (string) ($row['depozit'] ?? ''),
                (string) ($row['observatii'] ?? ''),
            ], ';');
        }
        fclose($out);
        exit;
    }

    private function eventAdd(): void
    {
        $this->requirePost();
        $this->requireCsrfJson();

        $user = function_exists('current_user') ? current_user() : null;

        try {
            $eventId = $this->model->addEvent(null, isset($user['id']) ? (int) $user['id'] : null);
            $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
            if ($vehicleId > 0) {
                $this->model->addVehicleToInvoice($eventId, $vehicleId);
            }
        } catch (Throwable $exception) {
            error_log('[OcrPartsController][event_add] ' . $exception->getMessage());
            http_response_code(500);
            $this->sendJson(['ok' => false, 'error' => 'Factura nu a putut fi adăugată.']);
            return;
        }

        $this->sendJson([
            'ok' => true,
            'redirect' => build_query_url(['page' => 'ocr_piese', 'deschide' => $eventId]),
        ]);
    }

    private function eventUpdate(): void
    {
        $this->requirePost();
        $this->requireCsrfJson();

        $eventId = (int) ($_POST['event_id'] ?? 0);
        $field = (string) ($_POST['field'] ?? '');
        if ($eventId <= 0) {
            http_response_code(422);
            $this->sendJson(['ok' => false, 'error' => 'Factură invalidă.']);
            return;
        }

        try {
            $normalized = $this->model->updateInvoiceField($eventId, $field, is_string($_POST['value'] ?? null) ? (string) $_POST['value'] : null);
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            $this->sendJson(['ok' => false, 'error' => $exception->getMessage()]);
            return;
        } catch (Throwable $exception) {
            error_log('[OcrPartsController][event_update] ' . $exception->getMessage());
            http_response_code(500);
            $this->sendJson(['ok' => false, 'error' => 'Modificarea nu a putut fi salvată.']);
            return;
        }

        $this->sendJson(['ok' => true, 'value' => $normalized]);
    }

    private function eventDelete(): void
    {
        $this->requirePost();
        $this->requireCsrfJson();

        $eventId = (int) ($_POST['event_id'] ?? 0);
        if ($eventId > 0) {
            try {
                $orphanFile = $this->model->deleteEvent($eventId);
                if ($orphanFile !== null) {
                    @unlink(BASE_PATH . '/' . self::UPLOAD_DIR . '/' . basename($orphanFile));
                }
            } catch (Throwable $exception) {
                error_log('[OcrPartsController][event_delete] ' . $exception->getMessage());
                http_response_code(500);
                $this->sendJson(['ok' => false, 'error' => 'Ștergerea a eșuat.']);
                return;
            }
        }

        $this->sendJson(['ok' => true]);
    }

    private function markVerified(): void
    {
        $this->requirePost();
        $this->requireCsrfJson();

        try {
            $this->model->markScanVerified((int) ($_POST['event_id'] ?? 0));
        } catch (Throwable $exception) {
            error_log('[OcrPartsController][mark_verified] ' . $exception->getMessage());
            http_response_code(500);
            $this->sendJson(['ok' => false, 'error' => 'Marcarea nu a putut fi salvată.']);
            return;
        }

        $this->sendJson(['ok' => true]);
    }

    private function sendMaintenance(): void
    {
        $this->requirePost();
        $this->requireCsrfJson();

        $eventId = (int) ($_POST['event_id'] ?? 0);
        $user = function_exists('current_user') ? current_user() : null;
        try {
            $summary = (new OcrPartsMaintenanceSyncService($this->db))->send($eventId, isset($user['id']) ? (int) $user['id'] : null);
            // Trimisa (chiar si partial) = operatorul a verificat-o; componentele devin locuri confirmate.
            $this->model->markScanVerified($eventId);
            $this->model->learnFromEvent($eventId);
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            $this->sendJson(['ok' => false, 'error' => $exception->getMessage()]);
            return;
        } catch (Throwable $exception) {
            error_log('[OcrPartsController][send_maintenance] ' . $exception->getMessage());
            http_response_code(500);
            $this->sendJson(['ok' => false, 'error' => 'Trimiterea în Reparații a eșuat. Detalii în logul serverului.']);
            return;
        }

        $this->sendJson(['ok' => true, 'summary' => $summary, 'sent_items' => $this->model->getSentItemIds($eventId)]);
    }

    private function itemAdd(): void
    {
        $this->requirePost();
        $this->requireCsrfJson();

        $eventId = (int) ($_POST['event_id'] ?? 0);
        if ($eventId <= 0) {
            http_response_code(422);
            $this->sendJson(['ok' => false, 'error' => 'Factură invalidă.']);
            return;
        }

        try {
            $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
            $itemId = $this->model->addItem($eventId, (string) ($_POST['tip'] ?? 'piesa'), $vehicleId > 0 ? $vehicleId : null);
        } catch (Throwable $exception) {
            error_log('[OcrPartsController][item_add] ' . $exception->getMessage());
            http_response_code(500);
            $this->sendJson(['ok' => false, 'error' => 'Articolul nu a putut fi adăugat.']);
            return;
        }

        $this->sendJson(['ok' => true, 'item_id' => $itemId, 'totals' => $this->model->getInvoiceTotals($eventId)]);
    }

    private function itemUpdate(): void
    {
        $this->requirePost();
        $this->requireCsrfJson();

        $itemId = (int) ($_POST['item_id'] ?? 0);
        $field = (string) ($_POST['field'] ?? '');
        if ($itemId <= 0) {
            http_response_code(422);
            $this->sendJson(['ok' => false, 'error' => 'Articol invalid.']);
            return;
        }

        try {
            $result = $this->model->updateItemField($itemId, $field, is_string($_POST['value'] ?? null) ? (string) $_POST['value'] : null);
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            $this->sendJson(['ok' => false, 'error' => $exception->getMessage()]);
            return;
        } catch (Throwable $exception) {
            error_log('[OcrPartsController][item_update] ' . $exception->getMessage());
            http_response_code(500);
            $this->sendJson(['ok' => false, 'error' => 'Modificarea nu a putut fi salvată.']);
            return;
        }

        $eventId = $this->model->getItemEventId($itemId);

        $this->sendJson([
            'ok' => true,
            'value' => $result['value'],
            'garantie_pana_la' => $result['garantie_pana_la'],
            'garantie_manuala' => $result['garantie_manuala'],
            'placement' => $result['placement'] ?? null,
            'totals' => $eventId !== null ? $this->model->getInvoiceTotals($eventId) : null,
        ]);
    }

    private function itemDelete(): void
    {
        $this->requirePost();
        $this->requireCsrfJson();

        $itemId = (int) ($_POST['item_id'] ?? 0);
        if ($itemId <= 0) {
            http_response_code(422);
            $this->sendJson(['ok' => false, 'error' => 'Articol invalid.']);
            return;
        }

        try {
            $eventId = $this->model->deleteItem($itemId);
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            $this->sendJson(['ok' => false, 'error' => $exception->getMessage()]);
            return;
        } catch (Throwable $exception) {
            error_log('[OcrPartsController][item_delete] ' . $exception->getMessage());
            http_response_code(500);
            $this->sendJson(['ok' => false, 'error' => 'Ștergerea a eșuat.']);
            return;
        }

        $this->sendJson([
            'ok' => true,
            'totals' => $eventId !== null ? $this->model->getInvoiceTotals($eventId) : null,
        ]);
    }

    private function vehicleAdd(): void
    {
        $this->requirePost();
        $this->requireCsrfJson();

        $eventId = (int) ($_POST['event_id'] ?? 0);
        $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
        if ($eventId <= 0 || $vehicleId <= 0) {
            http_response_code(422);
            $this->sendJson(['ok' => false, 'error' => 'Factură sau vehicul invalid.']);
            return;
        }

        try {
            $added = $this->model->addVehicleToInvoice($eventId, $vehicleId);
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            $this->sendJson(['ok' => false, 'error' => $exception->getMessage()]);
            return;
        } catch (Throwable $exception) {
            error_log('[OcrPartsController][vehicle_add] ' . $exception->getMessage());
            http_response_code(500);
            $this->sendJson(['ok' => false, 'error' => 'Vehiculul nu a putut fi asociat.']);
            return;
        }

        $this->sendJson(['ok' => true, 'added' => $added]);
    }

    /**
     * Elimina un vehicul de pe factura (doar asocierea; vehiculul din flota
     * ramane neatins). Modurile cu articole asociate sunt rezolvate explicit.
     */
    private function vehicleRemove(): void
    {
        $this->requirePost();
        $this->requireCsrfJson();

        $eventId = (int) ($_POST['event_id'] ?? 0);
        $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
        $mode = (string) ($_POST['mode'] ?? 'remove');
        $targetVehicleId = (int) ($_POST['target_vehicle_id'] ?? 0);

        try {
            $totals = $this->model->removeVehicleFromInvoice(
                $eventId,
                $vehicleId,
                $mode,
                $targetVehicleId > 0 ? $targetVehicleId : null
            );
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            $this->sendJson(['ok' => false, 'error' => $exception->getMessage()]);
            return;
        } catch (Throwable $exception) {
            error_log('[OcrPartsController][vehicle_remove] ' . $exception->getMessage());
            http_response_code(500);
            $this->sendJson(['ok' => false, 'error' => 'Eliminarea vehiculului a eșuat.']);
            return;
        }

        $this->sendJson(['ok' => true, 'totals' => $totals]);
    }

    /** @return array{0:string,1:string} [nume original, nume stocat] */
    private function storeInvoiceFile(array $file): array
    {
        $directory = BASE_PATH . '/' . self::UPLOAD_DIR;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new InvalidArgumentException('Directorul de upload nu a putut fi creat.');
        }

        $originalName = (string) ($file['name'] ?? 'factura');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $storedName = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;

        if (!move_uploaded_file((string) $file['tmp_name'], $directory . '/' . $storedName)) {
            throw new InvalidArgumentException('Fișierul nu a putut fi salvat pe server.');
        }

        return [mb_substr($originalName, 0, 255), $storedName];
    }

    private function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            $this->sendJson(['ok' => false, 'error' => 'Metodă HTTP invalidă.']);
        }
    }

    private function requireCsrfJson(): void
    {
        if (!verify_csrf_token($_POST['_token'] ?? null)) {
            http_response_code(403);
            $this->sendJson(['ok' => false, 'error' => 'Token CSRF invalid. Reîncarcă pagina și încearcă din nou.']);
        }
    }

    private function sendJson(array $payload): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }
}
