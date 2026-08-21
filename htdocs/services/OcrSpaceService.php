<?php
declare(strict_types=1);

/**
 * Client izolat pentru OCR.Space (https://ocr.space/ocrapi) - folosit DOAR de
 * sandbox-ul experimental ?page=dev_ocr_test. Nu este integrat in fluxurile
 * reale de cheltuieli/facturi si nu scrie nimic in baza de date.
 *
 * Cheia API vine exclusiv din .env (OCR_SPACE_API_KEY) si este trimisa doar
 * server-side, prin header; nu ajunge niciodata in HTML/JS/raspunsuri.
 *
 * Flux: fisier factura -> validare -> multipart POST -> JSON -> rezultat normalizat.
 */
class OcrSpaceService
{
    private const API_ENDPOINT = 'https://api.ocr.space/parse/image';

    // Limite ale planului gratuit OCR.Space: 1 MB / fisier, max 3 pagini PDF.
    private const MAX_FILE_BYTES = 1024 * 1024;
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
    private const ALLOWED_MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];

    /**
     * Parametrii trimisi la OCR.Space. Pastrati intr-un singur loc ca sa poata
     * fi modificati usor in timpul experimentelor (engine, limba, tabele etc.).
     */
    private array $requestParams = [
        'OCREngine' => '3',
        'language' => 'auto',
        'isTable' => 'true',
        'isOverlayRequired' => 'false',
        'detectOrientation' => 'true',
        'scale' => 'true',
    ];

    private string $apiKey;
    private int $timeoutSeconds;

    public function __construct(?array $paramOverrides = null)
    {
        $this->apiKey = trim((string) (getenv('OCR_SPACE_API_KEY') ?: ''));
        $this->timeoutSeconds = max(10, min(120, (int) (getenv('OCR_SPACE_TIMEOUT') ?: 60)));

        if (is_array($paramOverrides)) {
            $this->requestParams = array_merge($this->requestParams, $paramOverrides);
        }
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function engineLabel(): string
    {
        return 'OCR.Space Engine ' . ($this->requestParams['OCREngine'] ?? '?');
    }

    public function maxFileBytes(): int
    {
        return self::MAX_FILE_BYTES;
    }

    /** @return string[] */
    public function allowedExtensions(): array
    {
        return self::ALLOWED_EXTENSIONS;
    }

    /**
     * Valideaza un element din $_FILES. Arunca InvalidArgumentException cu
     * mesaje in romana, afisabile direct utilizatorului.
     */
    public function validateUpload(?array $file): void
    {
        if ($file === null || !isset($file['error']) || is_array($file['error'])) {
            throw new InvalidArgumentException('Nu a fost primit niciun fișier. Selectează o factură și reîncearcă.');
        }

        switch ((int) $file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new InvalidArgumentException('Fișierul depășește limita de upload a serverului. Încearcă un fișier mai mic.');
            case UPLOAD_ERR_NO_FILE:
                throw new InvalidArgumentException('Nu a fost selectat niciun fișier.');
            default:
                throw new InvalidArgumentException('Upload-ul a eșuat (cod ' . (int) $file['error'] . '). Reîncearcă.');
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new InvalidArgumentException('Fișierul nu a ajuns pe server. Reîncearcă upload-ul.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new InvalidArgumentException('Fișierul este gol (0 bytes).');
        }
        if ($size > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'Fișierul are %s și depășește limita planului gratuit OCR.Space (1 MB / fișier). Comprimă documentul sau folosește o rezoluție mai mică.',
                self::formatBytes($size)
            ));
        }

        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new InvalidArgumentException('Tip de fișier neacceptat. Formate permise: PDF, JPG, JPEG, PNG.');
        }

        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = (string) finfo_file($finfo, $tmpPath);
                finfo_close($finfo);
            }
        }
        if ($mime !== '' && !in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidArgumentException('Conținutul fișierului (' . $mime . ') nu corespunde unui PDF sau unei imagini acceptate.');
        }
    }

    /**
     * Trimite fisierul la OCR.Space si intoarce un rezultat normalizat:
     *  success        - bool, OCR incheiat cu text
     *  parsed_text    - textul recunoscut (toate paginile, separate cu linie noua)
     *  raw            - raspunsul JSON complet decodat (pentru sectiunea de debug)
     *  duration_ms    - durata masurata de aplicatia noastra
     *  engine         - eticheta engine-ului folosit
     *  error          - mesaj de eroare in romana (null la succes)
     *  error_details  - detalii tehnice de la API (null daca nu exista)
     */
    public function recognizeUploadedFile(array $file): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Cheia OCR.Space lipsește. Adaugă OCR_SPACE_API_KEY în fișierul .env și reîncarcă pagina.');
        }

        $this->validateUpload($file);

        $tmpPath = (string) $file['tmp_name'];
        $originalName = (string) ($file['name'] ?? 'document');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mime = $extension === 'pdf' ? 'application/pdf' : ($extension === 'png' ? 'image/png' : 'image/jpeg');

        $postFields = $this->requestParams;
        $postFields['filetype'] = strtoupper($extension === 'jpeg' ? 'jpg' : $extension);
        $postFields['file'] = new CURLFile($tmpPath, $mime, $originalName);

        error_log('[OcrSpaceService] OCR request started: ' . $originalName . ' (' . self::formatBytes((int) $file['size']) . ')');
        $startedAt = microtime(true);

        $curl = curl_init(self::API_ENDPOINT);
        if ($curl === false) {
            throw new RuntimeException('Nu s-a putut inițializa conexiunea HTTP către OCR.Space.');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Cheia este trimisa DOAR aici, server-side; nu apare in UI sau loguri.
            CURLOPT_HTTPHEADER => ['apikey: ' . $this->apiKey],
        ]);

        $body = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (!is_string($body) || $body === '') {
            error_log('[OcrSpaceService] HTTP failure after ' . $durationMs . 'ms: ' . ($curlError !== '' ? $curlError : 'raspuns gol'));
            $message = str_contains(strtolower($curlError), 'timed out')
                ? 'OCR.Space nu a răspuns în timp util (timeout după ' . $this->timeoutSeconds . 's). Reîncearcă sau folosește un fișier mai mic.'
                : 'OCR.Space nu a putut fi contactat. Verifică conexiunea la internet.';

            return $this->failureResult($message, $curlError !== '' ? $curlError : null, $durationMs, null, $httpCode);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            error_log('[OcrSpaceService] Invalid JSON after ' . $durationMs . 'ms (HTTP ' . $httpCode . ')');

            return $this->failureResult(
                'OCR.Space a răspuns într-un format neașteptat (JSON invalid, HTTP ' . $httpCode . ').',
                mb_substr($body, 0, 500),
                $durationMs,
                null,
                $httpCode
            );
        }

        if ($httpCode >= 400) {
            error_log('[OcrSpaceService] API HTTP error ' . $httpCode . ' after ' . $durationMs . 'ms');

            return $this->failureResult(
                'OCR.Space a refuzat cererea (HTTP ' . $httpCode . '). Verifică cheia API și limitele planului.',
                $this->extractApiError($decoded),
                $durationMs,
                $decoded,
                $httpCode
            );
        }

        $isErrored = (bool) ($decoded['IsErroredOnProcessing'] ?? false);
        $exitCode = (int) ($decoded['OCRExitCode'] ?? 0);

        if ($isErrored || $exitCode >= 3) {
            $details = $this->extractApiError($decoded);
            error_log('[OcrSpaceService] OCR processing error after ' . $durationMs . 'ms: ' . ($details ?? 'necunoscut'));

            return $this->failureResult('OCR.Space nu a putut procesa documentul.', $details, $durationMs, $decoded, $httpCode);
        }

        $parsedText = $this->collectParsedText($decoded);
        if (trim($parsedText) === '') {
            error_log('[OcrSpaceService] OCR completed with empty text after ' . $durationMs . 'ms');

            return $this->failureResult(
                'OCR s-a încheiat, dar nu a fost recunoscut niciun text în document.',
                $this->extractApiError($decoded),
                $durationMs,
                $decoded,
                $httpCode
            );
        }

        error_log('[OcrSpaceService] OCR request completed in ' . $durationMs . 'ms (' . mb_strlen($parsedText) . ' caractere)');

        return [
            'success' => true,
            'parsed_text' => $parsedText,
            'raw' => $decoded,
            'duration_ms' => $durationMs,
            'engine' => $this->engineLabel(),
            'http_code' => $httpCode,
            'error' => null,
            'error_details' => null,
        ];
    }

    private function collectParsedText(array $decoded): string
    {
        $pages = [];
        foreach ((array) ($decoded['ParsedResults'] ?? []) as $result) {
            if (is_array($result) && isset($result['ParsedText'])) {
                $pages[] = (string) $result['ParsedText'];
            }
        }

        return implode("\n", $pages);
    }

    private function extractApiError(array $decoded): ?string
    {
        $message = $decoded['ErrorMessage'] ?? null;
        if (is_array($message)) {
            $message = implode(' | ', array_map('strval', $message));
        }

        $details = trim((string) ($message ?? ''));
        $extra = trim((string) ($decoded['ErrorDetails'] ?? ''));
        if ($extra !== '') {
            $details = $details === '' ? $extra : $details . ' | ' . $extra;
        }

        return $details !== '' ? $details : null;
    }

    private function failureResult(string $message, ?string $details, int $durationMs, ?array $raw, int $httpCode): array
    {
        return [
            'success' => false,
            'parsed_text' => '',
            'raw' => $raw,
            'duration_ms' => $durationMs,
            'engine' => $this->engineLabel(),
            'http_code' => $httpCode,
            'error' => $message,
            'error_details' => $details,
        ];
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2, ',', '.') . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1, ',', '.') . ' KB';
        }

        return $bytes . ' B';
    }
}
