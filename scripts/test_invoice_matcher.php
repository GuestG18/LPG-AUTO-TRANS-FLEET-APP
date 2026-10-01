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

echo "\nPotrivire unica\n";
$trips = [
    trip(1, '2026-09-01', '2026-09-05', 10, 100),
    trip(2, '2026-09-10', '2026-09-12', 11, 101),
];
$r = $matcher->decide(['tip' => 'trece', 'data_document' => '2026-09-03', 'vehicle_id' => 10], $trips);
check('Trecere, vehicul 10, 03.09 -> cursa 1', $r['status'] === 'asociata_auto' && $r['cursa_id'] === 1, describe($r));
$r = $matcher->decide(['tip' => 'cazare', 'data_document' => '2026-09-11', 'driver_id' => 101], $trips);
check('Cazare, sofer 101, 11.09 -> cursa 2', $r['status'] === 'asociata_auto' && $r['cursa_id'] === 2, describe($r));

echo "\nAcelasi vehicul, doua curse cu soferi diferiti\n";
$trips = [
    trip(3, '2026-09-01', '2026-09-03', 20, 200),
    trip(4, '2026-09-01', '2026-09-03', 20, 201),
];
$r = $matcher->decide(['tip' => 'cazare', 'data_document' => '2026-09-02', 'driver_id' => 201, 'vehicle_id' => 20], $trips);
check('Cazare alege dupa sofer -> cursa 4', $r['status'] === 'asociata_auto' && $r['cursa_id'] === 4, describe($r));
$r = $matcher->decide(['tip' => 'trece', 'data_document' => '2026-09-02', 'vehicle_id' => 20], $trips);
check('Trecere fara sofer -> de_verificat cu 2 candidati', $r['status'] === 'de_verificat' && count($r['candidates']) === 2, describe($r));
$r = $matcher->decide(['tip' => 'trece', 'data_document' => '2026-09-02', 'vehicle_id' => 20, 'driver_id' => 200], $trips);
check('Trecere cu sofer -> soferul restrange la cursa 3', $r['status'] === 'asociata_auto' && $r['cursa_id'] === 3, describe($r));
$r = $matcher->decide(['tip' => 'trece', 'data_document' => '2026-09-02', 'vehicle_id' => 20, 'driver_id' => 999], $trips);
check('Trecere cu sofer strain -> nu restrange, raman 2', $r['status'] === 'de_verificat' && count($r['candidates']) === 2, describe($r));
$candidate = $r['candidates'][0] ?? [];
check('candidatul are id, perioada, numar, sofer, motiv', isset($candidate['id'], $candidate['data_inceput'], $candidate['data_sfarsit'], $candidate['nr_inmatriculare'], $candidate['sofer'], $candidate['motiv']));

echo "\nSchimbare de vehicul / sofer prin segmente\n";
$trips = [
    trip(5, '2026-09-01', '2026-09-06', 30, 300, [
        segment(1, '2026-09-01', '2026-09-03', 30, 300),
        segment(2, '2026-09-04', '2026-09-06', 31, 301),
    ]),
];
$r = $matcher->decide(['tip' => 'port', 'data_document' => '2026-09-05', 'vehicle_id' => 31], $trips);
check('Port pe vehiculul din segmentul 2 -> cursa 5', $r['status'] === 'asociata_auto' && $r['cursa_id'] === 5, describe($r));
$r = $matcher->decide(['tip' => 'port', 'data_document' => '2026-09-05', 'vehicle_id' => 30], $trips);
check('Port pe vehiculul segmentului 1, dupa predare -> neasociata', $r['status'] === 'neasociata', describe($r));
$r = $matcher->decide(['tip' => 'cazare', 'data_document' => '2026-09-02', 'driver_id' => 301], $trips);
check('Cazare a soferului 2 inainte sa preia -> neasociata', $r['status'] === 'neasociata', describe($r));
$r = $matcher->decide(['tip' => 'diurna', 'data_document' => '2026-09-04', 'driver_id' => 301], $trips);
check('Diurna soferului 2 in segmentul lui -> cursa 5', $r['status'] === 'asociata_auto' && $r['cursa_id'] === 5, describe($r));
$trips = [
    trip(6, '2026-09-01', '2026-09-04', 40, 400, [
        segment(1, '2026-09-01', '2026-09-02', 40, 400),
        segment(2, '2026-09-02', '2026-09-04', 41, 401),
    ]),
];
$r1 = $matcher->decide(['tip' => 'trece', 'data_document' => '2026-09-02', 'vehicle_id' => 40], $trips);
$r2 = $matcher->decide(['tip' => 'trece', 'data_document' => '2026-09-02', 'vehicle_id' => 41], $trips);
check('Ziua predarii: ambele vehicule se potrivesc', $r1['cursa_id'] === 6 && $r2['cursa_id'] === 6, describe($r1) . ' | ' . describe($r2));

