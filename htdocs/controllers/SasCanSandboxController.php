<?php
declare(strict_types=1);

/**
 * SANDBOX - Km din CAN (raportul web SAS „Foaie de parcurs cu distanțe din CAN”).
 *
 * Pagina de test pentru SasCanKmService cu credentiale introduse in formular
 * (sau cele din .env daca utilizatorul e lasat gol). Credentialele se folosesc doar
 * pentru cererea curenta: nu se salveaza, nu se logheaza si nu se trimit inapoi.
 *
 * Rute:
 *   ?page=sas_can_sandbox                 -> formularul
 *   ?page=sas_can_sandbox&action=fetch    -> POST (CSRF) JSON: raportul CAN + lista vehiculelor contului
 */
class SasCanSandboxController
{
    private const PAGE = 'sas_can_sandbox';

    public function handle(string $action): void
    {
        if (!can(self::PAGE)) {
            if ($action !== '') {
                $this->json(['ok' => false, 'message' => 'Nu ai dreptul pentru această acțiune.'], 403);
            }
            access_deny_403();
        }

        if ($action === 'fetch') {
            $this->fetch();
        }

        render('sas_can_sandbox/index.php', [
            'pageTitle' => 'Sandbox Km CAN',
            'currentPage' => self::PAGE,
            'envAvailable' => (new SasFleetClient())->credentialsAvailable(),
        ]);
    }

    private function fetch(): never
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['ok' => false, 'message' => 'Metodă nepermisă.'], 405);
        }
        if (!verify_csrf_token($_POST['_token'] ?? null)) {
            $this->json(['ok' => false, 'message' => 'Sesiunea a expirat. Reîncarcă pagina.'], 403);
        }
        session_write_close();

        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $host = strtolower(trim((string) ($_POST['host'] ?? '')));
        $host = preg_replace('#^https?://#', '', rtrim($host, '/')) ?? '';
        // Parola pleaca spre acest server: doar servere SAS, ca formularul sa nu trimita credentiale oriunde.
        if ($host !== '' && !preg_match('/^[a-z0-9-]+\.alarma\.ro$/', $host)) {
            $this->json(['ok' => false, 'message' => 'Serverul trebuie să fie de forma wwwNN.alarma.ro (sau gol pentru detectare automată).'], 422);
        }
        if ($username !== '' && $password === '') {
            $this->json(['ok' => false, 'message' => 'Completează parola pentru utilizatorul SAS.'], 422);
        }

        $started = microtime(true);
        try {
            $client = $username !== ''
                ? new SasFleetClient($username, $password, $host !== '' ? $host : null)
                : new SasFleetClient(null, null, $host !== '' ? $host : null);
            if (!$client->credentialsAvailable()) {
                throw new InvalidArgumentException('Nu există credențiale: completează utilizatorul și parola (în .env nu sunt setate).');
            }

            $cars = [];
            foreach ($client->getCars() as $car) {
                $cars[] = ['car_id' => (int) ($car['carId'] ?? 0), 'plate' => trim((string) ($car['licensePlate'] ?? ''))];
            }
            $carId = $this->resolveCarId(trim((string) ($_POST['vehicle'] ?? '')), $cars);

            $report = (new SasCanKmService($client))->travelSheet($carId, (string) ($_POST['from'] ?? ''), (string) ($_POST['to'] ?? ''));
        } catch (InvalidArgumentException $exception) {
            $this->json(['ok' => false, 'message' => $exception->getMessage(), 'cars' => $cars ?? null], 422);
        } catch (Throwable $exception) {
            $this->json(['ok' => false, 'message' => $exception->getMessage(), 'cars' => $cars ?? null], 502);
        }

        $this->json([
            'ok' => true,
            'source' => $username !== '' ? 'form' : 'env',
            'host' => $client->resolveHost(),
            'seconds' => round(microtime(true) - $started, 2),
            'cars' => $cars,
            'report' => $report,
        ]);
    }

    /** Nr. de inmatriculare (orice spatiere) sau carId SAS. */
    private function resolveCarId(string $vehicle, array $cars): int
    {
        if ($vehicle === '') {
            throw new InvalidArgumentException('Alege vehiculul (număr de înmatriculare sau carId SAS).');
        }
        $wanted = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $vehicle));
        foreach ($cars as $car) {
            if ((ctype_digit($vehicle) && $car['car_id'] === (int) $vehicle)
                || strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $car['plate'])) === $wanted) {
                return $car['car_id'];
            }
        }
        throw new InvalidArgumentException('Vehiculul „' . $vehicle . '” nu există în contul SAS folosit.');
    }

    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
}
