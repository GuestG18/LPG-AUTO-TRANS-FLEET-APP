<?php
declare(strict_types=1);

/**
 * SANDBOX - Precompletare cursa din GPS (SAS Fleet API).
 *
 * Primeste vehiculul local + data/ora de inceput si construieste sugestiile
 * pentru campurile din formularul Dispecer curse pe baza foii de parcurs SAS:
 *  - data incarcare (prima oprire intr-un POI de incarcare)
 *  - data + ora sfarsit (ultima miscare, daca cursa pare incheiata)
 *  - km cursa (suma segmentelor) si km totali (delta odometru)
 *  - sugestie loc incarcare (potrivire nume POI <-> configurare_locuri_incarcare)
 *
 * Serviciul este doar citire (SAS + DB) si nu modifica nicio cursa.
 * Reutilizeaza cache-ul de sesiune SAS din storage/cache (acelasi fisier ca
 * FleetLivePositionService, ca sa nu faca login-uri suplimentare).
 */
class SasTripPrefillService
{
    private const POIS_TTL_SECONDS = 600;
    private const CARS_TTL_SECONDS = 600;
    // Peste acest prag de stationare dupa ultima miscare, cursa e considerata incheiata.
    private const TRIP_FINISHED_PARK_MINUTES = 120;
    // Sub aceasta distanta un segment e considerat "fara miscare" (zgomot GPS / manevre).
    private const MIN_MOVING_SEGMENT_KM = 0.5;
    // O cursa se inchide la revenirea in punctul de plecare, dar numai dupa minim atatia km
    // (altfel manevrele locale din jurul garajului ar inchide cursa imediat).
    private const MIN_TRIP_KM = 10.0;
    // Doua pozitii aflate sub aceasta raza sunt considerate aceeasi locatie (plecare = sosire).
    private const SAME_LOCATION_RADIUS_KM = 0.5;
    // Fereastra maxima interogata o singura data din SAS.
    private const MAX_WINDOW_DAYS = 7;

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
     * Vehiculele locale care au corespondent in SAS (dupa numar de inmatriculare
     * normalizat), pentru dropdown-ul din sandbox.
     *
     * @return array<int, array{id: int, nr_inmatriculare: string, label: string, sas_car_id: int, driver: ?string}>
     */
    public function getVehicleOptions(): array
    {
        $this->restoreSasSession();
        $sasCars = $this->getSasCars();
        $this->persistSasSession();

        $sasByPlate = [];
        foreach ($sasCars as $car) {
            $plate = $this->plateKey((string) ($car['licensePlate'] ?? ''));
            if ($plate !== '') {
                $sasByPlate[$plate] = $car;
            }
        }

        $options = [];
        $statement = $this->db->query(
            "SELECT id, nr_inmatriculare, marca, model
             FROM vehicule
             WHERE nr_inmatriculare <> 'STOC-ANVELOPE'
             ORDER BY nr_inmatriculare"
        );
        foreach ($statement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [] as $vehicle) {
            $sasCar = $sasByPlate[$this->plateKey((string) $vehicle['nr_inmatriculare'])] ?? null;
            if ($sasCar === null || !empty($sasCar['disabled'])) {
                continue;
            }
            $options[] = [
                'id' => (int) $vehicle['id'],
                'nr_inmatriculare' => (string) $vehicle['nr_inmatriculare'],
                'label' => trim((string) $vehicle['nr_inmatriculare'] . ' — ' . trim((string) $vehicle['marca'] . ' ' . (string) $vehicle['model'])),
                'sas_car_id' => (int) ($sasCar['carId'] ?? 0),
                'driver' => isset($sasCar['driver']) && trim((string) $sasCar['driver']) !== '' ? (string) $sasCar['driver'] : null,
            ];
        }

        return $options;
    }

