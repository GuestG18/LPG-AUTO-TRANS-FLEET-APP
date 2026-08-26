<?php
declare(strict_types=1);

/**
 * SANDBOX - Serviciu pentru dashboardul live al flotei (SAS).
 *
 * Construit peste FleetLivePositionService (care respecta deja regula SAS de
 * minim 20s intre interogari currentpositions si persista sesiunea SAS).
 * Adauga deasupra:
 *  - clasificarea vehiculelor pe statusuri (moving / idle / parked / offline)
 *  - detectia tranzitiilor de miscare intre doua interogari consecutive
 *    (a pornit / s-a oprit / a revenit online / a iesit offline), pastrate
 *    intr-un feed rulant pe disc, ca toate sesiunile sa vada aceleasi evenimente
 *  - sumarul zilei curente per vehicul din travelsheet cu interval deschis
 *    (intervalul inchis returneaza gol pentru ziua in curs)
 *
 * Praguri deduse din studiul datelor reale (scripts/study_sas_dashboard_data.php):
 * masinile in mers raporteaza la ~60-70s, cele oprite raman pe ultimul timestamp.
 */
class SasDashboardService
{
    // Pozitie mai veche de atat + speed>0 nu mai e considerata "in miscare".
    private const MOVING_MAX_AGE_SECONDS = 600;
    // Speed 0 dar raporteaza recent = oprit temporar (motor pornit / semafor / descarcare).
    private const IDLE_MAX_AGE_SECONDS = 1800;
    // Fara raport sub 24h = parcat normal (contact luat); peste = offline/GPS mut.
    private const PARKED_MAX_AGE_SECONDS = 86400;
    // Feedul de evenimente pastreaza cel mult atatea intrari.
    private const FEED_MAX_ENTRIES = 150;
    // Sumarul de zi per vehicul se reimprospateaza la 60s.
    private const DAY_TTL_SECONDS = 60;
    // Statistici zi (km/CAN/odometru) per vehicul: cate se reimprospateaza per poll
    // si cat de des, ca sa nu facem 48 de interogari travelsheet la fiecare 30s.
    private const DAY_STATS_BATCH = 10;
    private const DAY_STATS_TTL_MOVING = 300;
    private const DAY_STATS_TTL_OTHER = 3600;
    // Garda de plauzibilitate pentru consumul CAN (L/100km), ca la Carburanti.
    private const L100_MIN = 4.0;
    private const L100_MAX = 120.0;
    private const L100_MIN_KM = 5.0;
    // Map matching (traseul "lipit" de drum) prin Valhalla public (FOSSGIS).
    // Nota: serverele OSRM publice NU merg pentru asta — accepta doar ~10
    // coordonate per cerere si blocheaza temporar IP-ul la rafale de cereri
    // (testat empiric). Valhalla primeste toata tura intr-un singur POST.
    private const VALHALLA_TRACE_URL = 'https://valhalla1.openstreetmap.de/trace_route';
    private const VALHALLA_MAX_POINTS = 2000;
    private const VALHALLA_TIMEOUT_SECONDS = 15;
    // O pauza mai lunga de atat intre doua puncte GPS rupe traseul in "ture"
    // separate, ca matching-ul sa nu inventeze drum peste stationari.
    private const TRIP_GAP_SECONDS = 600;

