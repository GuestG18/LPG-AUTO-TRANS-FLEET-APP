<?php
declare(strict_types=1);

/**
 * Studiu read-only: descopera rutele facute frecvent de flota, pornind de la
 * foaia de parcurs SAS (reports/travelsheet) pe un interval istoric.
 *
 * Ideea: segmentele SAS sunt prea marunte (fiecare pornire/oprire de motor este
 * un segment). Le lipim intr-un "leg" = deplasare intre doua stationari reale
 * (parcare peste pragul --stop-min), apoi numaram perechile plecare -> sosire.
 *
 * Raspunsurile brute se pun in cache pe disc, deci rulari repetate nu mai lovesc
 * API-ul. Scriptul NU scrie nimic in baza de date.
 *
 * Utilizare:
 *   php scripts/study_sas_frequent_routes.php
 *   php scripts/study_sas_frequent_routes.php --days=90 --top=40
 *   php scripts/study_sas_frequent_routes.php --from=2026-06-01 --to=2026-08-27
 *   php scripts/study_sas_frequent_routes.php --plate=B655NET --granularity=poi
 *   php scripts/study_sas_frequent_routes.php --dump-raw       - cheile brute ale unui segment
 *   php scripts/study_sas_frequent_routes.php --pois           - POI-urile definite in SAS
 *   php scripts/study_sas_frequent_routes.php --json=storage/cache/rute_frecvente.json
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/htdocs/config/config.php';
require_once $projectRoot . '/htdocs/services/SasFleetClient.php';

function arg(string $name, array $argv, ?string $default = null): ?string
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $a) {
        if (str_starts_with((string) $a, $prefix)) {
            return substr((string) $a, strlen($prefix));
        }
    }
    return $default;
}

function flag(string $name, array $argv): bool
{
    return in_array('--' . $name, array_map('strval', $argv), true);
}

function out(string $line = ''): void
{
    fwrite(STDOUT, $line . "\n");
}

function strOrNull(mixed $v): ?string
{
    if (!is_string($v)) {
        return null;
    }
    $v = trim($v);
    return ($v === '' || $v === '-') ? null : $v;
}

/**
 * Numele locului pentru un capat de segment. Pentru granularitatea "city"
 * mergem direct la oras, ca sa nu spargem acelasi oras in zeci de chei
 * (strazi diferite). Pentru "poi" pastram numele locatiei definite in SAS.
 *
 * Atentie: cityStart/cityEnd vine "-" pe drumurile din afara localitatilor,
 * deci pentru granularitatea "geo" numele e doar eticheta, nu cheia de grupare.
 */
function placeName(array $segment, string $suffix, string $granularity): string
{
    $poi = strOrNull($segment['locationDescription' . $suffix] ?? null);
    $city = strOrNull($segment['city' . $suffix] ?? null);
    $county = strOrNull($segment['county' . $suffix] ?? null);
    $address = strOrNull($segment['address' . $suffix] ?? null);

    if ($granularity === 'poi' && $poi !== null) {
        return $poi;
    }
    if ($city !== null) {
        return $city;
    }
    if ($poi !== null) {
        return $poi;
    }
    if ($address !== null && $county !== null) {
        return $address . ' (' . preg_replace('/\s*\(RO\)$/', '', $county) . ')';
    }
    if ($county !== null) {
        return 'jud. ' . preg_replace('/\s*\(RO\)$/', '', $county);
    }
    if ($address !== null) {
        return $address;
    }
    return 'necunoscut';
}

function coordOrNull(array $segment, string $key): ?float
{
    $v = $segment[$key] ?? null;
    return is_numeric($v) ? (float) $v : null;
}

/** Distanta in km intre doua perechi lat/lon (haversine). */
function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * Grupeaza punctele de capat in zone geografice: doua opriri la sub $radiusKm
 * una de alta sunt acelasi loc. Fara asta, aceeasi hala descarcata de 20 de ori
 * apare ca 20 de "rute" diferite, pentru ca adresa inversa difera de la o zi la alta.
 *
 * @param array<int, array{lat: float, lon: float, label: string}> $points
 * @return array<int, array{lat: float, lon: float, label: string, count: int, labels: array<string, int>}>
 */
