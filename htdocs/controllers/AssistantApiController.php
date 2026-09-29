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
    private ?PDO $db = null;

    /**
     * @param string $path ruta dupa /api/assistant/, ex. "v1/trips/today"
     */
    public function handle(string $path): void
    {
        try {
            $route = trim($path, '/');
            if ($route !== 'v1/trips/today') {
                $this->sendJson(['success' => false, 'error' => 'not_found'], 404);
            }

            $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            if ($method !== 'GET') {
                header('Allow: GET');
                $this->sendJson(['success' => false, 'error' => 'method_not_allowed'], 405);
            }

            $this->requireValidToken();
            $user = $this->resolveChannelUser();
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
     * @return array{id:int,nume:string,rol:string,status:string,employee_id:?int}
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