    /**
     * Construieste sugestiile de precompletare pentru o cursa.
     *
     * @param int $localVehicleId id din tabela vehicule
     * @param string $dateStart data de inceput selectata de dispecer (Y-m-d)
     * @param string|null $timeStart ora de inceput (H:i) sau null pentru autodetectie
     */
    public function prefill(int $localVehicleId, string $dateStart, ?string $timeStart): array
    {
        $day = DateTimeImmutable::createFromFormat('Y-m-d', $dateStart);
        if (!$day instanceof DateTimeImmutable || $day->format('Y-m-d') !== $dateStart) {
            throw new InvalidArgumentException('Data de inceput invalida (format asteptat: Y-m-d).');
        }
        if ($timeStart !== null && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $timeStart)) {
            throw new InvalidArgumentException('Ora de inceput invalida (format asteptat: HH:MM).');
        }

        $vehicle = $this->getLocalVehicle($localVehicleId);
        if ($vehicle === null) {
            throw new InvalidArgumentException('Vehiculul selectat nu exista.');
        }

        $this->restoreSasSession();
        $carId = $this->resolveSasCarId((string) $vehicle['nr_inmatriculare']);
        if ($carId === 0) {
            throw new InvalidArgumentException('Vehiculul ' . $vehicle['nr_inmatriculare'] . ' nu are corespondent in SAS.');
        }

        $now = new DateTimeImmutable('now');
        $windowStart = $day->setTime(0, 0, 0);
        if ($timeStart !== null) {
            [$hour, $minute] = array_map('intval', explode(':', $timeStart));
            $windowStart = $day->setTime($hour, $minute, 0);
        }

        $warnings = [];
        $windowEnd = $now;
        $maxEnd = $windowStart->modify('+' . self::MAX_WINDOW_DAYS . ' days');
        if ($windowEnd > $maxEnd) {
            $windowEnd = $maxEnd;
            $warnings[] = 'Fereastra interogata a fost limitata la ' . self::MAX_WINDOW_DAYS . ' zile de la data de inceput.';
        }
        if ($windowStart > $now) {
            throw new InvalidArgumentException('Data de inceput este in viitor - nu exista date GPS.');
        }

        $sheet = $this->client->getTravelSheet(
            $carId,
            $windowStart->format('Y-m-d\TH:i:s'),
            $windowEnd->format('Y-m-d\TH:i:s')
        );
        $pois = $this->getPois();
        $this->persistSasSession();

        $segments = $this->normalizeSegments(is_array($sheet['segments'] ?? null) ? $sheet['segments'] : [], $pois);
        $moving = array_values(array_filter($segments, static fn (array $s) => $s['distance_km'] >= self::MIN_MOVING_SEGMENT_KM));

        $suggestions = [
            'data_inceput' => $dateStart,
            'ora_inceput' => $timeStart,
            'data_incarcare' => null,
            'data_sfarsit' => null,
            'ora_sfarsit' => null,
            'km_cursa' => null,
            'km_totali' => null,
            'loc_incarcare' => null,
            'trip_finished' => false,
        ];

        if ($moving === []) {
            $warnings[] = 'GPS-ul nu arata nicio miscare pentru acest vehicul in fereastra selectata.';
            return $this->buildResult($vehicle, $carId, $sheet, $segments, $suggestions, $warnings, $windowStart, $windowEnd, null);
        }

        $first = $moving[0];

        // Ora de inceput: prima miscare reala, daca dispecerul nu a fixat-o deja.
        if ($timeStart === null) {
            $suggestions['ora_inceput'] = substr((string) $first['date_start'], 11, 5);
        }

        // Cursa = bucla: pleaca dintr-o locatie (ancora = pozitia de la inceputul
        // ferestrei) si se inchide la prima revenire in aceeasi locatie, dupa
        // minim MIN_TRIP_KM. Tot ce urmeaza dupa revenire apartine cursei urmatoare.
        $anchor = [
            'poi_id' => $segments[0]['start_poi_id'],
            'lat' => $segments[0]['lat_start'],
            'lng' => $segments[0]['lng_start'],
        ];

