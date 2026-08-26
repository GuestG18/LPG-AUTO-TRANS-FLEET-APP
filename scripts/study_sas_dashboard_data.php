<?php
declare(strict_types=1);

/**
 * Studiu date SAS pentru viitorul dashboard live.
 * Doar citire: inventariaza campurile din /api/info, currentpositions,
 * travelsheet si reports/events, plus un dublu-sample la ~25s pentru a
 * observa cum se vede "miscarea" intre doua interogari consecutive.
 *
 * Utilizare:
 *   php scripts/study_sas_dashboard_data.php                 - studiu complet (cu dublu-sample)
 *   php scripts/study_sas_dashboard_data.php --no-delta      - fara al doilea sample (rapid)
 *   php scripts/study_sas_dashboard_data.php --raw           - include raspunsuri brute complete
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/htdocs/config/config.php';
require_once $projectRoot . '/htdocs/services/SasFleetClient.php';

function out(string $line = ''): void
{
    fwrite(STDOUT, $line . "\n");
}

function outJson(mixed $data): void
{
    out((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/** Inventar de campuri: pentru fiecare cheie -> tipuri intalnite + exemple de valori. */
function fieldInventory(array $rows, int $maxExamples = 4): array
{
    $inventory = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        foreach ($row as $key => $value) {
            $type = get_debug_type($value);
            $inventory[$key]['types'][$type] = true;
            $example = is_scalar($value) || $value === null ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
            $examples = $inventory[$key]['examples'] ?? [];
            if (!in_array($example, $examples, true) && count($examples) < $maxExamples) {
                $examples[] = $example;
            }
            $inventory[$key]['examples'] = $examples;
        }
    }
    foreach ($inventory as $key => $meta) {
        $inventory[$key]['types'] = implode('|', array_keys($meta['types']));
    }
    return $inventory;
}

function printInventory(array $inventory): void
{
    foreach ($inventory as $key => $meta) {
        $examples = array_map(
            static fn ($v) => $v === null ? 'null' : (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v),
            $meta['examples']
        );
        out(sprintf('  %-22s %-14s ex: %s', $key, '(' . $meta['types'] . ')', implode(' | ', $examples)));
    }
}

$noDelta = in_array('--no-delta', $argv, true);
$raw = in_array('--raw', $argv, true);

$client = new SasFleetClient();
if (!$client->credentialsAvailable()) {
    out('[EROARE] Credentiale SAS lipsa in .env.');
    exit(1);
}

// Refoloseste sesiunea persistata de Harta Flota, daca exista, pentru a nu face login inutil.
$sessionFile = $projectRoot . '/storage/cache/sas_session.json';
if (is_file($sessionFile)) {
    $state = json_decode((string) file_get_contents($sessionFile), true);
    if (is_array($state)) {
        $client->restoreState($state);
    }
}
if (!$client->isAuthenticated()) {
    $client->login();
}

out('=== 1. /api/info — structura flotei ===');
$info = $client->getCompanyInfo();
out('Chei radacina: ' . implode(', ', array_keys($info)));
foreach ($info as $key => $value) {
    if (is_array($value)) {
        out('  ' . $key . ': ' . count($value) . ' elemente');
    } else {
        out('  ' . $key . ' = ' . json_encode($value, JSON_UNESCAPED_UNICODE));
    }
}
$cars = $client->getCars();
$activeCars = array_values(array_filter($cars, static fn (array $c) => empty($c['disabled'])));
out('Masini: ' . count($cars) . ' total, ' . count($activeCars) . ' active.');
out('Inventar campuri masina:');
printInventory(fieldInventory($cars));
if ($raw && $cars !== []) {
    out('Exemplu masina bruta:');
    outJson($cars[0]);
}
out();

out('=== 2. currentpositions — sample #1 (' . date('H:i:s') . ') ===');
$carIds = array_map(static fn (array $c) => (int) $c['carId'], $activeCars);
$positions1 = $client->getCurrentPositions($carIds);
out('Pozitii primite: ' . count($positions1));
out('Inventar campuri pozitie:');
printInventory(fieldInventory($positions1));
if ($raw && $positions1 !== []) {
    out('Exemplu pozitie bruta:');
    outJson($positions1[0]);
}

$speedOf = static function (array $p): float {
    $s = $p['Speed'] ?? $p['speed'] ?? 0;
    return is_numeric($s) ? (float) $s : 0.0;
};
$idOf = static function (array $p): int {
    return (int) ($p['CarID'] ?? $p['carId'] ?? $p['carID'] ?? 0);
};
$dateOf = static function (array $p): string {
    return (string) ($p['Date'] ?? $p['date'] ?? '');
};

$moving1 = array_values(array_filter($positions1, static fn (array $p) => $speedOf($p) > 0));
out('In miscare (speed > 0): ' . count($moving1) . ' / ' . count($positions1));
$triggers = [];
foreach ($positions1 as $p) {
    $t = $p['TriggerEvent'] ?? $p['triggerEvent'] ?? null;
    if ($t !== null) {
        $triggers[(string) $t] = ($triggers[(string) $t] ?? 0) + 1;
    }
}
ksort($triggers);
out('Distributie TriggerEvent: ' . json_encode($triggers));

