<?php
declare(strict_types=1);

/**
 * API server-to-server pentru Fleet Assistant (FleetBot, FastAPI).
 *
 * Fara sesiune de browser: apelantul se autentifica prin X-Assistant-Token
 * (FLEET_ASSISTANT_API_TOKEN din .env), iar utilizatorul real se identifica prin
 * canalul extern (X-Assistant-Provider + X-Assistant-User -> assistant_channels).
 * Drepturile se verifica pe utilizatorul canalului, cu aceleasi reguli ca in
 * aplicatie (user_can din includes/access.php) — FleetBot nu primeste acces in plus.
 */
class AssistantApiController
{
    /** Tipurile de document expuse asistentului: cheie = tip_document in majuscule. */
    private const DOCUMENT_TYPES = [
        'RCA' => 'RCA',
        'ITP' => 'ITP',
        'ROVINIETA' => 'Rovinieta',
        'IPROCHIM' => 'Iprochim',
    ];

    /** Extensiile acceptate la upload in Documente vehicule. */
    private const FILE_MIME_TYPES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    private ?PDO $db = null;

    /**
     * @param string $path ruta dupa /api/assistant/, ex. "v1/trips/today"
     */
    public function handle(string $path): void
    {
        try {
            $route = trim($path, '/');
            $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

            // Link-ul temporar e descarcat de Twilio, care nu poate trimite headerele
            // asistentului: tokenul din URL tine loc de autentificare.
            if (preg_match('#^v1/download/([^/]*)$#', $route, $matches) === 1) {
                if ($method !== 'GET' && $method !== 'HEAD') {
                    header('Allow: GET, HEAD');
                    $this->sendJson(['success' => false, 'error' => 'method_not_allowed'], 405);
                }
                $this->downloadDocument($matches[1], $method === 'HEAD');
            }

            if (!in_array($route, ['v1/trips/today', 'v1/vehicle-document'], true)) {
                $this->sendJson(['success' => false, 'error' => 'not_found'], 404);
            }

            if ($method !== 'GET') {
                header('Allow: GET');
                $this->sendJson(['success' => false, 'error' => 'method_not_allowed'], 405);
            }

            $this->requireValidToken();
            $user = $this->resolveChannelUser();
            if ($route === 'v1/vehicle-document') {
                $this->vehicleDocument($user);
            }
            $this->tripsToday($user);
        } catch (Throwable $exception) {
            error_log('[assistant_api] ' . get_class($exception) . ': ' . $exception->getMessage());
            $this->sendJson(['success' => false, 'error' => 'server_error'], 500);
        }
    }

    private function tripsToday(array $user): void
    {
        // Lista de curse este pagina Dispecer curse; aceeasi garda ca in aplicatie.
        if (!user_can($user, 'dispecer_curse', 'view')) {
            $this->sendJson(['success' => false, 'error' => 'forbidden'], 403);
        }

        $now = new DateTimeImmutable('now');
        $today = $now->format('Y-m-d');
        $races = (new DispecerCurseModel($this->db()))->getRacesActiveOnDay($today);

        $trips = [];
        foreach ($races as $race) {
            $trips[] = $this->tripPayload($race, $today, $now);
        }

        $this->sendJson([
            'success' => true,
            'date' => $today,
            'user' => [
                'id' => (int) $user['id'],
                'role' => (string) $user['rol'],
            ],
            'count' => count($trips),
            'trips' => $trips,
        ]);
    }

