<?php
declare(strict_types=1);

/**
 * Test pentru luna de facturare per beneficiar x tip transport x componenta (km / tone)
 * - BillingMonthRule - si pentru tonele facturate la Distributie / Primar+Distributie.
 *
 *   php scripts/test_luna_facturare.php
 *
 * Verifica:
 *   - implicit (Data inceput) o cursa 27.07 - 01.08 e in iulie, in Centralizator si Dashboard V2;
 *   - Primar km si Primar tona au reguli separate (km in august, tone in iulie);
 *   - cursa Primar+Distributie cu km pe Data inceput si tone pe Data sfarsit se imparte:
 *     iulie primeste km-ii si partea de valoare a km-ilor, august tonele si restul,
 *     cele doua parti insumeaza valoarea cursei, iar cursa se numara o singura data;
 *   - Istoric activitati: aceeasi impartire;
 *   - Distributie se factureaza pe tonele livrate, cu fallback pe cele incarcate.
 *
 * Cursa de test foloseste o ruta reala Primar+Distributie tona + km (motorul de tarifare
 * da ponderea km-ilor). SAFETY: totul ruleaza intr-o tranzactie anulata la final.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/includes/helpers.php';
require_once $root . '/htdocs/services/BillingMonthRule.php';
require_once $root . '/htdocs/models/TransportTariffModel.php';
require_once $root . '/htdocs/services/FuelPriceIndexService.php';
require_once $root . '/htdocs/services/TransportPricingService.php';
require_once $root . '/htdocs/services/CentralizatorFacturareService.php';
require_once $root . '/htdocs/models/OperationalCostModel.php';
require_once $root . '/htdocs/models/DashboardAnaliticV2Model.php';
require_once $root . '/htdocs/models/FuelModel.php';
require_once $root . '/htdocs/models/DriverDiurnaModel.php';
require_once $root . '/htdocs/models/DriverActivityHistoryModel.php';

$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
        echo "\033[31m  FAIL\033[0m  $name" . ($detail !== '' ? "  [$detail]" : '') . "\n";
    }
}

function callPrivate(object $object, string $method, array $args): mixed
{
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($object, $args);
}

// DDL inainte de tranzactie: CREATE TABLE / ALTER fac COMMIT implicit. Constructorul
// DriverActivityHistoryModel verifica schema (ensureEmploymentEndSchema), deci se
// creeaza tot aici, nu in tranzactie.
BillingMonthRule::ensureSchema($db);
$history = new DriverActivityHistoryModel($db);

// Dashboard V2 getData verifica si el schema (DDL), deci ruleaza tot inainte de tranzactie,
// pe datele reale: interogarile pe sursa noua (periodTripsSql) trebuie sa mearga.
echo "
-- Dashboard V2 pe date reale --
";
try {
    $realData = (new DashboardAnaliticV2Model($db))->getData(['date_start' => '2026-07-01', 'date_end' => '2026-07-31']);
    check('0. Dashboard V2 getData ruleaza pe sursa noua (iulie 2026)', is_array($realData));
} catch (Throwable $exception) {
    check('0. Dashboard V2 getData ruleaza pe sursa noua (iulie 2026)', false, $exception->getMessage());
}

// Ruta reala Primar+Distributie facturata pe tona + km, fara pret fix.
$route = $db->query("
    SELECT beneficiar_id, loc_incarcare_id, zona_distributie_id, vehicle_ids
    FROM configurare_rute_distributie
    WHERE activ = 1 AND transport_scope = 'primar_distributie' AND tarif_mod = 'tona_km'
      AND tarif_tona > 0 AND cost_extra_km > 0 AND COALESCE(aplica_cost_cursa, 0) = 0
    ORDER BY id LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
$driverId = (int) $db->query('SELECT id FROM soferi ORDER BY id LIMIT 1')->fetchColumn();
if (!$route || $driverId <= 0) {
    fwrite(STDERR, "Este necesara o ruta Primar+Distributie tona + km si un sofer in baza.\n");
    exit(1);
}
$beneficiaryId = (int) $route['beneficiar_id'];
$vehicleId = (int) explode(',', (string) $route['vehicle_ids'])[0];

$db->beginTransaction();
try {
    $insert = $db->prepare("
        INSERT INTO curse_dispecer
            (vehicle_id, driver_id, beneficiar_id, tip_transport, loc_incarcare_id, zona_distributie_id,
             data_cursa, data_inceput, ora_inceput, data_sfarsit, ora_sfarsit, km_cursa, km_totali,
             cantitate_incarcata, tona_livrata, pret_tarifare, total_facturare, status_facturare, created_at, updated_at)
        VALUES
            (:vehicle_id, :driver_id, :beneficiar_id, :tip_transport, :loc, :zona,
             :data_cursa, :data_inceput, '08:00:00', :data_sfarsit, '18:00:00', 500, 520,
             20, NULL, 75, :total, 'in_curs_facturare', NOW(), NOW())
    ");
    $newTrip = static function (string $type, string $start, string $end, float $total)
        use ($insert, $db, $vehicleId, $driverId, $beneficiaryId, $route): int {
        $insert->execute([
            ':vehicle_id' => $vehicleId,
            ':driver_id' => $driverId,
            ':beneficiar_id' => $beneficiaryId,
            ':tip_transport' => $type,
            ':loc' => (int) $route['loc_incarcare_id'],
            ':zona' => (int) $route['zona_distributie_id'],
            ':data_cursa' => $start,
            ':data_inceput' => $start,
            ':data_sfarsit' => $end,
            ':total' => $total,
        ]);

        return (int) $db->lastInsertId();
    };

    // Ani in viitor, ca sa nu se amestece cu cursele reale.
    $primarTrip = $newTrip('primar', '2099-07-27', '2099-08-01', 635.0);
    $primarTonTrip = $newTrip('primar_tona', '2099-07-27', '2099-08-01', 1500.0);
    $pdTrip = $newTrip('primar_distributie', '2099-07-30', '2099-08-02', 2135.0);

    $centralizator = new CentralizatorFacturareService($db);
    $centralizatorRows = static function (string $month) use ($db): array {
        $service = new CentralizatorFacturareService($db);   // sursa perioadei e memorata pe instanta
        $filters = $service->normalizeFilters(['month' => $month]);
        $rows = [];
        foreach (callPrivate($service, 'fetchTripRows', [$filters, 'tst']) as $row) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    };
    $dashboardRows = static function (string $from, string $to) use ($db): array {
        $model = new DashboardAnaliticV2Model($db);
        callPrivate($model, 'useBillingPeriod', [['date_start' => $from, 'date_end' => $to]]);
        $source = callPrivate($model, 'billingTripsSource', []);
        $rows = [];
        foreach ($db->query("SELECT c.* FROM {$source} c WHERE c.deleted_at IS NULL")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    };
    $setRules = static function (array $rules) use ($db, $beneficiaryId): void {
        $db->prepare('DELETE FROM configurare_beneficiari_luna_facturare WHERE beneficiar_id = :id')->execute([':id' => $beneficiaryId]);
        BillingMonthRule::resetCache();
        BillingMonthRule::saveRulesForBeneficiary($db, $beneficiaryId, $rules);
    };

    echo "\n-- Implicit: Data inceput --\n";
    $setRules([]);
    $july = $centralizatorRows('2099-07');
    check('1a. Centralizator: Primar 27.07-01.08 in iulie', isset($july[$primarTrip]));
    check('1b. Centralizator: nu si in august', !isset($centralizatorRows('2099-08')[$primarTrip]));
    check('1c. Dashboard V2: P+D in iulie, intreaga', ($dashboardRows('2099-07-01', '2099-07-31')[$pdTrip]['billing_part'] ?? '') === 'full');
    check('1d. Dashboard V2: nu si in august', !isset($dashboardRows('2099-08-01', '2099-08-31')[$pdTrip]));

    echo "\n-- Primar km si Primar tona, reguli separate --\n";
    $setRules(['primar' => ['km' => BillingMonthRule::END], 'primar_tona' => ['tone' => BillingMonthRule::START]]);
    check('2a. Regula Primar km se citeste', BillingMonthRule::ruleFor($db, $beneficiaryId, 'primar', 'km') === BillingMonthRule::END);
    $july = $centralizatorRows('2099-07');
    $august = $centralizatorRows('2099-08');
    check('2b. Primar km trece in august', isset($august[$primarTrip]) && !isset($july[$primarTrip]));
    check('2c. Primar tona ramane in iulie', isset($july[$primarTonTrip]) && !isset($august[$primarTonTrip]));
    check('2d. P+D (fara regula) ramane in iulie, intreaga', isset($july[$pdTrip]) && (float) $july[$pdTrip]['total_facturare'] === 2135.0);

    echo "\n-- P+D: km pe Data inceput, tone pe Data sfarsit (cursa impartita) --\n";
    $setRules(['primar_distributie' => ['km' => BillingMonthRule::START, 'tone' => BillingMonthRule::END]]);
    $kmFraction = BillingMonthRule::kmFraction($db, $db->query("SELECT * FROM curse_dispecer WHERE id = {$pdTrip}")->fetch(PDO::FETCH_ASSOC));
    check('3a. Motorul da ponderea km-ilor (~635 / 2135)', $kmFraction !== null && abs($kmFraction - 635 / 2135) < 0.01, var_export($kmFraction, true));
    $july = $centralizatorRows('2099-07');
    $august = $centralizatorRows('2099-08');
    $jul = $july[$pdTrip] ?? [];
    $aug = $august[$pdTrip] ?? [];
    check('3b. Centralizator iulie: partea de km', ($jul['billing_part'] ?? '') === 'km' && (float) $jul['km_cursa'] === 500.0 && (float) $jul['cantitate_incarcata'] === 0.0);
    check('3c. Centralizator august: partea de tone', ($aug['billing_part'] ?? '') === 'tone' && (float) $aug['km_cursa'] === 0.0 && (float) $aug['cantitate_incarcata'] === 20.0);
    $sum = (float) ($jul['total_facturare'] ?? 0) + (float) ($aug['total_facturare'] ?? 0);
    check('3d. Partile insumeaza valoarea cursei', abs($sum - 2135.0) <= 0.01, $sum . ' = ' . ($jul['total_facturare'] ?? '?') . ' + ' . ($aug['total_facturare'] ?? '?'));
    check('3e. Valoarea km-ilor in iulie (~635)', abs((float) ($jul['total_facturare'] ?? 0) - 635.0) < 25, (string) ($jul['total_facturare'] ?? ''));
    check('3f. Cursa se numara o data (luna tonelor)', (int) ($jul['billing_counts'] ?? -1) === 0 && (int) ($aug['billing_counts'] ?? -1) === 1);
    $dashJuly = $dashboardRows('2099-07-01', '2099-07-31');
    $dashAug = $dashboardRows('2099-08-01', '2099-08-31');
    check('3g. Dashboard V2: aceeasi impartire', ($dashJuly[$pdTrip]['billing_part'] ?? '') === 'km' && ($dashAug[$pdTrip]['billing_part'] ?? '') === 'tone');
    check('3h. Dashboard V2: data partii (iulie = inceput, august = sfarsit)', ($dashJuly[$pdTrip]['billing_date'] ?? '') === '2099-07-30' && ($dashAug[$pdTrip]['billing_date'] ?? '') === '2099-08-02');
    $dashBoth = $dashboardRows('2099-07-01', '2099-08-31');
    check('3i. Ambele luni (iul + aug) = toata cursa', ($dashBoth[$pdTrip]['billing_part'] ?? '') === 'full');

    echo "\n-- Istoric activitati sofer --\n";
    $pdRow = $db->query("SELECT * FROM curse_dispecer WHERE id = {$pdTrip}")->fetch(PDO::FETCH_ASSOC);
    $pdRow += ['transported_tons' => 0.0, 'delivered_tons' => 20.0, 'clients' => 3, 'total_refacturare' => 50.0, 'total_refacturare_facturata' => 50.0];
    $hJul = callPrivate($history, 'applyBillingPeriod', [$pdRow, ['date_start' => '2099-07-01', 'date_end' => '2099-07-31']]);
    $hAug = callPrivate($history, 'applyBillingPeriod', [$pdRow, ['date_start' => '2099-08-01', 'date_end' => '2099-08-31']]);
    check('4a. Iulie: partial, doar valoarea km-ilor, fara tone', $hJul['billing_partial'] && (float) $hJul['delivered_tons'] === 0.0 && abs((float) $hJul['total_facturare'] - (float) ($jul['total_facturare'] ?? -1)) < 0.02);
    check('4b. August: partial, tonele si restul valorii', $hAug['billing_partial'] && (float) $hAug['delivered_tons'] === 20.0 && abs((float) $hAug['total_facturare'] - (float) ($aug['total_facturare'] ?? -1)) < 0.02);
    check('4c. Refacturarile o singura data (luna tonelor)', (float) $hJul['total_refacturare_facturata'] === 0.0 && (float) $hAug['total_refacturare_facturata'] === 50.0);
    $primarRow = $db->query("SELECT * FROM curse_dispecer WHERE id = {$primarTrip}")->fetch(PDO::FETCH_ASSOC) + ['transported_tons' => 0.0, 'delivered_tons' => 0.0];
    $setRules(['primar' => ['km' => BillingMonthRule::END]]);
    $hPrimar = callPrivate($history, 'applyBillingPeriod', [$primarRow, ['date_start' => '2099-07-01', 'date_end' => '2099-07-31']]);
    check('4d. Primar km facturat in august: in iulie fara valoare', $hPrimar['billing_outside_period'] && (float) $hPrimar['total_facturare'] === 0.0 && $hPrimar['billing_month_label'] === '08.2099');

    echo "\n-- Regula cu efect de la o luna (nu rescrie lunile facturate) --\n";
    $setRules([]);
    $augTrip = $newTrip('primar', '2099-08-31', '2099-09-01', 700.0);
    $sepTrip = $newTrip('primar', '2099-09-30', '2099-10-02', 800.0);
    $changed = BillingMonthRule::saveRulesForBeneficiary($db, $beneficiaryId, ['primar' => ['km' => BillingMonthRule::END]], null, '2099-09');
    check('7a. O regula schimbata = o versiune noua', $changed === 1, (string) $changed);
    $again = BillingMonthRule::saveRulesForBeneficiary($db, $beneficiaryId, ['primar' => ['km' => BillingMonthRule::END], 'compresor' => ['total' => BillingMonthRule::START]], null, '2099-09');
    check('7b. Salvare fara schimbari = nimic scris', $again === 0, (string) $again);
    check('7c. Cursa inceputa in august ramane pe regula veche (august)', isset($centralizatorRows('2099-08')[$augTrip]) && !isset($centralizatorRows('2099-09')[$augTrip]));
    check('7d. Cursa inceputa in septembrie urmeaza regula noua (octombrie)', isset($centralizatorRows('2099-10')[$sepTrip]) && !isset($centralizatorRows('2099-09')[$sepTrip]));
    check('7e. Dashboard V2 la fel', isset($dashboardRows('2099-08-01', '2099-08-31')[$augTrip]) && isset($dashboardRows('2099-10-01', '2099-10-31')[$sepTrip]));
    check('7f. ruleFor dupa data de start', BillingMonthRule::ruleFor($db, $beneficiaryId, 'primar', 'km', '2099-08-31') === BillingMonthRule::START
        && BillingMonthRule::ruleFor($db, $beneficiaryId, 'primar', 'km', '2099-09-01') === BillingMonthRule::END);
    $back = BillingMonthRule::saveRulesForBeneficiary($db, $beneficiaryId, ['primar' => ['km' => BillingMonthRule::START]], null, '2099-11');
    check('7g. Revenire din noiembrie: septembrie-octombrie raman pe Data sfarsit', $back === 1
        && BillingMonthRule::ruleFor($db, $beneficiaryId, 'primar', 'km', '2099-10-15') === BillingMonthRule::END
        && BillingMonthRule::ruleFor($db, $beneficiaryId, 'primar', 'km', '2099-11-01') === BillingMonthRule::START);
    $historyRows = BillingMonthRule::history($db, $beneficiaryId);
    check('7h. Istoricul arata ambele modificari', count($historyRows) === 2 && $historyRows[0]['valabil_de_la'] === '2099-11-01', (string) count($historyRows));
    check('7i. Luna invalida respinsa', BillingMonthRule::normalizeMonth('2099-13') === null && BillingMonthRule::normalizeMonth('abc') === null && BillingMonthRule::normalizeMonth('2099-09') === '2099-09-01');

    echo "\n-- Tone facturate --\n";
    check('5a. Distributie cu tone livrate: se factureaza livratele', TransportPricingService::billableTons(['tip_transport' => 'distributie', 'cantitate_incarcata' => 20, 'tona_livrata' => 17.5]) === 17.5);
    check('5b. Distributie fara tone livrate: fallback pe incarcate', TransportPricingService::billableTons(['tip_transport' => 'distributie', 'cantitate_incarcata' => 20, 'tona_livrata' => null]) === 20.0);
    check('5c. P+D cu tone livrate: livratele', TransportPricingService::billableTons(['tip_transport' => 'primar_distributie', 'cantitate_incarcata' => 20, 'tona_livrata' => 18]) === 18.0);
    check('5d. Primar tona: mereu tonele transportate', TransportPricingService::billableTons(['tip_transport' => 'primar_tona', 'cantitate_incarcata' => 20, 'tona_livrata' => 5]) === 20.0);
    $centralizatorTons = callPrivate($centralizator, 'normalizedBilledTons', [['tip_transport' => 'distributie', 'cantitate_incarcata' => 20, 'tona_livrata' => 17.5, 'capacitate_transport' => 24]]);
    check('5e. Centralizator foloseste tonele livrate la Distributie', abs($centralizatorTons - 17.5) < 0.0001, (string) $centralizatorTons);

    check('6. Tranzactia a ramas deschisa (nimic persistat)', $db->inTransaction());
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    } else {
        fwrite(STDERR, "ATENTIE: tranzactia a fost inchisa implicit - verifica si sterge cursele din 2099.\n");
    }
    BillingMonthRule::resetCache();
}

echo "\n==============================\n";
echo "PASSED: $passed   FAILED: $failed\n";
echo "(tranzactie anulata - nicio modificare persistata)\n";
exit($failed > 0 ? 1 : 0);