// Vechimea pozitiilor: cat de "live" sunt datele.
$ages = [];
foreach ($positions1 as $p) {
    $ts = strtotime($dateOf($p));
    if ($ts !== false) {
        $ages[] = time() - $ts;
    }
}
if ($ages !== []) {
    sort($ages);
    out(sprintf(
        'Vechime pozitii (secunde): min=%d, median=%d, max=%d; sub 2 min: %d/%d',
        $ages[0],
        $ages[(int) (count($ages) / 2)],
        $ages[count($ages) - 1],
        count(array_filter($ages, static fn (int $a) => $a <= 120)),
        count($ages)
    ));
}
out();

if (!$noDelta) {
    out('=== 3. currentpositions — sample #2 dupa 25s (studiu delta miscare) ===');
    sleep(25);
    $positions2 = $client->getCurrentPositions($carIds);
    $byId1 = [];
    foreach ($positions1 as $p) {
        $byId1[$idOf($p)] = $p;
    }
    $changedTimestamp = 0;
    $changedCoords = 0;
    $transitions = [];
    foreach ($positions2 as $p2) {
        $id = $idOf($p2);
        $p1 = $byId1[$id] ?? null;
        if ($p1 === null) {
            continue;
        }
        if ($dateOf($p2) !== $dateOf($p1)) {
            $changedTimestamp++;
        }
        $lat1 = $p1['Latitude'] ?? $p1['latitude'] ?? null;
        $lat2 = $p2['Latitude'] ?? $p2['latitude'] ?? null;
        $lon1 = $p1['Longitude'] ?? $p1['longitude'] ?? null;
        $lon2 = $p2['Longitude'] ?? $p2['longitude'] ?? null;
        if ($lat1 !== $lat2 || $lon1 !== $lon2) {
            $changedCoords++;
        }
        $was = $speedOf($p1) > 0;
        $is = $speedOf($p2) > 0;
        if ($was !== $is) {
            $transitions[] = $id . ': ' . ($was ? 'miscare -> oprit' : 'oprit -> miscare');
        }
    }
    out('Pozitii cu timestamp schimbat in 25s: ' . $changedTimestamp . ' / ' . count($positions2));
    out('Pozitii cu coordonate schimbate in 25s: ' . $changedCoords . ' / ' . count($positions2));
    out('Tranzitii oprit<->miscare: ' . ($transitions === [] ? 'niciuna' : implode('; ', $transitions)));
    out();
}

out('=== 4. travelsheet azi — pentru o masina in miscare (sumar zi) ===');
$target = $moving1[0] ?? $positions1[0] ?? null;
if ($target !== null) {
    $targetId = $idOf($target);
    $startTime = date('Y-m-d') . 'T00:00:00';
    $endTime = date('Y-m-d') . 'T23:59:59';
    try {
        $sheet = $client->getTravelSheet($targetId, $startTime, $endTime);
        out('Masina carId=' . $targetId . ', chei radacina: ' . implode(', ', array_keys($sheet)));
        foreach ($sheet as $key => $value) {
            if (is_array($value)) {
                out('  ' . $key . ': ' . count($value) . ' elemente');
            } else {
                out('  ' . $key . ' = ' . json_encode($value, JSON_UNESCAPED_UNICODE));
            }
        }
        $segments = null;
        foreach ($sheet as $value) {
            if (is_array($value) && $value !== [] && is_array($value[array_key_first($value)] ?? null)) {
                $segments = array_values(array_filter($value, 'is_array'));
                break;
            }
        }
        if ($segments !== null) {
            out('Inventar campuri segment (foaie de parcurs):');
            printInventory(fieldInventory($segments));
            if ($raw) {
                out('Exemplu segment brut:');
                outJson($segments[0]);
            }
        }
    } catch (Throwable $e) {
        out('[EROARE] travelsheet: ' . $e->getMessage());
    }
} else {
    out('Nicio pozitie disponibila pentru travelsheet.');
}
out();

out('=== 5. reports/events azi — aceeasi masina (ultimele evenimente) ===');
if ($target !== null) {
    try {
        $events = $client->getCarEvents($idOf($target), date('Y-m-d') . 'T00:00:00', date('Y-m-d') . 'T23:59:59');
        out('Total evenimente azi: ' . count($events));
        out('Inventar campuri eveniment:');
        printInventory(fieldInventory(array_slice($events, -50)));
        $eventTriggers = [];
        foreach ($events as $e) {
            $t = $e['triggerEvent'] ?? $e['TriggerEvent'] ?? null;
            if ($t !== null) {
                $eventTriggers[(string) $t] = ($eventTriggers[(string) $t] ?? 0) + 1;
            }
        }
        ksort($eventTriggers);
        out('Distributie triggerEvent in evenimente: ' . json_encode($eventTriggers));
        if ($raw && $events !== []) {
            out('Ultimele 3 evenimente brute:');
            outJson(array_slice($events, -3));
        }
    } catch (Throwable $e) {
        out('[EROARE] events: ' . $e->getMessage());
    }
}
out();

// Persista sesiunea pentru a nu forta alt login la urmatoarea rulare / pe Harta Flota.
@file_put_contents($sessionFile, json_encode($client->exportState()));
out('=== Gata ===');