function clusterPoints(array $points, float $radiusKm): array
{
    $clusters = [];
    foreach ($points as $i => $point) {
        $best = null;
        $bestDist = $radiusKm;
        foreach ($clusters as $ci => $cluster) {
            $d = distanceKm($cluster['lat'], $cluster['lon'], $point['lat'], $point['lon']);
            if ($d <= $bestDist) {
                $best = $ci;
                $bestDist = $d;
            }
        }
        if ($best === null) {
            $clusters[] = [
                'lat' => $point['lat'],
                'lon' => $point['lon'],
                'label' => $point['label'],
                'count' => 1,
                'labels' => [$point['label'] => 1],
                'members' => [$i],
            ];
            continue;
        }
        // Centroid incremental, ca zona sa se aseze pe media opririlor reale.
        $n = $clusters[$best]['count'];
        $clusters[$best]['lat'] = ($clusters[$best]['lat'] * $n + $point['lat']) / ($n + 1);
        $clusters[$best]['lon'] = ($clusters[$best]['lon'] * $n + $point['lon']) / ($n + 1);
        $clusters[$best]['count'] = $n + 1;
        $clusters[$best]['labels'][$point['label']] = ($clusters[$best]['labels'][$point['label']] ?? 0) + 1;
        $clusters[$best]['members'][] = $i;
    }

    // Numele zonei = eticheta cea mai frecventa, ignorand "necunoscut" daca se poate.
    foreach ($clusters as $ci => $cluster) {
        $labels = $cluster['labels'];
        arsort($labels);
        $name = array_key_first($labels);
        if ($name === 'necunoscut' && count($labels) > 1) {
            unset($labels['necunoscut']);
            $name = array_key_first($labels);
        }
        $clusters[$ci]['label'] = (string) $name;
    }

    return $clusters;
}

function hoursLabel(float $seconds): string
{
    $h = (int) floor($seconds / 3600);
    $m = (int) floor(($seconds - $h * 3600) / 60);
    return $h . 'h' . str_pad((string) $m, 2, '0', STR_PAD_LEFT);
}

function median(array $values): float
{
    if ($values === []) {
        return 0.0;
    }
    sort($values);
    $n = count($values);
    $mid = intdiv($n, 2);
    return $n % 2 ? (float) $values[$mid] : (($values[$mid - 1] + $values[$mid]) / 2);
}

// ---------------------------------------------------------------- parametri
$today = date('Y-m-d');
$days = max(1, (int) (arg('days', $argv, '60') ?? '60'));
$from = arg('from', $argv) ?? date('Y-m-d', strtotime('-' . $days . ' days'));
$to = arg('to', $argv) ?? $today;
$plateFilter = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) (arg('plate', $argv) ?? '')));
$minKm = (float) (arg('min-km', $argv, '25') ?? '25');
$stopMinutes = (int) (arg('stop-min', $argv, '45') ?? '45');
$top = (int) (arg('top', $argv, '30') ?? '30');
$granularity = (string) arg('granularity', $argv, 'geo');
if (!in_array($granularity, ['geo', 'city', 'poi'], true)) {
    $granularity = 'geo';
}
$radiusKm = (float) (arg('radius-km', $argv, '3') ?? '3');
$maxCars = (int) (arg('max-cars', $argv, '0') ?? '0');
$refresh = flag('refresh', $argv);
$dumpRaw = flag('dump-raw', $argv);
$showPois = flag('pois', $argv);
$jsonOut = arg('json', $argv);
$legsOut = arg('legs-json', $argv);

$cacheDir = $projectRoot . '/storage/cache/sas_routes';
if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
    out('[EROARE] Nu pot crea ' . $cacheDir);
    exit(1);
}

$client = new SasFleetClient();
if (!$client->credentialsAvailable()) {
    out('[EROARE] Credentiale SAS lipsa in .env.');
    exit(1);
}

$sessionFile = $projectRoot . '/storage/cache/sas_session.json';
if (is_file($sessionFile)) {
    $state = json_decode((string) file_get_contents($sessionFile), true);
    if (is_array($state)) {
        $client->restoreState($state);
    }
}
if (!$client->isAuthenticated()) {
    $client->login();
    @file_put_contents($sessionFile, (string) json_encode($client->exportState()));
}

