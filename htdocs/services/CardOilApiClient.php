<?php
declare(strict_types=1);

class CardOilApiClient
{
    private string $username;
    private string $apiKey;
    private string $baseUrl;
    private string $endpoint;
    private string $method;
    private int $timeoutSeconds;

    public function __construct()
    {
        $this->username = trim((string) (getenv('CARDOIL_USERNAME') ?: ''));
        $this->apiKey = trim((string) (getenv('CARDOIL_API_KEY') ?: ''));
        $this->baseUrl = rtrim(trim((string) (getenv('CARDOIL_API_BASE_URL') ?: 'https://cardoilavantaj.ro/api')), '/');
        $this->endpoint = trim((string) (getenv('CARDOIL_API_FILLUPS_ENDPOINT') ?: '/alimentari'));
        $this->method = strtoupper(trim((string) (getenv('CARDOIL_API_METHOD') ?: 'GET')));
        $this->timeoutSeconds = max(3, min(60, (int) (getenv('CARDOIL_API_TIMEOUT') ?: 15)));
    }

    public function credentialsAvailable(): bool
    {
        return $this->username !== '' && $this->apiKey !== '';
    }

    public function fetchFillups(DateTimeInterface $dateFrom, DateTimeInterface $dateTo): array
    {
        return $this->fetchWithPayload([
            'data_inceput' => $dateFrom->format('d.m.Y'),
            'data_sfarsit' => $dateTo->format('d.m.Y'),
        ]);
    }

    /**
     * Preluare incrementala dupa ID: intoarce alimentarile cu id_alimentare
     * STRICT mai mare decat $idMinim, fara restrictia de 31 de zile a
     * parametrilor de data (conform documentatiei v2.4). Maximum 1000 de
     * inregistrari per cerere - apelantul pagineaza avansand cursorul.
     */
    public function fetchFillupsSinceId(int $idMinim): array
    {
        return $this->fetchWithPayload([
            'id_minim' => $idMinim,
        ]);
    }

    private function fetchWithPayload(array $payload): array
    {
        if (!$this->credentialsAvailable()) {
            return [
                'records' => [],
                'source' => 'missing_credentials',
                'error' => 'Credentialele CardOil lipsesc din .env.',
                'meta' => [],
                'raw_count' => 0,
                'max_id' => 0,
            ];
        }

        $response = $this->request($payload);
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(trim($response) === '' ? 'Raspuns CardOil gol.' : 'Raspuns CardOil invalid sau non-JSON.');
        }

        if ((int) ($decoded['id_eroare'] ?? 0) > 0) {
            $message = trim((string) ($decoded['mesaj_eroare'] ?? 'Eroare CardOil necunoscuta.'));
            throw new RuntimeException($message !== '' ? $message : 'Eroare CardOil necunoscuta.');
        }

        $items = $this->extractItems($decoded);
        $records = [];
        $maxRawId = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            // ID-ul maxim din raspunsul BRUT (inainte de filtrarea pe produs):
            // cursorul incremental trebuie sa avanseze si peste randurile
            // non-carburant, altfel paginarea s-ar bloca pe ele.
            $rawId = (int) ($item['id_alimentare'] ?? ($item['id'] ?? 0));
            if ($rawId > $maxRawId) {
                $maxRawId = $rawId;
            }

