<?php
declare(strict_types=1);

/**
 * Furnizeaza pozitiile live ale flotei pentru pagina Harta Flota.
 *
 * Responsabilitati:
 *  - interogheaza SAS prin SasFleetClient (izolat, fara expunerea campurilor SAS)
 *  - cache pe disc (storage/cache) pentru a respecta recomandarea SAS de
 *    minim 20 de secunde intre interogarile currentpositions, indiferent
 *    cati utilizatori au pagina deschisa
 *  - persista sesiunea SAS (host + token) intre request-uri pentru a evita
 *    un login la fiecare interogare
 *  - imbogateste pozitiile cu vehiculul local (dupa nr. de inmatriculare normalizat)
 */
class FleetLivePositionService
{
    // SAS recomanda minim 20s intre interogari; cache-ul serveste toate sesiunile.
    private const POSITIONS_TTL_SECONDS = 20;
    // Lista de masini SAS se schimba rar; se reimprospateaza la 10 minute.
    private const CARS_TTL_SECONDS = 600;
    // Peste acest prag pozitia este marcata "stale" (vehicul oprit/offline de mult).
    private const STALE_THRESHOLD_SECONDS = 3600;

    private PDO $db;
    private SasFleetClient $client;
    private string $cacheDir;

    public function __construct(PDO $db, ?SasFleetClient $client = null)
    {
        $this->db = $db;
        $this->client = $client ?? new SasFleetClient();
        $this->cacheDir = dirname(BASE_PATH) . '/storage/cache';
    }

    public function credentialsAvailable(): bool
    {
        return $this->client->credentialsAvailable();
    }

    /**
     * Returneaza payload-ul complet pentru frontend:
     * ['positions' => [...], 'fetched_at' => 'Y-m-d H:i:s', 'from_cache' => bool, 'error' => ?string]
     *
     * La eroare SAS se serveste ultimul cache disponibil (oricat de vechi),
     * cu mesajul de eroare atasat, ca harta sa nu ramana goala.
     */
    public function getLivePositions(): array
    {
        if (!$this->credentialsAvailable()) {
            return [
                'positions' => [],
                'fetched_at' => null,
                'from_cache' => false,
                'error' => 'Credentialele SAS nu sunt configurate in .env.',
            ];
        }

        $cache = $this->readJson($this->positionsCacheFile());
        $fetchedAt = (int) ($cache['fetched_at_ts'] ?? 0);
        if ($cache !== null && (time() - $fetchedAt) < self::POSITIONS_TTL_SECONDS) {
            return [
                'positions' => $cache['positions'] ?? [],
                'fetched_at' => $cache['fetched_at'] ?? null,
                'from_cache' => true,
                'error' => null,
            ];
        }

        try {
            $positions = $this->fetchFromSas();
            $payload = [
                'positions' => $positions,
                'fetched_at' => date('Y-m-d H:i:s'),
                'fetched_at_ts' => time(),
            ];
            $this->writeJson($this->positionsCacheFile(), $payload);

            return [
                'positions' => $positions,
                'fetched_at' => $payload['fetched_at'],
                'from_cache' => false,
                'error' => null,
            ];
        } catch (Throwable $exception) {
            error_log('[FleetLivePositionService] ' . $exception->getMessage());

            return [
                'positions' => $cache['positions'] ?? [],
                'fetched_at' => $cache['fetched_at'] ?? null,
                'from_cache' => true,
                'error' => 'Interogarea SAS a esuat: ' . $exception->getMessage(),
            ];
        }
    }

    /**
     * Structura flotei pentru panoul lateral (companie -> sucursala -> punct de lucru -> masini),
     * in formatul intern al aplicatiei.
     */
    public function getFleetHierarchy(): array
    {
        $this->restoreSasSession();
        $info = $this->getSasInfo();
        $this->persistSasSession();

        $localByPlate = $this->getLocalVehiclesByPlate();
        $cars = [];
        foreach ((array) ($info['cars'] ?? []) as $car) {
            if (!is_array($car)) {
                continue;
            }
            $plate = (string) ($car['licensePlate'] ?? '');
            $local = $localByPlate[$this->plateKey($plate)] ?? null;
            $cars[] = [
                'sas_vehicle_id' => (int) ($car['carId'] ?? 0),
                'registration' => $plate,
                'driver' => $car['driver'] ?? null,
                'disabled' => (bool) ($car['disabled'] ?? false),
                'company_id' => (int) ($car['companyId'] ?? 0),
                'branch_id' => (int) ($car['branchId'] ?? 0),
                'work_point_id' => (int) ($car['workPointId'] ?? 0),
                'local_vehicle_id' => $local !== null ? (int) $local['id'] : null,
                'local_label' => $local !== null ? trim((string) $local['marca'] . ' ' . (string) $local['model']) : null,
            ];
        }

        $mapNamed = static function (array $items, string $idKey, array $extraKeys = []): array {
            $result = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $row = [
                    'id' => (int) ($item[$idKey] ?? 0),
                    'name' => (string) ($item['name'] ?? ''),
                ];
                foreach ($extraKeys as $internal => $sas) {
                    $row[$internal] = (int) ($item[$sas] ?? 0);
                }
                $result[] = $row;
            }
            return $result;
        };

