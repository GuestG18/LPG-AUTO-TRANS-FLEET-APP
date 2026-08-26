<?php
declare(strict_types=1);

/**
 * Sandbox EXPERIMENTAL pentru testarea OCR.Space pe facturi romanesti.
 *
 * Rute:
 *   ?page=dev_ocr_test             -> pagina de laborator (upload + rezultate)
 *   ?page=dev_ocr_test&action=run  -> POST fisier, intoarce JSON cu rezultatul OCR
 *
 * Pagina NU scrie nimic in baza de date, NU creeaza cheltuieli si NU pastreaza
 * fisierele incarcate. Este doar un instrument de evaluare inainte de o
 * eventuala integrare OCR in fluxul real de facturi.
 */
class DevOcrTestController
{
    private PDO $db;
    private OcrSpaceService $service;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->service = new OcrSpaceService();
    }

    public function handle(string $action): void
    {
        if ($action === 'run') {
            $this->run();
            return;
        }

        $this->index();
    }

    private function index(): void
    {
        render('dev_ocr_test/index.php', [
            'pageTitle' => 'OCR Invoice Sandbox (dev)',
            'currentPage' => 'dev_ocr_test',
            'apiKeyConfigured' => $this->service->isConfigured(),
            'engineLabel' => $this->service->engineLabel(),
            'maxFileBytes' => $this->service->maxFileBytes(),
            'maxImageBytes' => $this->service->maxImageUploadBytes(),
            'allowedExtensions' => $this->service->allowedExtensions(),
        ]);
    }

    private function run(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            $this->sendJson(['ok' => false, 'error' => 'Metodă HTTP invalidă.']);
        }

        if (!verify_csrf_token($_POST['_token'] ?? null)) {
            http_response_code(403);
            $this->sendJson(['ok' => false, 'error' => 'Token CSRF invalid. Reîncarcă pagina și încearcă din nou.']);
        }

        $file = $_FILES['invoice'] ?? null;

        try {
            $result = $this->service->recognizeUploadedFile(is_array($file) ? $file : null);
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            $this->sendJson(['ok' => false, 'error' => $exception->getMessage()]);
            return;
        } catch (RuntimeException $exception) {
            http_response_code(503);
            $this->sendJson(['ok' => false, 'error' => $exception->getMessage()]);
            return;
        } catch (Throwable $exception) {
            error_log('[DevOcrTestController][run] ' . $exception->getMessage());
            http_response_code(500);
            $this->sendJson([
                'ok' => false,
                'error' => 'A apărut o eroare internă la procesarea OCR.',
                'error_details' => APP_DEBUG ? $exception->getMessage() : null,
            ]);
            return;
        }

        // Parsarea experimentala nu are voie sa strice raspunsul OCR.
        $analysis = ['fields' => [], 'debug' => []];
        if ($result['success']) {
            try {
                $analysis = OcrInvoiceHeuristics::analyze($result['parsed_text']);
            } catch (Throwable $exception) {
                error_log('[DevOcrTestController][parse] ' . $exception->getMessage());
            }
        }

        $this->sendJson([
            'ok' => $result['success'],
            'error' => $result['error'],
            'error_details' => $result['error_details'],
            'status' => [
                'engine' => $result['engine'],
                'duration_ms' => $result['duration_ms'],
                'http_code' => $result['http_code'],
                'file_name' => (string) ($file['name'] ?? ''),
                'file_size' => OcrSpaceService::formatBytes((int) ($file['size'] ?? 0)),
            ],
            'parsed_text' => $result['parsed_text'],
            'raw_response' => $result['raw'],
            'fields' => $analysis['fields'],
            'debug' => $analysis['debug'],
        ]);
    }

    private function sendJson(array $payload): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }
}
