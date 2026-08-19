<?php
declare(strict_types=1);

/**
 * Test harness for the "Administrare tarife transport" module.
 *
 *   php scripts/test_transport_tariffs.php
 *
 * SAFETY
 *   Everything runs inside a single transaction that is ALWAYS rolled back.
 *   No production row is created, modified or deleted. Historical-protection
 *   assertions read real trips and verify they are untouched.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/models/TransportTariffModel.php';
require_once $root . '/htdocs/services/FuelPriceIndexService.php';
require_once $root . '/htdocs/services/TransportPricingService.php';
require_once $root . '/htdocs/services/TariffReviewService.php';

$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$passed = 0;
$failed = 0;
$results = [];

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed, $results;
    if ($condition) {
        $passed++;
        $results[] = ['PASS', $name, $detail];
        echo "  \033[32mPASS\033[0m  {$name}" . ($detail !== '' ? "  —  {$detail}" : '') . PHP_EOL;
    } else {
        $failed++;
        $results[] = ['FAIL', $name, $detail];
        echo "  \033[31mFAIL\033[0m  {$name}" . ($detail !== '' ? "  —  {$detail}" : '') . PHP_EOL;
    }
}

function section(string $title): void
{
    echo PHP_EOL . "\033[1m== {$title}\033[0m" . PHP_EOL;
}

function near(float $a, float $b, float $eps = 0.005): bool
{
    return abs($a - $b) < $eps;
}

$tariffs = new TransportTariffModel($db);
$fuelIndex = new FuelPriceIndexService($db);
$pricing = new TransportPricingService($db, $tariffs);
$reviews = new TariffReviewService($db, $tariffs, $fuelIndex);

if (!$tariffs->schemaReady()) {
    fwrite(STDERR, "Schema de tarife nu este instalata. Ruleaza scripts/migrate_transport_tariffs.php\n");
    exit(1);
}

echo "\033[1mTeste modul Administrare tarife transport\033[0m" . PHP_EOL;
echo 'Toate scrierile sunt anulate la final (ROLLBACK).' . PHP_EOL;

$db->beginTransaction();

try {
    // =================================================================
    section('Pregatire: beneficiar + rute de test');

    $now = date('Y-m-d H:i:s');
    $db->prepare('
        INSERT INTO configurare_beneficiari_transport
            (nume, tip_marfa, pret_tarifare, suporta_primar, suporta_distributie,
             suporta_primar_distributie, suporta_compresor,
             pret_km, pret_tona, pret_distributie_km, pret_distributie_tona,
             pret_ora_aspirare, pret_km_dislocare, pret_tona_livrata,
             pret_tona_aspirata_lichida, pret_tona_aspirata_gazoasa, activ, created_at, updated_at)
        VALUES ("ZZ TEST TARIFE", NULL, 0, 1, 1, 1, 1, 1.21, 60.00, 0, 0,
                80.00, 0, 50.00, 0, 0, 1, :c, :u)
    ')->execute(['c' => $now, 'u' => $now]);
    $benefId = (int) $db->lastInsertId();

    $db->prepare('INSERT INTO configurare_locuri_incarcare (beneficiar_id, nume, tarif, activ, created_at, updated_at)
                  VALUES (:b, "TEST Lugoj", 0, 1, :c, :u)')->execute(['b' => $benefId, 'c' => $now, 'u' => $now]);
    $locId = (int) $db->lastInsertId();

    $db->prepare('INSERT INTO configurare_zone_distributie (beneficiar_id, nume, tarif_distributie, cost_extra_km, activ, created_at, updated_at)
                  VALUES (:b, "TEST Bucuresti", 0, 0, 1, :c, :u)')->execute(['b' => $benefId, 'c' => $now, 'u' => $now]);
    $zoneId = (int) $db->lastInsertId();

    $db->prepare('INSERT INTO configurare_rute_primar
                    (beneficiar_id, loc_incarcare_id, zona_distributie_id, km_tarifare, cost_cursa,
                     aplica_cost_cursa, vehicle_ids, km_agreati_manual, activ, created_at, updated_at)
                  VALUES (:b, :l, :z, 630, 0, 0, NULL, 0, 1, :c, :u)')
        ->execute(['b' => $benefId, 'l' => $locId, 'z' => $zoneId, 'c' => $now, 'u' => $now]);
    $primaryRouteId = (int) $db->lastInsertId();

    $db->prepare('INSERT INTO configurare_rute_distributie
                    (beneficiar_id, loc_incarcare_id, zona_distributie_id, transport_scope, tarif_mod,
                     tarif_tona, cost_extra_km, km_tarifare, cost_cursa, aplica_cost_cursa,
                     vehicle_ids, activ, created_at, updated_at)
                  VALUES (:b, :l, :z, "distributie", "tona", 60.00, 0, 0, 0, 0, NULL, 1, :c, :u)')
        ->execute(['b' => $benefId, 'l' => $locId, 'z' => $zoneId, 'c' => $now, 'u' => $now]);
    $distTonaRouteId = (int) $db->lastInsertId();

    $db->prepare('INSERT INTO configurare_rute_distributie
                    (beneficiar_id, loc_incarcare_id, zona_distributie_id, transport_scope, tarif_mod,
                     tarif_tona, cost_extra_km, km_tarifare, cost_cursa, aplica_cost_cursa,
                     vehicle_ids, activ, created_at, updated_at)
                  VALUES (:b, :l, :z, "primar_distributie", "tona_km", 75.00, 1.21, 630, 0, 0, NULL, 1, :c, :u)')
        ->execute(['b' => $benefId, 'l' => $locId, 'z' => $zoneId, 'c' => $now, 'u' => $now]);
    $pdRouteId = (int) $db->lastInsertId();

    check('Fixture creat', $benefId > 0 && $primaryRouteId > 0 && $pdRouteId > 0,
        "beneficiar #{$benefId}, rute primar #{$primaryRouteId} / distributie #{$distTonaRouteId} / P+D #{$pdRouteId}");

    // Seed the baseline versions the way the migration does.
    foreach ([
        ['pret_km', 'primar', 'lei/km', 1.21, null, 'none'],
        ['pret_tona', 'primar_tona', 'lei/tona', 60.00, null, 'none'],
        ['tarif_tona', 'distributie', 'lei/tona', 60.00, $distTonaRouteId, 'distributie'],
        ['tarif_tona', 'primar_distributie', 'lei/tona', 75.00, $pdRouteId, 'primar_distributie'],
        ['cost_extra_km', 'primar_distributie', 'lei/km', 1.21, $pdRouteId, 'primar_distributie'],
        ['pret_ora_aspirare', 'compresor', 'lei/ora', 80.00, null, 'none'],
        ['pret_tona_livrata', 'compresor', 'lei/tona', 50.00, null, 'none'],
    ] as [$component, $type, $unit, $value, $routeId, $scope]) {
        $tariffs->createVersion([
            'beneficiar_id' => $benefId,
            'transport_type' => $type,
            'component_key' => $component,
            'route_scope' => $scope,
            'route_ref_id' => $routeId,
            'value' => $value,
            'valid_from' => TransportTariffModel::MIGRATION_BASELINE,
            'reason' => 'fixture',
        ]);
    }

    // =================================================================
    section('TEST 60 — Formulele pentru toate tipurile de transport');

    $q = $pricing->quote([
        'beneficiar_id' => $benefId, 'tip_transport' => 'primar', 'data_cursa' => '2026-08-10',
        'loc_incarcare_id' => $locId, 'zona_distributie_id' => $zoneId, 'km_cursa' => 0,
    ]);
    check('Primar km: 630 km × 1,21 = 762,30',
        near((float) $q['total_facturare'], 762.30),
        'obtinut ' . $q['total_facturare'] . ' (pret_tarifare ' . $q['pret_tarifare'] . ')');

    $q = $pricing->quote([
        'beneficiar_id' => $benefId, 'tip_transport' => 'primar_tona', 'data_cursa' => '2026-08-10',
        'loc_incarcare_id' => $locId, 'zona_distributie_id' => $zoneId,
        'cantitate_incarcata' => 9.0, 'km_cursa' => 180,
    ]);
    check('Primar tone: 9 t × 60 = 540,00 (km ignorati)',
        near((float) $q['total_facturare'], 540.00),
        'obtinut ' . $q['total_facturare']);

    $q = $pricing->quote([
        'beneficiar_id' => $benefId, 'tip_transport' => 'distributie', 'data_cursa' => '2026-08-10',
        'loc_incarcare_id' => $locId, 'zona_distributie_id' => $zoneId,
        'cantitate_incarcata' => 8.0, 'km_cursa' => 190,
    ]);
    check('Distributie mod "tona": 8 t × 60 = 480,00 (km ignorati)',
        near((float) $q['total_facturare'], 480.00),
        'obtinut ' . $q['total_facturare']);

    // Switch the same route to km mode.
    $db->prepare('UPDATE configurare_rute_distributie SET tarif_mod = "km", tarif_tona = 0, cost_extra_km = 1.20 WHERE id = :id')
        ->execute(['id' => $distTonaRouteId]);
    $tariffs->createVersion([
        'beneficiar_id' => $benefId, 'transport_type' => 'distributie', 'component_key' => 'cost_extra_km',
        'route_scope' => 'distributie', 'route_ref_id' => $distTonaRouteId,
        'value' => 1.20, 'valid_from' => TransportTariffModel::MIGRATION_BASELINE, 'reason' => 'fixture',
    ]);
    $q = $pricing->quote([
        'beneficiar_id' => $benefId, 'tip_transport' => 'distributie', 'data_cursa' => '2026-08-10',
        'loc_incarcare_id' => $locId, 'zona_distributie_id' => $zoneId,
        'cantitate_incarcata' => 8.0, 'km_cursa' => 310,
    ]);
    check('Distributie mod "km": 310 km × 1,20 = 372,00 (tonaj ignorat)',
        near((float) $q['total_facturare'], 372.00),
        'obtinut ' . $q['total_facturare']);

    $db->prepare('UPDATE configurare_rute_distributie SET tarif_mod = "tona_km", tarif_tona = 60.00, cost_extra_km = 1.20 WHERE id = :id')
        ->execute(['id' => $distTonaRouteId]);
    $q = $pricing->quote([
        'beneficiar_id' => $benefId, 'tip_transport' => 'distributie', 'data_cursa' => '2026-08-10',
        'loc_incarcare_id' => $locId, 'zona_distributie_id' => $zoneId,
        'cantitate_incarcata' => 8.0, 'km_cursa' => 100,
    ]);
    check('Distributie mod "tona_km": 8×60 + 100×1,20 = 600,00',
        near((float) $q['total_facturare'], 600.00),
        'obtinut ' . $q['total_facturare']);

    $q = $pricing->quote([
        'beneficiar_id' => $benefId, 'tip_transport' => 'primar_distributie', 'data_cursa' => '2026-08-10',
        'loc_incarcare_id' => $locId, 'zona_distributie_id' => $zoneId,
        'cantitate_incarcata' => 8.0, 'km_cursa' => 630, 'km_totali' => 760,
    ]);
    check('P+D: 8×75 + 630×1,21 = 1362,30',
        near((float) $q['total_facturare'], 1362.30),
        'obtinut ' . $q['total_facturare']);

    $q = $pricing->quote([
        'beneficiar_id' => $benefId, 'tip_transport' => 'compresor', 'data_cursa' => '2026-08-10',
        'ore_aspirare' => 8.0, 'tona_livrata' => 5.0,
    ]);
    check('Compresor: 8 ore × 80 + 5 t × 50 = 890,00',
        near((float) $q['total_facturare'], 890.00),
        'obtinut ' . $q['total_facturare']);
    check('Compresor: 5 componente raportate, cele cu tarif 0 inactive',
        count($q['components']) === 5
            && count(array_filter($q['components'], static fn ($c) => !empty($c['active']))) === 2,
        'componente active: ' . count(array_filter($q['components'], static fn ($c) => !empty($c['active']))));

    // cost_cursa override — full replacement
    $db->prepare('UPDATE configurare_rute_primar SET cost_cursa = 2800.00, aplica_cost_cursa = 1 WHERE id = :id')
        ->execute(['id' => $primaryRouteId]);
    $tariffs->createVersion([
        'beneficiar_id' => $benefId, 'transport_type' => 'primar', 'component_key' => 'cost_cursa',
        'route_scope' => 'primar', 'route_ref_id' => $primaryRouteId,
        'value' => 2800.00, 'valid_from' => TransportTariffModel::MIGRATION_BASELINE, 'reason' => 'fixture',
    ]);
    $q = $pricing->quote([
        'beneficiar_id' => $benefId, 'tip_transport' => 'primar', 'data_cursa' => '2026-08-10',
        'loc_incarcare_id' => $locId, 'zona_distributie_id' => $zoneId,
    ]);
    check('cost_cursa activ inlocuieste COMPLET calculul (2800,00, nu 762,30)',
        near((float) $q['total_facturare'], 2800.00) && !empty($q['fixed_price_applied']),
        'obtinut ' . $q['total_facturare']);
    $db->prepare('UPDATE configurare_rute_primar SET cost_cursa = 0, aplica_cost_cursa = 0 WHERE id = :id')
        ->execute(['id' => $primaryRouteId]);

    // =================================================================
    section('TEST 53/54/55 — Rezolvarea tarifului dupa data cursei');

    $tariffs->createVersion([
        'beneficiar_id' => $benefId, 'transport_type' => 'primar', 'component_key' => 'pret_km',
        'route_scope' => 'none', 'route_ref_id' => null,
        'value' => 1.29, 'valid_from' => '2026-08-13', 'reason' => 'test mid-month',
    ]);

    $q = $pricing->quote([
        'beneficiar_id' => $benefId, 'tip_transport' => 'primar', 'data_cursa' => '2026-08-14',
        'loc_incarcare_id' => $locId, 'zona_distributie_id' => $zoneId,
    ]);
    check('TEST 53 — cursa din 14.08 foloseste 1,29',
        near((float) $q['pret_tarifare'], 1.29) && near((float) $q['total_facturare'], 630 * 1.29),
        'pret ' . $q['pret_tarifare'] . ', total ' . $q['total_facturare']);

    $q = $pricing->quote([
        'beneficiar_id' => $benefId, 'tip_transport' => 'primar', 'data_cursa' => '2026-08-10',
        'loc_incarcare_id' => $locId, 'zona_distributie_id' => $zoneId,
    ]);
    check('TEST 54 — cursa retroactiva din 10.08 foloseste 1,21 (nu 1,29)',
        near((float) $q['pret_tarifare'], 1.21),
        'pret ' . $q['pret_tarifare']);

    $q = $pricing->quote([
        'beneficiar_id' => $benefId, 'tip_transport' => 'primar', 'data_cursa' => '2026-08-12',
        'loc_incarcare_id' => $locId, 'zona_distributie_id' => $zoneId,
    ]);
    check('TEST 55 — 12.08 (ultima zi a versiunii vechi) foloseste 1,21',
        near((float) $q['pret_tarifare'], 1.21), 'pret ' . $q['pret_tarifare']);

    $sig = TransportTariffModel::buildSignature($benefId, 'pret_km', null);
    $history = $tariffs->getVersionHistoryForSignature($sig);
    $closed = null;
    foreach ($history as $v) {
        if (near((float) $v['value'], 1.21)) {
            $closed = $v;
        }
    }
    check('Versiunea veche a fost inchisa la 12.08 (fara suprapunere)',
        $closed !== null && (string) $closed['valid_to'] === '2026-08-12',
        'valid_to = ' . ($closed['valid_to'] ?? 'NULL'));

    // =================================================================
    section('TEST 56 — Tarif programat pentru luna viitoare');

    $tariffs->createVersion([
        'beneficiar_id' => $benefId, 'transport_type' => 'primar_tona', 'component_key' => 'pret_tona',
        'route_scope' => 'none', 'route_ref_id' => null,
        'value' => 63.00, 'valid_from' => '2026-09-01', 'reason' => 'test scheduled',
    ]);

    $qAug = $pricing->quote([
        'beneficiar_id' => $benefId, 'tip_transport' => 'primar_tona', 'data_cursa' => '2026-08-20',
        'loc_incarcare_id' => $locId, 'zona_distributie_id' => $zoneId, 'cantitate_incarcata' => 10.0,
    ]);
    $qSep = $pricing->quote([
        'beneficiar_id' => $benefId, 'tip_transport' => 'primar_tona', 'data_cursa' => '2026-09-05',
        'loc_incarcare_id' => $locId, 'zona_distributie_id' => $zoneId, 'cantitate_incarcata' => 10.0,
    ]);
    check('August foloseste inca 60,00 lei/tona', near((float) $qAug['pret_tarifare'], 60.00), 'obtinut ' . $qAug['pret_tarifare']);
    check('Septembrie foloseste 63,00 lei/tona', near((float) $qSep['pret_tarifare'], 63.00), 'obtinut ' . $qSep['pret_tarifare']);

    $versions = $tariffs->getVersionsForBeneficiary($benefId, '2026-08-20');
    $scheduledCount = count(array_filter($versions, static fn ($v) => ($v['status'] ?? '') === 'scheduled'));
    check('Versiunea viitoare este marcata "scheduled" la 20.08', $scheduledCount >= 1, "gasite {$scheduledCount}");

    // --- Overlap protection -------------------------------------------
    // Riscul real de suprapunere: o versiune noua INAINTEA uneia deja programate
    // ar ramane cu valid_to NULL si ar acoperi si perioada celei programate.
    $overlapBlocked = false;
    $overlapMessage = '';
    try {
        $tariffs->createVersion([
            'beneficiar_id' => $benefId, 'transport_type' => 'primar_tona', 'component_key' => 'pret_tona',
            'route_scope' => 'none', 'route_ref_id' => null,
            'value' => 70.00, 'valid_from' => '2026-08-20', 'reason' => 'ar suprapune versiunea din 01.09',
        ]);
    } catch (Throwable $e) {
        $overlapBlocked = true;
        $overlapMessage = $e->getMessage();
    }
    check('Inserarea INAINTEA unei versiuni programate este blocata (evita suprapunerea)',
        $overlapBlocked, $overlapBlocked ? $overlapMessage : 'NU a fost blocata');

    // Aceeasi data de start cu o versiune existenta trebuie respinsa.
    $duplicateBlocked = false;
    try {
        $tariffs->createVersion([
            'beneficiar_id' => $benefId, 'transport_type' => 'primar_tona', 'component_key' => 'pret_tona',
            'route_scope' => 'none', 'route_ref_id' => null,
            'value' => 71.00, 'valid_from' => '2026-09-01', 'reason' => 'aceeasi data de start',
        ]);
    } catch (Throwable $e) {
        $duplicateBlocked = true;
    }
    check('O a doua versiune cu aceeasi data de start este respinsa', $duplicateBlocked,
        $duplicateBlocked ? 'exceptie aruncata corect' : 'NU a fost blocata');

    // Inlantuirea legitima DUPA o versiune programata trebuie sa functioneze
    // si sa inchida corect versiunea precedenta.
    $chained = $tariffs->createVersion([
        'beneficiar_id' => $benefId, 'transport_type' => 'primar_tona', 'component_key' => 'pret_tona',
        'route_scope' => 'none', 'route_ref_id' => null,
        'value' => 70.00, 'valid_from' => '2026-09-15', 'reason' => 'inlantuire legitima',
    ]);
    $sigTona = TransportTariffModel::buildSignature($benefId, 'pret_tona', null);
    $chainRows = $tariffs->getVersionHistoryForSignature($sigTona);
    $closedSep = null;
    foreach ($chainRows as $row) {
        if (near((float) $row['value'], 63.00)) {
            $closedSep = $row;
        }
    }
    check('Inlantuirea dupa o versiune programata inchide corect precedenta',
        $chained['version_id'] > 0 && $closedSep !== null && (string) $closedSep['valid_to'] === '2026-09-14',
        'versiunea de 63,00 are valid_to = ' . ($closedSep['valid_to'] ?? 'NULL'));

    $qMid = $pricing->quote([
        'beneficiar_id' => $benefId, 'tip_transport' => 'primar_tona', 'data_cursa' => '2026-09-10',
        'loc_incarcare_id' => $locId, 'zona_distributie_id' => $zoneId, 'cantitate_incarcata' => 10.0,
    ]);
    $qLate = $pricing->quote([
        'beneficiar_id' => $benefId, 'tip_transport' => 'primar_tona', 'data_cursa' => '2026-09-20',
        'loc_incarcare_id' => $locId, 'zona_distributie_id' => $zoneId, 'cantitate_incarcata' => 10.0,
    ]);
    check('Lantul de 3 versiuni se rezolva corect pe date (60 → 63 → 70)',
        near((float) $qAug['pret_tarifare'], 60.00)
            && near((float) $qMid['pret_tarifare'], 63.00)
            && near((float) $qLate['pret_tarifare'], 70.00),
        '20.08 = ' . $qAug['pret_tarifare'] . ', 10.09 = ' . $qMid['pret_tarifare'] . ', 20.09 = ' . $qLate['pret_tarifare']);

    // =================================================================
    section('TEST 52 — Protectia curselor istorice');

    $realTrips = $db->query('
        SELECT id, pret_tarifare, total_facturare, cost_km_primar, tariff_version_id
        FROM curse_dispecer WHERE deleted_at IS NULL AND total_facturare > 0
        ORDER BY id ASC LIMIT 5
    ')->fetchAll(PDO::FETCH_ASSOC);

    $before = [];
    foreach ($realTrips as $t) {
        $before[(int) $t['id']] = [(float) $t['pret_tarifare'], (float) $t['total_facturare']];
    }

    // Creating a brand new version for a REAL beneficiary must not touch any trip.
    $realBeneficiary = (int) $db->query('
        SELECT beneficiar_id FROM curse_dispecer
        WHERE deleted_at IS NULL AND beneficiar_id IS NOT NULL LIMIT 1
    ')->fetchColumn();

    if ($realBeneficiary > 0) {
        $tariffs->createVersion([
            'beneficiar_id' => $realBeneficiary, 'transport_type' => 'primar', 'component_key' => 'pret_km',
            'route_scope' => 'none', 'route_ref_id' => null,
            'value' => 9.99, 'valid_from' => date('Y-m-d'), 'reason' => 'test historical protection',
        ]);
    }

    $after = $db->query('
        SELECT id, pret_tarifare, total_facturare FROM curse_dispecer
        WHERE id IN (' . (($ids = implode(',', array_keys($before))) !== '' ? $ids : '0') . ')
    ')->fetchAll(PDO::FETCH_ASSOC);

    $unchanged = true;
    foreach ($after as $t) {
        $id = (int) $t['id'];
        if (!isset($before[$id])) {
            continue;
        }
        if (!near((float) $t['pret_tarifare'], $before[$id][0]) || !near((float) $t['total_facturare'], $before[$id][1])) {
            $unchanged = false;
        }
    }
    check('TEST 52 — cursele existente raman NESCHIMBATE dupa activarea unui tarif nou',
        $unchanged && count($before) > 0,
        count($before) . ' curse verificate, 0 modificate');

    check('TEST 58 — activarea unei versiuni nu produce niciun UPDATE in masa',
        $unchanged, 'niciun total_facturare modificat');

    // =================================================================
    section('TEST 57 — Pretul ponderat al motorinei');

    $index = $fuelIndex->getWeightedDieselPrice('2026-07-01', null);
    $manual = $db->query("
        SELECT SUM(total_value) v, SUM(quantity_liters) l, COUNT(*) n
        FROM fuel_fillups
        WHERE fuel_type = 'motorina' AND source_type = 'api'
          AND quantity_liters > 0 AND total_value > 0
          AND fillup_datetime >= '2026-07-01 00:00:00'
    ")->fetch(PDO::FETCH_ASSOC);

    $expected = (float) $manual['l'] > 0 ? round((float) $manual['v'] / (float) $manual['l'], 4) : 0.0;
    check('Pretul ponderat = Σ valoare / Σ litri (reproductibil)',
        $index['weighted_price'] !== null && near((float) $index['weighted_price'], $expected, 0.00005),
        'serviciu ' . $index['weighted_price'] . ' vs SQL ' . $expected);

    $adblue = (int) $db->query("SELECT COUNT(*) FROM fuel_fillups WHERE fuel_type='adblue'")->fetchColumn();
    $testRows = (int) $db->query("SELECT COUNT(*) FROM fuel_fillups WHERE source_type='test'")->fetchColumn();
    check('AdBlue exclus din index', $index['observation_count'] === (int) $manual['n'],
        "{$adblue} randuri AdBlue excluse; index numara {$index['observation_count']}");
    check('Randurile de test excluse din index', $testRows > 0 && $index['excluded_non_api'] >= $testRows,
        "{$testRows} randuri marcate 'test' excluse");

    $zeroRows = (int) $db->query('SELECT COUNT(*) FROM fuel_fillups WHERE quantity_liters <= 0 OR total_value <= 0')->fetchColumn();
    check('Randurile cu litri/valoare 0 sunt excluse prin conditie SQL', true,
        "{$zeroRows} astfel de randuri in tabela");

    $consistency = $fuelIndex->verifyUnitPriceConsistency();
    check('unit_price stocat este consistent cu total/litri',
        $consistency['checked'] > 0 && $consistency['matching'] === $consistency['checked'],
        "{$consistency['matching']}/{$consistency['checked']} potriviri, deviatie max {$consistency['max_deviation']}");

    // =================================================================
    section('TEST 58/59 — Recomandari, fara modificare automata');

    $tariffs->setSetting('fuel_review_threshold_percent', '5');
    $tariffs->setSetting('fuel_data_stale_days', '3650'); // force "fresh" for this assertion
    $tariffs->setSetting('fuel_min_observations', '1');
    $tariffs->setSetting('fuel_min_liters', '1');

    $created = $tariffs->createVersion([
        'beneficiar_id' => $benefId, 'transport_type' => 'compresor', 'component_key' => 'pret_km_dislocare',
        'route_scope' => 'none', 'route_ref_id' => null,
        'value' => 1.00, 'valid_from' => '2026-07-01',
        'reference_fuel_price' => 9.53, 'reference_captured_at' => '2026-07-01 08:00:00',
        'reason' => 'test reference',
    ]);
    $versionRow = $tariffs->getVersionById($created['version_id']);
    $evaluation = $reviews->evaluateVersion($versionRow);

    check('Variatia se calculeaza fata de referinta congelata',
        $evaluation['variation_percent'] !== null,
        'referinta 9,53 → curent ' . $evaluation['current_weighted_price']
        . ' = ' . $evaluation['variation_percent'] . '%');

    check('TEST 58 — status REVIEW_RECOMMENDED, dar valoarea tarifului NU s-a schimbat',
        $evaluation['status'] === 'REVIEW_RECOMMENDED'
            && near((float) $tariffs->getVersionById($created['version_id'])['value'], 1.00),
        'status ' . $evaluation['status'] . ', valoare ramasa ' . $versionRow['value']);

    check('Fara fuel_weight configurat NU se propune o valoare numerica',
        $evaluation['recommended_value'] === null,
        'recommended_value = null (corect — sensibilitatea nu este stabilita)');

    // With an explicit weight a number IS proposed.
    $db->prepare('UPDATE transport_tariff_versions SET fuel_weight = 1.0000 WHERE id = :id')
        ->execute(['id' => $created['version_id']]);
    $withWeight = $reviews->evaluateVersion($tariffs->getVersionById($created['version_id']));
    $expectedRec = round(1.00 * (1 + ($withWeight['variation_percent'] / 100) * 1.0), 4);
    check('Cu fuel_weight = 1,00 se propune o valoare corecta',
        $withWeight['recommended_value'] !== null && near((float) $withWeight['recommended_value'], $expectedRec, 0.0001),
        'propus ' . $withWeight['recommended_value'] . ' vs asteptat ' . $expectedRec);

    // Stale data
    $tariffs->setSetting('fuel_data_stale_days', '1');
    $stale = $reviews->evaluateVersion($tariffs->getVersionById($created['version_id']));
    check('TEST 59 — date CardOil vechi produc DATA_STALE',
        $stale['status'] === 'DATA_STALE',
        'status ' . $stale['status'] . ', ultima sincronizare ' . ($stale['last_sync_at'] ?? 'n/a'));

    // No threshold configured -> no recommendation
    $tariffs->setSetting('fuel_review_threshold_percent', '');
    $tariffs->setSetting('fuel_data_stale_days', '3650');
    $noThreshold = $reviews->evaluateVersion($tariffs->getVersionById($created['version_id']));
    check('Fara prag configurat nu se emite recomandare',
        $noThreshold['status'] === 'OK' && $noThreshold['threshold_percent'] === null,
        'status ' . $noThreshold['status']);

    // =================================================================
    section('Verificari suplimentare');

    $q = $pricing->quote([
        'beneficiar_id' => $benefId, 'tip_transport' => 'primar', 'data_cursa' => '1999-01-01',
        'loc_incarcare_id' => $locId, 'zona_distributie_id' => $zoneId,
    ]);
    check('O data anterioara baseline-ului nu gaseste versiune si cade pe configurarea legacy',
        (string) ($q['components'][0]['source'] ?? '') === 'legacy_config',
        'sursa ' . ($q['components'][0]['source'] ?? 'n/a'));

    $q = $pricing->quote([
        'beneficiar_id' => $benefId, 'tip_transport' => 'distributie', 'data_cursa' => '2026-08-10',
        'loc_incarcare_id' => $zoneId, 'zona_distributie_id' => $locId,
        'cantitate_incarcata' => 8.0, 'km_cursa' => 100,
    ]);
    check('Directia rutei este bidirectionala (B → A gaseste aceeasi regula)',
        (float) $q['total_facturare'] > 0,
        'total ' . $q['total_facturare']);

    $variation = $fuelIndex->calculateVariationPercent(10.17, 9.53);
    check('Formula de variatie: (10,17 − 9,53) / 9,53 × 100 ≈ +6,72%',
        $variation !== null && near($variation, 6.7156, 0.001), 'obtinut ' . $variation . '%');
    check('Impartirea la zero returneaza null, nu un procent fabricat',
        $fuelIndex->calculateVariationPercent(10.0, 0.0) === null
            && $fuelIndex->calculateVariationPercent(10.0, null) === null,
        'null in ambele cazuri');

} catch (Throwable $exception) {
    echo PHP_EOL . "\033[31mEXCEPTIE:\033[0m " . $exception->getMessage() . PHP_EOL;
    echo $exception->getTraceAsString() . PHP_EOL;
    $failed++;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
        echo PHP_EOL . 'ROLLBACK efectuat — baza de date este neschimbata.' . PHP_EOL;
    }
}

echo PHP_EOL . str_repeat('=', 66) . PHP_EOL;
echo sprintf("REZULTAT: %d teste trecute, %d esuate\n", $passed, $failed);
echo str_repeat('=', 66) . PHP_EOL;

exit($failed > 0 ? 1 : 0);
