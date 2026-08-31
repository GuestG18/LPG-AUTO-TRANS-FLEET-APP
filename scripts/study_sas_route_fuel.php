<?php
declare(strict_types=1);

/**
 * Studiu read-only: evolutia consumului CAN pe o ruta anume, luna de luna.
 *
 * De ce asa: SAS NU expune un odometru CAN independent - campul kmIndex este
 * suma distantelor GPS (verificat: abatere 0.0000% pe 2,1 mil. km). Singura
 * masuratoare care nu vine din GPS este CANFuelUsed, citit de pe magistrala
 * masinii. Deci km-ii nu se pot compara intre ei, dar litri/100 km pe aceeasi
 * ruta se pot compara in timp: daca o masina face km pe care trackerul nu ii
 * inregistreaza, combustibilul tot se consuma si raportul sare in sus.
 *
 * CANFuelUsed exista doar la nivel de foaie, nu pe segment, deci pentru fiecare
 * cursa se cere o foaie de parcurs pe exact intervalul ei. Raspunsurile se pun
 * in cache, deci a doua rulare e gratis.
 *
 * Necesita intai:
 *   php scripts/study_sas_frequent_routes.php --from=... --to=... --legs-json=storage/cache/curse_individuale.json
 *
 * Utilizare:
 *   php scripts/study_sas_route_fuel.php --from-match=Dambovita --to-match=Prahova
 *   php scripts/study_sas_route_fuel.php --from-match=Lugoj --to-match=Lugoj --plate=B655NET
 *   php scripts/study_sas_route_fuel.php --list                 - rutele disponibile, dupa numar de curse
 *   php scripts/study_sas_route_fuel.php --from-match=X --to-match=Y --csv=storage/cache/ruta.csv
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

$legsFile = arg('legs', $argv, $projectRoot . '/storage/cache/curse_individuale.json');
$fromMatch = (string) (arg('from-match', $argv) ?? '');
$toMatch = (string) (arg('to-match', $argv) ?? '');
$plateFilter = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) (arg('plate', $argv) ?? '')));
$minKm = (float) (arg('min-km', $argv, '50') ?? '50');
$listOnly = flag('list', $argv);
$csvOut = arg('csv', $argv);

$payload = is_file((string) $legsFile) ? json_decode((string) file_get_contents((string) $legsFile), true) : null;
if (!is_array($payload) || !is_array($payload['legs'] ?? null)) {
    out('[EROARE] Lipseste ' . $legsFile);
    out('Ruleaza intai: php scripts/study_sas_frequent_routes.php --from=2025-08-01 --to='
        . date('Y-m-d') . ' --legs-json=storage/cache/curse_individuale.json');
    exit(1);
}
$allLegs = $payload['legs'];

if ($listOnly) {
    $counts = [];
    foreach ($allLegs as $leg) {
        $key = $leg['from'] . ' -> ' . $leg['to'];
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
    arsort($counts);
    out('=== Rute disponibile (primele 40) ===');
    $i = 0;
    foreach ($counts as $key => $count) {
        if (++$i > 40) {
            break;
        }
        out(sprintf('%5d  %s', $count, $key));
    }
    exit(0);
}

if ($fromMatch === '' && $toMatch === '' && $plateFilter === '') {
    out('[EROARE] Da cel putin --from-match=, --to-match= sau --plate=. Vezi --list.');
    exit(1);
}

$legs = array_values(array_filter($allLegs, static function (array $leg) use ($fromMatch, $toMatch, $plateFilter, $minKm): bool {
    if ($fromMatch !== '' && stripos((string) $leg['from'], $fromMatch) === false) {
        return false;
    }
    if ($toMatch !== '' && stripos((string) $leg['to'], $toMatch) === false) {
        return false;
    }
    if ($plateFilter !== '') {
        $p = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $leg['plate']));
        if ($p !== $plateFilter) {
            return false;
        }
    }
    return (float) $leg['km'] >= $minKm;
}));

if ($legs === []) {
    out('Nicio cursa care sa se potriveasca. Vezi --list pentru numele zonelor.');
    exit(0);
}

out('Curse gasite: ' . count($legs) . '  |  filtru: from~"' . $fromMatch . '" to~"' . $toMatch
    . '"' . ($plateFilter !== '' ? ' plate=' . $plateFilter : '') . '  |  min ' . $minKm . ' km');
out();

$cacheDir = $projectRoot . '/storage/cache/sas_trip_fuel';
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

$rows = [];
$apiCalls = 0;
$noCan = 0;

foreach ($legs as $leg) {
    $carId = (int) $leg['car_id'];
    $cacheFile = $cacheDir . '/' . $carId . '_' . preg_replace('/[^0-9]/', '', (string) $leg['start']) . '.json';
    $sheet = null;

    if (is_file($cacheFile)) {
        $decoded = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($decoded)) {
            $sheet = $decoded;
        }
    }

    if ($sheet === null) {
        try {
            $sheet = $client->getTravelSheet($carId, (string) $leg['start'], (string) $leg['end']);
            $apiCalls++;
            @file_put_contents($cacheFile, (string) json_encode($sheet, JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            out('  ! ' . $leg['plate'] . ' ' . $leg['start'] . ': ' . $e->getMessage());
            sleep(2);
            continue;
        }
        usleep(600000);
    }

    $km = is_numeric($sheet['totalDistance'] ?? null) ? (float) $sheet['totalDistance'] : 0.0;
    $fuel = is_numeric($sheet['CANFuelUsed'] ?? null) ? (float) $sheet['CANFuelUsed'] : null;
    if ($fuel === null || empty($sheet['hasCANTotalFuel']) || $km < $minKm) {
        $noCan++;
        continue;
    }

    $l100 = $km > 0 ? $fuel / $km * 100 : null;
    // Garda de plauzibilitate: sub 4 sau peste 120 L/100 inseamna date rupte,
    // nu consum real (acelasi prag ca in modulul de carburanti).
    if ($l100 === null || $l100 < 4 || $l100 > 120) {
        $noCan++;
        continue;
    }

    $rows[] = [
        'date' => substr((string) $leg['start'], 0, 10),
        'month' => substr((string) $leg['start'], 0, 7),
        'plate' => (string) $leg['plate'],
        'km' => $km,
        'fuel' => $fuel,
        'l100' => $l100,
        'work_h' => is_numeric($sheet['workTimeSpanInSeconds'] ?? null) ? (float) $sheet['workTimeSpanInSeconds'] / 3600 : null,
        'idle_h' => is_numeric($sheet['idleTimeSpanInSeconds'] ?? null) ? (float) $sheet['idleTimeSpanInSeconds'] / 3600 : null,
        'route' => $leg['from'] . ' -> ' . $leg['to'],
    ];
}

out('Cereri API noi: ' . $apiCalls . '  |  curse cu CAN valid: ' . count($rows)
    . '  |  fara CAN / date implauzibile: ' . $noCan);
out();

if ($rows === []) {
    out('Nicio cursa cu date CAN pe aceasta ruta. Doar 39 din 48 de masini raporteaza consum CAN.');
    exit(0);
}

// ------------------------------------------------------------ trend lunar
$byMonth = [];
foreach ($rows as $r) {
    $byMonth[$r['month']]['km'][] = $r['km'];
    $byMonth[$r['month']]['l100'][] = $r['l100'];
    $byMonth[$r['month']]['fuel'][] = $r['fuel'];
    $byMonth[$r['month']]['plates'][$r['plate']] = true;
}
ksort($byMonth);

out('=== EVOLUTIE LUNARA PE RUTA ===');
out(sprintf('%-9s %6s %10s %10s %10s %9s %6s', 'LUNA', 'CURSE', 'KM MEDN', 'LITRI MED', 'L/100 MEDN', 'L/100 MIN-MAX', 'VEH'));
$firstL100 = null;
$lastL100 = null;
foreach ($byMonth as $month => $data) {
    $medL100 = median($data['l100']);
    $firstL100 ??= $medL100;
    $lastL100 = $medL100;
    out(sprintf(
        '%-9s %6d %10.1f %10.1f %10.2f %9s %6d',
        $month,
        count($data['l100']),
        median($data['km']),
        array_sum($data['fuel']) / count($data['fuel']),
        $medL100,
        sprintf('%.0f-%.0f', min($data['l100']), max($data['l100'])),
        count($data['plates'])
    ));
}
out();

if ($firstL100 !== null && $firstL100 > 0) {
    $delta = $lastL100 - $firstL100;
    out(sprintf(
        'Deriva pe interval: %.2f -> %.2f L/100 (%+.2f L/100, %+.1f%%)',
        $firstL100,
        $lastL100,
        $delta,
        100 * $delta / $firstL100
    ));
    out();
}

// ------------------------------------------------------- per masina pe ruta
$byPlate = [];
foreach ($rows as $r) {
    $byPlate[$r['plate']]['l100'][] = $r['l100'];
    $byPlate[$r['plate']]['km'][] = $r['km'];
}
uasort($byPlate, static fn (array $a, array $b) => median($b['l100']) <=> median($a['l100']));

out('=== ACEEASI RUTA, PE MASINA (sortat dupa consum) ===');
out(sprintf('%-12s %6s %10s %10s %11s', 'MASINA', 'CURSE', 'KM MEDN', 'L/100 MEDN', 'L/100 MIN-MAX'));
foreach ($byPlate as $plate => $data) {
    out(sprintf(
        '%-12s %6d %10.1f %10.2f %11s',
        $plate,
        count($data['l100']),
        median($data['km']),
        median($data['l100']),
        sprintf('%.0f-%.0f', min($data['l100']), max($data['l100']))
    ));
}
out();

// -------------------------------------------------------- curse anormale
$allL100 = array_map(static fn (array $r) => $r['l100'], $rows);
$med = median($allL100);
$deviants = array_values(array_filter($rows, static fn (array $r) => $med > 0 && $r['l100'] > $med * 1.35));
usort($deviants, static fn (array $a, array $b) => $b['l100'] <=> $a['l100']);

out('=== CURSE CU CONSUM PESTE +35% FATA DE MEDIANA RUTEI (' . number_format($med, 2) . ' L/100) ===');
if ($deviants === []) {
    out('Niciuna. Consumul e uniform pe aceasta ruta.');
} else {
    out(sprintf('%-11s %-12s %9s %9s %9s %8s', 'DATA', 'MASINA', 'KM', 'LITRI', 'L/100', 'ORE MOT'));
    foreach (array_slice($deviants, 0, 20) as $d) {
        out(sprintf(
            '%-11s %-12s %9.1f %9.1f %9.2f %8s',
            $d['date'],
            $d['plate'],
            $d['km'],
            $d['fuel'],
            $d['l100'],
            $d['work_h'] !== null ? number_format($d['work_h'], 1) : '-'
        ));
    }
    out();
    out('Nota: consum mare pe km normali = fie km nefacuti pe GPS, fie mers in gol,');
    out('fie incarcatura/relief. Orele de motor separa mersul in gol de restul.');
}
out();

if ($csvOut !== null) {
    $path = str_starts_with($csvOut, '/') || preg_match('/^[A-Za-z]:/', $csvOut)
        ? $csvOut
        : $projectRoot . '/' . ltrim($csvOut, '/');
    $fh = fopen($path, 'wb');
    if ($fh !== false) {
        fputcsv($fh, ['data', 'luna', 'masina', 'km', 'litri', 'l_100km', 'ore_motor', 'ore_ralanti', 'ruta']);
        foreach ($rows as $r) {
            fputcsv($fh, [
                $r['date'], $r['month'], $r['plate'],
                round($r['km'], 1), round($r['fuel'], 1), round($r['l100'], 2),
                $r['work_h'] !== null ? round($r['work_h'], 2) : '',
                $r['idle_h'] !== null ? round($r['idle_h'], 2) : '',
                $r['route'],
            ]);
        }
        fclose($fh);
        out('Export CSV: ' . $path);
    }
}
