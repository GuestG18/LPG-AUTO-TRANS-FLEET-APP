<?php
declare(strict_types=1);

/**
 * Client izolat pentru SAS Fleet API (documentatie: https://sasrest.docs.apiary.io).
 *
 * Fluxul de autentificare conform documentatiei SAS:
 *  1. GET  {loginService}/webServers/{username}  -> descopera HOST-ul companiei (ex. www10.alarma.ro)
 *  2. POST https://{host}/SASFleetService/api/login?api-version=1.0  -> tokenul este returnat
 *     in header-ul de raspuns "Token"; body-ul contine {message, state} unde state 2 = succes.
 *  3. Toate cererile ulterioare trimit header-ul "Token: <token>".
 *
 * Tokenul si credentialele nu sunt niciodata logate sau expuse in raspunsuri.
 */
class SasFleetClient
{
    private string $username;
    private string $password;
    private string $culture;
    private string $loginServiceUrl;
    private string $configuredHost;
    private int $timeoutSeconds;

    private ?string $host = null;
    private ?string $token = null;

    public function __construct()
    {
        $this->username = trim((string) (getenv('SAS_API_USERNAME') ?: ''));
        $this->password = trim((string) (getenv('SAS_API_PASSWORD') ?: ''));
        $this->culture = trim((string) (getenv('SAS_API_CULTURE') ?: 'ro-RO'));
        $this->loginServiceUrl = rtrim(trim((string) (getenv('SAS_API_LOGIN_SERVICE_URL')
            ?: 'https://fleetlogin-webapp.azurewebsites.net/LoginService.svc')), '/');
        $this->configuredHost = trim((string) (getenv('SAS_API_HOST') ?: ''));
        $this->timeoutSeconds = max(3, min(60, (int) (getenv('SAS_API_TIMEOUT') ?: 15)));
    }

    public function credentialsAvailable(): bool
    {
        return $this->username !== '' && $this->password !== '';
    }

    /**
     * Descopera HOST-ul companiei prin loginservice. Daca SAS_API_HOST este setat
     * in .env, discovery-ul este sarit si se foloseste valoarea configurata.
     */
    public function resolveHost(): string
    {
        if ($this->host !== null) {
            return $this->host;
        }

        if ($this->configuredHost !== '') {
            $this->host = $this->normalizeHost($this->configuredHost);
            return $this->host;
        }

        if (!$this->credentialsAvailable()) {
            throw new RuntimeException('Credentialele SAS lipsesc din .env (SAS_API_USERNAME / SAS_API_PASSWORD).');
        }

        $url = $this->loginServiceUrl . '/webServers/' . rawurlencode($this->username);
        $result = $this->httpRequest('GET', $url, null, []);
        $decoded = json_decode($result['body'], true);

        $servers = [];
        if (is_array($decoded)) {
            if (array_is_list($decoded)) {
                $servers = $decoded;
            } elseif (isset($decoded['WebServers']) && is_array($decoded['WebServers'])) {
                $servers = $decoded['WebServers'];
            }
        }

        $servers = array_values(array_filter(array_map(
            static fn ($server) => is_scalar($server) ? trim((string) $server) : '',
            $servers
        ), static fn (string $server) => $server !== ''));

        if ($servers === []) {
            throw new RuntimeException('SAS loginservice nu a returnat niciun server pentru acest utilizator.');
        }

        // Daca utilizatorul exista pe mai multe servere se foloseste primul;
        // serverul dorit poate fi fixat explicit prin SAS_API_HOST in .env.
        $this->host = $this->normalizeHost($servers[0]);
        return $this->host;
    }

