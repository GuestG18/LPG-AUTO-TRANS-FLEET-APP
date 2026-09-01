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
 *
 * Separat complet de stocul de productie (mentenanta_piese): destinatia "stoc"
 * este inregistrata pe articol, dar NU scrie inca in stocul real (decizie
 * anterioara a utilizatorului de a tine experimentul separat).
 */
class OcrPartsController
{
    private const UPLOAD_DIR = 'uploads/ocr_piese';
    private const MAX_LINES = 200;

    private PDO $db;
    private OcrPartsModel $model;
    private OcrSpaceService $ocrService;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->model = new OcrPartsModel($db);
        $this->ocrService = new OcrSpaceService();
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
        ]);
    }

    private function intake(): void
    {
        render('ocr_piese/intake.php', [
            'pageTitle' => 'Recepție factură piese (OCR)',
            'currentPage' => 'ocr_piese',
            'apiKeyConfigured' => $this->ocrService->isConfigured(),
            'maxFileBytes' => $this->ocrService->maxFileBytes(),
            'maxImageBytes' => $this->ocrService->maxImageUploadBytes(),
            'vehicles' => $this->model->getVehicleOptions(),
        ]);
    }

    /** Ruleaza OCR pe fisierul incarcat si propune antetul + liniile de articole. */
    private function run(): void
    {
        $this->requirePost();
        $this->requireCsrfJson();

        $file = $_FILES['invoice'] ?? null;

        try {
            $result = $this->ocrService->recognizeUploadedFile(is_array($file) ? $file : null);
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            $this->sendJson(['ok' => false, 'error' => $exception->getMessage()]);
            return;
        } catch (RuntimeException $exception) {
            http_response_code(503);
            $this->sendJson(['ok' => false, 'error' => $exception->getMessage()]);
            return;
        } catch (Throwable $exception) {
            error_log('[OcrPartsController][run] ' . $exception->getMessage());
            http_response_code(500);
            $this->sendJson(['ok' => false, 'error' => 'Eroare internă la procesarea OCR.']);
            return;
        }

        if (!$result['success']) {
            $this->sendJson([
                'ok' => false,
                'error' => $result['error'],
                'error_details' => $result['error_details'],
                'duration_ms' => $result['duration_ms'],
            ]);
            return;
        }

        $headerAnalysis = ['fields' => []];
        $proposedLines = [];
        $parseWarning = null;
        try {
            $headerAnalysis = OcrInvoiceHeuristics::analyze($result['parsed_text']);
            $proposedLines = OcrPartsLineExtractor::extract($result['parsed_text']);
        } catch (Throwable $exception) {
            error_log('[OcrPartsController][run][parse] ' . $exception->getMessage());
            $parseWarning = 'Parsarea automată a articolelor a eșuat — textul OCR este disponibil, completează liniile manual.';
        }

        $this->sendJson([
            'ok' => true,
            'duration_ms' => $result['duration_ms'],
            'engine' => $result['engine'],
            'parsed_text' => $result['parsed_text'],
            'header' => $headerAnalysis['fields'],
            'lines' => $proposedLines,
            'parse_warning' => $parseWarning,
            'compression_note' => $result['compression_note'] ?? null,
        ]);
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
            ];
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
                $this->ocrService->validateUpload($file);
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
                $this->model->deleteEvent($eventId);
            } catch (Throwable $exception) {
                error_log('[OcrPartsController][event_delete] ' . $exception->getMessage());
                http_response_code(500);
                $this->sendJson(['ok' => false, 'error' => 'Ștergerea a eșuat.']);
                return;
            }
        }

        $this->sendJson(['ok' => true]);
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
