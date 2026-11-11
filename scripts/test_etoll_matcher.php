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

echo "\nKm service (drum la / de la service)\n";

function service(int $id, string $start, ?string $startTime, ?string $end, ?string $endTime): array
{
    return [
        'id' => $id,
        'data_plecare' => $start,
        'ora_plecare' => $startTime,
        'data_intoarcere' => $end,
        'ora_intoarcere' => $endTime,
        'driver_id' => 9,
        'service_nume' => 'Peco',
        'nr_inmatriculare' => 'B 315 NET',
        'sofer_nume' => 'S9',
    ];
}

$r = EtollTripMatcher::decide('2026-10-12 12:48:08', [], [service(7, '2026-10-03', '17:31:00', '2026-10-12', '18:24:00')]);
check('fara cursa, in perioada Km service -> asociata_auto pe service', $r['status'] === 'asociata_auto' && $r['cursa_id'] === null && $r['service_km_id'] === 7, json_encode($r));
check('candidatul e marcat tip=service_km, soferul din service', ($r['candidates'][0]['tip'] ?? '') === 'service_km' && $r['driver_id'] === 9);

$r = EtollTripMatcher::decide('2026-10-12 12:48:08', [trip(3, '2026-10-12', '08:00:00', '2026-10-12', '16:00:00')], [service(7, '2026-10-03', null, '2026-10-12', null)]);
check('cursa care contine momentul castiga in fata Km service', $r['status'] === 'asociata_auto' && $r['cursa_id'] === 3 && $r['service_km_id'] === null, json_encode($r));

$r = EtollTripMatcher::decide('2026-11-10 09:00:00', [], [service(8, '2026-11-08', '10:00:00', null, null)]);
check('vehicul inca in service (fara intoarcere) -> interval deschis', $r['status'] === 'asociata_auto' && $r['service_km_id'] === 8, json_encode($r));

$r = EtollTripMatcher::decide('2026-10-12 20:00:00', [], [service(7, '2026-10-03', '17:31:00', '2026-10-12', '18:24:00')]);
check('dupa ora intoarcerii, aceeasi zi -> de_verificat cu candidatul service', $r['status'] === 'de_verificat' && $r['service_km_id'] === null && ($r['candidates'][0]['tip'] ?? '') === 'service_km', json_encode($r));

$r = EtollTripMatcher::decide('2026-10-12 20:00:00', [trip(4, '2026-10-12', '06:00:00', '2026-10-12', '10:00:00')], [service(7, '2026-10-03', null, '2026-10-12', '18:24:00')]);
check('nici cursa, nici service nu contin ora -> de_verificat cu ambii candidati', $r['status'] === 'de_verificat' && count($r['candidates']) === 2, json_encode($r));

$r = EtollTripMatcher::decide('2026-10-27 21:08:28', [trip(1, '2026-10-27', null, '2026-10-27', null)]);
check('fara al treilea argument: service_km_id = null (compatibil)', $r['status'] === 'asociata_auto' && array_key_exists('service_km_id', $r) && $r['service_km_id'] === null);

echo "\n$passed trecute, $failed picate\n";
exit($failed > 0 ? 1 : 0);