// ------------------------------------------------------------------- POI-uri
if ($showPois) {
    $info = $client->getCompanyInfo();
    $company = (string) ($info['name'] ?? $info['Name'] ?? '');
    out('=== POI-uri definite in SAS (companie: ' . ($company !== '' ? $company : '?') . ') ===');
    try {
        $pois = $client->findPois($company);
        out('Total POI: ' . count($pois));
        foreach ($pois as $poi) {
            out(sprintf(
                '  %-40s lat %s..%s  lon %s..%s',
                (string) ($poi['name'] ?? '?'),
                (string) ($poi['latitudeMinInDegrees'] ?? '?'),
                (string) ($poi['latitudeMaxInDegrees'] ?? '?'),
                (string) ($poi['longitudeMinInDegrees'] ?? '?'),
                (string) ($poi['longitudeMaxInDegrees'] ?? '?')
            ));
        }
    } catch (Throwable $e) {
        out('  EROARE pois/find: ' . $e->getMessage());
    }
    out();
}

// -------------------------------------------------------------------- masini
$cars = array_values(array_filter(
    $client->getCars(),
    static fn (array $c) => empty($c['disabled'])
));
if ($plateFilter !== '') {
    $cars = array_values(array_filter($cars, static function (array $c) use ($plateFilter): bool {
        $p = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($c['licensePlate'] ?? '')));
        return $p === $plateFilter;
    }));
}
if ($maxCars > 0) {
    $cars = array_slice($cars, 0, $maxCars);
}
if ($cars === []) {
    out('[EROARE] Nicio masina activa dupa filtre.');
    exit(1);
}

out('Interval: ' . $from . ' -> ' . $to . '  |  masini: ' . count($cars)
    . '  |  granularitate: ' . $granularity . ($granularity === 'geo' ? ' (raza ' . $radiusKm . ' km)' : '')
    . '  |  prag stationare: ' . $stopMinutes . ' min  |  km minim/leg: ' . $minKm);
out();

// Intervalele mari se taie in felii de 30 de zile: raspunsul travelsheet creste
// liniar cu perioada si API-ul raspunde mai prost pe intervale lungi.
$chunks = [];
$cursor = strtotime($from);
$endTs = strtotime($to);
while ($cursor <= $endTs) {
    $chunkEnd = min($endTs, strtotime('+29 days', $cursor));
    $chunks[] = [date('Y-m-d', $cursor), date('Y-m-d', $chunkEnd)];
    $cursor = strtotime('+1 day', $chunkEnd);
}

$legs = [];
$rawDumped = false;
$apiCalls = 0;
$cacheHits = 0;

