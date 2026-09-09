<?php
declare(strict_types=1);

/**
 * Import cazari din Google Sheet (prin worker-ul Cloudflare mcp-sheets).
 *
 * Fluxul: GET /cazari (JSON cu randurile din sheet) -> pentru fiecare rand nou:
 * potrivire sofer dupa nume, insert in cheltuieli_cazare (asocierea cu cursa o face
 * modelul, ca la introducerea manuala), descarcare factura din Drive prin
 * GET /download (fisierele sunt private) si atasare ca document.
 *
 * Dedup: un rand se considera deja importat daca exista o cazare cu aceeasi
 * data + sofer + total_cu_tva. Reimportul repetat nu creeaza duplicate.
 */
class CazariSheetImportService
{
    private const UPLOAD_DIR = 'uploads/curse_cheltuieli';
    private const MAX_DOWNLOAD_SIZE = 5242880; // 5 MB, ca la upload-ul manual

    private PDO $db;
    private AccommodationExpenseModel $model;
    private string $apiUrl;
    private string $apiToken;

    public function __construct(PDO $db, AccommodationExpenseModel $model)
    {
        $this->db = $db;
        $this->model = $model;
        $this->apiUrl = rtrim((string) (getenv('CAZARI_API_URL') ?: ''), '/');
        $this->apiToken = (string) (getenv('CAZARI_API_TOKEN') ?: '');
    }

    /**
     * @return array{imported: int, skipped: int, errors: array<int, string>}
     */
    public function import(?int $createdBy): array
    {
        if ($this->apiUrl === '' || $this->apiToken === '') {
            throw new RuntimeException('CAZARI_API_URL / CAZARI_API_TOKEN lipsesc din .env.');
        }

        $rows = $this->fetchRows();
        $drivers = $this->driverIndex();

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $i => $row) {
            $line = $i + 2; // randul din sheet (header pe randul 1)

            $date = $this->parseDate((string) ($row['data'] ?? ''));
            if ($date === null) {
                $errors[] = "Rand {$line}: data invalida (" . (string) ($row['data'] ?? '') . ').';
                continue;
            }

            $driverId = $this->matchDriver((string) ($row['sofer'] ?? ''), $drivers);
            if ($driverId === null) {
                $errors[] = "Rand {$line}: soferul \"" . (string) ($row['sofer'] ?? '') . '" nu exista in aplicatie.';
                continue;
            }

            $total = $this->parseDecimal($row['cost'] ?? null);
            $totalWithVat = $this->parseDecimal($row['costTva'] ?? null);
            if ($totalWithVat === null || $totalWithVat <= 0) {
                $errors[] = "Rand {$line}: totalul cu TVA lipseste sau este invalid.";
                continue;
            }
            if ($total === null || $total < 0) {
                $total = 0.0;
            }

            if ($this->alreadyImported($date, $driverId, $totalWithVat)) {
                $skipped++;
                continue;
            }

            try {
                $id = $this->model->create([
                    'data' => $date,
                    'sofer_id' => $driverId,
                    'total' => $total,
                    'total_cu_tva' => $totalWithVat,
                    'observatii' => 'Import Google Sheet' .
                        (($row['documentLabel'] ?? '') !== '' ? ' - ' . (string) $row['documentLabel'] : ''),
                    'created_by' => $createdBy,
                ]);
            } catch (Throwable $exception) {
                $errors[] = "Rand {$line}: nu s-a putut salva (" . $exception->getMessage() . ').';
                continue;
            }

            $fileId = $this->extractDriveFileId((string) ($row['documentUrl'] ?? ''));
            if ($fileId !== null) {
                try {
                    $document = $this->downloadInvoice($fileId);
                    if ($document !== null) {
                        $this->model->addDocument($id, $document);
                    }
                } catch (Throwable $exception) {
                    // Randul ramane valid si fara factura; semnalam doar problema.
                    $errors[] = "Rand {$line}: cazarea a fost importata, dar factura nu s-a putut descarca ("
                        . $exception->getMessage() . ').';
                }
            }

            $imported++;
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    // -------------------------------------------------------------------------
    // API worker
    // -------------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    private function fetchRows(): array
    {
        $response = $this->httpGet($this->apiUrl . '/cazari');
        $json = json_decode($response, true);
        if (!is_array($json) || !isset($json['rows']) || !is_array($json['rows'])) {
            throw new RuntimeException('Raspuns neasteptat de la API-ul de cazari.');
        }

        return $json['rows'];
    }

    /**
     * @return array{file_path: string, original_name: string, mime_type: string, file_size: int}|null
     */
    private function downloadInvoice(string $fileId): ?array
    {
        $headers = [];
        $body = $this->httpGet(
            $this->apiUrl . '/download?fileId=' . rawurlencode($fileId),
            $headers,
            self::MAX_DOWNLOAD_SIZE
        );

        if ($body === '') {
            return null;
        }

        $mime = strtolower(trim((string) ($headers['content-type'] ?? 'application/pdf')));
        $extension = match (true) {
            str_contains($mime, 'pdf') => 'pdf',
            str_contains($mime, 'jpeg') => 'jpg',
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'webp') => 'webp',
            default => 'pdf',
        };

        $originalName = 'factura_cazare.' . $extension;
        if (isset($headers['x-file-name'])) {
            $decoded = rawurldecode((string) $headers['x-file-name']);
            $decoded = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $decoded) ?: $originalName;
            $originalName = substr(trim($decoded), 0, 180);
        }