    /**
     * Autentificare la SAS. Tokenul este pastrat doar in memoria procesului.
     */
    public function login(): void
    {
        if (!$this->credentialsAvailable()) {
            throw new RuntimeException('Credentialele SAS lipsesc din .env (SAS_API_USERNAME / SAS_API_PASSWORD).');
        }

        $host = $this->resolveHost();
        $url = 'https://' . $host . '/SASFleetService/api/login?api-version=1.0';
        $payload = [
            'user' => $this->username,
            'password' => $this->password,
            'culture' => $this->culture !== '' ? $this->culture : 'ro-RO',
        ];

        $result = $this->httpRequest('POST', $url, $payload, ['Content-Type: application/json']);
        $decoded = json_decode($result['body'], true);
        $state = is_array($decoded) ? (int) ($decoded['state'] ?? 0) : 0;
        $token = trim((string) ($result['headers']['token'] ?? ''));

        // state: 0 = esec, 1 = avertisment, 2 = succes. Tokenul e valabil si la avertisment.
        if ($token === '' || $state === 0) {
            $message = is_array($decoded) ? trim((string) ($decoded['message'] ?? '')) : '';
            throw new RuntimeException($message !== '' ? 'Autentificare SAS esuata: ' . $message : 'Autentificare SAS esuata (token lipsa).');
        }

        $this->token = $token;
    }

    public function isAuthenticated(): bool
    {
        return $this->token !== null;
    }

    /**
     * Exporta starea de sesiune (host + token) pentru persistare intre request-uri,
     * astfel incat sa nu fie necesar un login SAS la fiecare interogare de pozitii.
     * Fisierul de stare trebuie tinut in afara webroot-ului (ex. storage/).
     */
    public function exportState(): array
    {
        return [
            'host' => $this->host,
            'token' => $this->token,
        ];
    }

    /**
     * Restaureaza starea de sesiune exportata anterior. Un token invalid/expirat
     * este tratat automat: cererile autentificate fac re-login la HTTP 401.
     */
    public function restoreState(array $state): void
    {
        $host = $state['host'] ?? null;
        $token = $state['token'] ?? null;
        if (is_string($host) && trim($host) !== '') {
            $this->host = $this->normalizeHost($host);
        }
        if (is_string($token) && trim($token) !== '') {
            $this->token = trim($token);
        }
    }

    /**
     * GET /SASFleetService/api/info — informatii utilizator si structura flotei
     * (companii, sucursale, puncte de lucru si masini).
     */
    public function getCompanyInfo(): array
    {
        $body = $this->authenticatedRequest('GET', '/SASFleetService/api/info?api-version=1.0', null);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Raspuns SAS invalid sau non-JSON la /api/info.');
        }

