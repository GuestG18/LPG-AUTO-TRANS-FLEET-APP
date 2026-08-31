<?php
declare(strict_types=1);

/**
 * Studiu read-only: km inregistrati de GPS vs km pe care ii da harta, pe aceeasi ruta.
 *
 * De ce nu se compara GPS cu CAN: SAS nu expune odometru CAN independent -
 * campul kmIndex este exact suma distantelor GPS (abatere 0.0000% pe 2,1 mil. km).
 * A treia masuratoare utila nu vine deci de la masina, ci de la harta:
 *
 *   1. KM GPS      - suma distantelor dintre pozitiile raportate de tracker.
 *                    Subestimeaza pe drum sinuos (linii drepte intre puncte) si
 *                    supraestimeaza cand aparatul "deriva" in stationare.
 *   2. KM RUTAT    - cat da Valhalla pe drumuri reale intre plecare si sosire,
 *                    cu profil de camion. Asta e "ce arata harta".
 *   3. DIFERENTA   - GPS peste rutat = ocol sau opriri in plus; GPS sub rutat =
 *                    tracker care a pierdut bucati de drum.
 *
 * Foloseste aceeasi instanta publica Valhalla ca harta din dashboard (FOSSGIS).
 * Rezultatele se pun in cache dupa coordonate rotunjite la ~100 m, deci cursele
 * care pleaca si ajung in acelasi loc costa o singura cerere.
 *
 * Necesita intai:
 *   php scripts/study_sas_frequent_routes.php --from=... --to=... --legs-json=storage/cache/curse_individuale.json
 *
 * Utilizare:
 *   php scripts/study_sas_km_vs_harta.php --from-match="Lugoj #Z2" --to-match="DC (Prahova) #Z3"
 *   php scripts/study_sas_km_vs_harta.php --from-match=X --to-match=Y --csv=storage/cache/km_harta.csv
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);

const VALHALLA_ROUTE_URL = 'https://valhalla1.openstreetmap.de/route';
const VALHALLA_TIMEOUT = 20;

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

function out(string $line = ''): void
{
    fwrite(STDOUT, $line . "\n");
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

/**
 * Distanta pe drumuri intre doua puncte, profil camion. Null daca Valhalla
 * nu raspunde sau nu gaseste drum (punct in mijlocul campului, tara fara date).
 */
function routedKm(float $fromLat, float $fromLon, float $toLat, float $toLon, string $cacheDir, int &$apiCalls): ?float
{
    $key = sprintf('%.3f_%.3f_%.3f_%.3f', $fromLat, $fromLon, $toLat, $toLon);
    $cacheFile = $cacheDir . '/' . str_replace(['.', '-'], ['p', 'm'], $key) . '.json';

    if (is_file($cacheFile)) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return is_numeric($cached['km'] ?? null) ? (float) $cached['km'] : null;
        }
    }

    $payload = json_encode([
        'locations' => [
            ['lat' => $fromLat, 'lon' => $fromLon],
            ['lat' => $toLat, 'lon' => $toLon],
        ],
        'costing' => 'truck',
        'units' => 'kilometers',
        'directions_options' => ['language' => 'ro-RO'],
    ], JSON_UNESCAPED_SLASHES);

    $curl = curl_init(VALHALLA_ROUTE_URL);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => VALHALLA_TIMEOUT,
        CURLOPT_TIMEOUT => VALHALLA_TIMEOUT,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_USERAGENT => 'aplicatie-fleet-studiu-km',
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $apiCalls++;

    $km = null;
    if ($status === 200 && is_string($body)) {
        $decoded = json_decode($body, true);
        $length = $decoded['trip']['summary']['length'] ?? null;
        if (is_numeric($length)) {
            $km = (float) $length;
        }
    }

    @file_put_contents($cacheFile, (string) json_encode(['km' => $km, 'status' => $status]));
    // Instanta publica FOSSGIS - o cerere pe secunda, ca sa nu abuzam.
    sleep(1);

    return $km;
}

