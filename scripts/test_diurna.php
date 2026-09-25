<?php
declare(strict_types=1);

/**
 * Test pentru calculul diurnei din Desfasurator curse.
 *
 *   php scripts/test_diurna.php
 *
 * REGULA
 *   Prima diurna la 12h, apoi cate una dupa fiecare 24h in plus:
 *   diurne = durata < 720 min ? 0 : floor((durata - 720) / 1440) + 1
 *   Durata = Data si ora sfarsit - Data si ora inceput, in minute.
 *
 * Nu atinge baza de date.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/includes/helpers.php';

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
        echo "\033[31m  FAIL\033[0m  $name" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    }
}

/** Randul unei curse care incepe la $start si tine $minutes minute. */
function race_row(string $start, int $minutes): array
{
    $from = new DateTimeImmutable($start);
    $to = $from->modify('+' . $minutes . ' minutes');

    return [
        'data_inceput' => $from->format('Y-m-d'),
        'ora_inceput' => $from->format('H:i:s'),
        'data_sfarsit' => $to->format('Y-m-d'),
        'ora_sfarsit' => $to->format('H:i:s'),
    ];
}

echo "\n1) Tabelul de praguri (durata exacta, in minute)\n";
$cases = [
    [0, 0, 0], [11, 59, 0], [12, 0, 1], [24, 0, 1], [35, 59, 1], [36, 0, 2],
    [48, 0, 2], [59, 59, 2], [60, 0, 3], [72, 0, 3], [84, 0, 4], [96, 0, 4],
    [108, 0, 5], [120, 0, 5], [132, 0, 6],
    // Continuarea secventei si limitele de sub prag.
    [83, 59, 3], [107, 59, 4], [131, 59, 5], [155, 59, 6], [156, 0, 7],
];
foreach ($cases as [$hours, $minutes, $expected]) {
    $total = $hours * 60 + $minutes;
    $label = sprintf('%dh %02dm', $hours, $minutes);
    $fromMinutes = dispatcher_diurna_from_minutes($total);
    check("$label -> $expected (din minute)", $fromMinutes === $expected, "primit $fromMinutes");

    // Aceeasi durata, prin data si ora de inceput / sfarsit.
    $result = dispatcher_diurna_for_interval(race_row('2026-03-10 08:00', $total));
    check(
        "$label -> $expected (din data si ora)",
        $result['status'] === 'ok' && $result['minute'] === $total && $result['diurne'] === $expected,
        json_encode($result)
    );
}

echo "\n2) Intervale peste miezul noptii, luna si an\n";
$crossing = [
    'peste miezul noptii (22:00 -> 10:00, 12h)' => [['2026-09-14', '22:00', '2026-09-15', '10:00'], 720, 1],
    'peste miezul noptii, sub prag (22:00 -> 09:59)' => [['2026-09-14', '22:00', '2026-09-15', '09:59'], 719, 0],
    'peste sfarsit de luna (30.09 20:00 -> 02.10 08:00, 36h)' => [['2026-09-30', '20:00', '2026-10-02', '08:00'], 2160, 2],
    'peste februarie (28.02 06:00 -> 03.03 06:00, 72h)' => [['2027-02-28', '06:00', '2027-03-03', '06:00'], 4320, 3],
    'peste an nou (31.12 18:00 -> 01.01 06:00, 12h)' => [['2026-12-31', '18:00', '2027-01-01', '06:00'], 720, 1],
    'peste an nou (30.12 08:00 -> 03.01 20:00, 108h)' => [['2026-12-30', '08:00', '2027-01-03', '20:00'], 6480, 5],
];
foreach ($crossing as $label => [[$d1, $t1, $d2, $t2], $expectedMinutes, $expected]) {
    $result = dispatcher_diurna_for_interval([
        'data_inceput' => $d1, 'ora_inceput' => $t1 . ':00',
        'data_sfarsit' => $d2, 'ora_sfarsit' => $t2 . ':00',
    ]);
    check(
        "$label -> $expected",
        $result['status'] === 'ok' && $result['minute'] === $expectedMinutes && $result['diurne'] === $expected,
        json_encode($result)
    );
}