    private PDO $db;
    private FleetLivePositionService $positions;
    private SasFleetClient $client;
    private string $cacheDir;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->positions = new FleetLivePositionService($db);
        $this->client = new SasFleetClient();
        $this->cacheDir = dirname(BASE_PATH) . '/storage/cache';
    }

    public function credentialsAvailable(): bool
    {
        return $this->client->credentialsAvailable();
    }

    /**
     * Snapshot complet pentru dashboard: vehicule clasificate, KPI-uri si
     * feedul de evenimente de miscare.
     */
    public function getSnapshot(): array
    {
        $payload = $this->positions->getLivePositions();
        $vehicles = [];
        foreach ((array) $payload['positions'] as $position) {
            if (is_array($position)) {
                $vehicles[] = $this->classify($position);
            }
        }

        // Ordinea implicita: in miscare primele, apoi dupa prospetimea raportarii.
        usort($vehicles, static function (array $a, array $b): int {
            $rank = ['moving' => 0, 'idle' => 1, 'parked' => 2, 'offline' => 3];
            $byStatus = ($rank[$a['status']] ?? 9) <=> ($rank[$b['status']] ?? 9);
            if ($byStatus !== 0) {
                return $byStatus;
            }
            return ($a['age_seconds'] ?? PHP_INT_MAX) <=> ($b['age_seconds'] ?? PHP_INT_MAX);
        });

        $feed = $this->updateMovementFeed($vehicles);
        $this->attachDayStats($vehicles);

        return [
            'kpis' => $this->buildKpis($vehicles),
            'vehicles' => $vehicles,
            'feed' => $feed,
            'meta' => [
                'generated_at' => date('c'),
                'fetched_at' => $payload['fetched_at'],
                'from_cache' => (bool) $payload['from_cache'],
                'refresh_seconds' => 30,
                'error' => $payload['error'],
            ],
        ];
    }

    /**
     * Sumarul unui vehicul pe un interval de zile (implicit ziua curenta):
     * km, timpi, CAN, odometru si segmente. Pentru intervale care includ ziua
     * curenta se foloseste travelsheet cu isClosedTimeInterval=false ca sa
     * includa si segmentul aflat in desfasurare; intervalele trecute nu se mai
     * schimba, deci se cacheuiesc mult.
     */
    public function getVehicleRange(int $carId, ?string $startDate = null, ?string $endDate = null): array
    {
        if ($carId <= 0) {
            throw new InvalidArgumentException('Identificator vehicul invalid.');
        }

        [$start, $end] = $this->validateRange($startDate, $endDate);
        $includesToday = $end->format('Y-m-d') >= date('Y-m-d');

        $cacheFile = $this->cacheDir . '/sas_dash_range_' . $carId . '_' . $start->format('Ymd') . '_' . $end->format('Ymd') . '.json';
        $cache = $this->readJson($cacheFile);
        $ttl = $includesToday ? self::DAY_TTL_SECONDS : 7 * 86400;
        if ($cache !== null
            && (time() - (int) ($cache['fetched_at_ts'] ?? 0)) < $ttl
            && is_array($cache['day'] ?? null)
        ) {
            return $cache['day'];
        }

        $this->restoreSasSession();
        $sheet = $this->client->getTravelSheet(
            $carId,
            $start->format('Y-m-d') . 'T00:00:00',
            $end->format('Y-m-d') . 'T23:59:59',
            !$includesToday
        );
        $this->persistSasSession();

        $segments = [];
        foreach ((array) ($sheet['segments'] ?? []) as $segment) {
            if (!is_array($segment)) {
                continue;
            }
            $segments[] = [
                'index' => (int) ($segment['indexId'] ?? 0),
                'start_time' => $this->toStringOrNull($segment['dateStart'] ?? null),
                'end_time' => $this->toStringOrNull($segment['dateEnd'] ?? null),
                'from' => $this->segmentPlace($segment, 'Start'),
                'to' => $this->segmentPlace($segment, 'End'),
                'distance_km' => is_numeric($segment['distance'] ?? null) ? round((float) $segment['distance'], 1) : null,
                'average_speed' => is_numeric($segment['averageSpeed'] ?? null) ? (float) $segment['averageSpeed'] : null,
                'work_seconds' => is_numeric($segment['workSeconds'] ?? null) ? (int) $segment['workSeconds'] : null,
                'idle_engine_seconds' => is_numeric($segment['idleEngineSeconds'] ?? null) ? (int) $segment['idleEngineSeconds'] : null,
                'park_seconds_before' => is_numeric($segment['parkTimeSeconds'] ?? null) ? (int) $segment['parkTimeSeconds'] : null,
                'km_index' => is_numeric($segment['kmIndex'] ?? null) ? (int) round((float) $segment['kmIndex']) : null,
            ];
        }

        $dayKm = is_numeric($sheet['totalDistance'] ?? null) ? round((float) $sheet['totalDistance'], 1) : null;
        $canL100 = is_numeric($sheet['CANFuelUsedPer100Km'] ?? null) ? round((float) $sheet['CANFuelUsedPer100Km'], 1) : null;
        if ($canL100 !== null && (($dayKm ?? 0) < self::L100_MIN_KM || $canL100 < self::L100_MIN || $canL100 > self::L100_MAX)) {
            $canL100 = null;
        }
        $lastKmIndex = $this->lastKmIndex($sheet);
        if ($lastKmIndex === null && $includesToday) {
            // Doar pentru "acum": daca azi nu a circulat, arata ultimul odometru cunoscut.
            $odometers = $this->readJson($this->cacheDir . '/sas_dash_odometer.json') ?? [];
            $stored = $odometers[(string) $carId]['km'] ?? null;
            $lastKmIndex = is_numeric($stored) ? (float) $stored : null;
        }

        $day = [
            'sas_vehicle_id' => $carId,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'days' => (int) $start->diff($end)->days + 1,
            'registration' => $this->toStringOrNull($sheet['licensePlate'] ?? null),
            'vehicle_name' => trim((string) ($sheet['makeName'] ?? '') . ' ' . (string) ($sheet['modelName'] ?? '')) ?: null,
            'total_km' => $dayKm,
            'average_speed' => is_numeric($sheet['averageSpeed'] ?? null) ? round((float) $sheet['averageSpeed'], 1) : null,
            'work_seconds' => is_numeric($sheet['workTimeSpanInSeconds'] ?? null) ? (int) $sheet['workTimeSpanInSeconds'] : null,
            'idle_seconds' => is_numeric($sheet['idleTimeSpanInSeconds'] ?? null) ? (int) $sheet['idleTimeSpanInSeconds'] : null,
            'has_can' => !empty($sheet['hasCANInfo']),
            'can_fuel_l' => is_numeric($sheet['CANFuelUsed'] ?? null) ? round((float) $sheet['CANFuelUsed'], 1) : null,
            'can_l100' => $canL100,
            'odometer_km' => $lastKmIndex !== null ? (int) round($lastKmIndex) : null,
            'segments' => $segments,
        ];

        $this->writeJson($cacheFile, ['day' => $day, 'fetched_at_ts' => time()]);

        return $day;
    }

    /**
     * Ataseaza fiecarui vehicul statisticile zilei (km, combustibil CAN) si
     * odometrul (ultimul kmIndex cunoscut, persistat pe disc).
     *
     * Travelsheet-ul e scump (o cerere per vehicul), asa ca per poll se
     * reimprospateaza doar un lot de vehicule (cele in miscare au prioritate,
     * apoi cele mai vechi); restul se servesc din cache. Dashboardul se umple
     * complet in cateva cicluri de refresh.
     *
     * @param array<int, array<string, mixed>> $vehicles
     */
    private function attachDayStats(array &$vehicles): void
    {
        $statsFile = $this->cacheDir . '/sas_dash_day_stats.json';
        $odoFile = $this->cacheDir . '/sas_dash_odometer.json';
        $today = date('Y-m-d');
        $now = time();

        $statsRaw = $this->readJson($statsFile) ?? [];
        $stats = is_array($statsRaw['stats'] ?? null) ? $statsRaw['stats'] : [];
        foreach ($stats as $id => $row) {
            if (!is_array($row) || ($row['date'] ?? '') !== $today) {
                unset($stats[$id]);
            }
        }
        $odometers = $this->readJson($odoFile) ?? [];

        // Candidatii la refresh: fara statistici azi sau cu statistici expirate.
        $candidates = [];
        foreach ($vehicles as $vehicle) {
            $id = (int) ($vehicle['sas_vehicle_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $row = $stats[(string) $id] ?? null;
            $ttl = ($vehicle['status'] ?? '') === 'moving' ? self::DAY_STATS_TTL_MOVING : self::DAY_STATS_TTL_OTHER;
            $age = $row !== null ? $now - (int) ($row['updated_at'] ?? 0) : PHP_INT_MAX;
            if ($age > $ttl) {
                $candidates[] = ['id' => $id, 'age' => $age, 'moving' => ($vehicle['status'] ?? '') === 'moving'];
            }
        }
        usort($candidates, static function (array $a, array $b): int {
            return [$a['moving'] ? 0 : 1, -$a['age']] <=> [$b['moving'] ? 0 : 1, -$b['age']];
        });

        if ($candidates !== []) {
            $this->restoreSasSession();
            $budget = self::DAY_STATS_BATCH;
            foreach ($candidates as $candidate) {
                if ($budget <= 0) {
                    break;
                }
                $id = $candidate['id'];
                try {
                    $sheet = $this->client->getTravelSheet($id, $today . 'T00:00:00', $today . 'T23:59:59', false);
                    $budget--;
                } catch (Throwable $exception) {
                    // Probabil rate-limit SAS; restul vehiculelor la urmatorul poll.
                    error_log('[SasDashboardService][day_stats] carId=' . $id . ': ' . $exception->getMessage());
                    break;
                }

                $lastKmIndex = $this->lastKmIndex($sheet);
                if ($lastKmIndex !== null) {
                    $previous = $odometers[(string) $id]['km'] ?? null;
                    if (!is_numeric($previous) || $lastKmIndex >= (float) $previous) {
                        $odometers[(string) $id] = array_merge(
                            is_array($odometers[(string) $id] ?? null) ? $odometers[(string) $id] : [],
                            ['km' => $lastKmIndex, 'seen_at' => date('c')]
                        );
                    }
                } elseif ($budget > 0
                    && !is_numeric($odometers[(string) $id]['km'] ?? null)
                    && (($odometers[(string) $id]['lookback_at'] ?? '') !== $today)
                ) {
                    // Vehiculul nu a circulat azi si nu avem odometru: o singura
                    // privire in urma pe ultimele 7 zile (limita SAS), o data pe zi.
                    $entry = is_array($odometers[(string) $id] ?? null) ? $odometers[(string) $id] : [];
                    $entry['lookback_at'] = $today;
                    try {
                        $back = $this->client->getTravelSheet(
                            $id,
                            date('Y-m-d', strtotime('-6 days')) . 'T00:00:00',
                            $today . 'T23:59:59',
                            false
                        );
                        $budget--;
                        $backKm = $this->lastKmIndex($back);
                        if ($backKm !== null) {
                            $entry['km'] = $backKm;
                            $entry['seen_at'] = date('c');
                        }
                        $odometers[(string) $id] = $entry;
                    } catch (Throwable $exception) {
                        $odometers[(string) $id] = $entry;
                        error_log('[SasDashboardService][odometer_lookback] carId=' . $id . ': ' . $exception->getMessage());
                        break;
                    }
                }

                $dayKm = is_numeric($sheet['totalDistance'] ?? null) ? round((float) $sheet['totalDistance'], 1) : 0.0;
                $canFuel = is_numeric($sheet['CANFuelUsed'] ?? null) ? round((float) $sheet['CANFuelUsed'], 1) : null;
                $l100 = is_numeric($sheet['CANFuelUsedPer100Km'] ?? null) ? round((float) $sheet['CANFuelUsedPer100Km'], 1) : null;
                // Garda de plauzibilitate: pe distante mici SAS raporteaza consumuri absurde.
                if ($l100 !== null && ($dayKm < self::L100_MIN_KM || $l100 < self::L100_MIN || $l100 > self::L100_MAX)) {
                    $l100 = null;
                }

                $stats[(string) $id] = [
                    'date' => $today,
                    'day_km' => $dayKm,
                    'can_fuel_l' => $canFuel,
                    'can_l100' => $l100,
                    'has_can' => !empty($sheet['hasCANInfo']),
                    'updated_at' => $now,
                ];
            }
            $this->persistSasSession();
        }

        $this->writeJson($statsFile, ['stats' => $stats]);
        $this->writeJson($odoFile, $odometers);

        $photos = $this->getPhotoUrlsByPlate();
        foreach ($vehicles as &$vehicle) {
            $plateKey = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($vehicle['registration'] ?? '')) ?? '');
            $vehicle['photo_url'] = $photos[$plateKey] ?? null;
            $id = (string) (int) ($vehicle['sas_vehicle_id'] ?? 0);
            $row = $stats[$id] ?? null;
            $vehicle['day_km'] = $row['day_km'] ?? null;
            $vehicle['can_fuel_l'] = $row['can_fuel_l'] ?? null;
            $vehicle['can_l100'] = $row['can_l100'] ?? null;
            $vehicle['has_can'] = $row !== null ? (bool) ($row['has_can'] ?? false) : null;
            $odometer = $odometers[$id]['km'] ?? null;
            $vehicle['odometer_km'] = is_numeric($odometer) ? (int) round((float) $odometer) : null;
        }
        unset($vehicle);
    }

    /**
     * Traseul GPS al unui vehicul pe un interval (maxim 7 zile, limita SAS
     * pentru reports/events): punctele pentru polilinia de pe harta.
     */
    public function getRoute(int $carId, ?string $startDate = null, ?string $endDate = null): array
    {
        if ($carId <= 0) {
            throw new InvalidArgumentException('Identificator vehicul invalid.');
        }

        [$start, $end] = $this->validateRange($startDate, $endDate);
        $days = (int) $start->diff($end)->days + 1;
        if ($days > 7) {
            throw new InvalidArgumentException('Traseul pe harta se poate afisa pentru maxim 7 zile (limita SAS reports/events).');
        }

        $includesToday = $end->format('Y-m-d') >= date('Y-m-d');
        // v5: geometria potrivita pe drumuri vine de la Valhalla (nu OSRM).
        $cacheFile = $this->cacheDir . '/sas_dash_route_v5_' . $carId . '_' . $start->format('Ymd') . '_' . $end->format('Ymd') . '.json';
        $cache = $this->readJson($cacheFile);
        $ttl = $includesToday ? self::DAY_TTL_SECONDS : 7 * 86400;
        if ($cache !== null
            && (time() - (int) ($cache['fetched_at_ts'] ?? 0)) < $ttl
            && is_array($cache['route'] ?? null)
        ) {
            return $cache['route'];
        }

        $this->restoreSasSession();
        $events = $this->client->getCarEvents(
            $carId,
            $start->format('Y-m-d') . 'T00:00:00',
            $end->format('Y-m-d') . 'T23:59:59'
        );
        $this->persistSasSession();

        $points = [];
        foreach ($events as $event) {
            $latitude = $event['latitude'] ?? null;
            $longitude = $event['longitude'] ?? null;
            if (!is_numeric($latitude) || !is_numeric($longitude)) {
                continue;
            }
            $points[] = [
                'lat' => round((float) $latitude, 6),
                'lng' => round((float) $longitude, 6),
                'ts' => $this->toStringOrNull($event['date'] ?? null),
                'speed' => is_numeric($event['speed'] ?? null) ? (int) round((float) $event['speed']) : null,
            ];
        }

        // GPS-ul parcat "deriva" zeci de metri si deseneaza un ghem de linii pe
        // langa drum; punctele stationare apropiate de ultimul punct pastrat se elimina.
        $points = $this->filterStationaryNoise($points);

        // Poliliniile foarte lungi se subesantioneaza ca sa ramana fluida harta.
        $maxPoints = 3000;
        if (count($points) > $maxPoints) {
            $step = (int) ceil(count($points) / $maxPoints);
            $points = array_values(array_filter(
                $points,
                static fn (int $index) => $index % $step === 0,
                ARRAY_FILTER_USE_KEY
            ));
        }

        $route = [
            'sas_vehicle_id' => $carId,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'points' => $points,
            // Geometria potrivita pe reteaua de drumuri; null daca OSRM nu e
            // disponibil — frontend-ul revine la linia dreapta intre puncte.
            'matched' => $this->matchRouteToRoads($points),
        ];

        $this->writeJson($cacheFile, ['route' => $route, 'fetched_at_ts' => time()]);

        return $route;
    }

    /**
     * Potriveste punctele GPS pe reteaua de drumuri (map matching) prin OSRM
     * public. Punctele se trimit in loturi (limita serverelor ~100 coordonate),
     * cu un punct de suprapunere intre loturi; raspunsul poate contine mai multe
     * "matchings" (traseul se rupe la pauze lungi), fiecare devenind un segment.
     *
     * @param array<int, array{lat: float, lng: float, ts: ?string, speed: ?int}> $points
     * @return array<int, array<int, array{0: float, 1: float}>>|null segmente [lat, lng] sau null
     */
    private function matchRouteToRoads(array $points): ?array
    {
        if (count($points) < 2 || count($points) > self::VALHALLA_MAX_POINTS || !function_exists('curl_init')) {
            return null;
        }

        $segments = [];
        foreach ($this->splitIntoTrips($points) as $trip) {
            $shape = array_map(static function (array $p): array {
                $entry = ['lat' => $p['lat'], 'lon' => $p['lng']];
                $ts = is_string($p['ts'] ?? null) ? strtotime($p['ts']) : false;
                if ($ts !== false) {
                    $entry['time'] = $ts;
                }
                return $entry;
            }, $trip);

            $payload = json_encode([
                'shape' => $shape,
                'costing' => 'auto',
                'shape_match' => 'map_snap',
            ], JSON_UNESCAPED_SLASHES);
            if (!is_string($payload)) {
                continue;
            }

            $curl = curl_init(self::VALHALLA_TRACE_URL);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => self::VALHALLA_TIMEOUT_SECONDS,
                CURLOPT_TIMEOUT => self::VALHALLA_TIMEOUT_SECONDS,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_USERAGENT => 'aplicatie-fleet-sandbox',
            ]);
            $body = curl_exec($curl);
            curl_close($curl);

            $decoded = is_string($body) ? json_decode($body, true) : null;
            foreach ((array) ($decoded['trip']['legs'] ?? []) as $leg) {
                $shapeEncoded = $leg['shape'] ?? null;
                if (is_string($shapeEncoded) && $shapeEncoded !== '') {
                    $coords = $this->decodePolyline6($shapeEncoded);
                    if (count($coords) >= 2) {
                        $segments[] = $coords;
                    }
                }
            }
        }

        return $segments !== [] ? $segments : null;
    }

    /**
     * Rupe sirul de puncte GPS in "ture" separate la pauzele lungi de raportare
     * (masina stationata), ca matching-ul sa nu inventeze drum intre opriri.
     * Turele dintr-un singur punct se elimina (nu au geometrie).
     *
     * @param array<int, array{lat: float, lng: float, ts: ?string, speed: ?int}> $points
     * @return array<int, array<int, array{lat: float, lng: float, ts: ?string, speed: ?int}>>
     */
    private function splitIntoTrips(array $points): array
    {
        $trips = [];
        $current = [];
        $lastTs = null;
        foreach ($points as $point) {
            $ts = is_string($point['ts'] ?? null) ? strtotime($point['ts']) : false;
            if ($current !== [] && $ts !== false && $lastTs !== null && ($ts - $lastTs) > self::TRIP_GAP_SECONDS) {
                $trips[] = $current;
                $current = [];
            }
            $current[] = $point;
            $lastTs = $ts !== false ? $ts : $lastTs;
        }
        if ($current !== []) {
            $trips[] = $current;
        }

        return array_values(array_filter($trips, static fn (array $trip) => count($trip) >= 2));
    }

    /**
     * Decodor pentru formatul polyline al Valhalla (precizie 1e-6).
     *
     * @return array<int, array{0: float, 1: float}> perechi [lat, lng]
     */
    private function decodePolyline6(string $encoded): array
    {
        $points = [];
        $index = 0;
        $length = strlen($encoded);
        $lat = 0;
        $lng = 0;

        while ($index < $length) {
            foreach (['lat', 'lng'] as $which) {
                $shift = 0;
                $result = 0;
                do {
                    if ($index >= $length) {
                        return $points;
                    }
                    $byte = ord($encoded[$index++]) - 63;
                    $result |= ($byte & 0x1f) << $shift;
                    $shift += 5;
                } while ($byte >= 0x20);
                $delta = ($result & 1) ? ~($result >> 1) : ($result >> 1);
                if ($which === 'lat') {
                    $lat += $delta;
                } else {
                    $lng += $delta;
                }
            }
            $points[] = [round($lat / 1e6, 6), round($lng / 1e6, 6)];
        }

        return $points;
    }

    /**
     * Elimina zgomotul GPS de stationare dintr-un traseu: cand masina sta pe loc
     * (viteza ~0), receptorul GPS "deriva" cateva zeci de metri si genereaza
     * puncte imprastiate pe langa drum. Se pastreaza primul punct al opririi,
     * iar urmatoarele puncte lente aflate la sub ~40m de el se arunca.
     *
     * Nota: coordonatele SAS sunt WGS84 (lat/lng in grade) — exact ce asteapta
     * Leaflet — deci abaterile de la drum NU sunt o problema de proiectie, ci
     * vin din esantionarea rara (~1 punct/minut in mers) si din deriva de mai sus.
     *
     * @param array<int, array{lat: float, lng: float, ts: ?string, speed: ?int}> $points
     * @return array<int, array{lat: float, lng: float, ts: ?string, speed: ?int}>
     */
    private function filterStationaryNoise(array $points): array
    {
        $filtered = [];
        $lastKept = null;
        foreach ($points as $point) {
            if ($lastKept !== null) {
                $speed = (int) ($point['speed'] ?? 0);
                if ($speed <= 3
                    && $this->distanceMeters($lastKept['lat'], $lastKept['lng'], $point['lat'], $point['lng']) < 40.0
                ) {
                    continue;
                }
            }
            $filtered[] = $point;
            $lastKept = $point;
        }

        return $filtered;
    }

    /** Distanta haversine in metri intre doua coordonate WGS84. */
    private function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $earthRadius * asin(min(1.0, sqrt($a)));
    }

    /**
     * Valideaza si normalizeaza un interval de date (implicit: azi).
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function validateRange(?string $startDate, ?string $endDate): array
    {
        $startDate = $startDate !== null && trim($startDate) !== '' ? trim($startDate) : date('Y-m-d');
        $endDate = $endDate !== null && trim($endDate) !== '' ? trim($endDate) : $startDate;

        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
        if (!$start instanceof DateTimeImmutable || $start->format('Y-m-d') !== $startDate
            || !$end instanceof DateTimeImmutable || $end->format('Y-m-d') !== $endDate
        ) {
            throw new InvalidArgumentException('Data invalida (format asteptat: Y-m-d).');
        }
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
        if ((int) $start->diff($end)->days + 1 > 92) {
            throw new InvalidArgumentException('Intervalul maxim este de 92 de zile.');
        }

        return [$start, $end];
    }

    /**
     * Harta numar inmatriculare (normalizat) -> URL poza vehicul din aplicatie.
     *
     * @return array<string, string>
     */
    private function getPhotoUrlsByPlate(): array
    {
        $photos = [];
        if (!function_exists('vehicle_image_url')) {
            return $photos;
        }
        try {
            $statement = $this->db->query(
                "SELECT nr_inmatriculare, poza_stocata FROM vehicule
                 WHERE poza_stocata IS NOT NULL AND poza_stocata <> ''"
            );
            foreach ($statement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
                $url = vehicle_image_url((string) $row['poza_stocata']);
                if ($url !== null) {
                    $key = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $row['nr_inmatriculare']) ?? '');
                    $photos[$key] = $url;
                }
            }
        } catch (Throwable $exception) {
            error_log('[SasDashboardService][photos] ' . $exception->getMessage());
        }

        return $photos;
    }

    /** Ultimul kmIndex (odometru) din segmentele unui travelsheet, daca exista. */
    private function lastKmIndex(array $sheet): ?float
    {
        $last = null;
        foreach ((array) ($sheet['segments'] ?? []) as $segment) {
            if (is_array($segment) && is_numeric($segment['kmIndex'] ?? null)) {
                $last = (float) $segment['kmIndex'];
            }
        }
        return $last;
    }

    /** Clasifica o pozitie normalizata in status de dashboard. */
    private function classify(array $position): array
    {
        $age = $position['age_seconds'] ?? null;
        $speed = (float) ($position['speed'] ?? 0);
        // Din studiul datelor reale: triggerEvent 15 = heartbeat de stationare
        // (masina parcata raporteaza periodic), 6 = pornire motor, 7 = oprire,
        // 0 = pozitie normala in mers. O masina cu trigger 15 e parcata chiar
        // daca heartbeat-ul e recent.
        $trigger = $position['trigger_event'] ?? null;

        if ($age !== null && $speed > 0 && $age <= self::MOVING_MAX_AGE_SECONDS) {
            $status = 'moving';
        } elseif ($age !== null && $age <= self::IDLE_MAX_AGE_SECONDS && $trigger !== 15) {
            $status = 'idle';
        } elseif ($age !== null && $age <= self::PARKED_MAX_AGE_SECONDS) {
            $status = 'parked';
        } else {
            $status = 'offline';
        }

        $position['status'] = $status;
        $position['place'] = $this->placeLabel($position);

        return $position;
    }

    /** Eticheta de locatie: POI daca exista, altfel adresa + oras. */
    private function placeLabel(array $position): ?string
    {
        $poi = $this->toStringOrNull($position['poi'] ?? null);
        if ($poi !== null) {
            return $poi;
        }

        // SAS foloseste "-" pentru oras/adresa necunoscuta.
        $clean = fn (?string $value): ?string => $value !== null && $value !== '-' ? $value : null;
        $parts = array_filter([
            $clean($this->toStringOrNull($position['address'] ?? null)),
            $clean($this->toStringOrNull($position['city'] ?? null)),
        ]);

        return $parts !== [] ? implode(', ', $parts) : $this->toStringOrNull($position['county'] ?? null);
    }

    /** @param array<int, array<string, mixed>> $vehicles */
    private function buildKpis(array $vehicles): array
    {
        $counts = ['moving' => 0, 'idle' => 0, 'parked' => 0, 'offline' => 0];
        $topSpeed = null;
        $inPoi = 0;
        $counties = [];
        $fleetDayKm = 0.0;
        $fleetCanFuel = 0.0;
        $statsCovered = 0;

        foreach ($vehicles as $vehicle) {
            if (is_numeric($vehicle['day_km'] ?? null)) {
                $fleetDayKm += (float) $vehicle['day_km'];
                $statsCovered++;
            }
            if (is_numeric($vehicle['can_fuel_l'] ?? null)) {
                $fleetCanFuel += (float) $vehicle['can_fuel_l'];
            }
            $status = (string) ($vehicle['status'] ?? '');
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
            if (!empty($vehicle['poi'])) {
                $inPoi++;
            }
            $county = $this->toStringOrNull($vehicle['county'] ?? null);
            if ($county !== null && $status !== 'offline') {
                $counties[$county] = true;
            }
            $speed = (float) ($vehicle['speed'] ?? 0);
            if ($status === 'moving' && ($topSpeed === null || $speed > $topSpeed['speed'])) {
                $topSpeed = [
                    'speed' => round($speed),
                    'registration' => (string) ($vehicle['registration'] ?? ''),
                ];
            }
        }

        return [
            'total' => count($vehicles),
            'moving' => $counts['moving'],
            'idle' => $counts['idle'],
            'parked' => $counts['parked'],
            'offline' => $counts['offline'],
            'in_poi' => $inPoi,
            'counties' => count($counties),
            'top_speed' => $topSpeed,
            'fleet_day_km' => round($fleetDayKm),
            'fleet_can_fuel_l' => round($fleetCanFuel),
            'stats_covered' => $statsCovered,
        ];
    }

    /**
     * Compara snapshotul curent cu cel precedent si adauga in feed tranzitiile
     * de miscare. Returneaza feedul complet (cel mai recent primul).
     *
     * @param array<int, array<string, mixed>> $vehicles
     * @return array<int, array<string, mixed>>
     */
    private function updateMovementFeed(array $vehicles): array
    {
        $prevFile = $this->cacheDir . '/sas_dash_prev.json';
        $feedFile = $this->cacheDir . '/sas_dash_feed.json';

        $previous = $this->readJson($prevFile) ?? [];
        $prevStatuses = is_array($previous['statuses'] ?? null) ? $previous['statuses'] : [];
        $feed = $this->readJson($feedFile) ?? [];
        $entries = is_array($feed['entries'] ?? null) ? $feed['entries'] : [];

        $currentStatuses = [];
        foreach ($vehicles as $vehicle) {
            $id = (int) ($vehicle['sas_vehicle_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $status = (string) $vehicle['status'];
            $currentStatuses[(string) $id] = $status;

            $prev = $prevStatuses[(string) $id] ?? null;
            if ($prev === null || $prev === $status) {
                continue;
            }

            $event = $this->transitionEvent((string) $prev, $status);
            if ($event === null) {
                continue;
            }

            array_unshift($entries, [
                'type' => $event,
                'sas_vehicle_id' => $id,
                'registration' => (string) ($vehicle['registration'] ?? ''),
                'driver' => $this->toStringOrNull($vehicle['driver'] ?? null),
                'place' => $vehicle['place'] ?? null,
                'speed' => is_numeric($vehicle['speed'] ?? null) ? round((float) $vehicle['speed']) : null,
                'at' => date('c'),
            ]);
        }

        // Snapshotul precedent se suprascrie doar cand avem date proaspete,
        // altfel un raspuns din cache ar "uita" tranzitiile.
        if ($currentStatuses !== []) {
            $this->writeJson($prevFile, ['statuses' => $currentStatuses, 'saved_at' => time()]);
        }

        $entries = array_slice($entries, 0, self::FEED_MAX_ENTRIES);
        $this->writeJson($feedFile, ['entries' => $entries]);

        return $entries;
    }

    /** Mapeaza o tranzitie de status intr-un tip de eveniment pentru feed. */
    private function transitionEvent(string $from, string $to): ?string
    {
        if ($to === 'moving') {
            return $from === 'offline' ? 'back_online' : 'started_moving';
        }
        if ($from === 'moving' && ($to === 'idle' || $to === 'parked')) {
            return 'stopped';
        }
        if ($to === 'offline') {
            return 'went_offline';
        }
        if ($from === 'offline') {
            return 'back_online';
        }

        // idle <-> parked este doar imbatranirea raportului, nu un eveniment real.
        return null;
    }

    private function segmentPlace(array $segment, string $suffix): ?string
    {
        $poi = $this->toStringOrNull($segment['locationDescription' . $suffix] ?? null);
        if ($poi !== null) {
            return $poi;
        }

        $city = $this->toStringOrNull($segment['city' . $suffix] ?? null);
        $city = $city !== null && $city !== '-' ? $city : null;
        $address = $this->toStringOrNull($segment['address' . $suffix] ?? null);
        $parts = array_filter([$address, $city]);

        if ($parts !== []) {
            return implode(', ', $parts);
        }

        $county = $this->toStringOrNull($segment['county' . $suffix] ?? null);
        return $county;
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

    private function toStringOrNull(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
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