foreach ($cars as $car) {
    $carId = (int) ($car['carId'] ?? 0);
    $plate = (string) ($car['licensePlate'] ?? ('car' . $carId));
    if ($carId <= 0) {
        continue;
    }

    $segments = [];
    foreach ($chunks as $chunk) {
        [$cStart, $cEnd] = $chunk;
        $cacheFile = $cacheDir . '/' . $carId . '_' . str_replace('-', '', $cStart)
            . '_' . str_replace('-', '', $cEnd) . '.json';
        $sheet = null;

        if (!$refresh && is_file($cacheFile)) {
            $decoded = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($decoded)) {
                $sheet = $decoded;
                $cacheHits++;
            }
        }

        if ($sheet === null) {
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                try {
                    $sheet = $client->getTravelSheet($carId, $cStart . 'T00:00:00', $cEnd . 'T23:59:59');
                    $apiCalls++;
                    @file_put_contents($cacheFile, (string) json_encode($sheet, JSON_UNESCAPED_UNICODE));
                    break;
                } catch (Throwable $e) {
                    if ($attempt === 3) {
                        out('  ! ' . $plate . ' ' . $cStart . '..' . $cEnd . ': ' . $e->getMessage());
                        $sheet = null;
                        break;
                    }
                    sleep(3 * $attempt);
                }
            }
            // Throttle: SAS raspunde cu erori daca il lovim in rafala.
            usleep(700000);
        }

        foreach ((array) ($sheet['segments'] ?? []) as $segment) {
            if (is_array($segment)) {
                $segments[] = $segment;
            }
        }
    }

    if ($segments === []) {
        continue;
    }

    if ($dumpRaw && !$rawDumped) {
        out('=== Chei brute ale unui segment travelsheet (' . $plate . ') ===');
        out((string) json_encode($segments[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        out();
        $rawDumped = true;
    }

    usort($segments, static fn (array $a, array $b) => strcmp((string) ($a['dateStart'] ?? ''), (string) ($b['dateStart'] ?? '')));

    // Lipim segmentele intr-un leg pana la prima parcare peste prag.
    $stopThreshold = $stopMinutes * 60;
    $open = null;

    foreach ($segments as $segment) {
        $parkBefore = is_numeric($segment['parkTimeSeconds'] ?? null) ? (int) $segment['parkTimeSeconds'] : 0;
        $km = is_numeric($segment['distance'] ?? null) ? (float) $segment['distance'] : 0.0;
        $segStart = strtotime((string) ($segment['dateStart'] ?? '')) ?: null;
        $segEnd = strtotime((string) ($segment['dateEnd'] ?? '')) ?: null;

        if ($open !== null && $parkBefore >= $stopThreshold) {
            if ($open['km'] >= $minKm) {
                $legs[] = $open + ['plate' => $plate, 'car_id' => $carId];
            }
            $open = null;
        }

        if ($open === null) {
            $open = [
                'from' => placeName($segment, 'Start', $granularity),
                'to' => placeName($segment, 'End', $granularity),
                'from_lat' => coordOrNull($segment, 'latitudeStart'),
                'from_lon' => coordOrNull($segment, 'longitudeStart'),
                'to_lat' => coordOrNull($segment, 'latitudeEnd'),
                'to_lon' => coordOrNull($segment, 'longitudeEnd'),
                'km' => 0.0,
                'start_ts' => $segStart,
                'end_ts' => $segEnd,
            ];
        }

        $open['km'] += $km;
        $open['to'] = placeName($segment, 'End', $granularity);
        $open['to_lat'] = coordOrNull($segment, 'latitudeEnd') ?? $open['to_lat'];
        $open['to_lon'] = coordOrNull($segment, 'longitudeEnd') ?? $open['to_lon'];
        if ($segEnd !== null) {
            $open['end_ts'] = $segEnd;
        }
    }
    if ($open !== null && $open['km'] >= $minKm) {
        $legs[] = $open + ['plate' => $plate, 'car_id' => $carId];
    }
}

out('Cereri API: ' . $apiCalls . '  |  din cache: ' . $cacheHits . '  |  deplasari valide: ' . count($legs));
out();

if ($legs === []) {
    out('Nicio deplasare peste ' . $minKm . ' km in interval. Incearca --min-km=5 sau alt interval.');
    exit(0);
}

// ------------------------------------------- grupare geografica a capetelor
$zones = [];
if ($granularity === 'geo') {
    $points = [];
    $refs = [];
    foreach ($legs as $i => $leg) {
        foreach (['from', 'to'] as $end) {
            if ($leg[$end . '_lat'] === null || $leg[$end . '_lon'] === null) {
                continue;
            }
            $points[] = [
                'lat' => $leg[$end . '_lat'],
                'lon' => $leg[$end . '_lon'],
                'label' => $leg[$end],
            ];
            $refs[] = [$i, $end];
        }
    }

    $zones = clusterPoints($points, $radiusKm);
    foreach ($zones as $zi => $zone) {
        foreach ($zone['members'] as $pointIndex) {
            [$legIndex, $end] = $refs[$pointIndex];
            // Numele afisat include indexul zonei, ca sa distingem doua hale
            // din acelasi oras care primesc aceeasi eticheta inversa.
            $legs[$legIndex][$end] = $zone['label'] . ' #Z' . ($zi + 1);
            $legs[$legIndex][$end . '_zone'] = $zi;
        }
    }
    out('Zone geografice distincte (raza ' . $radiusKm . ' km): ' . count($zones));
    out();
}

// ------------------------------------------------------------- agregare rute
$routes = [];
foreach ($legs as $leg) {
    $key = $leg['from'] . ' -> ' . $leg['to'];
    if (!isset($routes[$key])) {
        $routes[$key] = [
            'from' => $leg['from'],
            'to' => $leg['to'],
            'from_lat' => $leg['from_lat'],
            'from_lon' => $leg['from_lon'],
            'to_lat' => $leg['to_lat'],
            'to_lon' => $leg['to_lon'],
            'count' => 0,
            'km' => [],
            'durations' => [],
            'plates' => [],
            'dates' => [],
            'months' => [],
        ];
    }
    $routes[$key]['count']++;
    $routes[$key]['km'][] = round($leg['km'], 1);
    if ($leg['start_ts'] && $leg['end_ts'] && $leg['end_ts'] > $leg['start_ts']) {
        $routes[$key]['durations'][] = $leg['end_ts'] - $leg['start_ts'];
    }
    $routes[$key]['plates'][$leg['plate']] = true;
    if ($leg['start_ts']) {
        $routes[$key]['dates'][] = date('Y-m-d', $leg['start_ts']);
        $monthKey = date('Y-m', $leg['start_ts']);
        $routes[$key]['months'][$monthKey] = ($routes[$key]['months'][$monthKey] ?? 0) + 1;
    }
}

uasort($routes, static fn (array $a, array $b) => $b['count'] <=> $a['count']);

$totalLegs = count($legs);
$totalKm = array_sum(array_map(static fn (array $l) => $l['km'], $legs));

out('=== TOP ' . $top . ' rute (plecare -> sosire), ' . $totalLegs . ' deplasari, '
    . number_format($totalKm, 0, ',', '.') . ' km total ===');
out(sprintf('%-4s %-52s %5s %8s %8s %7s %5s  %s', '#', 'RUTA', 'NR', 'KM MED', 'KM MEDN', 'DURATA', 'VEH', 'ULTIMA'));
$rank = 0;
foreach ($routes as $route) {
    if (++$rank > $top) {
        break;
    }
    $dates = $route['dates'];
    sort($dates);
    out(sprintf(
        '%-4d %-52s %5d %8.1f %8.1f %7s %5d  %s',
        $rank,
        mb_strimwidth($route['from'] . ' -> ' . $route['to'], 0, 52, '..'),
        $route['count'],
        array_sum($route['km']) / max(1, count($route['km'])),
        median($route['km']),
        $route['durations'] !== [] ? hoursLabel(median($route['durations'])) : '-',
        count($route['plates']),
        $dates !== [] ? end($dates) : '-'
    ));
}
out();

// ------------------------------------------------------------- sezonalitate
// Lista lunilor din interval, ca sa avem coloane fixe chiar si pentru lunile goale.
$monthsList = [];
$mCursor = date('Y-m-01', (int) strtotime($from));
$mLast = date('Y-m-01', (int) strtotime($to));
while ($mCursor <= $mLast) {
    $monthsList[] = date('Y-m', (int) strtotime($mCursor));
    $mCursor = date('Y-m-01', (int) strtotime('+1 month', (int) strtotime($mCursor)));
}

if (count($monthsList) >= 3) {
    $monthly = [];
    foreach ($monthsList as $m) {
        $monthly[$m] = ['legs' => 0, 'km' => 0.0, 'plates' => [], 'zones' => []];
    }
    foreach ($legs as $leg) {
        if (!$leg['start_ts']) {
            continue;
        }
        $m = date('Y-m', $leg['start_ts']);
        if (!isset($monthly[$m])) {
            continue;
        }
        $monthly[$m]['legs']++;
        $monthly[$m]['km'] += $leg['km'];
        $monthly[$m]['plates'][$leg['plate']] = true;
        $monthly[$m]['zones'][$leg['from']] = true;
        $monthly[$m]['zones'][$leg['to']] = true;
    }

    out('=== ACTIVITATE LUNARA ===');
    out(sprintf('%-9s %8s %12s %10s %8s %8s', 'LUNA', 'CURSE', 'KM', 'KM/CURSA', 'VEH', 'ZONE'));
    foreach ($monthly as $m => $row) {
        out(sprintf(
            '%-9s %8d %12s %10.1f %8d %8d',
            $m,
            $row['legs'],
            number_format($row['km'], 0, ',', '.'),
            $row['legs'] > 0 ? $row['km'] / $row['legs'] : 0,
            count($row['plates']),
            count($row['zones'])
        ));
    }
    out();

    // Profil lunar per ruta: o cifra pe luna (9+ se plafoneaza), '.' = luna fara curse.
    out('=== PROFIL LUNAR AL RUTELOR (o cifra = curse in luna, . = zero) ===');
    out('Luni, in ordine: ' . implode(' ', $monthsList));
    out();
    out(sprintf('%-46s %5s  %s', 'RUTA', 'TOTAL', 'PROFIL'));
    $rank = 0;
    foreach ($routes as $route) {
        if (++$rank > $top) {
            break;
        }
        $profile = '';
        foreach ($monthsList as $m) {
            $c = $route['months'][$m] ?? 0;
            $profile .= $c === 0 ? '.' : (string) min(9, $c);
        }
        out(sprintf(
            '%-46s %5d  %s',
            mb_strimwidth($route['from'] . ' -> ' . $route['to'], 0, 46, '..'),
            $route['count'],
            $profile
        ));
    }
    out();

    // Rute sezoniere: cele la care o fereastra de 3 luni consecutive strange
    // majoritatea curselor. Acelea sunt cele care cer decizii de flota pe sezon.
    // Sub 6 luni de istoric orice ruta pare "sezoniera", deci nu are sens sa masuram.
    $seasonal = [];
    if (count($monthsList) < 6) {
        out('=== RUTE SEZONIERE ===');
        out('Interval prea scurt (' . count($monthsList) . ' luni). Sezonalitatea cere minim 6 luni.');
        out();
    } else {
    foreach ($routes as $key => $route) {
        if ($route['count'] < 6) {
            continue;
        }
        $best = 0;
        $bestWindow = '';
        $n = count($monthsList);
        for ($i = 0; $i + 2 < $n; $i++) {
            $sum = 0;
            for ($j = $i; $j <= $i + 2; $j++) {
                $sum += $route['months'][$monthsList[$j]] ?? 0;
            }
            if ($sum > $best) {
                $best = $sum;
                $bestWindow = $monthsList[$i] . '..' . $monthsList[$i + 2];
            }
        }
        $share = $route['count'] > 0 ? $best / $route['count'] : 0.0;
        if ($share >= 0.7) {
            $seasonal[$key] = [
                'route' => $route,
                'share' => $share,
                'window' => $bestWindow,
            ];
        }
    }
    uasort($seasonal, static fn (array $a, array $b) => $b['route']['count'] <=> $a['route']['count']);

    out('=== RUTE SEZONIERE (>=70% din curse intr-o fereastra de 3 luni, min 6 curse) ===');
    if ($seasonal === []) {
        out('Nicio ruta cu concentrare sezoniera clara - traficul pare uniform pe an.');
    } else {
        out(sprintf('%-46s %5s %7s  %s', 'RUTA', 'TOTAL', 'CONCENTR', 'FEREASTRA'));
        $rank = 0;
        foreach ($seasonal as $entry) {
            if (++$rank > 25) {
                break;
            }
            out(sprintf(
                '%-46s %5d %6.0f%%  %s',
                mb_strimwidth($entry['route']['from'] . ' -> ' . $entry['route']['to'], 0, 46, '..'),
                $entry['route']['count'],
                $entry['share'] * 100,
                $entry['window']
            ));
        }
    }
    out();
    }

    // Top rute pe anotimp, ca sa vedem ce se schimba efectiv de la sezon la sezon.
    $seasonOf = static function (string $month): string {
        $m = (int) substr($month, 5, 2);
        if (in_array($m, [12, 1, 2], true)) {
            return 'IARNA (dec-feb)';
        }
        if (in_array($m, [3, 4, 5], true)) {
            return 'PRIMAVARA (mar-mai)';
        }
        if (in_array($m, [6, 7, 8], true)) {
            return 'VARA (iun-aug)';
        }
        return 'TOAMNA (sep-nov)';
    };

    $bySeason = [];
    foreach ($routes as $key => $route) {
        foreach ($route['months'] as $m => $count) {
            $s = $seasonOf((string) $m);
            $bySeason[$s][$key] = ($bySeason[$s][$key] ?? 0) + $count;
        }
    }
    out('=== TOP 8 RUTE PE ANOTIMP ===');
    foreach (['IARNA (dec-feb)', 'PRIMAVARA (mar-mai)', 'VARA (iun-aug)', 'TOAMNA (sep-nov)'] as $season) {
        if (!isset($bySeason[$season])) {
            continue;
        }
        $list = $bySeason[$season];
        arsort($list);
        out('-- ' . $season . ' (total curse: ' . array_sum($list) . ')');
        $rank = 0;
        foreach ($list as $key => $count) {
            if (++$rank > 8) {
                break;
            }
            out(sprintf('   %-52s %5d', mb_strimwidth((string) $key, 0, 52, '..'), $count));
        }
        out();
    }
}

// --------------------------------------------------- relatii bidirectionale
$pairs = [];
foreach ($routes as $route) {
    if ($route['from'] === $route['to']) {
        continue;
    }
    $ends = [$route['from'], $route['to']];
    sort($ends);
    $key = $ends[0] . ' <-> ' . $ends[1];
    $pairs[$key] = ($pairs[$key] ?? 0) + $route['count'];
}
arsort($pairs);
out('=== TOP 15 relatii dus-intors (ambele sensuri la un loc) ===');
$rank = 0;
foreach ($pairs as $key => $count) {
    if (++$rank > 15) {
        break;
    }
    out(sprintf('%-4d %-60s %5d', $rank, mb_strimwidth((string) $key, 0, 60, '..'), $count));
}
out();

// ------------------------------------------------------------- top locatii
$places = [];
foreach ($legs as $leg) {
    $places[$leg['from']] = ($places[$leg['from']] ?? 0) + 1;
    $places[$leg['to']] = ($places[$leg['to']] ?? 0) + 1;
}
arsort($places);
out('=== TOP 20 locatii atinse (plecari + sosiri) ===');
$rank = 0;
foreach ($places as $place => $count) {
    if (++$rank > 20) {
        break;
    }
    out(sprintf('%-4d %-50s %5d', $rank, mb_strimwidth((string) $place, 0, 50, '..'), $count));
}
out();

// ------------------------------------------------------------------- export
// Lista bruta a deplasarilor individuale, cu ora de start/stop: de aici pleaca
// analizele care au nevoie de o cerere SAS pe fiecare cursa in parte
// (ex. consumul CAN pe ruta, in study_sas_route_fuel.php).
if ($legsOut !== null) {
    $path = str_starts_with($legsOut, '/') || preg_match('/^[A-Za-z]:/', $legsOut)
        ? $legsOut
        : $projectRoot . '/' . ltrim($legsOut, '/');
    $rows = [];
    foreach ($legs as $leg) {
        if (!$leg['start_ts'] || !$leg['end_ts']) {
            continue;
        }
        $rows[] = [
            'car_id' => $leg['car_id'],
            'plate' => $leg['plate'],
            'from' => $leg['from'],
            'to' => $leg['to'],
            'start' => date('Y-m-d\TH:i:s', $leg['start_ts']),
            'end' => date('Y-m-d\TH:i:s', $leg['end_ts']),
            'km' => round($leg['km'], 1),
            'from_lat' => $leg['from_lat'],
            'from_lon' => $leg['from_lon'],
            'to_lat' => $leg['to_lat'],
            'to_lon' => $leg['to_lon'],
        ];
    }
    usort($rows, static fn (array $a, array $b) => strcmp($a['start'], $b['start']));
    @file_put_contents($path, (string) json_encode([
        'generated_at' => date('c'),
        'from' => $from,
        'to' => $to,
        'granularity' => $granularity,
        'legs' => $rows,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    out('Export deplasari individuale: ' . $path . ' (' . count($rows) . ' curse)');
}

if ($jsonOut !== null) {
    $path = str_starts_with($jsonOut, '/') || preg_match('/^[A-Za-z]:/', $jsonOut)
        ? $jsonOut
        : $projectRoot . '/' . ltrim($jsonOut, '/');
    $payload = [
        'generated_at' => date('c'),
        'from' => $from,
        'to' => $to,
        'granularity' => $granularity,
        'stop_minutes' => $stopMinutes,
        'min_km' => $minKm,
        'total_legs' => $totalLegs,
        'routes' => array_values(array_map(static function (array $r): array {
            $dates = $r['dates'];
            sort($dates);
            return [
                'from' => $r['from'],
                'to' => $r['to'],
                'from_lat' => $r['from_lat'],
                'from_lon' => $r['from_lon'],
                'to_lat' => $r['to_lat'],
                'to_lon' => $r['to_lon'],
                'count' => $r['count'],
                'km_avg' => round(array_sum($r['km']) / max(1, count($r['km'])), 1),
                'km_median' => round(median($r['km']), 1),
                'duration_median_seconds' => $r['durations'] !== [] ? (int) median($r['durations']) : null,
                'vehicles' => array_keys($r['plates']),
                'months' => $r['months'],
                'first_seen' => $dates[0] ?? null,
                'last_seen' => $dates !== [] ? end($dates) : null,
            ];
        }, $routes)),
    ];
    @file_put_contents($path, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    out('Export JSON: ' . $path);
}
