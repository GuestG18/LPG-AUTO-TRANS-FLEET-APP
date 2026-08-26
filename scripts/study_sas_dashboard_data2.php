<?php
declare(strict_types=1);

/**
 * Studiu SAS #2 (follow-up): travelsheet pe zi deschisa (isClosedTimeInterval=false)
 * si evenimentele de azi pentru o masina cu pozitie recenta, pentru a intelege
 * codurile triggerEvent si granularitatea datelor. Doar citire.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/htdocs/config/config.php';
require_once $projectRoot . '/htdocs/services/SasFleetClient.php';

function out(string $line = ''): void
{
    fwrite(STDOUT, $line . "\n");
}

$client = new SasFleetClient();
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

$cars = $client->getCars();
$carIds = array_map(static fn (array $c) => (int) $c['carId'], $cars);
$positions = $client->getCurrentPositions($carIds);

// Cele mai recente pozitii, in miscare daca se poate.
usort($positions, static function (array $a, array $b): int {
    return strcmp((string) ($b['Date'] ?? ''), (string) ($a['Date'] ?? ''));
});
$fresh = $positions[0] ?? null;
$freshMoving = null;
foreach ($positions as $p) {
    if ((float) ($p['Speed'] ?? 0) > 0) {
        $freshMoving = $p;
        break;
    }
}
$target = $freshMoving ?? $fresh;
$targetId = (int) ($target['CarID'] ?? 0);
out('Masina studiata: carId=' . $targetId . ', speed=' . round((float) ($target['Speed'] ?? 0), 1)
    . ', date=' . (string) ($target['Date'] ?? '?') . ', trigger=' . (string) ($target['TriggerEvent'] ?? '?'));
out();

$startTime = date('Y-m-d') . 'T00:00:00';
$endTime = date('Y-m-d') . 'T23:59:59';

out('=== travelsheet azi cu isClosedTimeInterval=FALSE ===');
try {
    $sheet = $client->getTravelSheet($targetId, $startTime, $endTime, false);
    out('totalDistance=' . json_encode($sheet['totalDistance'] ?? null)
        . ', averageSpeed=' . json_encode($sheet['averageSpeed'] ?? null)
        . ', workTime(s)=' . json_encode($sheet['workTimeSpanInSeconds'] ?? null)
        . ', idleTime(s)=' . json_encode($sheet['idleTimeSpanInSeconds'] ?? null)
        . ', segments=' . count((array) ($sheet['segments'] ?? [])));
    $segments = array_values(array_filter((array) ($sheet['segments'] ?? []), 'is_array'));
    if ($segments !== []) {
        out('Primul segment brut:');
        out((string) json_encode($segments[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if (count($segments) > 1) {
            out('Ultimul segment brut:');
            out((string) json_encode($segments[count($segments) - 1], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }
} catch (Throwable $e) {
    out('[EROARE] ' . $e->getMessage());
}
out();

out('=== reports/events azi — distributie triggerEvent + esantioane pe fiecare cod ===');
try {
    $events = $client->getCarEvents($targetId, $startTime, $endTime);
    out('Total evenimente azi: ' . count($events));
    $byTrigger = [];
    foreach ($events as $e) {
        $t = (string) ($e['triggerEvent'] ?? $e['TriggerEvent'] ?? 'null');
        $byTrigger[$t]['count'] = ($byTrigger[$t]['count'] ?? 0) + 1;
        if (!isset($byTrigger[$t]['sample'])) {
            $byTrigger[$t]['sample'] = $e;
        }
    }
    ksort($byTrigger, SORT_NATURAL);
    foreach ($byTrigger as $trigger => $meta) {
        $s = $meta['sample'];
        out(sprintf(
            'trigger=%-4s count=%-5d ex: date=%s speed=%s course=%s addr=%s',
            $trigger,
            $meta['count'],
            (string) ($s['date'] ?? $s['Date'] ?? '?'),
            json_encode($s['speed'] ?? $s['Speed'] ?? null),
            json_encode($s['course'] ?? $s['Course'] ?? null),
            json_encode($s['address'] ?? $s['Address'] ?? null)
        ));
    }
    if ($events !== []) {
        out();
        out('Un eveniment complet brut:');
        out((string) json_encode($events[(int) (count($events) / 2)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        // Cadenta: distanta mediana in secunde intre evenimente consecutive.
        $timestamps = [];
        foreach ($events as $e) {
            $ts = strtotime((string) ($e['date'] ?? $e['Date'] ?? ''));
            if ($ts !== false) {
                $timestamps[] = $ts;
            }
        }
        sort($timestamps);
        $gaps = [];
        for ($i = 1; $i < count($timestamps); $i++) {
            $gaps[] = $timestamps[$i] - $timestamps[$i - 1];
        }
        if ($gaps !== []) {
            sort($gaps);
            out('Cadenta evenimente (s): min=' . $gaps[0] . ', median=' . $gaps[(int) (count($gaps) / 2)] . ', max=' . $gaps[count($gaps) - 1]);
        }
    }
} catch (Throwable $e) {
    out('[EROARE] ' . $e->getMessage());
}

@file_put_contents($sessionFile, json_encode($client->exportState()));
out('=== Gata ===');