$legsFile = arg('legs', $argv, $projectRoot . '/storage/cache/curse_individuale.json');
$fromMatch = (string) (arg('from-match', $argv) ?? '');
$toMatch = (string) (arg('to-match', $argv) ?? '');
$minKm = (float) (arg('min-km', $argv, '50') ?? '50');
$limit = (int) (arg('limit', $argv, '0') ?? '0');
$csvOut = arg('csv', $argv);

$payload = is_file((string) $legsFile) ? json_decode((string) file_get_contents((string) $legsFile), true) : null;
if (!is_array($payload) || !is_array($payload['legs'] ?? null)) {
    out('[EROARE] Lipseste ' . $legsFile . '. Ruleaza intai study_sas_frequent_routes.php cu --legs-json=');
    exit(1);
}

if ($fromMatch === '' && $toMatch === '') {
    out('[EROARE] Da --from-match= si/sau --to-match=.');
    exit(1);
}

$legs = array_values(array_filter($payload['legs'], static function (array $leg) use ($fromMatch, $toMatch, $minKm): bool {
    if ($fromMatch !== '' && stripos((string) $leg['from'], $fromMatch) === false) {
        return false;
    }
    if ($toMatch !== '' && stripos((string) $leg['to'], $toMatch) === false) {
        return false;
    }
    if (!is_numeric($leg['from_lat'] ?? null) || !is_numeric($leg['to_lat'] ?? null)) {
        return false;
    }
    return (float) $leg['km'] >= $minKm;
}));

if ($legs === []) {
    out('Nicio cursa potrivita. Daca exportul de curse e vechi, regenereaza-l (acum include si coordonate).');
    exit(0);
}
if ($limit > 0) {
    $legs = array_slice($legs, 0, $limit);
}

$cacheDir = $projectRoot . '/storage/cache/valhalla_route';
if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
    out('[EROARE] Nu pot crea ' . $cacheDir);
    exit(1);
}

out('Curse de comparat: ' . count($legs) . '  |  ruta: "' . $fromMatch . '" -> "' . $toMatch . '"');
out('Rutare: Valhalla FOSSGIS, profil camion. Cache pe coordonate rotunjite la ~100 m.');
out();

$rows = [];
$apiCalls = 0;
$failed = 0;

foreach ($legs as $leg) {
    $routed = routedKm(
        (float) $leg['from_lat'],
        (float) $leg['from_lon'],
        (float) $leg['to_lat'],
        (float) $leg['to_lon'],
        $cacheDir,
        $apiCalls
    );
    if ($routed === null || $routed <= 0) {
        $failed++;
        continue;
    }

    $gps = (float) $leg['km'];
    $rows[] = [
        'date' => substr((string) $leg['start'], 0, 10),
        'month' => substr((string) $leg['start'], 0, 7),
        'plate' => (string) $leg['plate'],
        'gps' => $gps,
        'routed' => $routed,
        'diff' => $gps - $routed,
        'pct' => $routed > 0 ? 100 * ($gps - $routed) / $routed : 0.0,
    ];
}

out('Cereri Valhalla: ' . $apiCalls . '  |  curse comparate: ' . count($rows) . '  |  fara ruta: ' . $failed);
out();

if ($rows === []) {
    out('Valhalla nu a putut ruta niciuna dintre curse.');
    exit(0);
}

$gpsAll = array_map(static fn (array $r) => $r['gps'], $rows);
$routedAll = array_map(static fn (array $r) => $r['routed'], $rows);
$pctAll = array_map(static fn (array $r) => $r['pct'], $rows);

out('=== SUMAR RUTA ===');
out(sprintf('  KM GPS      : mediana %.1f   min %.1f   max %.1f   amplitudine %.1f km',
    median($gpsAll), min($gpsAll), max($gpsAll), max($gpsAll) - min($gpsAll)));
out(sprintf('  KM HARTA    : mediana %.1f   min %.1f   max %.1f',
    median($routedAll), min($routedAll), max($routedAll)));
out(sprintf('  DIFERENTA   : mediana %+.1f km (%+.1f%%)', median(array_map(static fn (array $r) => $r['diff'], $rows)), median($pctAll)));
out();