        return $decoded;
    }

    /**
     * Lista masinilor vizibile pentru utilizatorul curent (din /api/info).
     * Fiecare element contine: carId, licensePlate, companyId, branchId, workPointId, driver, disabled.
     */
    public function getCars(): array
    {
        $info = $this->getCompanyInfo();
        $cars = $info['cars'] ?? [];
        return is_array($cars) ? array_values(array_filter($cars, 'is_array')) : [];
    }

    /**
     * POST /SASFleetService/PrivateAPI/cars/currentpositions — ultimele pozitii GPS
     * pentru masinile date. SAS recomanda interogare la minim 20 de secunde.
     */
    public function getCurrentPositions(array $carIds): array
    {
        $carIds = array_values(array_filter(array_map('intval', $carIds), static fn (int $id) => $id > 0));
        if ($carIds === []) {
            return [];
        }

        $payload = [
            'carsLastPositionParam' => [
                'Cars' => $carIds,
            ],
        ];

        $body = $this->authenticatedRequest('POST', '/SASFleetService/PrivateAPI/cars/currentpositions', $payload);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Raspuns SAS invalid sau non-JSON la currentpositions.');
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    /**
     * POST /SASFleetService/api/reports/events — toate pozitiile/evenimentele unei
     * masini in intervalul dat (max 7 zile conform documentatiei SAS).
     */
    public function getCarEvents(int $carId, string $startTime, string $endTime): array
    {
        $payload = [
            'carId' => $carId,
            'startTime' => $startTime,
            'endTime' => $endTime,
        ];

        $body = $this->authenticatedRequest('POST', '/SASFleetService/api/reports/events?api-version=1.0', $payload);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Raspuns SAS invalid sau non-JSON la reports/events.');
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    /**
     * POST /SASFleetService/api/reports/travelsheet — foaia de parcurs (segmente,
     * distanta totala, viteza medie) pentru o masina in intervalul dat.
     */
    public function getTravelSheet(int $carId, string $startTime, string $endTime, bool $isClosedTimeInterval = true): array
    {
        $payload = [
            'carId' => $carId,
            'startTime' => $startTime,
            'endTime' => $endTime,
            'isClosedTimeInterval' => $isClosedTimeInterval,
        ];

        $body = $this->authenticatedRequest('POST', '/SASFleetService/api/reports/travelsheet?api-version=1.0', $payload);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Raspuns SAS invalid sau non-JSON la reports/travelsheet.');
        }

        return $decoded;
    }

    /**
     * POST /SASFleetService/api/pois/find — lista POI-urilor (locatii definite in SAS)
     * care contin filtrul dat in nume. Filtru gol = toate POI-urile companiei.
     * Fiecare element: locationId, name, memo, latitudeMin/MaxInDegrees, longitudeMin/MaxInDegrees.
     */
    public function findPois(string $companyName, string $filter = ''): array
    {
        $payload = [
            'companyName' => $companyName,
            'filter' => $filter,
        ];

        $body = $this->authenticatedRequest('POST', '/SASFleetService/api/pois/find?api-version=1.0', $payload);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Raspuns SAS invalid sau non-JSON la pois/find.');
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    /**
     * Returneaza pozitiile curente normalizate in formatul intern al aplicatiei.
     * Cheile SAS sunt izolate aici; restul aplicatiei foloseste doar formatul intern.
     */
    public function getNormalizedPositions(?array $cars = null): array
    {
        $cars = $cars ?? $this->getCars();
        $carsById = [];
        foreach ($cars as $car) {
            $carId = (int) ($car['carId'] ?? 0);
            if ($carId > 0) {
                $carsById[$carId] = $car;
            }
        }

        if ($carsById === []) {
            return [];
        }

        $positions = $this->getCurrentPositions(array_keys($carsById));

        $normalized = [];
        foreach ($positions as $position) {
            $carId = (int) $this->pick($position, ['CarID', 'carId', 'carID'], 0);
            $normalized[] = $this->normalizePosition($position, $carsById[$carId] ?? []);
        }

        return $normalized;
    }

    /**
     * Mapare campuri SAS -> format intern. Campurile pe care SAS nu le furnizeaza raman null.
     *
     * Nota: documentatia foloseste chei lowercase iar exemplul de raspuns PascalCase,
     * de aceea fiecare camp este citit in ambele variante.
     */
    public function normalizePosition(array $position, array $car = []): array
    {
        $course = $this->pick($position, ['Course', 'course'], null);
        $course = is_numeric($course) ? (float) $course : null;
        // Course -1 inseamna "masina oprita, directia nu are sens" conform documentatiei.
        if ($course !== null && ($course < 0 || $course > 360)) {
            $course = null;
        }

        $speed = $this->pick($position, ['Speed', 'speed'], null);
        $isAvailable = $this->pick($position, ['IsAvailable', 'isAvailable'], null);

        return [
            'sas_vehicle_id' => (int) $this->pick($position, ['CarID', 'carId', 'carID'], 0),
            'registration' => $this->normalizeRegistration((string) ($car['licensePlate'] ?? '')),
            'latitude' => $this->toFloatOrNull($this->pick($position, ['Latitude', 'latitude'], null)),
            'longitude' => $this->toFloatOrNull($this->pick($position, ['Longitude', 'longitude'], null)),
            'speed' => is_numeric($speed) ? (float) $speed : null,
            'heading' => $course,
            'timestamp' => $this->toStringOrNull($this->pick($position, ['Date', 'date'], null)),
            // SAS nu expune contact/ignition direct in currentpositions -> ramane null.
            'ignition' => null,
            'status' => is_bool($isAvailable) ? ($isAvailable ? 'available' : 'unavailable') : null,
            'address' => $this->toStringOrNull($this->pick($position, ['Address', 'address'], null)),
            'city' => $this->toStringOrNull($this->pick($position, ['City', 'city'], null)),
            'county' => $this->toStringOrNull($this->pick($position, ['County', 'county'], null)),
            'poi' => $this->toStringOrNull($this->pick($position, ['Location', 'location'], null)),
            'gps_signal_missing' => (bool) $this->pick($position, ['Fake', 'fake'], false),
            'trigger_event' => $this->toIntOrNull($this->pick($position, ['TriggerEvent', 'triggerEvent'], null)),
            'driver' => $this->toStringOrNull($this->pick($position, ['Driver', 'driver'], null)) ?? $this->toStringOrNull($car['driver'] ?? null),
            'disabled' => isset($car['disabled']) ? (bool) $car['disabled'] : null,
        ];
    }

    /**
     * Cerere autentificata cu re-login automat o singura data in caz de 401
     * (documentatia SAS nu precizeaza durata de viata a tokenului).
     */
    private function authenticatedRequest(string $method, string $path, ?array $payload): string
    {
        if ($this->token === null) {
            $this->login();
        }

        $host = $this->resolveHost();
        $url = 'https://' . $host . $path;
        $headers = ['Token: ' . $this->token];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        try {
            $result = $this->httpRequest($method, $url, $payload, $headers, true);
        } catch (SasUnauthorizedException) {
            $this->token = null;
            $this->login();
            $headers[0] = 'Token: ' . $this->token;
            $result = $this->httpRequest($method, $url, $payload, $headers, true);
        }

        return $result['body'];
    }

    /**
     * @return array{body: string, status: int, headers: array<string, string>}
     */
    private function httpRequest(string $method, string $url, ?array $payload, array $headers, bool $throwOnUnauthorized = false): array
    {
        if (!function_exists('curl_init')) {
            return $this->httpRequestViaStream($method, $url, $payload, $headers, $throwOnUnauthorized);
        }

        $json = null;
        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) {
                throw new RuntimeException('Payload SAS invalid (JSON encode esuat).');
            }
        }

        $responseHeaders = [];
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($headerLine);
            },
        ]);
        if ($json !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
        }

        $body = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException('Conexiunea la SAS a esuat: ' . ($error !== '' ? $error : 'eroare cURL necunoscuta.'));
        }

        $this->assertHttpStatus($statusCode, (string) $body, $throwOnUnauthorized);

        return [
            'body' => (string) $body,
            'status' => $statusCode,
            'headers' => $responseHeaders,
        ];
    }

    /**
     * @return array{body: string, status: int, headers: array<string, string>}
     */
    private function httpRequestViaStream(string $method, string $url, ?array $payload, array $headers, bool $throwOnUnauthorized): array
    {
        $json = null;
        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) {
                throw new RuntimeException('Payload SAS invalid (JSON encode esuat).');
            }
        }

        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", array_merge(['Accept: application/json'], $headers)),
                'content' => $json ?? '',
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('Nu s-a putut apela endpoint-ul SAS.');
        }

        $statusCode = 0;
        $responseHeaders = [];
        foreach ($http_response_header ?? [] as $headerLine) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $matches) === 1) {
                $statusCode = (int) $matches[1];
                continue;
            }
            $parts = explode(':', $headerLine, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        $this->assertHttpStatus($statusCode, (string) $body, $throwOnUnauthorized);

        return [
            'body' => (string) $body,
            'status' => $statusCode,
            'headers' => $responseHeaders,
        ];
    }

    private function assertHttpStatus(int $statusCode, string $body, bool $throwOnUnauthorized): void
    {
        if ($statusCode === 401 && $throwOnUnauthorized) {
            throw new SasUnauthorizedException('Token SAS expirat sau invalid (HTTP 401).');
        }

        if ($statusCode >= 400) {
            $decoded = json_decode($body, true);
            $message = is_array($decoded) ? trim((string) ($decoded['message'] ?? '')) : '';
            if ($statusCode === 503) {
                $message = $message !== '' ? $message : 'Serviciul SAS este temporar indisponibil (posibil prea multe cereri intr-un minut).';
            }
            throw new RuntimeException('SAS HTTP ' . $statusCode . ($message !== '' ? ': ' . $message : ''));
        }
    }

    private function normalizeHost(string $host): string
    {
        $host = trim($host);
        $host = preg_replace('#^https?://#i', '', $host) ?? $host;
        return rtrim(trim($host, "[] \t"), '/');
    }

    private function normalizeRegistration(string $registration): string
    {
        $registration = strtoupper(trim($registration));
        return preg_replace('/\s+/', ' ', $registration) ?: $registration;
    }

    private function pick(array $payload, array $keys, mixed $default): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                return $payload[$key];
            }
        }

        return $default;
    }

    private function toFloatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function toIntOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function toStringOrNull(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }
}

class SasUnauthorizedException extends RuntimeException
{
}