    /**
     * Documentul curent al unui vehicul + link temporar de descarcare. Clientul
     * trimite doar numarul si tipul; fisierul se alege exclusiv din BD.
     */
    private function vehicleDocument(array $user): void
    {
        // Aceeasi garda ca pagina Documente vehicule din aplicatie.
        if (!user_can($user, 'documente', 'view')) {
            $this->sendJson(['success' => false, 'error' => 'forbidden'], 403);
        }

        $vehicleInput = trim((string) ($_GET['vehicle'] ?? ''));
        $typeInput = strtoupper(trim((string) ($_GET['document_type'] ?? '')));
        if ($vehicleInput === '' || $typeInput === '' || mb_strlen($vehicleInput) > 30) {
            $this->sendJson(['success' => false, 'error' => 'invalid_request'], 400);
        }
        if (!isset(self::DOCUMENT_TYPES[$typeInput])) {
            $this->sendJson([
                'success' => false,
                'error' => 'unsupported_document_type',
                'supported_types' => array_values(self::DOCUMENT_TYPES),
            ], 400);
        }
        $typeLabel = self::DOCUMENT_TYPES[$typeInput];

        $documents = new DocumentModel($this->db());
        $vehicle = $documents->findVehicleByRegistration($vehicleInput);
        if ($vehicle === null) {
            $this->sendJson(['success' => false, 'error' => 'vehicle_not_found'], 404);
        }

        $document = $documents->findCurrentVehicleDocument((int) $vehicle['id'], $typeInput);
        if ($document === null) {
            $this->sendJson(['success' => false, 'error' => 'document_not_found'], 404);
        }

        $file = $this->documentFile($document['fisier_stocat'] ?? null);
        if ($file === null) {
            $this->sendJson(['success' => false, 'error' => 'document_file_missing'], 404);
        }

        $plate = trim((string) $vehicle['nr_inmatriculare']);
        $token = (new AssistantDownloadTokenModel($this->db()))
            ->create((int) $document['id'], (int) $user['id'], $user['channel_id']);
        $expiresAt = (new DateTimeImmutable('now'))->modify('+' . AssistantDownloadTokenModel::TTL_SECONDS . ' seconds');

        $this->sendJson([
            'success' => true,
            'document' => [
                'type' => $typeLabel,
                'vehicle' => $plate,
                'valid_until' => $document['data_expirare'] !== null ? substr((string) $document['data_expirare'], 0, 10) : null,
                'filename' => $this->downloadFilename($typeLabel, $plate, $file['extension']),
                'mime_type' => $file['mime_type'],
                'download_url' => $this->publicBaseUrl() . '/api/assistant/v1/download/' . $token,
                'download_expires_at' => $expiresAt->format(DATE_ATOM),
            ],
        ]);
    }

    private function downloadDocument(string $token, bool $headOnly): void
    {
        $tokens = new AssistantDownloadTokenModel($this->db());
        $grant = $tokens->findByToken($token);
        if ($grant === null) {
            $this->sendJson(['success' => false, 'error' => 'invalid_token'], 404);
        }

        $expiresAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $grant['expires_at']);
        if ($expiresAt === false || $expiresAt <= new DateTimeImmutable('now')) {
            $this->sendJson(['success' => false, 'error' => 'token_expired'], 410);
        }

        // Drepturile se reverifica: contul poate fi dezactivat intre cerere si descarcare.
        $user = (new UserModel($this->db()))->findAuthUserById((int) $grant['user_id']);
        if ($user === null || !user_can($user, 'documente', 'view')) {
            $this->sendJson(['success' => false, 'error' => 'forbidden'], 403);
        }

        $document = (new DocumentModel($this->db()))->findVehicleDocumentWithVehicle((int) $grant['document_id']);
        $file = $document !== null ? $this->documentFile($document['fisier_stocat'] ?? null) : null;
        if ($document === null || $file === null) {
            $this->sendJson(['success' => false, 'error' => 'file_not_found'], 404);
        }

        $rawType = trim((string) $document['tip_document']);
        $typeLabel = self::DOCUMENT_TYPES[strtoupper($rawType)] ?? $rawType;
        $filename = $this->downloadFilename($typeLabel, (string) $document['nr_inmatriculare'], $file['extension']);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(200);
        header('Content-Type: ' . $file['mime_type']);
        header('Content-Length: ' . (string) filesize($file['path']));
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');