        return [
            'companies' => $mapNamed((array) ($info['companies'] ?? []), 'companyId'),
            'branches' => $mapNamed((array) ($info['branches'] ?? []), 'branchId', ['company_id' => 'companyId']),
            'work_points' => $mapNamed((array) ($info['workPoints'] ?? []), 'workPointId', ['branch_id' => 'branchId']),
            'cars' => $cars,
        ];
    }

    /**
     * Traseul parcurs de o masina intr-o zi: punctele GPS (polilinie + evenimente)
     * si sumarul din foaia de parcurs (km totali, viteza medie).
     */
    public function getRouteForDay(int $carId, string $date): array
    {
        $day = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if (!$day instanceof DateTimeImmutable || $day->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Data invalida pentru traseu (format asteptat: Y-m-d).');
        }

        $cacheFile = $this->cacheDir . '/sas_route_' . $carId . '_' . $day->format('Ymd') . '.json';
        $cache = $this->readJson($cacheFile);
        $fetchedAt = (int) ($cache['fetched_at_ts'] ?? 0);
        // Zilele trecute nu se mai schimba -> cache lung; ziua curenta se reimprospateaza la 60s.
        $ttl = $day->format('Y-m-d') < date('Y-m-d') ? 86400 : 60;
        if ($cache !== null && (time() - $fetchedAt) < $ttl && is_array($cache['route'] ?? null)) {
            return $cache['route'];
        }

        $this->restoreSasSession();
        $startTime = $day->format('Y-m-d') . 'T00:00:00.000';
        $endTime = $day->format('Y-m-d') . 'T23:59:59.999';

        $events = $this->client->getCarEvents($carId, $startTime, $endTime);

        $summary = ['total_km' => null, 'average_speed' => null, 'license_plate' => null];
        try {
            $sheet = $this->client->getTravelSheet($carId, $startTime, $endTime);
            $summary['total_km'] = isset($sheet['totalDistance']) && is_numeric($sheet['totalDistance'])
                ? round((float) $sheet['totalDistance'], 1)
                : null;
            $summary['average_speed'] = isset($sheet['averageSpeed']) && is_numeric($sheet['averageSpeed'])
                ? round((float) $sheet['averageSpeed'], 1)
                : null;
            $summary['license_plate'] = $sheet['licensePlate'] ?? null;
        } catch (Throwable $exception) {
            // Sumarul este optional; traseul ramane utilizabil doar cu punctele GPS.
            error_log('[FleetLivePositionService][travelsheet] ' . $exception->getMessage());
        }

        $this->persistSasSession();

        $points = [];
        foreach ($events as $event) {
            $latitude = $event['latitude'] ?? null;
            $longitude = $event['longitude'] ?? null;
            if (!is_numeric($latitude) || !is_numeric($longitude)) {
                continue;
            }
            $course = $event['course'] ?? null;
            $points[] = [
                'latitude' => (float) $latitude,
                'longitude' => (float) $longitude,
                'timestamp' => $event['date'] ?? null,
                'speed' => is_numeric($event['speed'] ?? null) ? (float) $event['speed'] : null,
                'heading' => is_numeric($course) && (float) $course >= 0 ? (float) $course : null,
                'address' => $event['address'] ?? null,
                'city' => $event['city'] ?? null,
                'county' => $event['county'] ?? null,
                'trigger_event' => is_numeric($event['triggerEvent'] ?? null) ? (int) $event['triggerEvent'] : null,
                'segment_index' => is_numeric($event['segmentIndex'] ?? null) ? (int) $event['segmentIndex'] : null,
                'gps_signal_missing' => (bool) ($event['fake'] ?? false),
            ];
        }

        $route = [
            'sas_vehicle_id' => $carId,
            'date' => $day->format('Y-m-d'),
            'points' => $points,
            'summary' => $summary,
        ];

        $this->writeJson($cacheFile, ['route' => $route, 'fetched_at_ts' => time()]);

        return $route;
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchFromSas(): array
    {
        $this->restoreSasSession();

        $cars = $this->getSasCars();
        $normalized = $this->client->getNormalizedPositions($cars);

        $this->persistSasSession();

        return $this->enrichWithLocalVehicles($normalized);
    }

    private function restoreSasSession(): void
    {
        $session = $this->readJson($this->sessionFile());
        if (is_array($session)) {
            $this->client->restoreState($session);
        }
    }

    private function persistSasSession(): void
    {
        // Persista sesiunea SAS (host + token) pentru urmatoarele request-uri.
        $this->writeJson($this->sessionFile(), $this->client->exportState());
    }

    /** Raspunsul complet /api/info, cu cache pe disc. */
    private function getSasInfo(): array
    {
        $cache = $this->readJson($this->carsCacheFile());
        $fetchedAt = (int) ($cache['fetched_at_ts'] ?? 0);
        if ($cache !== null && (time() - $fetchedAt) < self::CARS_TTL_SECONDS && is_array($cache['info'] ?? null)) {
            return $cache['info'];
        }

        $info = $this->client->getCompanyInfo();
        $this->writeJson($this->carsCacheFile(), [
            'info' => $info,
            'fetched_at_ts' => time(),
        ]);

        return $info;
    }

    /** @return array<int, array<string, mixed>> */
    private function getSasCars(): array
    {
        $info = $this->getSasInfo();
        $cars = $info['cars'] ?? [];
        return is_array($cars) ? array_values(array_filter($cars, 'is_array')) : [];
    }

    /**
     * Ataseaza fiecarei pozitii vehiculul local (daca exista, dupa numar normalizat)
     * si indicatorii derivati (moving / stale).
     *
     * @param array<int, array<string, mixed>> $positions
     * @return array<int, array<string, mixed>>
     */
    private function enrichWithLocalVehicles(array $positions): array
    {
        $localByPlate = $this->getLocalVehiclesByPlate();
        $now = time();

        foreach ($positions as &$position) {
            $plateKey = $this->plateKey((string) ($position['registration'] ?? ''));
            $local = $localByPlate[$plateKey] ?? null;

            $position['local_vehicle_id'] = $local !== null ? (int) $local['id'] : null;
            $position['local_label'] = $local !== null
                ? trim((string) $local['marca'] . ' ' . (string) $local['model'])
                : null;
            $position['local_type'] = $local !== null ? (string) $local['tip_vehicul'] : null;

            $timestamp = strtotime((string) ($position['timestamp'] ?? ''));
            $ageSeconds = $timestamp !== false ? max(0, $now - $timestamp) : null;
            $position['age_seconds'] = $ageSeconds;
            $position['is_stale'] = $ageSeconds === null || $ageSeconds > self::STALE_THRESHOLD_SECONDS;
            $position['is_moving'] = ((float) ($position['speed'] ?? 0)) > 0;
        }
        unset($position);

        // Pozitiile fara coordonate nu pot fi afisate pe harta.
        return array_values(array_filter(
            $positions,
            static fn (array $position) => $position['latitude'] !== null && $position['longitude'] !== null
        ));
    }

    /** @return array<string, array<string, mixed>> */
    private function getLocalVehiclesByPlate(): array
    {
        $vehicles = [];
        try {
            $statement = $this->db->query(
                "SELECT id, nr_inmatriculare, marca, model, tip_vehicul
                 FROM vehicule
                 WHERE nr_inmatriculare <> 'STOC-ANVELOPE'"
            );
            foreach ($statement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [] as $vehicle) {
                $vehicles[$this->plateKey((string) $vehicle['nr_inmatriculare'])] = $vehicle;
            }
        } catch (Throwable $exception) {
            error_log('[FleetLivePositionService][local_vehicles] ' . $exception->getMessage());
        }

        return $vehicles;
    }

    private function plateKey(string $plate): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $plate) ?? $plate);
    }

    private function positionsCacheFile(): string
    {
        return $this->cacheDir . '/sas_positions.json';
    }

    private function carsCacheFile(): string
    {
        return $this->cacheDir . '/sas_cars.json';
    }

    private function sessionFile(): string
    {
        return $this->cacheDir . '/sas_session.json';
    }

    private function readJson(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function writeJson(string $file, array $data): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }

        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}