        $uploadDir = BASE_PATH . '/' . self::UPLOAD_DIR;
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Nu s-a putut crea folderul de upload.');
        }

        try {
            $storedName = 'cazare_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8));
        } catch (Throwable) {
            $storedName = 'cazare_' . date('Ymd_His') . '_' . uniqid('', true);
        }
        $storedName .= '.' . $extension;

        if (file_put_contents($uploadDir . '/' . $storedName, $body) === false) {
            throw new RuntimeException('Fisierul nu a putut fi salvat pe server.');
        }

        return [
            'file_path' => $storedName,
            'original_name' => $originalName,
            'mime_type' => $mime !== '' ? $mime : 'application/octet-stream',
            'file_size' => strlen($body),
        ];
    }

    /** @param array<string, string> $responseHeaders */
    private function httpGet(string $url, array &$responseHeaders = [], int $maxBytes = 2097152): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init a esuat.');
        }

        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_DNS_CACHE_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['x-upload-token: ' . $this->apiToken],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$responseHeaders): int {
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($header);
            },
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Conexiunea la API a esuat: ' . $curlError);
        }
        if ($status >= 400) {
            throw new RuntimeException('API-ul a raspuns cu HTTP ' . $status . ': ' . substr((string) $body, 0, 200));
        }
        if (strlen((string) $body) > $maxBytes) {
            throw new RuntimeException('Fisierul depaseste limita de ' . $maxBytes . ' bytes.');
        }

        return (string) $body;
    }

    // -------------------------------------------------------------------------
    // Potrivire si parsare
    // -------------------------------------------------------------------------

    /** @return array<string, int> nume normalizat => sofer_id */
    private function driverIndex(): array
    {
        $index = [];
        foreach ($this->model->getDrivers() as $driver) {
            $normalized = $this->normalizeName((string) $driver['nume']);
            if ($normalized !== '') {
                $index[$normalized] = (int) $driver['id'];
            }
        }

        return $index;
    }

    private function matchDriver(string $name, array $index): ?int
    {
        $normalized = $this->normalizeName($name);
        if ($normalized === '') {
            return null;
        }
        if (isset($index[$normalized])) {
            return $index[$normalized];
        }

        // "Andreias Catalin" vs "Catalin Andreias": incercam si ordinea inversata.
        $reversed = implode(' ', array_reverse(explode(' ', $normalized)));

        return $index[$reversed] ?? null;
    }

    private function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($transliterated !== false) {
            $name = $transliterated;
        }
        $name = preg_replace('/[^a-z ]+/', ' ', $name) ?? $name;

        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Numar serial Google Sheets (zile de la 30.12.1899), posibil cu FORMULA render.
        if (is_numeric($value) && (float) $value > 20000 && (float) $value < 80000) {
            $base = new DateTimeImmutable('1899-12-30');

            return $base->modify('+' . (int) round((float) $value) . ' days')->format('Y-m-d');
        }

        foreach (['d.m.Y', 'd/m/Y', 'Y-m-d'] as $format) {
            $date = DateTime::createFromFormat($format, $value);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function parseDecimal(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return round((float) $value, 2);
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        // Format romanesc: "1.198,20" -> "1198.20"; "198,20" -> "198.20".
        if (str_contains($value, ',')) {
            $value = str_replace(['.', ' '], '', $value);
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function extractDriveFileId(string $url): ?string
    {
        if ($url === '') {
            return null;
        }
        if (preg_match('#/file/d/([A-Za-z0-9_-]+)#', $url, $m) === 1) {
            return $m[1];
        }
        if (preg_match('#[?&]id=([A-Za-z0-9_-]+)#', $url, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function alreadyImported(string $date, int $driverId, float $totalWithVat): bool
    {
        $stmt = $this->db->prepare("
            SELECT id
            FROM cheltuieli_cazare
            WHERE data = :data
              AND sofer_id = :sofer_id
              AND ABS(total_cu_tva - :total_cu_tva) < 0.01
            LIMIT 1
        ");
        $stmt->bindValue(':data', $date, PDO::PARAM_STR);
        $stmt->bindValue(':sofer_id', $driverId, PDO::PARAM_INT);
        $stmt->bindValue(':total_cu_tva', number_format($totalWithVat, 2, '.', ''), PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }
}