echo "\n3) Date lipsa si intervale invalide (nu devin 0 diurne)\n";
$base = ['data_inceput' => '2026-09-15', 'ora_inceput' => '08:00:00', 'data_sfarsit' => '2026-09-16', 'ora_sfarsit' => '08:00:00'];
foreach (['data_inceput', 'ora_inceput', 'data_sfarsit', 'ora_sfarsit'] as $field) {
    foreach ([null, ''] as $empty) {
        $row = $base;
        $row[$field] = $empty;
        $result = dispatcher_diurna_for_interval($row);
        check(
            "$field " . ($empty === null ? 'NULL' : 'gol') . ' -> lipsa',
            $result['status'] === 'lipsa' && $result['diurne'] === null,
            json_encode($result)
        );
    }
}
$inverted = dispatcher_diurna_for_interval(['data_inceput' => '2026-09-16', 'ora_inceput' => '08:00', 'data_sfarsit' => '2026-09-15', 'ora_sfarsit' => '20:00']);
check('sfarsit inaintea inceputului -> invalid', $inverted['status'] === 'invalid' && $inverted['diurne'] === null, json_encode($inverted));
$sameMinute = dispatcher_diurna_for_interval(['data_inceput' => '2026-09-16', 'ora_inceput' => '08:00', 'data_sfarsit' => '2026-09-16', 'ora_sfarsit' => '08:00']);
check('inceput = sfarsit -> 0 diurne (interval valid)', $sameMinute['status'] === 'ok' && $sameMinute['diurne'] === 0, json_encode($sameMinute));
$badDate = dispatcher_diurna_for_interval(['data_inceput' => '15/09/2026', 'ora_inceput' => '08:00', 'data_sfarsit' => '2026-09-16', 'ora_sfarsit' => '08:00']);
check('data in format necunoscut -> lipsa', $badDate['status'] === 'lipsa', json_encode($badDate));

echo "\n4) Editarea orelor schimba rezultatul\n";
$row = ['data_inceput' => '2026-09-15', 'ora_inceput' => '08:00:00', 'data_sfarsit' => '2026-09-15', 'ora_sfarsit' => '19:59:00'];
$before = dispatcher_diurna_for_interval($row)['diurne'];
$row['ora_sfarsit'] = '20:00:00';
$afterEnd = dispatcher_diurna_for_interval($row)['diurne'];
$row['data_sfarsit'] = '2026-09-17';
$afterDate = dispatcher_diurna_for_interval($row)['diurne'];
$row['ora_inceput'] = '07:59:00';
$afterStart = dispatcher_diurna_for_interval($row)['diurne'];
check('11h 59m -> 0', $before === 0, (string) $before);
check('sfarsit mutat la 20:00 (12h) -> 1', $afterEnd === 1, (string) $afterEnd);
check('sfarsit mutat pe 17.09 20:00 (60h) -> 3', $afterDate === 3, (string) $afterDate);
check('inceput mutat la 07:59 (60h 01m) -> 3', $afterStart === 3, (string) $afterStart);

echo "\n5) Cursa reluata (#710): perioada intreaga, nu suma fazelor\n";
$race = ['data_inceput' => '2026-09-15', 'ora_inceput' => '08:00:00', 'data_sfarsit' => '2026-09-18', 'ora_sfarsit' => '14:00:00'];
$raceResult = dispatcher_diurna_for_interval($race);
check('15.09 08:00 -> 18.09 14:00 (78h) -> 3', $raceResult['minute'] === 4680 && $raceResult['diurne'] === 3, json_encode($raceResult));
$phases = [
    ['driver_id' => 17, 'sofer_nume' => 'A', 'data_inceput' => '2026-09-15', 'ora_inceput' => '08:00:00', 'data_sfarsit' => '2026-09-15', 'ora_sfarsit' => '12:00:00'],
    ['driver_id' => 17, 'sofer_nume' => 'A', 'data_inceput' => '2026-09-16', 'ora_inceput' => '05:00:00', 'data_sfarsit' => '2026-09-16', 'ora_sfarsit' => '14:00:00'],
    ['driver_id' => 17, 'sofer_nume' => 'A', 'data_inceput' => '2026-09-17', 'ora_inceput' => '12:00:00', 'data_sfarsit' => '2026-09-18', 'ora_sfarsit' => '14:00:00'],
];
$split = dispatcher_diurna_split((int) $raceResult['diurne'], $phases);
check('un singur sofer primeste toate cele 3 diurne', count($split) === 1 && $split[0]['zile'] === 3, json_encode($split));
$phases[2]['driver_id'] = 21;
$phases[2]['sofer_nume'] = 'B';
$split = dispatcher_diurna_split((int) $raceResult['diurne'], $phases);
check('doi soferi: impartirea pastreaza totalul 3', array_sum(array_column($split, 'zile')) === 3, json_encode($split));

echo "\n" . ($failed === 0 ? "\033[32m" : "\033[31m") . "$passed trecute, $failed picate\033[0m\n";
exit($failed === 0 ? 0 : 1);
