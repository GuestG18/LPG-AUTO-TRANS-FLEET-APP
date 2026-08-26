<?php
declare(strict_types=1);

/**
 * Registru EXPERIMENTAL de piese/lucrari per vehicul, in stil Excel
 * (REPARATII / INLOCUIRI / IMBUNATATIRI), alimentat manual sau prin OCR.
 *
 * Rute (toate doar pentru admin, gate in index.php):
 *   ?page=ocr_piese                    -> grila editabila (trackerul principal)
 *   ?page=ocr_piese&action=intake      -> receptie factura: upload -> OCR -> formular
 *   ?page=ocr_piese&action=run         -> POST fisier, JSON cu antet + linii propuse
 *   ?page=ocr_piese&action=save        -> POST formular confirmat -> factura + randuri registru
 *   ?page=ocr_piese&action=row_add     -> POST, adauga rand gol in grila (JSON)
 *   ?page=ocr_piese&action=row_update  -> POST, salveaza o celula editata inline (JSON)
 *   ?page=ocr_piese&action=row_delete  -> POST, sterge un rand din grila (JSON)
 *
 * Separat complet de stocul de productie (mentenanta_piese).
 */
class OcrPartsController
{
    private const UPLOAD_DIR = 'uploads/ocr_piese';
    private const MAX_LINES = 200;
    private const LINE_TYPES = ['reparatii', 'inlocuiri', 'imbunatatiri'];

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
            case 'row_add':
                $this->rowAdd();
                return;
            case 'row_update':
                $this->rowUpdate();
                return;
            case 'row_delete':
                $this->rowDelete();
                return;
            default:
                $this->index();
        }
    }

    private function index(): void
    {
        $vehicleId = (int) ($_GET['vehicul'] ?? 0);

        render('ocr_piese/index.php', [
            'pageTitle' => 'Registru piese (OCR)',
            'currentPage' => 'ocr_piese',
            'rows' => $this->model->getRegistryRows($vehicleId > 0 ? $vehicleId : null),
            'kpis' => $this->model->getRegistryKpis($vehicleId > 0 ? $vehicleId : null),
            'vehicles' => $this->model->getVehicleOptions(),
            'selectedVehicleId' => $vehicleId,
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

        // Parsarea experimentala nu are voie sa strice raspunsul: daca esueaza,
        // intoarcem textul OCR fara propuneri si utilizatorul completeaza manual.
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

    /**
     * Salveaza formularul confirmat: factura (dovada) + cate un rand de registru
     * pentru fiecare linie, cu textul pus in coloana Reparatii/Inlocuiri/Imbunatatiri.
     */
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

        $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
        $kmBord = trim((string) ($_POST['km_bord'] ?? ''));
        $supplier = mb_substr(trim((string) ($_POST['furnizor'] ?? '')), 0, 190);

        $registryRows = [];
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

            $value = (float) ($line['valoare'] ?? 0);
            $quantity = (float) ($line['cantitate'] ?? 1);
            if (!is_finite($value) || $value < 0 || $value > 9999999999.99 || !is_finite($quantity) || $quantity < 0) {
                http_response_code(422);
                $this->sendJson(['ok' => false, 'error' => 'Linia ' . ((int) $index + 1) . ' („' . mb_substr($name, 0, 40)
                    . '") are o valoare invalidă — probabil OCR-ul a citit greșit. Corectează sau șterge linia.']);
                return;
            }

            $type = in_array($line['tip'] ?? '', self::LINE_TYPES, true) ? (string) $line['tip'] : 'inlocuiri';

            // Textul in stilul Excel: denumire + cod + cantitate cand nu e 1.
            $text = mb_substr($name, 0, 255);
            $code = trim((string) ($line['cod_piesa'] ?? ''));
            if ($code !== '') {
                $text .= ' [' . mb_substr($code, 0, 40) . ']';
            }
            $unit = trim((string) ($line['unitate_masura'] ?? 'buc')) ?: 'buc';
            if ($quantity > 0 && abs($quantity - 1.0) > 0.001) {
                $formattedQty = fmod($quantity, 1.0) === 0.0 ? (string) (int) $quantity : (string) $quantity;
                $text .= ' (' . $formattedQty . ' ' . mb_substr($unit, 0, 20) . ')';
            }

            $registryRows[] = [
                'vehicle_id' => $vehicleId,
                'data_interventie' => $invoiceDate,
                $type => $text,
                'pret' => $value,
                'furnizor' => $supplier,
                'km_bord' => $kmBord !== '' ? (int) $kmBord : null,
            ];
        }

        if ($registryRows === []) {
            http_response_code(422);
            $this->sendJson(['ok' => false, 'error' => 'Adaugă cel puțin un articol înainte de salvare.']);
            return;
        }

        // Fisierul facturii (dovada) este optional dar recomandat.
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
            $this->model->saveInvoiceToRegistry([
                'numar_factura' => mb_substr(trim((string) ($_POST['numar_factura'] ?? '')), 0, 80),
                'data_facturii' => $invoiceDate,
                'furnizor' => $supplier,
                'cui_furnizor' => mb_substr(trim((string) ($_POST['cui_furnizor'] ?? '')), 0, 20),
                'moneda' => mb_substr(trim((string) ($_POST['moneda'] ?? 'RON')), 0, 10),
                'total_factura' => $_POST['total_factura'] ?? null,
                'fisier_original' => $originalFile,
                'fisier_stocat' => $storedFile,
                'ocr_text' => (string) ($_POST['ocr_text'] ?? ''),
                'ocr_durata_ms' => $_POST['ocr_durata_ms'] ?? null,
                'observatii' => (string) ($_POST['observatii'] ?? ''),
            ], $registryRows, isset($user['id']) ? (int) $user['id'] : null);
        } catch (Throwable $exception) {
            error_log('[OcrPartsController][save] ' . $exception->getMessage());
            if ($storedFile !== null) {
                @unlink(BASE_PATH . '/' . self::UPLOAD_DIR . '/' . $storedFile);
            }
            http_response_code(500);
            $this->sendJson(['ok' => false, 'error' => 'Salvarea în registru a eșuat. Detalii în logul serverului.']);
            return;
        }

        flash_set('success', 'Factura a fost salvată: ' . count($registryRows) . ' rânduri adăugate în registru.');
        $this->sendJson([
            'ok' => true,
            'redirect' => build_query_url(['page' => 'ocr_piese', 'vehicul' => $vehicleId > 0 ? $vehicleId : null]),
        ]);
    }

    private function rowAdd(): void
    {
        $this->requirePost();
        $this->requireCsrfJson();

        $user = function_exists('current_user') ? current_user() : null;
        $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);

        try {
            $rowId = $this->model->addRegistryRow(
                $vehicleId > 0 ? $vehicleId : null,
                isset($user['id']) ? (int) $user['id'] : null
            );
        } catch (Throwable $exception) {
            error_log('[OcrPartsController][row_add] ' . $exception->getMessage());
            http_response_code(500);
            $this->sendJson(['ok' => false, 'error' => 'Rândul nu a putut fi adăugat.']);
            return;
        }

        $this->sendJson(['ok' => true, 'row_id' => $rowId, 'data_interventie' => date('Y-m-d')]);
    }

    private function rowUpdate(): void
    {
        $this->requirePost();
        $this->requireCsrfJson();

        $rowId = (int) ($_POST['row_id'] ?? 0);
        $field = (string) ($_POST['field'] ?? '');
        $value = $_POST['value'] ?? null;

        if ($rowId <= 0) {
            http_response_code(422);
            $this->sendJson(['ok' => false, 'error' => 'Rând invalid.']);
            return;
        }

        try {
            $normalized = $this->model->updateRegistryCell($rowId, $field, is_string($value) ? $value : null);
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            $this->sendJson(['ok' => false, 'error' => $exception->getMessage()]);
            return;
        } catch (Throwable $exception) {
            error_log('[OcrPartsController][row_update] ' . $exception->getMessage());
            http_response_code(500);
            $this->sendJson(['ok' => false, 'error' => 'Modificarea nu a putut fi salvată.']);
            return;
        }

        $this->sendJson(['ok' => true, 'value' => $normalized]);
    }

    private function rowDelete(): void
    {
        $this->requirePost();
        $this->requireCsrfJson();

        $rowId = (int) ($_POST['row_id'] ?? 0);
        if ($rowId > 0) {
            try {
                $this->model->deleteRegistryRow($rowId);
            } catch (Throwable $exception) {
                error_log('[OcrPartsController][row_delete] ' . $exception->getMessage());
                http_response_code(500);
                $this->sendJson(['ok' => false, 'error' => 'Ștergerea a eșuat.']);
                return;
            }
        }

        $this->sendJson(['ok' => true]);
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
