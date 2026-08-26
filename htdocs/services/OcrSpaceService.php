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

    // Limita planului gratuit OCR.Space: 1 MB / fisier, max 3 pagini PDF.
    private const MAX_FILE_BYTES = 1024 * 1024;
    // Imaginile mai mari sunt acceptate si comprimate automat cu GD sub limita API.
    private const MAX_IMAGE_UPLOAD_BYTES = 15 * 1024 * 1024;
    // Tinta de compresie: putin sub limita API, ca sa nu fim respinsi la limita.
    private const COMPRESS_TARGET_BYTES = 950 * 1024;
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

    public function maxImageUploadBytes(): int
    {
        return $this->canCompressImages() ? self::MAX_IMAGE_UPLOAD_BYTES : self::MAX_FILE_BYTES;
    }

    public function canCompressImages(): bool
    {
        return extension_loaded('gd');
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

        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new InvalidArgumentException('Tip de fișier neacceptat. Formate permise: PDF, JPG, JPEG, PNG.');
        }

        // PDF-urile merg direct la API, deci raman sub limita planului gratuit.
        // Imaginile mari sunt comprimate automat (GD) inainte de trimitere.
        if ($extension === 'pdf' && $size > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'PDF-ul are %s și depășește limita planului gratuit OCR.Space (1 MB). Alternativă: fotografiază factura (JPG/PNG până la 15 MB — o comprimăm noi automat) sau folosește un plan OCR.Space PRO.',
                self::formatBytes($size)
            ));
        }
        if ($extension !== 'pdf' && $size > $this->maxImageUploadBytes()) {
            throw new InvalidArgumentException(sprintf(
                'Imaginea are %s și depășește limita de %s. Folosește o rezoluție mai mică.',
                self::formatBytes($size),
                self::formatBytes($this->maxImageUploadBytes())
            ));
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

        // Imaginile peste limita API sunt comprimate automat (GD) intr-un JPEG
        // temporar; PDF-urile au fost deja validate sub limita.
        $compressedPath = null;
        $compressionNote = null;
        $originalSize = (int) $file['size'];
        if ($extension !== 'pdf' && $originalSize > self::MAX_FILE_BYTES) {
            $compressedPath = $this->compressImageUnderLimit($tmpPath);
            if ($compressedPath === null) {
                throw new InvalidArgumentException(sprintf(
                    'Imaginea are %s și nu a putut fi comprimată sub limita OCR.Space de 1 MB. Încearcă o rezoluție mai mică.',
                    self::formatBytes($originalSize)
                ));
            }
            $tmpPath = $compressedPath;
            $mime = 'image/jpeg';
            $extension = 'jpg';
            $compressionNote = sprintf(
                'Imaginea a fost comprimată automat: %s → %s.',
                self::formatBytes($originalSize),
                self::formatBytes((int) filesize($compressedPath))
            );
            error_log('[OcrSpaceService] ' . $compressionNote);
        }

        $postFields = $this->requestParams;
        $postFields['filetype'] = strtoupper($extension === 'jpeg' ? 'jpg' : $extension);
        $postFields['file'] = new CURLFile($tmpPath, $mime, $originalName);

        error_log('[OcrSpaceService] OCR request started: ' . $originalName . ' (' . self::formatBytes($originalSize) . ')');
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

        if ($compressedPath !== null) {
            @unlink($compressedPath);
        }

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
            'compression_note' => $compressionNote,
            'error' => null,
            'error_details' => null,
        ];
    }

    /**
     * Comprima o imagine (JPG/PNG) sub limita API folosind GD: redimensionare
     * progresiva + recomprimare JPEG. Intoarce calea fisierului temporar sau
     * null daca nu s-a putut ajunge sub limita.
     */
    private function compressImageUnderLimit(string $sourcePath): ?string
    {
        if (!$this->canCompressImages()) {
            return null;
        }

        $info = @getimagesize($sourcePath);
        if ($info === false) {
            return null;
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG => @imagecreatefrompng($sourcePath),
            default => false,
        };
        if ($image === false) {
            return null;
        }

        $targetPath = tempnam(sys_get_temp_dir(), 'ocr_cmp_');
        if ($targetPath === false) {
            imagedestroy($image);
            return null;
        }

        // Incercari progresive: latura maxima si calitatea scad pana incape.
        // Textul facturilor ramane lizibil pentru OCR pana pe la ~1600px.
        $attempts = [[3000, 85], [2400, 82], [2000, 78], [1600, 72], [1300, 65]];
        $width = imagesx($image);
        $height = imagesy($image);

        foreach ($attempts as [$maxSide, $quality]) {
            $scale = min(1.0, $maxSide / max($width, $height));
            $newWidth = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            // Fundal alb pentru PNG-urile cu transparenta (altfel devine negru in JPEG).
            $white = imagecolorallocate($resized, 255, 255, 255);
            imagefill($resized, 0, 0, $white);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            $saved = imagejpeg($resized, $targetPath, $quality);
            imagedestroy($resized);

            if ($saved && (int) filesize($targetPath) <= self::COMPRESS_TARGET_BYTES) {
                imagedestroy($image);
                return $targetPath;
            }
        }

        imagedestroy($image);
        @unlink($targetPath);
        return null;
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
