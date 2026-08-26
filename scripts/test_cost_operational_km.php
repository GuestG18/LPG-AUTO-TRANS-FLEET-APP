<?php
declare(strict_types=1);

/**
 * Test harness pentru pagina "Cost operațional / km".
 *
 *   php scripts/test_cost_operational_km.php
 *
 * SAFETY
 *   Totul rulează într-o singură tranzacție care este ÎNTOTDEAUNA anulată
 *   (rollback). Niciun rând de producție nu este creat, modificat sau șters.
 *
 * Acoperă cerințele §44 din specificație:
 *   normalizări (fix, variabil, anual→perioadă, per-100.000km, carburant, TVA),
 *   combinația CT+semiremorcă, agregările (categorie/vehicul/șofer/beneficiar/
 *   overall), alocarea costurilor partajate, 0 km, surse lipsă, imutabilitatea
 *   simulării, break-even, conversia EUR, consistența filtrelor, reconcilierea
 *   detaliilor, anti-dublare tractor+semi și șofer, LIPSĂ ≠ 0.
 * Plus validarea §43 împotriva modelului de referință Excel (§P.5 din analiză).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/models/OperationalCostModel.php';
require_once $root . '/htdocs/services/CostNormalizationService.php';
require_once $root . '/htdocs/services/CostBreakEvenService.php';
require_once $root . '/htdocs/services/OperationalCostService.php';

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

function approx(?float $a, ?float $b, float $eps = 1e-9): bool
{
    if ($a === null || $b === null) {
        return false;
    }
    return abs($a - $b) < $eps;
}

$db->beginTransaction();

try {
    // ==================================================================
    echo "\n== 1-6. Normalizări (CostNormalizationService, formule §P) ==\n";
    // ==================================================================

    // 1. normalizare cost fix: anual -> lei/km
    check('1. Fix anual -> lei/km: 84.000 / (7.000×12) = 1,00',
        approx(CostNormalizationService::annualToPerKm(84000.0, 7000.0), 1.0));

    // 2. normalizare cost variabil: lunar -> lei/km
    check('2. Variabil lunar -> lei/km: 3.500 / 7.000 = 0,50',
        approx(CostNormalizationService::monthlyToPerKm(3500.0, 7000.0), 0.5));

    // 3. anual -> perioadă lunară (identitate: anual/(km_l×12) == (anual/12)/km_l)
    check('3. Anual -> perioadă: identitatea anual/(km×12) == (anual/12)/km',
        approx(
            CostNormalizationService::annualToPerKm(115500.0, 7000.0),
            CostNormalizationService::monthlyToPerKm(115500.0 / 12.0, 7000.0)
        ));

    // 4. per-100.000 km -> lei/km
    check('4. 39.000 lei/100.000km = 0,39 lei/km',
        approx(CostNormalizationService::per100kToPerKm(39000.0), 0.39));

    // 5. carburant: preț brut -> net × l/km (parametrii Excel: 7,50 lei, TVA 19%, 0,38 l/km)
    check('5. Carburant: 7,50/1,19 × 0,38 = 2,394957983...',
        approx(CostNormalizationService::fuelPerKm(7.50, 19.0, 0.38), 7.50 / 1.19 * 0.38));

    // 6. TVA: de-TVA-izare o singură dată, cu cota reală (21% pe datele live)
    check('6a. Net din brut 21%: 121 -> 100',
        approx(CostNormalizationService::netFromGross(121.0, 21.0), 100.0));
    check('6b. Dublă de-TVA-izare ar da alt rezultat (garda conceptuală)',
        !approx(CostNormalizationService::netFromGross(CostNormalizationService::netFromGross(121.0, 21.0), 21.0), 100.0));

    // ==================================================================
    echo "\n== §43. Validarea împotriva modelului de referință Excel (§P.5) ==\n";
    // ==================================================================
    // Parametrii Excel: EUR=5, km/lună=7000, TVA 19%, motorină 7,50, AdBlue 5,90, diurnă 55.
    // Totalurile fixe anuale (§B.9, validate cell-cu-cell): CT=189.205, SR=90.105.

    $fixCtSr = CostNormalizationService::annualToPerKm(189205.0 + 90105.0, 7000.0);
    check('7a. CT+SR: fix = (189.205+90.105)/(7.000×12) = 3,3251190476',
        approx($fixCtSr, 3.3251190476, 1e-9), sprintf('%.10f', $fixCtSr));

    // componentele cu formulă ale fixului (§B.3-B.4)
    check('7b. Salariu anual: 5.500 × 1,75 × 12 = 115.500',
        approx(5500.0 * 1.75 * 12.0, 115500.0));
    check('7c. Management: 25.705 × 12 / 15 vehicule = 20.564/an/vehicul',
        approx(25705.0 * 12.0 / 15.0, 20564.0));
    check('7d. Amortizare EUR: 25.000 EUR / 6 ani × curs 5 = 20.833,33 lei/an',
        approx(CostNormalizationService::amortizedEurToAnnualLei(25000.0, 6.0, 5.0), 25000.0 / 6.0 * 5.0));

    // Variabil 7 TO — toate intrările cunoscute din §B.6 (consum 0,32; adblue 0;
    // revizii 2.300×4; reparații 14.800; anvelope 6×2.500; diurnă 15 zile × 55)
    $var7 = CostNormalizationService::referenceVariablePerKm([
        'diesel_gross' => 7.50, 'adblue_gross' => 5.90, 'vat_percent' => 19.0,
        'diesel_l_per_km' => 0.32, 'adblue_l_per_km' => 0.0,
        'service_100k' => 2300.0 * 4, 'repairs_100k' => 14800.0, 'tires_100k' => 6 * 2500.0,
        'per_diem_day' => 55.0, 'per_diem_days' => 15.0, 'km_per_month' => 7000.0,
    ]);
    check('8a. Variabil 7 TO = 2,5246638655 (reproducere exactă)',
        approx($var7, 2.5246638655462185, 1e-9), sprintf('%.10f', $var7));

    // Variabil 10 TO (consum 0,34; adblue 0,03; revizii 2.500×3,5; reparații 16.200; anvelope 8×2.500)
    $var10 = CostNormalizationService::referenceVariablePerKm([
        'diesel_gross' => 7.50, 'adblue_gross' => 5.90, 'vat_percent' => 19.0,
        'diesel_l_per_km' => 0.34, 'adblue_l_per_km' => 0.03,
        'service_100k' => 2500.0 * 3.5, 'repairs_100k' => 16200.0, 'tires_100k' => 8 * 2500.0,
        'per_diem_day' => 55.0, 'per_diem_days' => 15.0, 'km_per_month' => 7000.0,
    ]);
    check('8b. Variabil 10 TO = 2,8589537815',
        approx($var10, 2.8589537815, 1e-9), sprintf('%.10f', $var10));

    // Variabil 13 TO (consum 0,38; adblue 0,03; revizii 2.500×3,5; reparații 16.500; anvelope 10×2.500; diurnă 12 zile)
    $var13 = CostNormalizationService::referenceVariablePerKm([
        'diesel_gross' => 7.50, 'adblue_gross' => 5.90, 'vat_percent' => 19.0,
        'diesel_l_per_km' => 0.38, 'adblue_l_per_km' => 0.03,
        'service_100k' => 2500.0 * 3.5, 'repairs_100k' => 16500.0, 'tires_100k' => 10 * 2500.0,
        'per_diem_day' => 55.0, 'per_diem_days' => 12.0, 'km_per_month' => 7000.0,
    ]);
    check('8c. Variabil 13 TO = 3,1404831933',
        approx($var13, 3.1404831933, 1e-9), sprintf('%.10f', $var13));

    // 7. combinația CT + semiremorcă: fix combinat + variabil combinat = total (§P.4, §B.8)
    $totalCtSr = $fixCtSr + 3.3055630252;
    check('9. Total CT+SR: 3,3251190476 + 3,3055630252 = 6,6306820728',
        approx($totalCtSr, 6.6306820728, 1e-9), sprintf('%.10f', $totalCtSr));

    // 19. conversia EUR
    check('10. EUR/km: 6,6306820728 / 5,00 = 1,3261364146 (țintă §43: ≈1,33 €/km)',
        approx(CostNormalizationService::leiToEurPerKm(6.6306820728, 5.0), 1.3261364146, 1e-9));

    // ==================================================================
    echo "\n== Motorul pe date reale (august 2026) ==\n";
    // ==================================================================

    $service = new OperationalCostService($db);
    $payload = $service->compute(['period' => '2026-08']);
    $summary = $payload['summary'];
    $units = $service->internalUnits();

    // 12. agregarea overall == Σ categorii + rândurile companiei (§45 consistență)
    $catTotal = 0.0;
    foreach ($payload['categories'] as $c) {
        $catTotal += $c['total'];
    }
    $companyTotal = 0.0;
    foreach ($payload['company_rows'] as $r) {
        $companyTotal += $r['value'];
    }
    check('11. Σ(categorii) + nealocat == total overall (reconciliere ierarhie)',
        approx($catTotal + $companyTotal, $summary['total'], 0.01),
        sprintf('cat=%.2f company=%.2f overall=%.2f', $catTotal, $companyTotal, $summary['total']));

    // 8/9. agregare categorie & vehicul: Σ vehicule == Σ categorii
    $vehTotal = 0.0;
    foreach ($payload['vehicles'] as $v) {
        $vehTotal += $v['total_cost'];
    }
    check('12. Σ(vehicule) == Σ(categorii) (același model, alt grupaj)',
        approx($vehTotal, $catTotal, 0.01));

    // km-ul overall == suma din curse (verificare independentă SQL)
    $stmt = $db->query(
        "SELECT SUM(CASE WHEN km_totali IS NOT NULL AND km_totali > 0 THEN km_totali
                         WHEN km_cursa IS NOT NULL AND km_cursa > 0 THEN km_cursa ELSE 0 END)
           FROM curse_dispecer WHERE deleted_at IS NULL AND data_inceput BETWEEN '2026-08-01' AND '2026-08-31'"
    );
    $kmSql = (int) $stmt->fetchColumn();
    check('13. Km overall == Σ km reali din curse (SQL independent)',
        $summary['km'] === $kmSql, "engine={$summary['km']} sql=$kmSql");

    // 22. anti-dublare tractor+semiremorcă
    $semiInUnits = 0;
    $coupledSemis = $service->model()->getActiveCouplings();
    foreach ($units as $u) {
        if (in_array($u['vehicle_id'], $coupledSemis, true)) {
            $semiInUnits++;
        }
    }
    check('14. Nicio semiremorcă cuplată nu apare ca unitate proprie (km nedublați)',
        $semiInUnits === 0, "semis=$semiInUnits");

    $ctSrKm = 0;
    foreach ($payload['categories'] as $c) {
        if ($c['code'] === 'ct_sr') {
            $ctSrKm = $c['km'];
        }
    }
    $stmt = $db->query(
        "SELECT COALESCE(SUM(CASE WHEN c.km_totali IS NOT NULL AND c.km_totali > 0 THEN c.km_totali
                         WHEN c.km_cursa IS NOT NULL AND c.km_cursa > 0 THEN c.km_cursa ELSE 0 END), 0)
           FROM curse_dispecer c JOIN vehicule v ON v.id = c.vehicle_id
          WHERE c.deleted_at IS NULL AND c.data_inceput BETWEEN '2026-08-01' AND '2026-08-31'
            AND v.tip_vehicul = 'cap_tractor'"
    );
    check('15. Km CT+SR == doar km-ii tractoarelor (semiremorca moștenește, nu adună)',
        $ctSrKm === (int) $stmt->fetchColumn());

    // 23. anti-dublare salarii: elementul salariu == Σ salarii × multiplicator (SQL independent)
    $mult = (float) $service->model()->getSettings()['salariu_multiplicator'];
    $stmt = $db->prepare(
        'SELECT COALESCE(SUM(COALESCE(
                    (SELECT sh.current_salary FROM salary_history sh
                      WHERE sh.subject_type = "driver" AND sh.driver_id = s.id AND sh.effective_date <= :me
                      ORDER BY sh.effective_date DESC, sh.id DESC LIMIT 1), s.salariu)), 0)
           FROM soferi s WHERE s.status = "activ"
            AND COALESCE((SELECT sh.current_salary FROM salary_history sh
                      WHERE sh.subject_type = "driver" AND sh.driver_id = s.id AND sh.effective_date <= :me2
                      ORDER BY sh.effective_date DESC, sh.id DESC LIMIT 1), s.salariu) > 0'
    );
    $stmt->execute(['me' => '2026-08-31', 'me2' => '2026-08-31']);
    $salarySqlTotal = (float) $stmt->fetchColumn() * $mult;
    $salaryElement = null;
    foreach ($payload['elements'] as $e) {
        if ($e['cod'] === 'salariu_soferi') {
            $salaryElement = $e;
        }
    }
    check('16. Element salarii == Σ(salariu×multiplicator) o singură dată (fără dublare)',
        $salaryElement !== null && approx($salaryElement['total'], $salarySqlTotal, 0.01),
        sprintf('element=%.2f sql=%.2f', $salaryElement['total'] ?? -1, $salarySqlTotal));

    // 10. agregarea pe șofer: cost personal > 0 pentru șoferii cu salariu
    $driverWithSalary = null;
    foreach ($payload['drivers'] as $d) {
        if ($d['km'] > 0 && $d['personnel_cost'] > 0) {
            $driverWithSalary = $d;
            break;
        }
    }
    check('17. Vedere șofer: cost personal atribuit + cost personal/km calculat',
        $driverWithSalary !== null && $driverWithSalary['personnel_per_km'] > 0);

    // suma componentelor șoferului == costul personal afișat
    if ($driverWithSalary !== null) {
        $compSum = 0.0;
        foreach ($driverWithSalary['components'] as $c) {
            $compSum += $c['value'];
        }
        check('18. Σ componente șofer == cost personal afișat (trasabilitate)',
            approx($compSum, $driverWithSalary['personnel_cost'], 0.01));
    } else {
        check('18. Σ componente șofer == cost personal afișat (trasabilitate)', false, 'niciun șofer cu activitate');
    }

    // 11. agregarea pe beneficiar: km-ii beneficiarilor ⊆ km overall; venitul == SQL
    $benKm = 0;
    $benRevenue = 0.0;
    foreach ($payload['beneficiaries'] as $b) {
        $benKm += $b['km'];
        $benRevenue += $b['revenue'];
    }
    check('19. Σ km beneficiari <= km overall și venit beneficiari == venit overall',
        $benKm <= $summary['km'] && approx($benRevenue, $summary['revenue'], 0.01),
        sprintf('benKm=%d km=%d benRev=%.2f rev=%.2f', $benKm, $summary['km'], $benRevenue, $summary['revenue']));

    // 20. filtre consistente: compute(beneficiar X) == rândul lui X din vederea globală
    if ($payload['beneficiaries'] !== []) {
        $b0 = $payload['beneficiaries'][0];
        $filtered = (new OperationalCostService($db))->compute(['period' => '2026-08', 'beneficiar_id' => $b0['beneficiar_id']]);
        check('20. Filtru beneficiar: km/venit/cost identice cu rândul din vederea globală',
            $filtered['summary']['km'] === $b0['km']
            && approx($filtered['summary']['revenue'], $b0['revenue'], 0.01)
            && approx($filtered['summary']['total'], $b0['total_cost'], 0.01),
            sprintf('f.km=%d b.km=%d f.cost=%.2f b.cost=%.2f', $filtered['summary']['km'], $b0['km'], $filtered['summary']['total'], $b0['total_cost']));
    } else {
        check('20. Filtru beneficiar consistent', false, 'niciun beneficiar cu activitate');
    }

    // 21. suma componentelor din detalii == agregatul afișat (vehicul)
    $topVehicle = null;
    foreach ($payload['vehicles'] as $v) {
        if ($v['total_cost'] > 0) {
            $topVehicle = $v;
            break;
        }
    }
    if ($topVehicle !== null) {
        $details = (new OperationalCostService($db))->computeDetails(['period' => '2026-08'], 'vehicle', $topVehicle['vehicle_id']);
        $detSum = 0.0;
        foreach ($details['rows'] as $r) {
            $detSum += $r['value'];
        }
        check('21. Σ detalii vehicul == cost total vehicul (reconciliere drill-down)',
            approx($detSum, $topVehicle['total_cost'], 0.01),
            sprintf('detalii=%.2f agregat=%.2f', $detSum, $topVehicle['total_cost']));
    } else {
        check('21. Σ detalii vehicul == cost total vehicul', false, 'niciun vehicul cu costuri');
    }

    // 15/24. sursă lipsă: raportată explicit, NU tratată ca 0
    $missingCodes = array_column($summary['missing'], 'cod');
    check('22. Elementele MISSING (ex. impozit_auto) apar în lista de lipsuri',
        in_array('impozit_auto', $missingCodes, true));
    $anvelopeElement = null;
    foreach ($payload['elements'] as $e) {
        if ($e['cod'] === 'anvelope') {
            $anvelopeElement = $e;
        }
    }
    check('23. Anvelope fără preț: quality=lipsa și total=0 fără a pretinde cost 0',
        $anvelopeElement !== null && $anvelopeElement['quality'] === 'lipsa' && (float) $anvelopeElement['total'] === 0.0
        && in_array('anvelope', $missingCodes, true));

    // 13. alocarea costurilor partajate: element temporar company 1.500 lei/lună
    $db->exec("INSERT INTO cost_operational_elemente
        (cod, nume, tip, clasa_sursa, sursa_referinta, scop, periodicitate, alocare, valoare_config, valoare_moneda, regim_tva, activ, ordine, created_at, updated_at)
        VALUES ('test_shared_tmp', 'Test partajat', 'fix', 'config', 'manual', 'company', 'lunar', 'by_vehicle_count', 1500.00, 'RON', 'net', 1, 999, NOW(), NOW())");
    $payloadShared = (new OperationalCostService($db))->compute(['period' => '2026-08']);
    $sharedElement = null;
    foreach ($payloadShared['elements'] as $e) {
        if ($e['cod'] === 'test_shared_tmp') {
            $sharedElement = $e;
        }
    }
    check('24. Cost partajat firm-level: Σ alocări pe vehicule == 1.500 lei (fără pierderi)',
        $sharedElement !== null && approx($sharedElement['total'], 1500.0, 0.01),
        sprintf('alocat=%.2f', $sharedElement['total'] ?? -1));
    check('25. Costul partajat crește totalul overall exact cu 1.500 lei',
        approx($payloadShared['summary']['total'] - $summary['total'], 1500.0, 0.01));

    // ==================================================================
    echo "\n== Edge cases + break-even + simulare ==\n";
    // ==================================================================

    // 14. 0 km: perioadă fără activitate — fără division-by-zero, fără NaN
    $empty = (new OperationalCostService($db))->compute(['period' => '2020-01']);
    check('26. 0 km: cost/km == null (nu NaN/Infinity), quality=lipsa',
        $empty['summary']['cost_per_km'] === null && $empty['summary']['km'] === 0
        && $empty['summary']['quality'] === 'lipsa');
    check('27. 0 km: break-even indisponibil cu motiv explicit',
        $empty['breakeven']['reachable'] === false && $empty['breakeven']['reason'] !== '');

    // 18. break-even cu numere cunoscute: fixe 60.000; var/km 2; venit/km 5; km 10.000
    $be = CostBreakEvenService::compute(60000.0, 20000.0, 10000, 20, 50000.0);
    check('28. Break-even: 60.000/(5−2) = 20.000 km',
        approx($be['break_even_km'], 20000.0, 1e-6), sprintf('%.2f', $be['break_even_km'] ?? -1));
    check('29. Break-even: km lipsă 10.000, venit necesar 100.000, cost/km la BE == venit/km',
        approx($be['km_missing'], 10000.0, 1e-6)
        && approx($be['revenue_needed'], 100000.0, 1e-6)
        && approx($be['cost_per_km_at_breakeven'], 5.0, 1e-9));

    // 16. tarif/venit lipsă: nu se fabrică break-even
    $beNoRevenue = CostBreakEvenService::compute(60000.0, 20000.0, 10000, 20, 0.0);
    check('30. Venit lipsă: break-even imposibil, expus ca lipsă (nu inventat)',
        $beNoRevenue['reachable'] === false && str_contains($beNoRevenue['reason'], 'venit'));

    // marjă negativă: venit/km < variabil/km
    $beNegative = CostBreakEvenService::compute(60000.0, 50000.0, 10000, 20, 30000.0);
    check('31. Marjă negativă: break-even imposibil cu explicație',
        $beNegative['reachable'] === false && $beNegative['break_even_km'] === null);

    // 21-sim. fixele rămân fixe la mai mulți km (fix/km scade), variabilul urmează rata
    $sim = CostBreakEvenService::simulate($be, ['km' => 20000.0]);
    check('32. Simulare 10.000→20.000 km: fix constant 60.000; fix/km 6→3',
        approx($sim['fixed_total'], 60000.0, 1e-6) && approx($sim['fixed_per_km'], 3.0, 1e-9));
    check('33. Simulare: variabil scalat cu rata (2 lei/km × 20.000 = 40.000)',
        approx($sim['variable_total'], 40000.0, 1e-6));
    check('34. Simulare la km break-even: rezultat == 0 (recuperare 100%)',
        approx($sim['result'], 0.0, 1e-6) && approx($sim['recovery_pct'], 100.0, 1e-6));

    // 17. simularea NU modifică datele reale (checksums înainte/după)
    $checksum = static function () use ($db): string {
        $parts = [];
        foreach ([
            'SELECT COUNT(*), COALESCE(SUM(total_facturare),0), COALESCE(SUM(km_cursa),0) FROM curse_dispecer',
            'SELECT COUNT(*), COALESCE(SUM(total_value),0) FROM fuel_fillups',
            'SELECT COUNT(*), COALESCE(SUM(cost),0) FROM mentenanta',
            'SELECT COUNT(*), COALESCE(SUM(salariu),0) FROM soferi',
            'SELECT COUNT(*), COALESCE(SUM(suma),0) FROM curse_cheltuieli',
        ] as $sql) {
            $parts[] = implode('|', $db->query($sql)->fetch(PDO::FETCH_NUM));
        }
        return md5(implode(';', $parts));
    };
    $before = $checksum();
    CostBreakEvenService::simulate($payload['breakeven'], ['km' => 99999.0, 'trips' => 500, 'revenue_per_km' => 9.99]);
    (new OperationalCostService($db))->compute(['period' => '2026-08']);
    check('35. Simularea + recalcularea NU modifică datele reale (checksum identic)',
        $checksum() === $before);

    // vederile nu folosesc numitori amestecați: venit/km beneficiar == venit/km recalculat
    if ($payload['beneficiaries'] !== []) {
        $b0 = $payload['beneficiaries'][0];
        check('36. Numitor consecvent: venit/km beneficiar == venit/km_beneficiar (nu km alt scop)',
            approx($b0['revenue_per_km'], $b0['km'] > 0 ? $b0['revenue'] / $b0['km'] : null, 1e-9));
    }

    echo "\n";
} finally {
    $db->rollBack();
}

echo "==============================\n";
echo "PASSED: $passed   FAILED: $failed\n";
echo "(tranzacție anulată — nicio modificare persistată)\n";
exit($failed > 0 ? 1 : 0);