        if (!$headOnly) {
            readfile($file['path']);
            $tokens->markDownloaded((int) $grant['id']);
        }
        exit;
    }

    /**
     * Calea reala a fisierului, doar daca numele salvat arata ca unul generat la
     * upload si fisierul se afla chiar in uploads/documente (fara traversare).
     *
     * @return array{path:string,extension:string,mime_type:string}|null
     */
    private function documentFile(mixed $storedFile): ?array
    {
        $storedFile = trim((string) ($storedFile ?? ''));
        if ($storedFile === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$/', $storedFile) !== 1 || str_contains($storedFile, '..')) {
            return null;
        }

        $extension = strtolower(pathinfo($storedFile, PATHINFO_EXTENSION));
        if (!isset(self::FILE_MIME_TYPES[$extension])) {
            return null;
        }

        $directory = realpath(BASE_PATH . '/uploads/documente');
        $path = $directory !== false ? realpath($directory . DIRECTORY_SEPARATOR . $storedFile) : false;
        if ($directory === false || $path === false || !str_starts_with($path, $directory . DIRECTORY_SEPARATOR) || !is_file($path)) {
            return null;
        }

        return ['path' => $path, 'extension' => $extension, 'mime_type' => self::FILE_MIME_TYPES[$extension]];
    }

    private function downloadFilename(string $typeLabel, string $plate, string $extension): string
    {
        $base = trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $typeLabel . '_' . $plate), '_');

        return ($base !== '' ? $base : 'document') . '.' . $extension;
    }

    /**
     * Adresa publica (HTTPS) prin care Twilio ajunge la aplicatie.
     * FLEET_ASSISTANT_PUBLIC_URL are prioritate cand APP_URL e o adresa interna.
     */
    private function publicBaseUrl(): string
    {
        $configured = trim((string) (getenv('FLEET_ASSISTANT_PUBLIC_URL') ?: ''));

        return rtrim($configured !== '' ? $configured : APP_URL, '/');
    }

    private function tripPayload(array $race, string $today, DateTimeImmutable $now): array
    {
        $startDate = substr(trim((string) ($race['data_inceput'] ?? '')), 0, 10);
        $startTime = $this->shortTime($race['ora_inceput'] ?? null);

        $trip = [
            'id' => (int) $race['id'],
            'vehicle' => trim((string) ($race['nr_inmatriculare'] ?? '')),
            'route' => $this->routeLabel($race),
            'start' => $startTime,
            'status' => $this->statusLabel($race, $now),
        ];

        // Cursele pornite intr-o zi anterioara si inca active azi.
        if ($startDate !== '' && $startDate !== $today) {
            $trip['start_date'] = $startDate;
        }

        $driver = trim((string) ($race['sofer_nume'] ?? ''));
        if ($driver !== '') {
            $trip['driver'] = $driver;
        }

        return $trip;
    }

    /**
     * Incarcare -> descarcare, cu aceleasi surse ca eticheta de ruta din Dispecer:
     * locul/zona configurate au prioritate, apoi campurile text libere.
     */
    private function routeLabel(array $race): ?string
    {
        $pick = static function (array $keys) use ($race): string {
            foreach ($keys as $key) {
                $value = trim((string) ($race[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }

            return '';
        };

        $start = $pick(['loc_incarcare_nume', 'loc_plecare', 'loc_aspirare']);
        $end = $pick(['zona_distributie_nume', 'loc_livrare', 'loc_livrare_cursa']);

        if ($start !== '' && $end !== '' && mb_strtolower($start) !== mb_strtolower($end)) {
            return $start . ' → ' . $end;
        }

        if ($start !== '') {
            return $start;
        }

        return $end !== '' ? $end : null;
    }

    /**
     * Aplicatia nu stocheaza un status operational al cursei, doar intervalul;
     * statusul se deduce din el. Fara ora de sfarsit cursa e "in desfasurare".
     */
    private function statusLabel(array $race, DateTimeImmutable $now): string
    {
        $startDate = substr(trim((string) ($race['data_inceput'] ?? '')), 0, 10);
        $startTime = $this->shortTime($race['ora_inceput'] ?? null);
        if ($startTime !== null && $startDate !== '') {
            $start = DateTimeImmutable::createFromFormat('Y-m-d H:i', $startDate . ' ' . $startTime);
            if ($start !== false && $start > $now) {
                return 'Programată';
            }
        }

        $endTime = $this->shortTime($race['ora_sfarsit'] ?? null);
        if ($endTime !== null) {
            $endDate = substr(trim((string) ($race['data_sfarsit'] ?? '')), 0, 10);
            $end = DateTimeImmutable::createFromFormat('Y-m-d H:i', ($endDate !== '' ? $endDate : $startDate) . ' ' . $endTime);
            if ($end !== false && $end <= $now) {
                return 'Finalizată';
            }
        }

        return 'În desfășurare';
    }

    private function shortTime(mixed $value): ?string
    {
        $time = substr(trim((string) ($value ?? '')), 0, 5);

        return preg_match('/^\d{2}:\d{2}$/', $time) === 1 ? $time : null;
    }

    private function requireValidToken(): void
    {
        $expected = trim((string) (getenv('FLEET_ASSISTANT_API_TOKEN') ?: ''));
        $given = trim((string) ($_SERVER['HTTP_X_ASSISTANT_TOKEN'] ?? ''));

        // Un token neconfigurat pe server nu trebuie sa accepte niciodata un header gol.
        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            $this->sendJson(['success' => false, 'error' => 'unauthorized'], 401);
        }
    }

    /**
     * @return array{id:int,nume:string,rol:string,status:string,employee_id:?int,channel_id:int}
     */
    private function resolveChannelUser(): array
    {
        $provider = strtolower(trim((string) ($_SERVER['HTTP_X_ASSISTANT_PROVIDER'] ?? '')));
        $externalId = trim((string) ($_SERVER['HTTP_X_ASSISTANT_USER'] ?? ''));

        $validProvider = preg_match('/^[a-z0-9_-]{1,30}$/', $provider) === 1;
        $validExternalId = $externalId !== '' && mb_strlen($externalId) <= 100 && preg_match('/[\x00-\x1F\x7F]/', $externalId) !== 1;
        if (!$validProvider || !$validExternalId) {
            $this->sendJson(['success' => false, 'error' => 'assistant_user_not_found'], 404);
        }

        $channel = (new AssistantChannelModel($this->db()))->findActiveChannel($provider, $externalId);
        $userId = (int) ($channel['user_id'] ?? 0);
        if ($channel === null || $userId <= 0) {
            $this->sendJson(['success' => false, 'error' => 'assistant_user_not_found'], 404);
        }

        $user = (new UserModel($this->db()))->findAuthUserById($userId);
        if ($user === null) {
            $this->sendJson(['success' => false, 'error' => 'assistant_user_not_found'], 404);
        }

        // Aceeasi regula ca la login: doar conturile active.
        if ((string) ($user['status'] ?? 'inactiv') !== 'activ') {
            $this->sendJson(['success' => false, 'error' => 'user_inactive'], 403);
        }

        $employeeId = $channel['employee_id'] !== null ? (int) $channel['employee_id'] : null;

        return [
            'id' => (int) $user['id'],
            'nume' => (string) ($user['nume'] ?? ''),
            'rol' => (string) ($user['rol'] ?? ''),
            'status' => (string) $user['status'],
            'employee_id' => $employeeId,
            'channel_id' => (int) $channel['id'],
        ];
    }

    private function db(): PDO
    {
        return $this->db ??= get_pdo();
    }

    private function sendJson(array $payload, int $statusCode = 200): never
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