// -------------------------------------------------------------- pe luna
$byMonth = [];
foreach ($rows as $r) {
    $byMonth[$r['month']]['gps'][] = $r['gps'];
    $byMonth[$r['month']]['routed'][] = $r['routed'];
    $byMonth[$r['month']]['pct'][] = $r['pct'];
}
ksort($byMonth);

out('=== PE LUNA: GPS vs HARTA ===');
out(sprintf('%-9s %6s %10s %10s %10s %9s', 'LUNA', 'CURSE', 'KM GPS', 'KM HARTA', 'DIFF KM', 'DIFF %'));
foreach ($byMonth as $month => $d) {
    out(sprintf(
        '%-9s %6d %10.1f %10.1f %10.1f %8.1f%%',
        $month,
        count($d['gps']),
        median($d['gps']),
        median($d['routed']),
        median($d['gps']) - median($d['routed']),
        median($d['pct'])
    ));
}
out();

// ------------------------------------------------------------ pe masina
$byPlate = [];
foreach ($rows as $r) {
    $byPlate[$r['plate']]['pct'][] = $r['pct'];
    $byPlate[$r['plate']]['gps'][] = $r['gps'];
    $byPlate[$r['plate']]['diff'][] = $r['diff'];
}
uasort($byPlate, static fn (array $a, array $b) => median($b['pct']) <=> median($a['pct']));

out('=== PE MASINA: cat conduce peste ce zice harta ===');
out(sprintf('%-12s %6s %10s %10s %10s', 'MASINA', 'CURSE', 'KM GPS', 'DIFF KM', 'DIFF %'));
foreach ($byPlate as $plate => $d) {
    out(sprintf(
        '%-12s %6d %10.1f %10.1f %9.1f%%',
        $plate,
        count($d['pct']),
        median($d['gps']),
        median($d['diff']),
        median($d['pct'])
    ));
}
out();

// ------------------------------------------------------- curse deviante
usort($rows, static fn (array $a, array $b) => $b['pct'] <=> $a['pct']);
out('=== TOP 10 CURSE CU CEI MAI MULTI KM PESTE HARTA ===');
out(sprintf('%-11s %-12s %9s %10s %9s %8s', 'DATA', 'MASINA', 'KM GPS', 'KM HARTA', 'DIFF KM', 'DIFF %'));
foreach (array_slice($rows, 0, 10) as $r) {
    out(sprintf('%-11s %-12s %9.1f %10.1f %9.1f %7.1f%%',
        $r['date'], $r['plate'], $r['gps'], $r['routed'], $r['diff'], $r['pct']));
}
out();

out('=== TOP 5 CURSE SUB HARTA (posibile pierderi de semnal GPS) ===');
$under = array_reverse($rows);
out(sprintf('%-11s %-12s %9s %10s %9s %8s', 'DATA', 'MASINA', 'KM GPS', 'KM HARTA', 'DIFF KM', 'DIFF %'));
foreach (array_slice($under, 0, 5) as $r) {
    out(sprintf('%-11s %-12s %9.1f %10.1f %9.1f %7.1f%%',
        $r['date'], $r['plate'], $r['gps'], $r['routed'], $r['diff'], $r['pct']));
}
out();

if ($csvOut !== null) {
    $path = str_starts_with($csvOut, '/') || preg_match('/^[A-Za-z]:/', $csvOut)
        ? $csvOut
        : $projectRoot . '/' . ltrim($csvOut, '/');
    $fh = fopen($path, 'wb');
    if ($fh !== false) {
        fputcsv($fh, ['data', 'luna', 'masina', 'km_gps', 'km_harta', 'diff_km', 'diff_pct']);
        usort($rows, static fn (array $a, array $b) => strcmp($a['date'], $b['date']));
        foreach ($rows as $r) {
            fputcsv($fh, [$r['date'], $r['month'], $r['plate'],
                round($r['gps'], 1), round($r['routed'], 1), round($r['diff'], 1), round($r['pct'], 1)]);
        }
        fclose($fh);
        out('Export CSV: ' . $path);
    }
}
