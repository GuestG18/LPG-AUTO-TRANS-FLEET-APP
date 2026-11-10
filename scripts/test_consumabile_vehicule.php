<?php
declare(strict_types=1);

/**
 * Teste Consumabile — etapa 2: sistemele de fluide ale vehiculelor (revizie, telemetrie, nivel,
 * consum observat, anomalii) si legarea completarilor / schimburilor din stoc.
 *
 *   php scripts/test_consumabile_vehicule.php
 *
 * Partea de calcul e pura (fara baza). Partea de integrare ruleaza intr-o tranzactie ANULATA.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/includes/helpers.php';
require_once $root . '/htdocs/services/AutoComponentCatalogService.php';
require_once $root . '/htdocs/services/ConsumabileStockService.php';
require_once $root . '/htdocs/services/VehicleFluidService.php';
require_once $root . '/htdocs/models/ReviziiModel.php';

$passed = 0;
$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  ok   $name\n";
    } else {
        $failed++;
        echo "  FAIL $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}
function blocked(callable $fn, string $contains): bool
{
    try {
        $fn();
    } catch (InvalidArgumentException $exception) {
        return str_contains($exception->getMessage(), $contains);
    }

    return false;
}

$now = new DateTimeImmutable('2026-11-10 12:00:00');
$fresh = '2026-11-10 08:00:00';
$system = static fn (array $extra = []): array => $extra + [
    'id' => 1, 'vehicle_id' => 1, 'sistem' => 'motor', 'denumire' => 'Motor', 'um' => 'L', 'capacitate_nominala' => '38',
    'interval_km' => null, 'interval_luni' => null, 'interval_ore' => null, 'sursa_km' => 'km_can', 'sursa_ore' => 'none',
    'avertizare_procent' => 90, 'verificare_zile' => null, 'prag_consum' => null, 'critic' => 0, 'circuit_inchis' => 0,
    'referinta_data' => null, 'referinta_km_can' => null, 'referinta_km_gps' => null,
];
$event = static fn (string $tip, string $at, array $extra = []): array => $extra + [
    'tip' => $tip, 'efectuat_la' => $at, 'nivel_status' => null, 'nivel_valoare' => null, 'nivel_unitate' => null,
    'cantitate' => null, 'pana_la_reper' => 0, 'km_can' => null, 'km_gps' => null,
];
$noHours = ['value' => null, 'source' => 'none', 'note' => ''];

echo "[Calcul revizie (exemplul 5.1)]\n";
$s = $system(['interval_km' => '30000', 'referinta_data' => '2026-06-01 10:00:00', 'referinta_km_can' => '150000']);
$r = VehicleFluidService::evaluate($s, [], ['can' => ['km' => 175000.0, 'at' => $fresh]], $noHours, $now);
check('km parcurși 25.000', ($r['usage']['km']['used'] ?? null) == 25000);
check('km rămași 5.000', ($r['usage']['km']['remaining'] ?? null) == 5000);
check('interval utilizat 83,33%', ($r['usage']['km']['pct'] ?? null) == 83.33);
check('status normal sub pragul de 90%', $r['status'] === 'normal', $r['status']);
$r = VehicleFluidService::evaluate($s, [], ['can' => ['km' => 178000.0, 'at' => $fresh]], $noHours, $now);
check('93% → revizie apropiată', $r['status'] === 'revizie_apropiata', $r['status']);
$r = VehicleFluidService::evaluate($s, [], ['can' => ['km' => 181000.0, 'at' => $fresh]], $noHours, $now);
check('103% → revizie depășită', $r['status'] === 'revizie_depasita' && $r['usage']['km']['remaining'] == -1000);
$months = VehicleFluidService::evaluate($system(['interval_luni' => '12', 'sursa_km' => 'none', 'referinta_data' => '2025-10-01 00:00:00']), [], [], $noHours, $now);
check('interval în timp: 13 luni din 12 → depășit', $months['status'] === 'revizie_depasita' && $months['usage']['months']['due'] === '2026-10-01');

echo "\n[Telemetrie: fallback, învechită, indisponibilă]\n";
$s = $system(['interval_km' => '30000', 'referinta_data' => '2026-06-01 10:00:00', 'referinta_km_gps' => '150500']);
$r = VehicleFluidService::evaluate($s, [], ['sas' => ['km' => 160500.0, 'at' => $fresh]], $noHours, $now);
check('KM CAN indisponibil → fallback GPS declarat', $r['telemetry']['fallback'] === true && $r['usage']['km']['source'] === 'km_gps' && $r['usage']['km']['used'] == 10000);
$r = VehicleFluidService::evaluate($s, [], [], $noHours, $now);
check('fără nicio citire → telemetrie indisponibilă, fără km inventați', $r['telemetry']['state'] === 'indisponibil' && $r['usage']['km'] === null && $r['status'] === 'date_insuficiente');
$r = VehicleFluidService::evaluate($system(['interval_km' => '30000', 'referinta_data' => '2026-06-01 10:00:00', 'referinta_km_can' => '150000']), [], ['can' => ['km' => 160000.0, 'at' => '2026-11-03 01:00:00']], $noHours, $now);
check('date SAS de acum 7 zile → telemetrie învechită', $r['telemetry']['state'] === 'invechit');
$r = VehicleFluidService::evaluate($system(['interval_km' => '30000']), [], ['can' => ['km' => 160000.0, 'at' => $fresh]], $noHours, $now);
check('fără referință (ultimul schimb) → date insuficiente', $r['status'] === 'date_insuficiente' && str_contains($r['action'], 'referința'));

echo "\n[Nivel: doar măsurat]\n";
$r = VehicleFluidService::evaluate($system(['verificare_zile' => '14']), [], ['can' => ['km' => 1.0, 'at' => $fresh]], $noHours, $now);
check('nivel necunoscut → fără nivel fictiv și fără estimare', $r['level']['state'] === 'necunoscut' && $r['level']['value'] === null && $r['consumption']['estimated_missing'] === null);
check('fără verificare și interval de verificare setat → verificare necesară', $r['status'] === 'verificare_necesara');
$low = [$event('verificare', '2026-11-09 08:00:00', ['nivel_status' => 'sub_minim'])];
$r = VehicleFluidService::evaluate($system(['verificare_zile' => '14']), $low, [], $noHours, $now);
check('nivel sub minim confirmat → completare necesară', $r['status'] === 'completare_necesara' && $r['level']['state'] === 'masurat');
$resolved = array_merge($low, [$event('completare', '2026-11-09 10:00:00', ['cantitate' => '2', 'pana_la_reper' => 1])]);
$r = VehicleFluidService::evaluate($system(['verificare_zile' => '14']), $resolved, [], $noHours, $now);
check('după completare la reper → problema rezolvată (status actualizat)', $r['status'] === 'normal' && $r['level']['state'] === 'completat_la_reper', $r['status']);

echo "\n[Consum observat și anomalii]\n";
$history = [
    $event('schimb_complet', '2026-09-01 08:00:00', ['cantitate' => '38', 'pana_la_reper' => 1, 'km_can' => '100000']),
    $event('completare', '2026-09-20 08:00:00', ['cantitate' => '2', 'pana_la_reper' => 1, 'km_can' => '102000']),
    $event('completare', '2026-10-10 08:00:00', ['cantitate' => '2', 'pana_la_reper' => 1, 'km_can' => '104000']),
];
$r = VehicleFluidService::evaluate($system(), $history, ['can' => ['km' => 105000.0, 'at' => $fresh]], $noHours, $now);
check('consum observat = 4 L / 4.000 km × 1.000 = 1 L/1.000 km', ($r['consumption']['observed'] ?? null) == 1.0);
check('încredere medie (2 completări, 4.000 km)', $r['consumption']['confidence'] === 'mediu');
check('lipsă estimată 1 L (estimat, separat de nivelul măsurat)', ($r['consumption']['estimated_missing'] ?? null) == 1.0 && $r['level']['state'] === 'completat_la_reper');
$noRef = [$history[0], $event('completare', '2026-09-20 08:00:00', ['cantitate' => '2', 'pana_la_reper' => 0, 'km_can' => '102000'])];
$r = VehicleFluidService::evaluate($system(), $noRef, ['can' => ['km' => 105000.0, 'at' => $fresh]], $noHours, $now);
check('completări fără reper comparabil → fără consum calculat', $r['consumption']['observed'] === null);
$r = VehicleFluidService::evaluate($system(['prag_consum' => '0.5']), $history, ['can' => ['km' => 105000.0, 'at' => $fresh]], $noHours, $now);
check('consum peste pragul configurat → consum anormal', $r['status'] === 'consum_anormal');
$closed = [
    $event('completare', '2026-10-01 08:00:00', ['cantitate' => '0.5']),
    $event('completare', '2026-10-25 08:00:00', ['cantitate' => '0.5']),
];
$r = VehicleFluidService::evaluate($system(['sistem' => 'servodirectie', 'circuit_inchis' => 1, 'critic' => 1]), $closed, [], $noHours, $now);
check('circuit închis: completări repetate → pierdere suspectată', $r['status'] === 'consum_anormal');
check('fluid critic → recomandare de inspecție, nu doar completare', str_contains($r['action'], 'Inspecție'));

echo "\n[Ore: motor ≠ pompă]\n";
$pto = $system(['sistem' => 'hidraulic_pompa', 'sursa_km' => 'none', 'sursa_ore' => 'ore_pto', 'interval_ore' => '1000', 'referinta_data' => '2026-06-01 00:00:00']);
$r = VehicleFluidService::evaluate($pto, [], [], ['value' => 950.0, 'source' => 'ore_pto', 'note' => ''], $now);
check('interval pe ore pompă: 950 / 1.000 h → revizie apropiată', $r['status'] === 'revizie_apropiata' && $r['usage']['hours']['source'] === 'ore_pto');
$r = VehicleFluidService::evaluate($pto, [], [], ['value' => null, 'source' => 'ore_pto', 'note' => 'x'], $now);
check('ore pompă indisponibile → date insuficiente (nu se folosesc orele motorului)', $r['status'] === 'date_insuficiente' && $r['usage']['hours'] === null);

// ---------------------------------------------------------------------------------------------
echo "\n[Integrare cu stocul — tranzacție anulată]\n";
$db = get_pdo();
$stock = new ConsumabileStockService($db);
$stock->ensureSchema();
AutoComponentCatalogService::taxonomy($db);
new ReviziiModel($db);
$fluids = new VehicleFluidService($db);
// Vehicule fara sistemul „motor” configurat (testul nu atinge configuratiile reale).
$vehicles = array_map('intval', $db->query("SELECT id FROM vehicule WHERE status = 'activ' AND nr_inmatriculare <> 'STOC-ANVELOPE'
    AND id NOT IN (SELECT vehicle_id FROM vehicule_sisteme_fluide WHERE sistem = 'motor') ORDER BY id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN));
$key = static fn (): string => 'test2' . bin2hex(random_bytes(10));
$db->beginTransaction();
try {
    $oil = $stock->saveProduct(['denumire' => 'TEST2 Ulei 10W-40', 'tip_fluid' => 'ulei_motor', 'um' => 'L', 'interval_km' => '30000', 'interval_luni' => '12'], null, null);
    $other = $stock->saveProduct(['denumire' => 'TEST2 Ulei transmisie', 'tip_fluid' => 'ulei_transmisie', 'um' => 'L'], null, null);
    $drum = $stock->saveContainer(['cod' => 'TEST2-BUT', 'tip' => 'butoi', 'capacitate' => '200', 'produs_id' => $oil, 'cantitate_initiala' => '200', 'idempotency_key' => $key()], null, null);
    $drum2 = $stock->saveContainer(['cod' => 'TEST2-BUT-TR', 'tip' => 'butoi', 'capacitate' => '50', 'produs_id' => $other, 'cantitate_initiala' => '50', 'idempotency_key' => $key()], null, null);
    $saved = $fluids->saveSystem(['vehicle_id' => $vehicles[0], 'sistem' => 'motor', 'produs_id' => $oil, 'capacitate_nominala' => '38', 'sursa_km' => 'km_can',
        'referinta_data' => '2026-05-01 08:00', 'referinta_km_can' => '100000', 'copy_vehicle_ids' => [$vehicles[1], $vehicles[2]]], null, null);
    $sys = $fluids->system($saved['id']);
    check('sistem creat; intervalele preluate din produs', (int) $sys['interval_km'] === 30000 && (int) $sys['interval_luni'] === 12);
    check('configurația copiată pe alte vehicule, fără referința primului', $saved['copied'] === 2 && $fluids->systems(['vehicle_ids' => [$vehicles[1]], 'sistem' => 'motor'])[0]['referinta_data'] === null);
    check('al doilea sistem „motor” pe același vehicul → blocat', blocked(fn () => $fluids->saveSystem(['vehicle_id' => $vehicles[0], 'sistem' => 'motor'], null, null), 'deja'));

    $top = $stock->operate('completare', ['recipient_sursa_id' => $drum, 'vehicul_id' => $vehicles[0], 'sistem_id' => $saved['id'], 'cantitate' => '2', 'pana_la_reper' => '1', 'idempotency_key' => $key()], null);
    $ev = $db->prepare('SELECT * FROM vehicule_fluide_evenimente WHERE miscare_id = ?');
    $ev->execute([$top['movement']['id']]);
    $topEvent = $ev->fetch(PDO::FETCH_ASSOC);
    check('completarea din stoc creează evenimentul pe sistemul vehiculului', $topEvent !== false && $topEvent['tip'] === 'completare' && (int) $topEvent['pana_la_reper'] === 1);
    check('produs incompatibil cu sistemul → blocat', blocked(fn () => $stock->operate('completare', ['recipient_sursa_id' => $drum2, 'vehicul_id' => $vehicles[0], 'sistem_id' => $saved['id'], 'cantitate' => '1', 'idempotency_key' => $key()], null), 'nu este produsul configurat'));
    check('sistem al altui vehicul → blocat', blocked(fn () => $stock->operate('completare', ['recipient_sursa_id' => $drum, 'vehicul_id' => $vehicles[1], 'sistem_id' => $saved['id'], 'cantitate' => '1', 'idempotency_key' => $key()], null), 'nu aparține'));

    $change = $stock->operate('schimb_complet', ['recipient_sursa_id' => $drum, 'vehicul_id' => $vehicles[0], 'sistem_id' => $saved['id'], 'cantitate' => '38', 'idempotency_key' => $key()], null);
    $row = array_values(array_filter($fluids->statusRows(['vehicle_ids' => [$vehicles[0]]]), static fn ($x) => (int) $x['id'] === $saved['id']))[0];
    check('schimbul complet devine noua referință (separat de completare)', $row['reference']['origin'] === 'schimb' && $row['last_topup']['tip'] === 'schimb_complet');
    $stock->operate('stornare', ['referinta_id' => $change['movement']['id'], 'motiv' => 'greșeală test', 'idempotency_key' => $key()], null);
    $row = array_values(array_filter($fluids->statusRows(['vehicle_ids' => [$vehicles[0]]]), static fn ($x) => (int) $x['id'] === $saved['id']))[0];
    check('stornarea schimbului anulează evenimentul; referința revine la cea manuală', $row['reference']['origin'] === 'manual');

    $checkKey = $key();
    $a = $fluids->recordEvent(['sistem_id' => $saved['id'], 'tip' => 'verificare', 'nivel_status' => 'sub_minim', 'idempotency_key' => $checkKey], null);
    $b = $fluids->recordEvent(['sistem_id' => $saved['id'], 'tip' => 'verificare', 'nivel_status' => 'sub_minim', 'idempotency_key' => $checkKey], null);
    check('verificare trimisă de două ori → o singură înregistrare', $a['id'] === $b['id'] && $b['duplicate'] === true);
    $row = array_values(array_filter($fluids->statusRows(['vehicle_ids' => [$vehicles[0]]]), static fn ($x) => (int) $x['id'] === $saved['id']))[0];
    check('verificare sub minim → completare necesară', $row['status'] === 'completare_necesara' || $row['status'] === 'revizie_depasita', $row['status']);
    check('verificare fără nivel → blocată', blocked(fn () => $fluids->recordEvent(['sistem_id' => $saved['id'], 'tip' => 'verificare', 'idempotency_key' => $key()], null), 'nivelul'));
    $fluids->cancelEvent($a['id'], 'citire greșită', null);
    check('anulare verificare manuală (rămâne în istoric)', count(array_filter($fluids->events($saved['id']), static fn ($e) => $e['anulat_la'] !== null)) >= 1);
    check('evenimentul din stoc nu se anulează manual', blocked(fn () => $fluids->cancelEvent((int) $topEvent['id'], 'x', null), 'stornează'));
    $hours = $fluids->hoursSince(['vehicle_id' => $vehicles[0], 'sursa_ore' => 'ore_motor'], '1990-01-01 00:00:00', $now);
    check('ore motor fără acoperire SAS → indisponibil, nu inventat', $hours['value'] === null && $hours['note'] !== '', json_encode($hours));
    check('intervalul pe ore fără sursă de ore → blocat', blocked(fn () => $fluids->saveSystem(['vehicle_id' => $vehicles[0], 'sistem' => 'transmisie', 'interval_ore' => '100', 'sursa_ore' => 'none'], null, null), 'sursă de ore'));
} catch (Throwable $exception) {
    check('fără excepții neașteptate', false, $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
} finally {
    $db->rollBack();
}

echo "\n$passed ok, $failed FAIL\n";
exit($failed > 0 ? 1 : 0);