echo "\nMarginile perioadei si toleranta\n";
$trips = [trip(7, '2026-09-10', '2026-09-12', 50, 500)];
$r = $matcher->decide(['tip' => 'trece', 'data_document' => '2026-09-10', 'vehicle_id' => 50], $trips);
check('Data = prima zi a cursei -> asociata', $r['cursa_id'] === 7, describe($r));
$r = $matcher->decide(['tip' => 'trece', 'data_document' => '2026-09-12', 'vehicle_id' => 50], $trips);
check('Data = ultima zi a cursei -> asociata', $r['cursa_id'] === 7, describe($r));
$r = $matcher->decide(['tip' => 'trece', 'data_document' => '2026-09-13', 'vehicle_id' => 50], $trips);
check('Trecere a doua zi dupa cursa (toleranta 0) -> neasociata', $r['status'] === 'neasociata', describe($r));
$r = $matcher->decide(['tip' => 'cazare', 'data_document' => '2026-09-13', 'driver_id' => 500], $trips);
check('Cazare a doua zi dupa cursa (toleranta +1) -> asociata', $r['status'] === 'asociata_auto' && $r['cursa_id'] === 7, describe($r));
$r = $matcher->decide(['tip' => 'cazare', 'data_document' => '2026-09-14', 'driver_id' => 500], $trips);
check('Cazare la doua zile dupa cursa -> neasociata', $r['status'] === 'neasociata', describe($r));
$r = $matcher->decide(['tip' => 'cazare', 'data_document' => '2026-09-09', 'driver_id' => 500], $trips);
check('Cazare cu o zi inainte (toleranta inainte 0) -> neasociata', $r['status'] === 'neasociata', describe($r));
$trips = [
    trip(8, '2026-09-10', '2026-09-12', 50, 500),
    trip(9, '2026-09-13', '2026-09-15', 51, 500),
];
$r = $matcher->decide(['tip' => 'cazare', 'data_document' => '2026-09-13', 'driver_id' => 500], $trips);
check('Cursa care contine data bate cursa prinsa prin toleranta -> 9', $r['status'] === 'asociata_auto' && $r['cursa_id'] === 9, describe($r));
$custom = new InvoiceTripMatcher(null, ['trece' => ['before' => 2]]);
$r = $custom->decide(['tip' => 'trece', 'data_document' => '2026-09-08', 'vehicle_id' => 50], [trip(10, '2026-09-10', '2026-09-12', 50, 500)]);
check('Toleranta configurabila (trece: 2 zile inainte) -> asociata', $r['cursa_id'] === 10, describe($r));

echo "\nFara potrivire / date lipsa\n";
$trips = [trip(11, '2026-09-01', '2026-09-05', 60, 600)];
$r = $matcher->decide(['tip' => 'trece', 'data_document' => '2026-09-03', 'vehicle_id' => 61], $trips);
check('Alt vehicul -> neasociata', $r['status'] === 'neasociata' && $r['cursa_id'] === null, describe($r));
$r = $matcher->decide(['tip' => 'trece', 'data_document' => '2026-09-03', 'vehicle_id' => 60], []);
check('Nicio cursa in baza -> neasociata', $r['status'] === 'neasociata', describe($r));
$r = $matcher->decide(['tip' => 'trece', 'data_document' => '2026-09-03', 'driver_id' => 600], $trips);
check('Trecere fara numar -> de_verificat', $r['status'] === 'de_verificat' && str_contains($r['reason'], 'Lipseste numarul'), describe($r));
$r = $matcher->decide(['tip' => 'trece', 'data_document' => '2026-09-03', 'nr_inmatriculare_extras' => 'XX99ZZZ'], $trips);
check('Trecere cu numar negasit -> de_verificat cu numarul in motiv', $r['status'] === 'de_verificat' && str_contains($r['reason'], 'XX99ZZZ'), describe($r));
$r = $matcher->decide(['tip' => 'cazare', 'data_document' => '2026-09-03', 'vehicle_id' => 60], $trips);
check('Cazare fara sofer -> de_verificat', $r['status'] === 'de_verificat' && str_contains($r['reason'], 'Lipseste soferul'), describe($r));
$r = $matcher->decide(['tip' => 'trece', 'data_document' => null, 'vehicle_id' => 60], $trips);
check('Fara data -> de_verificat', $r['status'] === 'de_verificat' && str_contains($r['reason'], 'data'), describe($r));
$r = $matcher->decide(['tip' => 'necunoscut', 'data_document' => '2026-09-03', 'vehicle_id' => 60], $trips);
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