            $record = $this->normalizeFillup($item);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return [
            'records' => $records,
            'source' => 'api',
            'error' => null,
            'meta' => $this->extractMeta($decoded),
            'raw_count' => count($items),
            'max_id' => $maxRawId,
        ];
    }

    private function request(array $payload): string
    {
        $url = $this->buildUrl();
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($this->username . ':' . $this->apiKey),
        ];

        if ($this->method === 'POST') {
            return $this->postJson($url, $payload, $headers);
        }

        $separator = str_contains($url, '?') ? '&' : '?';
        return $this->get($url . $separator . http_build_query($payload), $headers);
    }

    private function buildUrl(): string
    {
        if (preg_match('/^https?:\/\//i', $this->endpoint) === 1) {
            return $this->endpoint;
        }

        return $this->baseUrl . '/' . ltrim($this->endpoint, '/');
    }

    private function get(string $url, array $headers): string
    {
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_HTTPHEADER => $headers,
            ]);

            $response = curl_exec($curl);
            $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);

            if ($response === false || $statusCode >= 400) {
                throw new RuntimeException($error !== '' ? $error : 'CardOil HTTP status ' . $statusCode);
            }

            return (string) $response;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => $this->timeoutSeconds,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException('Nu s-a putut apela endpoint-ul CardOil.');
        }

        return (string) $response;
    }

    private function postJson(string $url, array $payload, array $headers): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Payload CardOil invalid.');
        }

        $headers[] = 'Content-Type: application/json';

        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
            ]);

            $response = curl_exec($curl);
            $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);

            if ($response === false || $statusCode >= 400) {
                throw new RuntimeException($error !== '' ? $error : 'CardOil HTTP status ' . $statusCode);
            }

            return (string) $response;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $json,
                'timeout' => $this->timeoutSeconds,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException('Nu s-a putut apela endpoint-ul CardOil.');
        }

        return (string) $response;
    }

    private function extractItems(array $decoded): array
    {
        if (array_is_list($decoded)) {
            return $decoded;
        }

        foreach (['lista', 'data', 'alimentari', 'fillups', 'records', 'transactions', 'items', 'result'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                return $decoded[$key];
            }
        }

        return [];
    }

    private function extractMeta(array $decoded): array
    {
        $meta = [];
        foreach (['id_minim', 'id_maxim', 'id_min', 'id_max', 'last_id', 'data_inceput', 'data_sfarsit', 'cif_client', 'nr_inregistrari'] as $key) {
            if (array_key_exists($key, $decoded)) {
                $meta[$key] = (string) $decoded[$key];
            }
        }

        return $meta;
    }

    private function normalizeFillup(array $payload): ?array
    {
        $registration = $this->firstString($payload, [
            'nr_inmatriculare',
            'numar_inmatriculare',
            'vehicle_registration',
            'registration',
            'vehicul',
            'auto',
            'masina',
            'truck',
            'nrinmatric_card',
            'eticheta_card',
        ]);
        $fuelType = $this->normalizeFuelType($this->firstString($payload, [
            'fuel_type',
            'tip_carburant',
            'carburant',
            'produs',
            'product',
            'denumire_produs',
            'nume_produs',
            'nume_subcategorie',
            'nume_categorie',
        ]));
        $quantity = $this->firstNumber($payload, [
            'quantity_liters',
            'cantitate_litri',
            'litri',
            'quantity',
            'cantitate',
            'volum',
            'cantitate_alimentare',
        ]);
        $datetime = $this->normalizeDateTime(
            $this->firstString($payload, ['fillup_datetime', 'data_ora', 'data_alimentare', 'datetime', 'date', 'data']),
            $this->firstString($payload, ['ora', 'time', 'ora_alimentare'])
        );

        if ($registration === '' || $fuelType === null || $quantity <= 0.0 || $datetime === null) {
            return null;
        }

        $apiId = $this->firstString($payload, [
            'api_id',
            'id_alimentare',
            'alimentare_id',
            'transaction_id',
            'tranzactie_id',
            'document_id',
            'bon_id',
            'id',
        ]);
        if ($apiId === '') {
            $apiId = 'cardoil-' . sha1($registration . '|' . $fuelType . '|' . $quantity . '|' . $datetime->format('Y-m-d H:i:s'));
        }

        return [
            'api_id' => $apiId,
            'vehicle_registration' => $this->normalizeRegistration($registration),
            'driver_name' => $this->firstString($payload, [
                'driver_name',
                'sofer_card',
                'nume_sofer',
                'sofer',
                'driver',
            ]),
            'fuel_type' => $fuelType,
            'quantity_liters' => $quantity,
            'odometer_km' => $this->firstNumber($payload, [
                'odometer_km',
                'km_alimentare',
                'kilometraj',
                'km',
            ]),
            'total_value' => max(0.0, $this->firstNumber($payload, [
                'total_value',
                'valoare_totala',
                'valoare',
                'cost_total',
                'total',
                'suma',
                'valoare_alimentare',
            ])),
            // Pretul unitar autoritar din API (4 zecimale). Documentat ca INCLUZAND TVA.
            // Este pastrat ca atare; indicatorul de flota ramane ponderat volumetric.
            'unit_price' => $this->firstNumber($payload, [
                'unit_price',
                'pu_alimentare',
                'pret_unitar',
                'pret_litru',
            ]),
            'currency' => $this->firstString($payload, ['nume_moneda', 'currency', 'moneda']),
            'vat_percent' => $this->firstNumber($payload, ['cota_tva', 'vat_percent']),
            'product_subcategory' => $this->firstString($payload, ['nume_subcategorie', 'subcategorie']),
            'station_name' => $this->firstString($payload, ['station_name', 'statie', 'nume_statie', 'station', 'locatie', 'nume_furnizor']),
            'fillup_datetime' => $datetime->format('Y-m-d H:i:s'),
            'is_full' => $this->normalizeFullFlag($payload),
            'raw_payload' => $payload,
        ];
    }

    private function firstString(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function firstNumber(array $payload, array $keys): float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];
            if (!is_scalar($value)) {
                continue;
            }

            $normalized = str_replace([' ', ','], ['', '.'], trim((string) $value));
            if (is_numeric($normalized)) {
                return (float) $normalized;
            }
        }

        return 0.0;
    }

    private function normalizeFuelType(string $value): ?string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, 'adblue') || str_contains($normalized, 'ad blue')) {
            return 'adblue';
        }

        if (str_contains($normalized, 'motorina') || str_contains($normalized, 'diesel') || str_contains($normalized, 'gazole')) {
            return 'motorina';
        }

        return null;
    }

    private function normalizeDateTime(string $dateValue, string $timeValue = ''): ?DateTimeImmutable
    {
        $raw = trim($dateValue . ' ' . $timeValue);
        if ($raw === '') {
            return null;
        }

        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'd.m.Y H:i:s',
            'd.m.Y H:i',
            'd/m/Y H:i:s',
            'd/m/Y H:i',
            DateTimeInterface::ATOM,
            'Y-m-d',
            'd.m.Y',
            'd/m/Y',
        ];

        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $raw);
            if ($date instanceof DateTimeImmutable) {
                return $date;
            }
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeFullFlag(array $payload): int
    {
        foreach (['is_full', 'full', 'plin', 'full_tank'] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $value = strtolower(trim((string) $payload[$key]));
            if (in_array($value, ['1', 'true', 'yes', 'da', 'full', 'plin'], true)) {
                return 1;
            }
        }

        $type = strtolower($this->firstString($payload, ['tip_alimentare', 'fillup_type', 'alimentare_tip']));
        return (str_contains($type, 'full') || str_contains($type, 'plin')) ? 1 : 0;
    }

    private function normalizeRegistration(string $registration): string
    {
        $registration = strtoupper(trim($registration));
        return preg_replace('/\s+/', ' ', $registration) ?: $registration;
    }
}