        $departIdx = null;
        foreach ($segments as $idx => $segment) {
            if ($segment['distance_km'] >= self::MIN_MOVING_SEGMENT_KM) {
                $departIdx = $idx;
                break;
            }
        }

        $returnIdx = null;
        $cumulativeKm = 0.0;
        foreach ($segments as $idx => $segment) {
            if ($departIdx === null || $idx < $departIdx) {
                continue;
            }
            $cumulativeKm += $segment['distance_km'];
            if (
                $cumulativeKm >= self::MIN_TRIP_KM
                && $segment['distance_km'] >= self::MIN_MOVING_SEGMENT_KM
                && $this->isSameLocation($segment['end_poi_id'], $segment['lat_end'], $segment['lng_end'], $anchor)
            ) {
                $returnIdx = $idx;
                break;
            }
        }

        $lastTripIdx = $returnIdx;
        $tripCloseReason = null;

        if ($returnIdx !== null) {
            // Cursa inchisa prin revenirea in punctul de plecare.
            $tripCloseReason = 'return_to_start';
            $suggestions['trip_finished'] = true;
            $suggestions['data_sfarsit'] = substr((string) $segments[$returnIdx]['date_end'], 0, 10);
            $suggestions['ora_sfarsit'] = substr((string) $segments[$returnIdx]['date_end'], 11, 5);
        } else {
            // Fallback: vehiculul nu a revenit la plecare in fereastra interogata;
            // sfarsitul se estimeaza dupa ultima miscare urmata de stationare lunga.
            $last = $moving[count($moving) - 1];
            $lastTripIdx = count($segments) - 1;
            $lastEnd = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', (string) $last['date_end'])
                ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $last['date_end']);
            $parkedMinutes = $lastEnd instanceof DateTimeImmutable
                ? (int) floor(($now->getTimestamp() - $lastEnd->getTimestamp()) / 60)
                : null;

