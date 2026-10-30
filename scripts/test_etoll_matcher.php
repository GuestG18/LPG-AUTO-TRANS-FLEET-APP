<?php
declare(strict_types=1);

/**
 * Teste pure pentru asocierea sesiune eToll -> cursa (EtollTripMatcher::decide).
 *
 *   php scripts/test_etoll_matcher.php
 *
 * Cursele sunt construite in memorie (deja filtrate pe camion, ca in match()); nu se
 * atinge baza de date.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/services/EtollTripMatcher.php';

$passed = 0;
$failed = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "\033[32m  PASS\033[0m  $name\n";
    } else {
        $failed++;
        echo "\033[31m  FAIL\033[0m  $name" . ($detail !== '' ? "  -> $detail" : '') . "\n";
    }
}

function trip(int $id, string $start, ?string $startTime, string $end, ?string $endTime): array
{
    return [
        'id' => $id,
        'data_inceput' => $start,
        'ora_inceput' => $startTime,
        'data_sfarsit' => $end,
        'ora_sfarsit' => $endTime,
        'driver_id' => 7,
        'nr_inmatriculare' => 'B 652 NET',
        'sofer_nume' => 'S7',
    ];
}

echo "EtollTripMatcher::decide\n";

$r = EtollTripMatcher::decide('2026-10-27 21:08:28', [trip(881, '2026-10-26', '16:00:00', '2026-10-27', '21:10:00')]);
check('cursa de doua zile care contine ora -> asociata_auto', $r['status'] === 'asociata_auto' && $r['cursa_id'] === 881, json_encode($r));
check('soferul cursei alese se transmite', $r['driver_id'] === 7);

$r = EtollTripMatcher::decide('2026-10-27 21:08:28', [trip(1, '2026-10-27', null, '2026-10-27', null)]);
check('ore lipsa pe cursa = toata ziua', $r['status'] === 'asociata_auto' && $r['cursa_id'] === 1, json_encode($r));

$r = EtollTripMatcher::decide('2026-10-27 21:08:28', [
    trip(1, '2026-10-27', '06:00:00', '2026-10-27', '14:00:00'),
    trip(2, '2026-10-27', '15:00:00', '2026-10-27', '22:00:00'),
]);
check('doua curse in aceeasi zi: alege dupa ora', $r['status'] === 'asociata_auto' && $r['cursa_id'] === 2, json_encode($r));

$r = EtollTripMatcher::decide('2026-10-27 21:08:28', [
    trip(1, '2026-10-27', null, '2026-10-27', null),
    trip(2, '2026-10-27', '15:00:00', '2026-10-27', '22:00:00'),
]);
check('doua curse care contin ora -> de_verificat cu 2 candidati', $r['status'] === 'de_verificat' && count($r['candidates']) === 2 && $r['cursa_id'] === null, json_encode($r));

$r = EtollTripMatcher::decide('2026-10-27 21:08:28', [trip(1, '2026-10-27', '06:00:00', '2026-10-27', '14:00:00')]);
check('cursa in aceeasi zi, dar ora iese din interval -> de_verificat', $r['status'] === 'de_verificat' && count($r['candidates']) === 1, json_encode($r));

$r = EtollTripMatcher::decide('2026-10-27 21:08:28', []);
check('nicio cursa a camionului -> neasociata', $r['status'] === 'neasociata' && $r['candidates'] === [], json_encode($r));

$r = EtollTripMatcher::decide('2026-10-28 00:15:00', [trip(5, '2026-10-27', '18:00:00', '2026-10-28', null)]);
check('sesiune dupa miezul noptii, cursa peste noapte -> asociata', $r['status'] === 'asociata_auto' && $r['cursa_id'] === 5, json_encode($r));

$r = EtollTripMatcher::decide('nu e o data', [trip(5, '2026-10-27', null, '2026-10-28', null)]);
check('moment invalid -> de_verificat', $r['status'] === 'de_verificat');

echo "\n$passed trecute, $failed picate\n";
exit($failed > 0 ? 1 : 0);
