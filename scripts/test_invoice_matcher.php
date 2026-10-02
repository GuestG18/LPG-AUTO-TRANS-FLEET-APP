<?php
declare(strict_types=1);

/**
 * Teste pentru asocierea automata factura -> cursa (InvoiceTripMatcher).
 *
 *   php scripts/test_invoice_matcher.php            teste pure + verificare pe baza locala
 *   php scripts/test_invoice_matcher.php --no-db    doar testele pure
 *
 * Testele pure lucreaza pe curse construite in memorie (decide()). Partea cu baza
 * de date e DOAR CITIRE: ia o cursa reala si verifica ca o factura cu numarul si
 * data ei o gaseste. Nu scrie nimic.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/models/InvoiceModel.php';
require_once $root . '/htdocs/services/InvoiceTripMatcher.php';

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

function trip(int $id, string $start, string $end, ?int $vehicleId, ?int $driverId, array $segments = []): array
{
    return [
        'id' => $id,
        'data_inceput' => $start,
        'data_sfarsit' => $end,
        'vehicle_id' => $vehicleId,
        'driver_id' => $driverId,
        'nr_inmatriculare' => $vehicleId !== null ? 'V' . $vehicleId : '',
        'sofer_nume' => $driverId !== null ? 'S' . $driverId : '',
        'segments' => $segments,
    ];
}

function segment(int $order, string $start, string $end, ?int $vehicleId, ?int $driverId): array
{
    return [
        'ordine' => $order,
        'data_inceput' => $start,
        'data_sfarsit' => $end,
        'vehicle_id' => $vehicleId,
        'driver_id' => $driverId,
        'nr_inmatriculare' => $vehicleId !== null ? 'V' . $vehicleId : '',
        'sofer_nume' => $driverId !== null ? 'S' . $driverId : '',
    ];
}

function describe(array $result): string
{
    return $result['status'] . ' / cursa=' . var_export($result['cursa_id'], true)
        . ' / candidati=' . implode(',', array_column($result['candidates'], 'id'))
        . ' / ' . $result['reason'];
}

$matcher = new InvoiceTripMatcher();

echo "\nNormalizari\n";
check('numar: "b-123 abc" -> B123ABC', InvoiceTripMatcher::normalizePlate('b-123 abc') === 'B123ABC');
check('numar: " TM 07 LPG " -> TM07LPG', InvoiceTripMatcher::normalizePlate(' TM 07 LPG ') === 'TM07LPG');
check('nume: "Andreiaș  Cătălin" -> andreias catalin', InvoiceTripMatcher::normalizePersonName('Andreiaș  Cătălin') === 'andreias catalin');
check('nume gol -> ""', InvoiceTripMatcher::normalizePersonName('  ') === '');

echo "\nO singura cursa in ziua documentului: se asociaza oricum\n";
$trips = [
    trip(1, '2026-09-01', '2026-09-05', 10, 100),
    trip(2, '2026-09-10', '2026-09-12', 11, 101),
];
$r = $matcher->decide(['tip' => 'trece', 'data_document' => '2026-09-03'], $trips);
check('Trecere fara numar, o singura cursa pe 03.09 -> cursa 1', $r['status'] === 'asociata_auto' && $r['cursa_id'] === 1, describe($r));
$r = $matcher->decide(['tip' => 'cazare', 'data_document' => '2026-09-11'], $trips);
check('Cazare fara sofer, o singura cursa pe 11.09 -> cursa 2', $r['status'] === 'asociata_auto' && $r['cursa_id'] === 2, describe($r));
$r = $matcher->decide(['tip' => 'trece', 'data_document' => '2026-09-03', 'vehicle_id' => 99, 'nr_inmatriculare_extras' => 'XX99ZZZ'], $trips);
check('Numarul de pe factura (alt vehicul / negasit) nu conteaza -> cursa 1', $r['status'] === 'asociata_auto' && $r['cursa_id'] === 1, describe($r));
$r = $matcher->decide(['tip' => 'cazare', 'data_document' => '2026-09-11', 'driver_id' => 999, 'sofer_extras' => 'Popescu Ion'], $trips);
check('Soferul de pe factura (alt sofer / negasit) nu conteaza -> cursa 2', $r['status'] === 'asociata_auto' && $r['cursa_id'] === 2, describe($r));

echo "\nMai multe curse in aceeasi zi: numarul / soferul de pe factura decid\n";
$trips = [
    trip(3, '2026-09-01', '2026-09-03', 20, 200),
    trip(4, '2026-09-02', '2026-09-02', 21, 201),
];
$trips[0]['sofer_nume'] = 'Popescu Ion';
$trips[1]['sofer_nume'] = 'Ionescu Maria';
$r = $matcher->decide(['tip' => 'trece', 'data_document' => '2026-09-02'], $trips);
check('2 curse pe 02.09, fara numar / sofer -> de_verificat cu 2 candidati', $r['status'] === 'de_verificat' && $r['cursa_id'] === null && count($r['candidates']) === 2, describe($r));
check('motivul spune cate curse sunt', str_contains($r['reason'], '2 curse'), describe($r));
$candidate = $r['candidates'][0] ?? [];
check('candidatul are id, perioada, numar, sofer, motiv', isset($candidate['id'], $candidate['data_inceput'], $candidate['data_sfarsit'], $candidate['nr_inmatriculare'], $candidate['sofer'], $candidate['motiv']));
$r = $matcher->decide(['tip' => 'service', 'data_document' => '2026-09-02', 'nr_inmatriculare_extras' => 'v-21'], $trips);
check('Service cu numarul citit (scris altfel) -> cursa 4', $r['status'] === 'asociata_auto' && $r['cursa_id'] === 4, describe($r));
$r = $matcher->decide(['tip' => 'service', 'data_document' => '2026-09-02', 'vehicle_id' => 20], $trips);
check('Service cu vehiculul ales din lista -> cursa 3', $r['status'] === 'asociata_auto' && $r['cursa_id'] === 3, describe($r));
$r = $matcher->decide(['tip' => 'cazare', 'data_document' => '2026-09-02', 'sofer_extras' => 'IONESCU MARIA'], $trips);
check('Cazare cu numele soferului -> cursa 4', $r['status'] === 'asociata_auto' && $r['cursa_id'] === 4, describe($r));
$r = $matcher->decide(['tip' => 'cazare', 'data_document' => '2026-09-02', 'driver_id' => 200], $trips);
check('Cazare cu soferul ales din lista -> cursa 3', $r['status'] === 'asociata_auto' && $r['cursa_id'] === 3, describe($r));
$r = $matcher->decide(['tip' => 'trece', 'data_document' => '2026-09-02', 'nr_inmatriculare_extras' => 'XX99ZZZ'], $trips);
check('Numar care nu e pe nicio cursa a zilei -> ignorat, de_verificat cu 2 + motiv', $r['status'] === 'de_verificat' && count($r['candidates']) === 2 && str_contains($r['reason'], 'nu apare'), describe($r));
$r = $matcher->decide(['tip' => 'cazare', 'data_document' => '2026-09-02', 'nr_inmatriculare_extras' => 'XX99ZZZ', 'sofer_extras' => 'Popescu Ion'], $trips);
check('Numar gresit dar sofer bun -> soferul decide, cursa 3', $r['status'] === 'asociata_auto' && $r['cursa_id'] === 3, describe($r));
$r = $matcher->decide(['tip' => 'trece', 'data_document' => '2026-09-03'], $trips);
check('In 03.09 ramane doar cursa 3 -> asociata', $r['status'] === 'asociata_auto' && $r['cursa_id'] === 3, describe($r));

echo "\nCazul real: factura de cazare #161 (mai multe curse in ziua respectiva)\n";
$real = [
    ['id' => 255, 'data_inceput' => '2026-08-10', 'data_sfarsit' => '2026-08-12', 'vehicle_id' => 1, 'driver_id' => 1, 'nr_inmatriculare' => 'B 325 NET', 'sofer_nume' => 'Voinea Beniamin', 'segments' => []],
    ['id' => 260, 'data_inceput' => '2026-08-12', 'data_sfarsit' => '2026-08-13', 'vehicle_id' => 2, 'driver_id' => 2, 'nr_inmatriculare' => 'B 295 NET', 'sofer_nume' => 'Andreias Catalin', 'segments' => []],
    ['id' => 261, 'data_inceput' => '2026-08-12', 'data_sfarsit' => '2026-08-13', 'vehicle_id' => 3, 'driver_id' => 20, 'nr_inmatriculare' => 'B 275 NET', 'sofer_nume' => 'Beznea Cristian-Gheorghe', 'segments' => []],
    ['id' => 262, 'data_inceput' => '2026-08-12', 'data_sfarsit' => '2026-08-12', 'vehicle_id' => 4, 'driver_id' => 24, 'nr_inmatriculare' => 'B 999 NET', 'sofer_nume' => 'Beznea Ion', 'segments' => []],
];
$r = $matcher->decide(['tip' => 'cazare', 'data_document' => '2026-08-12', 'sofer_extras' => 'Beznea Christian'], $real);
check('"Beznea Christian" -> cursa 261 (Beznea Cristian-Gheorghe), nu Beznea Ion', $r['status'] === 'asociata_auto' && $r['cursa_id'] === 261, describe($r));
$r = $matcher->decide(['tip' => 'cazare', 'data_document' => '2026-08-12', 'sofer_extras' => 'Beznea'], $real);
check('Doar numele de familie, doi Beznea in acea zi -> de_verificat cu 2', $r['status'] === 'de_verificat' && count($r['candidates']) === 2, describe($r));

echo "\nPotrivirea numelor\n";
check('"Beznea Christian" ~ "Beznea Cristian-Gheorghe"', InvoiceTripMatcher::namesMatch('Beznea Christian', 'Beznea Cristian-Gheorghe'));
check('"CRISTIAN BEZNEA" (ordine inversa) ~ "Beznea Cristian-Gheorghe"', InvoiceTripMatcher::namesMatch('CRISTIAN BEZNEA', 'Beznea Cristian-Gheorghe'));
check('"Andreiaș Cătălin" ~ "Andreias Catalin"', InvoiceTripMatcher::namesMatch('Andreiaș Cătălin', 'Andreias Catalin'));
check('"Beznea Cristian" !~ "Beznea Ion"', !InvoiceTripMatcher::namesMatch('Beznea Cristian', 'Beznea Ion'));
check('"Ion" !~ "Ian" (cuvinte scurte: fara toleranta)', !InvoiceTripMatcher::namesMatch('Ion', 'Ian'));
check('nume gol !~ nimic', !InvoiceTripMatcher::namesMatch('', 'Beznea Ion'));

echo "\nSegmente: candidatul arata cine era pe cursa la data documentului\n";
$trips = [
    trip(5, '2026-09-01', '2026-09-06', 30, 300, [
        segment(1, '2026-09-01', '2026-09-03', 30, 300),
        segment(2, '2026-09-04', '2026-09-06', 31, 301),
    ]),
];
$r = $matcher->decide(['tip' => 'port', 'data_document' => '2026-09-05'], $trips);
check('Port pe 05.09 -> cursa 5, cu vehiculul / soferul segmentului 2', $r['cursa_id'] === 5 && ($r['candidates'][0]['nr_inmatriculare'] ?? '') === 'V31' && ($r['candidates'][0]['sofer'] ?? '') === 'S301', describe($r));

echo "\nMarginile perioadei si toleranta\n";
$trips = [trip(7, '2026-09-10', '2026-09-12', 50, 500)];
$r = $matcher->decide(['tip' => 'trece', 'data_document' => '2026-09-10'], $trips);
check('Data = prima zi a cursei -> asociata', $r['cursa_id'] === 7, describe($r));
$r = $matcher->decide(['tip' => 'trece', 'data_document' => '2026-09-12'], $trips);
check('Data = ultima zi a cursei -> asociata', $r['cursa_id'] === 7, describe($r));
$r = $matcher->decide(['tip' => 'trece', 'data_document' => '2026-09-13'], $trips);
check('Trecere a doua zi dupa cursa (toleranta 0) -> neasociata', $r['status'] === 'neasociata', describe($r));
$r = $matcher->decide(['tip' => 'cazare', 'data_document' => '2026-09-13'], $trips);
check('Cazare a doua zi dupa cursa (toleranta +1) -> asociata', $r['status'] === 'asociata_auto' && $r['cursa_id'] === 7, describe($r));
$r = $matcher->decide(['tip' => 'cazare', 'data_document' => '2026-09-14'], $trips);
check('Cazare la doua zile dupa cursa -> neasociata', $r['status'] === 'neasociata', describe($r));
$r = $matcher->decide(['tip' => 'cazare', 'data_document' => '2026-09-09'], $trips);
check('Cazare cu o zi inainte (toleranta inainte 0) -> neasociata', $r['status'] === 'neasociata', describe($r));
$trips = [
    trip(8, '2026-09-10', '2026-09-12', 50, 500),
    trip(9, '2026-09-13', '2026-09-15', 51, 501),
];
$r = $matcher->decide(['tip' => 'cazare', 'data_document' => '2026-09-13'], $trips);
check('Cursa care contine data bate cursa prinsa prin toleranta -> 9', $r['status'] === 'asociata_auto' && $r['cursa_id'] === 9, describe($r));
$custom = new InvoiceTripMatcher(null, ['trece' => ['before' => 2]]);
$r = $custom->decide(['tip' => 'trece', 'data_document' => '2026-09-08'], [trip(10, '2026-09-10', '2026-09-12', 50, 500)]);
check('Toleranta configurabila (trece: 2 zile inainte) -> asociata', $r['cursa_id'] === 10, describe($r));

echo "\nFara potrivire / date lipsa\n";
$trips = [trip(11, '2026-09-01', '2026-09-05', 60, 600)];
$r = $matcher->decide(['tip' => 'trece', 'data_document' => '2026-09-20'], $trips);
check('Nicio cursa la data documentului -> neasociata', $r['status'] === 'neasociata' && $r['cursa_id'] === null, describe($r));
$r = $matcher->decide(['tip' => 'trece', 'data_document' => '2026-09-03'], []);
check('Nicio cursa in baza -> neasociata', $r['status'] === 'neasociata', describe($r));
$r = $matcher->decide(['tip' => 'trece', 'data_document' => null, 'vehicle_id' => 60], $trips);
check('Fara data -> de_verificat', $r['status'] === 'de_verificat' && str_contains($r['reason'], 'data'), describe($r));
$r = $matcher->decide(['tip' => 'necunoscut', 'data_document' => '2026-09-03'], $trips);
check('Tip necunoscut -> de_verificat', $r['status'] === 'de_verificat', describe($r));

// -----------------------------------------------------------------------------
// Verificare pe baza locala (doar citire)
// -----------------------------------------------------------------------------
if (!in_array('--no-db', $argv, true)) {
    echo "\nBaza locala (doar citire)\n";
    require_once $root . '/htdocs/config/config.php';
    require_once $root . '/htdocs/config/database.php';
    $db = get_pdo();
    $dbMatcher = new InvoiceTripMatcher($db);

    $sample = $db->query("
        SELECT c.id, c.data_inceput, v.nr_inmatriculare, s.nume AS sofer_nume
        FROM curse_dispecer c
        INNER JOIN vehicule v ON v.id = c.vehicle_id
        LEFT JOIN soferi s ON s.id = c.driver_id AND s.employment_status <> 'terminated'
        WHERE c.deleted_at IS NULL
        ORDER BY c.data_inceput DESC, c.id DESC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    if ($sample === false) {
        echo "  (nicio cursa in baza locala, sarit)\n";
    } else {
        $plate = strtolower(str_replace(' ', '-', (string) $sample['nr_inmatriculare']));
        check('numarul scris altfel ("' . $plate . '") se rezolva la un vehicul', $dbMatcher->resolveVehicleId($plate) !== null);

        $r = $dbMatcher->match(['tip' => 'trece', 'data_document' => (string) $sample['data_inceput'], 'nr_inmatriculare_extras' => $plate]);
        $ids = $r['cursa_id'] !== null ? [$r['cursa_id']] : array_column($r['candidates'], 'id');
        check('Trecere pe cursa reala #' . $sample['id'] . ' o gaseste', in_array((int) $sample['id'], array_map('intval', $ids), true), describe($r));

        if (!empty($sample['sofer_nume'])) {
            $parts = explode(' ', trim((string) $sample['sofer_nume']));
            $reversed = implode(' ', array_reverse($parts));
            check('soferul cu numele inversat ("' . $reversed . '") se rezolva', $dbMatcher->resolveDriverId($reversed) !== null);

            $r = $dbMatcher->match(['tip' => 'cazare', 'data_document' => (string) $sample['data_inceput'], 'sofer_extras' => $reversed]);
            $ids = $r['cursa_id'] !== null ? [$r['cursa_id']] : array_column($r['candidates'], 'id');
            check('Cazare pe cursa reala #' . $sample['id'] . ' o gaseste', in_array((int) $sample['id'], array_map('intval', $ids), true), describe($r));
        }
    }
}

echo "\n" . ($failed === 0 ? "\033[32m" : "\033[31m") . "Rezultat: $passed trecute, $failed picate\033[0m\n";
exit($failed === 0 ? 0 : 1);