            if ($parkedMinutes !== null && $parkedMinutes >= self::TRIP_FINISHED_PARK_MINUTES) {
                $tripCloseReason = 'long_park';
                $suggestions['trip_finished'] = true;
                $suggestions['data_sfarsit'] = substr((string) $last['date_end'], 0, 10);
                $suggestions['ora_sfarsit'] = substr((string) $last['date_end'], 11, 5);
                $warnings[] = 'Vehiculul nu a revenit in punctul de plecare in fereastra interogata; '
                    . 'sfarsitul a fost estimat dupa ultima miscare urmata de stationare lunga - verifica manual.';
            } else {
                $warnings[] = 'Cursa pare inca in desfasurare (ultima miscare acum '
                    . ($parkedMinutes !== null ? $parkedMinutes . ' minute' : 'necunoscut')
                    . ') - data si ora de sfarsit nu au fost propuse.';
            }
        }

        // Segmentele de dupa inchiderea cursei sunt marcate ca apartinand cursei urmatoare.
        if ($returnIdx !== null) {
            foreach ($segments as $idx => $segment) {
                $segments[$idx]['after_trip'] = $idx > $returnIdx;
            }
        }

        // Data incarcare: prima oprire intr-un POI de incarcare, DOAR in interiorul cursei;
        // fallback: prima oprire in orice POI cunoscut din cursa.
        $loadingStop = null;
        foreach ($segments as $idx => $segment) {
            if ($lastTripIdx !== null && $idx > $lastTripIdx) {
                break;
            }
            if ($segment['end_poi'] === null) {
                continue;
            }
            if ($this->looksLikeLoadingPoi($segment['end_poi'])) {
                $loadingStop = $segment;
                break;
            }
            if ($loadingStop === null) {
                $loadingStop = $segment;
            }
        }
        if ($loadingStop !== null) {
            $suggestions['data_incarcare'] = substr((string) $loadingStop['date_end'], 0, 10);
            $suggestions['loc_incarcare'] = $this->matchLoadLocation((string) $loadingStop['end_poi']);
        }

        // Km cursa: suma distantelor segmentelor din cursa; km totali: delta odometru.
        $sumKm = 0.0;
        $lastKmIndex = null;
        foreach ($segments as $idx => $segment) {
            if ($lastTripIdx !== null && $idx > $lastTripIdx) {
                break;
            }
            $sumKm += $segment['distance_km'];
            if ($segment['km_index'] !== null) {
                $lastKmIndex = (float) $segment['km_index'];
            }
        }
        $suggestions['km_cursa'] = (int) round($sumKm);

        $kmIndexStart = $first['km_index'] !== null ? (float) $first['km_index'] - $first['distance_km'] : null;
        if ($kmIndexStart !== null && $lastKmIndex !== null && $lastKmIndex >= $kmIndexStart) {
            $suggestions['km_totali'] = (int) round($lastKmIndex - $kmIndexStart);
        } else {
            $suggestions['km_totali'] = $suggestions['km_cursa'];
        }

        return $this->buildResult($vehicle, $carId, $sheet, $segments, $suggestions, $warnings, $windowStart, $windowEnd, $tripCloseReason);
    }

    /** @param array<int, array<string, mixed>> $segments */
    private function buildResult(
        array $vehicle,
        int $carId,
        array $sheet,
        array $segments,
        array $suggestions,
        array $warnings,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
        ?string $tripCloseReason
    ): array {
        return [
            'trip_close_reason' => $tripCloseReason,
            'vehicle' => [
                'id' => (int) $vehicle['id'],
                'nr_inmatriculare' => (string) $vehicle['nr_inmatriculare'],
                'sas_car_id' => $carId,
            ],
            'window' => [
                'start' => $windowStart->format('Y-m-d H:i'),
                'end' => $windowEnd->format('Y-m-d H:i'),
            ],
            'sas_summary' => [
                'total_distance_km' => is_numeric($sheet['totalDistance'] ?? null) ? round((float) $sheet['totalDistance'], 1) : null,
                'average_speed' => is_numeric($sheet['averageSpeed'] ?? null) ? round((float) $sheet['averageSpeed'], 1) : null,
                'work_hours' => is_numeric($sheet['workTimeSpanInSeconds'] ?? null) ? round(((float) $sheet['workTimeSpanInSeconds']) / 3600, 1) : null,
                'idle_hours' => is_numeric($sheet['idleTimeSpanInSeconds'] ?? null) ? round(((float) $sheet['idleTimeSpanInSeconds']) / 3600, 1) : null,
                'can_fuel_used_l' => is_numeric($sheet['CANFuelUsed'] ?? null) ? round((float) $sheet['CANFuelUsed'], 1) : null,
            ],
            'suggestions' => $suggestions,
            'segments' => $segments,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rawSegments
     * @param array<int, string> $pois locationId => nume
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSegments(array $rawSegments, array $pois): array
    {
        $segments = [];
        foreach ($rawSegments as $segment) {
            if (!is_array($segment)) {
                continue;
            }
            $startLocation = $segment['startLocationId'] ?? null;
            $endLocation = $segment['endLocationId'] ?? null;
            $segments[] = [
                'index' => (int) ($segment['indexId'] ?? 0),
                'date_start' => (string) ($segment['dateStart'] ?? ''),
                'date_end' => (string) ($segment['dateEnd'] ?? ''),
                'distance_km' => is_numeric($segment['distance'] ?? null) ? round((float) $segment['distance'], 2) : 0.0,
                'km_index' => is_numeric($segment['kmIndex'] ?? null) ? (float) $segment['kmIndex'] : null,
                'from' => $this->describePlace($segment, 'Start', is_numeric($startLocation) ? ($pois[(int) $startLocation] ?? null) : null),
                'to' => $this->describePlace($segment, 'End', is_numeric($endLocation) ? ($pois[(int) $endLocation] ?? null) : null),
                'start_poi' => is_numeric($startLocation) ? ($pois[(int) $startLocation] ?? null) : null,
                'end_poi' => is_numeric($endLocation) ? ($pois[(int) $endLocation] ?? null) : null,
                'start_poi_id' => is_numeric($startLocation) ? (int) $startLocation : null,
                'end_poi_id' => is_numeric($endLocation) ? (int) $endLocation : null,
                'lat_start' => is_numeric($segment['latitudeStart'] ?? null) ? (float) $segment['latitudeStart'] : null,
                'lng_start' => is_numeric($segment['longitudeStart'] ?? null) ? (float) $segment['longitudeStart'] : null,
                'lat_end' => is_numeric($segment['latitudeEnd'] ?? null) ? (float) $segment['latitudeEnd'] : null,
                'lng_end' => is_numeric($segment['longitudeEnd'] ?? null) ? (float) $segment['longitudeEnd'] : null,
                'park_before_minutes' => is_numeric($segment['parkTimeSeconds'] ?? null) ? (int) round(((float) $segment['parkTimeSeconds']) / 60) : null,
                'idle_engine_minutes' => is_numeric($segment['idleEngineSeconds'] ?? null) ? (int) round(((float) $segment['idleEngineSeconds']) / 60) : null,
                'after_trip' => false,
            ];
        }

        return $segments;
    }

    private function describePlace(array $segment, string $suffix, ?string $poiName): string
    {
        if ($poiName !== null && $poiName !== '') {
            return $poiName;
        }

        $parts = [];
        foreach (['city' . $suffix, 'county' . $suffix] as $key) {
            $value = trim((string) ($segment[$key] ?? ''));
            if ($value !== '' && $value !== '-') {
                $parts[] = $value;
            }
        }
        $address = trim((string) ($segment['address' . $suffix] ?? ''));
        if ($address !== '' && $address !== '-') {
            $parts[] = $address;
        }

        return $parts !== [] ? implode(', ', $parts) : 'necunoscut';
    }

    /**
     * POI-urile SAS al caror nume sugereaza un punct de incarcare.
     * Numele reale contin "Cantar"/"incarcare"/terminale; euristica acopera si
     * potrivirea cu locurile de incarcare configurate local.
     */
    private function looksLikeLoadingPoi(string $poiName): bool
    {
        $normalized = $this->normalizeText($poiName);
        foreach (['incarcare', 'cantar', 'terminal'] as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return $this->matchLoadLocation($poiName) !== null;
    }

    /**
     * Potriveste numele unui POI SAS cu un loc de incarcare configurat local
     * (configurare_locuri_incarcare). Potrivire pe incluziune de text normalizat,
     * in ambele directii ("Vixon Gas Giurgiu" <-> "Giurgiu").
     *
     * @return array{id: int, nume: string}|null
     */
    private function matchLoadLocation(string $poiName): ?array
    {
        $poi = $this->normalizeText($poiName);
        if ($poi === '') {
            return null;
        }

        foreach ($this->getLoadLocations() as $location) {
            $local = $this->normalizeText((string) $location['nume']);
            if ($local === '') {
                continue;
            }
            if (str_contains($poi, $local) || str_contains($local, $poi)) {
                return ['id' => (int) $location['id'], 'nume' => (string) $location['nume']];
            }
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    private function getLoadLocations(): array
    {
        static $locations = null;
        if ($locations === null) {
            $statement = $this->db->query('SELECT id, nume FROM configurare_locuri_incarcare WHERE activ = 1');
            $locations = $statement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
        }

        return $locations;
    }

    private function getLocalVehicle(int $vehicleId): ?array
    {
        $statement = $this->db->prepare('SELECT id, nr_inmatriculare FROM vehicule WHERE id = :id');
        $statement->execute([':id' => $vehicleId]);
        $vehicle = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($vehicle) ? $vehicle : null;
    }

    private function resolveSasCarId(string $plate): int
    {
        $needle = $this->plateKey($plate);
        foreach ($this->getSasCars() as $car) {
            if ($this->plateKey((string) ($car['licensePlate'] ?? '')) === $needle) {
                return (int) ($car['carId'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * POI-urile SAS (locationId => nume), cu cache pe disc: lista se schimba rar,
     * iar interogarea are rate-limit pe minut.
     *
     * @return array<int, string>
     */
    private function getPois(): array
    {
        $cacheFile = $this->cacheDir . '/sas_pois.json';
        $cache = $this->readJson($cacheFile);
        $fetchedAt = (int) ($cache['fetched_at_ts'] ?? 0);
        if ($cache !== null && (time() - $fetchedAt) < self::POIS_TTL_SECONDS && is_array($cache['pois'] ?? null)) {
            return array_map('strval', $cache['pois']);
        }

        $companyName = $this->getCompanyName();
        $pois = [];
        if ($companyName !== '') {
            foreach ($this->client->findPois($companyName, '') as $poi) {
                $locationId = (int) ($poi['locationId'] ?? 0);
                $name = trim((string) ($poi['name'] ?? ''));
                if ($locationId > 0 && $name !== '') {
                    $pois[$locationId] = $name;
                }
            }
        }

        $this->writeJson($cacheFile, ['pois' => $pois, 'fetched_at_ts' => time()]);

        return $pois;
    }

    private function getCompanyName(): string
    {
        $info = $this->getSasInfo();
        $companies = is_array($info['companies'] ?? null) ? $info['companies'] : [];
        $first = is_array($companies[0] ?? null) ? $companies[0] : [];

        return trim((string) ($first['name'] ?? ''));
    }

    /** Raspunsul complet /api/info, cu acelasi cache pe disc ca FleetLivePositionService. */
    private function getSasInfo(): array
    {
        $cache = $this->readJson($this->cacheDir . '/sas_cars.json');
        $fetchedAt = (int) ($cache['fetched_at_ts'] ?? 0);
        if ($cache !== null && (time() - $fetchedAt) < self::CARS_TTL_SECONDS && is_array($cache['info'] ?? null)) {
            return $cache['info'];
        }

        $info = $this->client->getCompanyInfo();
        $this->writeJson($this->cacheDir . '/sas_cars.json', [
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

    private function restoreSasSession(): void
    {
        $session = $this->readJson($this->cacheDir . '/sas_session.json');
        if (is_array($session)) {
            $this->client->restoreState($session);
        }
    }

    private function persistSasSession(): void
    {
        $this->writeJson($this->cacheDir . '/sas_session.json', $this->client->exportState());
    }

    /**
     * Aceeasi locatie = acelasi POI SAS sau coordonate la sub SAME_LOCATION_RADIUS_KM
     * de ancora (punctul de plecare al cursei).
     */
    private function isSameLocation(?int $poiId, ?float $lat, ?float $lng, array $anchor): bool
    {
        if ($poiId !== null && $anchor['poi_id'] !== null && $poiId === (int) $anchor['poi_id']) {
            return true;
        }

        if ($lat === null || $lng === null || !is_numeric($anchor['lat']) || !is_numeric($anchor['lng'])) {
            return false;
        }

        return $this->haversineKm($lat, $lng, (float) $anchor['lat'], (float) $anchor['lng']) <= self::SAME_LOCATION_RADIUS_KM;
    }

    /** Distanta in km intre doua coordonate GPS (formula haversine). */
    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function plateKey(string $plate): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $plate) ?? $plate);
    }

    private function normalizeText(string $text): string
    {
        $text = function_exists('iconv') ? (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text) : $text;

        return strtolower(trim(preg_replace('/\s+/', ' ', $text) ?? $text));
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
