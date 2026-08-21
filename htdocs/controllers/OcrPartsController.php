<?php
declare(strict_types=1);

/**
 * Tracker EXPERIMENTAL piese auto receptionate din facturi citite cu OCR.
 *
 * Rute (toate doar pentru admin, gate in index.php):
 *   ?page=ocr_piese                -> lista facturi + articole (tracker)
 *   ?page=ocr_piese&action=intake  -> ecran de receptie: upload -> OCR -> formular editabil
 *   ?page=ocr_piese&action=run     -> POST fisier, JSON cu antet + linii propuse de OCR
 *   ?page=ocr_piese&action=save    -> POST formular confirmat, salveaza in tracker
 *   ?page=ocr_piese&action=delete  -> POST stergere factura din tracker
 *
 * Separat complet de stocul de productie (mentenanta_piese).
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
            case 'delete':
                $this->delete();
                return;
            default:
                $this->index();
        }
    }

    private function index(): void
    {
        render('ocr_piese/index.php', [
            'pageTitle' => 'Tracker piese OCR (experimental)',
            'currentPage' => 'ocr_piese',
            'invoices' => $this->model->getInvoicesWithLines(),
            'kpis' => $this->model->getKpis(),
        ]);
    }

    private function intake(): void
    {
        render('ocr_piese/intake.php', [
            'pageTitle' => 'Recepție factură piese (OCR)',
            'currentPage' => 'ocr_piese',
            'apiKeyConfigured' => $this->ocrService->isConfigured(),
            'maxFileBytes' => $this->ocrService->maxFileBytes(),
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
        ]);
    }

    /** Salveaza formularul confirmat de utilizator (antet + linii + fisierul facturii). */
    private function save(): void
    {
        $this->requirePost();
        $this->requireCsrfJson();

        $linesJson = (string) ($_POST['lines'] ?? '[]');
        $lines = json_decode($linesJson, true);
        if (!is_array($lines)) {
            http_response_code(422);
            $this->sendJson(['ok' => false, 'error' => 'Liniile de articole nu au putut fi citite. Reîncearcă.']);
            return;
        }
        if (count($lines) > self::MAX_LINES) {
            http_response_code(422);
            $this->sendJson(['ok' => false, 'error' => 'Prea multe linii (maxim ' . self::MAX_LINES . ').']);
            return;
        }

        $cleanLines = [];
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
            // Coloanele sunt DECIMAL(12,2): validam aici ca sa dam un mesaj
            // clar in loc de o eroare SQL "out of range".
            foreach (['cantitate' => 'Cantitatea', 'pret_unitar' => 'Prețul unitar', 'valoare' => 'Valoarea'] as $field => $label) {
                $amount = (float) ($line[$field] ?? 0);
                if (!is_finite($amount) || $amount < 0 || $amount > 9999999999.99) {
                    http_response_code(422);
                    $this->sendJson(['ok' => false, 'error' => $label . ' de pe linia ' . ((int) $index + 1)
                        . ' („' . mb_substr($name, 0, 40) . '") nu este un număr valid — probabil OCR-ul a citit greșit. Corectează sau șterge linia.']);
                    return;
                }
            }
            $cleanLines[] = [
                'denumire' => mb_substr($name, 0, 255),
                'cod_piesa' => mb_substr(trim((string) ($line['cod_piesa'] ?? '')), 0, 80),
                'categorie' => mb_substr(trim((string) ($line['categorie'] ?? '')), 0, 100),
                'unitate_masura' => mb_substr(trim((string) ($line['unitate_masura'] ?? 'buc')), 0, 30),
                'cantitate' => (float) ($line['cantitate'] ?? 1),
                'pret_unitar' => (float) ($line['pret_unitar'] ?? 0),
                'valoare' => (float) ($line['valoare'] ?? 0),
                'din_ocr' => !empty($line['din_ocr']),
                'observatii' => mb_substr(trim((string) ($line['observatii'] ?? '')), 0, 1000),
            ];
        }

        if ($cleanLines === []) {
            http_response_code(422);
            $this->sendJson(['ok' => false, 'error' => 'Adaugă cel puțin un articol înainte de salvare.']);
            return;
        }

        $invoiceDate = trim((string) ($_POST['data_facturii'] ?? ''));
        if ($invoiceDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate)) {
            http_response_code(422);
            $this->sendJson(['ok' => false, 'error' => 'Data facturii are un format invalid.']);
            return;
        }

        // Fisierul facturii este optional la salvare (dar recomandat): pastram
        // dovada pentru verificari ulterioare.
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
            $invoiceId = $this->model->saveInvoice([
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
            ], $cleanLines, isset($user['id']) ? (int) $user['id'] : null);
        } catch (Throwable $exception) {
            error_log('[OcrPartsController][save] ' . $exception->getMessage());
            if ($storedFile !== null) {
                @unlink(BASE_PATH . '/' . self::UPLOAD_DIR . '/' . $storedFile);
            }
            http_response_code(500);
            $this->sendJson(['ok' => false, 'error' => 'Salvarea în tracker a eșuat. Detalii în logul serverului.']);
            return;
        }

        flash_set('success', 'Factura a fost salvată în tracker (' . count($cleanLines) . ' articole).');
        $this->sendJson([
            'ok' => true,
            'invoice_id' => $invoiceId,
            'redirect' => build_query_url(['page' => 'ocr_piese']),
        ]);
    }

    private function delete(): void
    {
        $this->requirePost();
        ensure_csrf_or_redirect(build_query_url(['page' => 'ocr_piese']));

        $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
        if ($invoiceId > 0) {
            try {
                $storedFile = $this->model->deleteInvoice($invoiceId);
                if ($storedFile !== null) {
                    @unlink(BASE_PATH . '/' . self::UPLOAD_DIR . '/' . basename($storedFile));
                }
                flash_set('success', 'Factura a fost ștearsă din tracker.');
            } catch (Throwable $exception) {
                error_log('[OcrPartsController][delete] ' . $exception->getMessage());
                flash_set('danger', 'Ștergerea a eșuat.');
            }
        }

        redirect(build_query_url(['page' => 'ocr_piese']));
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
