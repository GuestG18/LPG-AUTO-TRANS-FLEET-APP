<?php
declare(strict_types=1);

/**
 * Teste „Ajustare tarife combustibil & curs BNR” (?page=ajustare_tarife).
 *
 * SIGURANȚĂ: calculele pure nu ating BD. Testele pe BD folosesc moneda de test
 * ISO „XTS” pentru cursuri și rulează fluxul de aprobare într-o tranzacție care
 * se anulează (ROLLBACK) — nicio referință, versiune sau aprobare nu rămâne.
 * DDL (ensureSchema) se face înainte de orice tranzacție.
 *
 * Rulare:  php scripts/test_ajustare_tarife.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/htdocs/config/config.php';
require_once $projectRoot . '/htdocs/config/database.php';
require_once $projectRoot . '/htdocs/includes/access.php';
require_once $projectRoot . '/htdocs/services/PermissionRegistry.php';
require_once $projectRoot . '/htdocs/models/BaseModel.php';
require_once $projectRoot . '/htdocs/models/TransportTariffModel.php';
require_once $projectRoot . '/htdocs/models/FuelFxAdjustmentModel.php';
require_once $projectRoot . '/htdocs/services/FuelPriceIndexService.php';
require_once $projectRoot . '/htdocs/services/TariffReviewService.php';
require_once $projectRoot . '/htdocs/services/FuelFxCalculator.php';
require_once $projectRoot . '/htdocs/services/BnrExchangeRateService.php';
require_once $projectRoot . '/htdocs/services/FuelFxAdjustmentService.php';

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $observed = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  %-66s %s%s\n", $name, $ok ? 'PASS' : 'FAIL', $observed !== '' ? '  [' . $observed . ']' : '');
}

function near(?float $a, ?float $b, float $eps = 1e-9): bool
{
    return $a !== null && $b !== null && abs($a - $b) <= $eps;
}

$xml = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<DataSet xmlns="https://www.bnr.ro/xsd"><Header><Publisher>National Bank of Romania</Publisher></Header>
<Body><Subject>Reference rates</Subject><OrigCurrency>RON</OrigCurrency>
<Cube date="2026-10-16"><Rate currency="EUR">5.3370</Rate><Rate currency="HUF" multiplier="100">1.4608</Rate></Cube>
<Cube date="2026-10-19"><Rate currency="EUR">5.3272</Rate></Cube>
<Cube date="2026-10-20"><Rate currency="EUR">5.3285</Rate><Rate currency="USD">4.5000</Rate></Cube>
</Body></DataSet>
XML;

// ====================================================================== calcule pure
echo "\nCalcul pur\n";

$parsed = BnrExchangeRateService::parseXml($xml);
check('1. XML BNR: doar EUR, 3 zile publicate', count($parsed) === 3 && $parsed[0]['date'] === '2026-10-16' && near($parsed[2]['rate'], 5.3285), (string) count($parsed));
$huf = BnrExchangeRateService::parseXml($xml, ['HUF']);
check('1b. multiplier păstrat (HUF ×100)', $huf[0]['multiplier'] === 100 && near($huf[0]['rate'], 1.4608));
$threwInvalid = false;
try {
    BnrExchangeRateService::parseXml('<html>eroare</html>');
} catch (RuntimeException) {
    $threwInvalid = true;
}
check('1c. răspuns non-BNR → excepție (nu se inventează cursuri)', $threwInvalid);

$avg = FuelFxCalculator::average(array_column($parsed, 'rate'));
check('3. media = Σ / n pe observațiile publicate', near($avg, (5.3370 + 5.3272 + 5.3285) / 3), number_format((float) $avg, 6));
check('5. weekend 17–18.10 nepublicat: n = 3, nu 5', count($parsed) === 3);
check('3b. fără observații → null', FuelFxCalculator::average([]) === null);

$w = FuelFxCalculator::rollingWindow('2026-10-21', 3);
check('fereastra 21.10.2026, 3 luni = 21.07 – 21.10', $w === ['start' => '2026-07-21', 'end' => '2026-10-21'], $w['start']);
$w = FuelFxCalculator::rollingWindow('2026-01-15', 3);
check('4. fereastra trece peste an: 15.10.2025 – 15.01.2026', $w['start'] === '2025-10-15', $w['start']);
$w = FuelFxCalculator::rollingWindow('2026-05-31', 3);
check('4b. 31.05 − 3 luni = 28.02 (fără depășire în martie)', $w['start'] === '2026-02-28', $w['start']);

check('conversie 10,50 / 5,3060 ≈ 1,9789 €/l', near(FuelFxCalculator::dieselEur(10.50, 5.3060), 10.50 / 5.3060) && abs(FuelFxCalculator::dieselEur(10.50, 5.3060) - 1.9789) < 0.0001);

// Cazul obligatoriu: motorină 10,50 constantă, EUR/RON 5,28 → 5,33.
$d = FuelFxCalculator::decompose(10.50, 10.50, 5.28, 5.33);
check('10. EUR/RON ↑ (5,28→5,33), motorină lei constantă → motorină € SCADE', $d['p1'] < $d['p0'] && $d['combined_pct'] < 0, number_format($d['combined_pct'], 4) . '%');
check('10b. efect motorină 0%, curs +0,947%, efect curs −0,938%', near($d['diesel_ron_pct'], 0.0) && abs($d['fx_rate_pct'] - 0.94697) < 0.0001 && abs($d['fx_only_pct'] - (-0.93809)) < 0.0001);

$d = FuelFxCalculator::decompose(10.00, 10.50, 5.30, 5.30);
check('9. motorină +5%, curs constant → combinat +5%', near($d['combined_pct'], 5.0, 1e-9) && near($d['fx_only_pct'], 0.0));

$d = FuelFxCalculator::decompose(10.00, 10.50, 5.30, 5.40);
$expected = ((10.50 / 5.40) / (10.00 / 5.30) - 1) * 100;
check('11. ambele variază: combinat multiplicativ, nu sumă', near($d['combined_pct'], $expected) && !near($d['combined_pct'], $d['diesel_ron_pct'] + $d['fx_only_pct'], 1e-6), number_format($d['combined_pct'], 4) . '%');
check('11b. atribuirea Shapley se reconciliază exact', near($d['contrib_diesel_pct'] + $d['contrib_fx_pct'], $d['combined_pct'], 1e-9));

$up = FuelFxCalculator::percentChange(10.20, 10.00);
$down = FuelFxCalculator::percentChange(9.80, 10.00);
check('12. exact +2,00% → prag atins în sus', FuelFxCalculator::thresholdState($up, 2.0) === 'up', var_export($up, true));
check('13. exact −2,00% (−1,9999999999999973 în binar) → prag atins în jos', FuelFxCalculator::thresholdState($down, 2.0) === 'down', var_export($down, true));
check('14. +1,9999% → sub prag', FuelFxCalculator::thresholdState(1.9999, 2.0) === 'within');
check('14b. −1,99% → sub prag', FuelFxCalculator::thresholdState(-1.99, 2.0) === 'within');
check('14c. fără prag configurat → null (nu se presupune 2%)', FuelFxCalculator::thresholdState(5.0, null) === null);

check('ajustare: 1,20 × (1 + 4% × 0,35) = 1,2168', near(FuelFxCalculator::adjustTariff(1.20, 4.0, 0.35), 1.2168));
check('ajustare: fără pondere → fără simulare (nu 100% implicit)', FuelFxCalculator::adjustTariff(1.20, 4.0, null) === null);

$classes = FuelFxCalculator::priceClasses([90.0, 70.0, 85.0, 70.0]);
check('17. Distribuție: 70 mic, 85 intermediar, 90 mare', $classes === ['70.0000' => 'mic', '85.0000' => 'intermediar', '90.0000' => 'mare'], json_encode($classes));
check('17b. un singur tarif → preț unic', FuelFxCalculator::priceClasses([75.0]) === ['75.0000' => 'unic']);

// ====================================================================== BD
echo "\nBază de date\n";

$db = get_pdo();
$model = new FuelFxAdjustmentModel($db); // DDL înainte de orice tranzacție
$tariffs = new TransportTariffModel($db);
$service = new FuelFxAdjustmentService($db, $model, $tariffs);

$db->exec("DELETE FROM bnr_exchange_rates WHERE currency = 'XTS'");
$xts = array_map(static fn (array $r): array => ['currency' => 'XTS'] + $r, $parsed);
$first = $model->storeRates($xts, 'test');
$second = $model->storeRates($xts, 'test');
$altered = $xts;
$altered[0]['rate'] = 9.9999;
$third = $model->storeRates($altered, 'test');
$stored = $model->getRates('XTS', '2026-10-01', '2026-10-31');
check('2. prima încărcare: 3 inserate', $first['inserted'] === 3);
check('2/22. reîncărcare: 0 inserate, fără dubluri', $second['inserted'] === 0 && count($stored) === 3);
check('2c. curs diferit pentru aceeași zi: semnalat, NU suprascris', $third['conflicts'] === 1 && near($stored[0]['rate'], 5.3370));
$db->exec("DELETE FROM bnr_exchange_rates WHERE currency = 'XTS'");

$eurBefore = (int) $db->query("SELECT COUNT(*) FROM bnr_exchange_rates WHERE currency = 'EUR'")->fetchColumn();
$failing = new BnrExchangeRateService($db, $model, static function (string $url): string {
    throw new RuntimeException('simulare BNR indisponibil');
});
$result = $failing->sync('test', null);
$eurAfter = (int) $db->query("SELECT COUNT(*) FROM bnr_exchange_rates WHERE currency = 'EUR'")->fetchColumn();
check('6. BNR indisponibil → status error, datele existente rămân', $result['status'] === 'error' && $eurBefore === $eurAfter, $result['status'] . ', ' . $eurAfter . ' cursuri');
$db->exec("DELETE FROM bnr_sync_log WHERE triggered_by = 'test'");

$threw = false;
try {
    $service->createBaselineFromWindow('2001-06-30', 'test', null, null, false);
} catch (RuntimeException $exception) {
    $threw = str_contains($exception->getMessage(), 'Prea puține cursuri');
}
check('7. observații insuficiente → referința e refuzată', $threw);

$lockConnection = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET), DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$lockConnection->query("SELECT GET_LOCK('fleet_bnr_sync', 0)");
$skipped = (new BnrExchangeRateService($db, $model, static fn (string $u): string => ''))->sync('test', null);
$lockConnection->query("SELECT RELEASE_LOCK('fleet_bnr_sync')");
check('18/22. sincronizări suprapuse: a doua e sărită', $skipped['status'] === 'skipped');
$db->exec("DELETE FROM bnr_sync_log WHERE triggered_by = 'test'");

// ------------------------------------------------- simulare pe indicatori sintetici
$settings = $service->settings();
$baseline = ['id' => 0, 'label' => 'test', 'diesel_ron' => '10.000000', 'eur_ron' => '5.000000', 'diesel_eur' => '2.000000', 'window_start' => '2026-01-01', 'window_end' => '2026-03-31'];
$indicators = static function (bool $stale, ?float $threshold) use ($settings, $baseline): array {
    return [
        'settings' => ['threshold' => $threshold, 'basis' => 'diesel_eur'] + $settings,
        'status' => $stale ? 'stale' : 'threshold_up',
        'baseline' => $baseline,
        'fx' => ['avg' => 5.0, 'stale' => false],
        'diesel' => ['avg_ron' => 10.40, 'stale' => $stale],
    ];
};
$rule = static fn (string $sig, int $beneficiar, float $value, string $unit = 'lei/km'): array => [
    'rule_signature' => $sig, 'beneficiar_id' => $beneficiar, 'beneficiar_nume' => 'Test ' . $beneficiar,
    'transport_type' => 'primar', 'transport_label' => 'Primar km', 'component_key' => 'pret_km', 'component_label' => 'Preț / km',
    'unit' => $unit, 'currency' => str_starts_with($unit, 'lei') ? 'RON' : 'EUR', 'value' => $value, 'weight' => FuelFxAdjustmentService::FUEL_SHARE,
    'scheduled_from' => null,
    'group_key' => 'primar_km', 'group_label' => 'Primar', 'route_label' => null,
];
$sim = $service->simulate($indicators(false, 2.0), [
    $rule('990001|pret_km|0', 990001, 1.20),
    $rule('990002|pret_km|0', 990002, 1.27),
    $rule('990004|pret_km|0', 990004, 0.25, '€/km'),
])['rules'];
check('16. beneficiari diferiți: fiecare cu tariful propriu, +4% ca motorina', near($sim[0]['proposed'], round(1.20 * 1.04, 4)) && near($sim[1]['proposed'], round(1.27 * 1.04, 4)), $sim[0]['proposed'] . ' / ' . $sim[1]['proposed']);
check('15. variația se măsoară față de referința salvată (+4% motorină €)', near($sim[0]['variation_pct'], 4.0, 1e-9) && $sim[0]['baseline_id'] === 0);
check('regula: tariful se modifică cu același procent ca motorina', near($sim[0]['change_pct'], $sim[0]['variation_pct'], 1e-3));
check('18. tarif în EUR: doar variația, fără a doua conversie FX', near($sim[2]['proposed'], round(0.25 * 1.04, 4)) && $sim[2]['currency'] === 'EUR');
check('prag atins → „Simulat”, eligibil', $sim[0]['status'] === 'simulated' && $sim[0]['eligible']);

$simStale = $service->simulate($indicators(true, 2.0), [$rule('990001|pret_km|0', 990001, 1.20)])['rules'];
check('8. motorină neactualizată → „Date neactualizate”, aplicarea blocată', $simStale[0]['status'] === 'stale' && !$simStale[0]['eligible']);
$simHigh = $service->simulate($indicators(false, 5.0), [$rule('990001|pret_km|0', 990001, 1.20)])['rules'];
check('sub prag (4% < 5%) → tariful nu se schimbă', $simHigh[0]['status'] === 'within' && $simHigh[0]['proposed'] === null);

// ====================================================================== aprobare (ROLLBACK)
echo "\nAprobare manuală (tranzacție anulată la final)\n";

$tripChecksum = static fn (): string => (string) json_encode($db->query('SELECT COUNT(*), COALESCE(SUM(total_facturare),0), COALESCE(SUM(pret_tarifare),0), MAX(updated_at) FROM curse_dispecer')->fetch(PDO::FETCH_NUM));
$invoiceChecksum = static function () use ($db): string {
    try {
        return (string) json_encode($db->query('SELECT COUNT(*), MAX(updated_at) FROM facturi')->fetch(PDO::FETCH_NUM));
    } catch (Throwable) {
        return 'n/a';
    }
};
$tripsBefore = $tripChecksum();
$invoicesBefore = $invoiceChecksum();
$versionsBefore = (string) json_encode($db->query('SELECT COUNT(*), SUM(value), MAX(id) FROM transport_tariff_versions')->fetch(PDO::FETCH_NUM));

$db->beginTransaction();
try {
    // Sursele „la zi” doar în tranzacție: o sincronizare CardOil reușită azi.
    $db->exec("INSERT INTO fuel_sync_logs (sync_started_at, date_from, date_to, status) VALUES (NOW(), CURDATE(), CURDATE(), 'success')");
    $db->exec("INSERT INTO configurare_beneficiari_transport (nume, activ, suporta_primar, suporta_distributie, suporta_primar_distributie, suporta_compresor, pret_km, created_at, updated_at)
               VALUES ('ZZTEST Ajustare FX', 1, 1, 0, 0, 0, 1.2000, NOW(), NOW())");
    $testBeneficiary = (int) $db->lastInsertId();
    $signature = TransportTariffModel::buildSignature($testBeneficiary, 'pret_km', null);

    // Referință implicită cu motorina mult sub nivelul curent → prag atins.
    $current = $service->indicators();
    $refId = $model->createBaseline([
        'label' => 'ZZTEST referință', 'window_start' => '2026-01-01', 'window_end' => '2026-03-31',
        'diesel_ron' => $current['diesel']['avg_ron'] * 0.9, 'eur_ron' => $current['fx']['avg'],
        'diesel_eur' => $current['diesel']['avg_ron'] * 0.9 / $current['fx']['avg'],
        'fx_observations' => 60, 'fuel_observations' => 100, 'fuel_liters' => 10000, 'source' => 'manual', 'created_by' => null,
    ]);
    $model->setDefaultBaseline($refId, array_column($service->ruleCatalog(), 'rule_signature'));
    check('tranzacția de test încă deschisă', $db->inTransaction());

    $testRule = static function () use ($service, $signature): ?array {
        foreach ($service->build()['simulation']['rules'] as $r) {
            if ($r['rule_signature'] === $signature) {
                return $r;
            }
        }
        return null;
    };
    $r = $testRule();
    check('prag atins → eligibil direct (fără pondere de confirmat)', $r !== null && $r['eligible'], ($r['status'] ?? '') . ' ' . number_format((float) ($r['variation_pct'] ?? 0), 4) . '%');

    $effective = (new DateTimeImmutable('today'))->modify('+10 days')->format('Y-m-d');
    $threw = '';
    try {
        $service->applyApproval([$signature => (float) $r['proposed']], date('Y-m-d'), null, null, 'test', true);
    } catch (RuntimeException $exception) {
        $threw = $exception->getMessage();
    }
    check('retroactiv (azi) → refuzat', str_contains($threw, 'în viitor'), $threw);

    $threw = '';
    try {
        $service->applyApproval([$signature => (float) $r['proposed'] + 0.5], $effective, null, null, 'test', true);
    } catch (RuntimeException $exception) {
        $threw = $exception->getMessage();
    }
    check('valoare propusă diferită de cea recalculată → refuzat', str_contains($threw, 'actualizat'), $threw);

    $lockConnection->query("SELECT GET_LOCK('fleet_fx_tariff_apply', 0)");
    $threw = '';
    $started = microtime(true);
    try {
        $service->applyApproval([$signature => (float) $r['proposed']], $effective, null, null, 'test', true);
    } catch (RuntimeException $exception) {
        $threw = $exception->getMessage();
    }
    $lockConnection->query("SELECT RELEASE_LOCK('fleet_fx_tariff_apply')");
    check('23. aprobare concurentă (lock ocupat) → refuzată, fără scrieri', str_contains($threw, 'altă aplicare'), round(microtime(true) - $started, 1) . 's');

    $result = $service->applyApproval([$signature => (float) $r['proposed']], $effective, 'ZZTEST notă', null, 'test', true);
    check('tranzacția de test încă deschisă după aplicare', $db->inTransaction());
    $versions = $tariffs->getVersionHistoryForSignature($signature);
    $newest = $versions[0] ?? [];
    $previous = $versions[1] ?? [];
    check('19. versiune nouă de la data aleasă, cu valoarea propusă', ($newest['valid_from'] ?? '') === $effective && near((float) ($newest['value'] ?? 0), (float) $r['proposed'], 0.00005), ($newest['value'] ?? '') . ' din ' . ($newest['valid_from'] ?? ''));
    check('19b. versiunea veche păstrată, închisă în ziua dinainte', near((float) ($previous['value'] ?? 0), 1.20, 0.00005) && ($previous['valid_to'] ?? '') === (new DateTimeImmutable($effective))->modify('-1 day')->format('Y-m-d'));
    check('19c. tariful nou = vechi × (1 + variație)', near((float) ($newest['value'] ?? 0), round(1.20 * (1 + $r['variation_pct'] / 100), 4), 0.00005));
    $approval = $model->getApproval($result['approval_id']);
    check('15b. aprobarea reține referința folosită + utilizator + notă', (int) $approval['baseline_id'] === $refId && $approval['note'] === 'ZZTEST notă' && count($approval['items']) === 1);
    check('15c. regula aprobată primește referința nouă', ($model->getRuleBaselineMap()[$signature] ?? null) === (int) $approval['new_baseline_id']);
    check('20. cursele (curse_dispecer) neschimbate', $tripChecksum() === $tripsBefore);
    check('20b. facturile neschimbate', $invoiceChecksum() === $invoicesBefore);
    $today = $tariffs->resolveVersionAt($signature, date('Y-m-d'));
    check('20c. azi se aplică tot tariful vechi', near((float) ($today['value'] ?? 0), 1.20, 0.00005));

    $threw = '';
    try {
        $service->applyApproval([$signature => (float) $r['proposed']], $effective, null, null, 'test', true);
    } catch (RuntimeException $exception) {
        $threw = $exception->getMessage();
    }
    check('dublă aplicare pe aceeași regulă → refuzată', $threw !== '', $threw);
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}
$versionsAfter = (string) json_encode($db->query('SELECT COUNT(*), SUM(value), MAX(id) FROM transport_tariff_versions')->fetch(PDO::FETCH_NUM));
check('ROLLBACK: versiunile de tarif identice cu cele inițiale', $versionsAfter === $versionsBefore);
check('ROLLBACK: nicio aprobare de test rămasă', (int) $db->query("SELECT COUNT(*) FROM fx_tariff_approvals WHERE note = 'ZZTEST notă'")->fetchColumn() === 0);

// ====================================================================== referință schimbată manual (ROLLBACK)
echo "\nReferință schimbată manual\n";
$db->beginTransaction();
try {
    $signatures = array_column($service->ruleCatalog(), 'rule_signature');
    $mk = static fn (string $label, float $diesel): int => $model->createBaseline([
        'label' => $label, 'window_start' => '2026-01-01', 'window_end' => '2026-03-31',
        'diesel_ron' => $diesel, 'eur_ron' => 5.0, 'diesel_eur' => $diesel / 5.0,
        'fx_observations' => 60, 'fuel_observations' => 100, 'fuel_liters' => 10000, 'source' => 'manual', 'created_by' => null,
    ]);
    $first = $mk('ZZTEST ref 1', 10.0);
    $model->setDefaultBaseline($first, $signatures);
    $second = $mk('ZZTEST ref 2', 9.0);
    $model->setDefaultBaseline($second, $signatures);
    $third = $mk('ZZTEST ref 3', 8.0);
    $model->setDefaultBaseline($third, $signatures);
    check('schimbare manuală de referință: nicio regulă fixată pe referința veche', $model->getRuleBaselineMap() === []);
    $sim = $service->build()['simulation']['rules'];
    check('toate tarifele se compară cu referința implicită nouă', $sim !== [] && array_unique(array_column($sim, 'baseline_id')) === [$third]);
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

// ====================================================================== mod test (prețuri pe lună)
echo "\nMod test — prețuri motorină introduse pe lună\n";

$testService = new FuelFxAdjustmentService($db, $model, $tariffs);
$testService->enableTestMode(['2026-07' => 10.0, '2026-08' => 10.2, '2026-09' => 10.4, '2026-10' => 10.6], null);
$window = $testService->testDieselWindow('2026-07-21', '2026-10-21');
$expectedAvg = (10.0 * 11 + 10.2 * 31 + 10.4 * 30 + 10.6 * 21) / 93;
check('media ferestrei = Σ(preț × zile) / Σ zile (11+31+30+21 = 93 zile)', near($window['avg'], $expectedAvg) && $window['days'] === 93, number_format((float) $window['avg'], 6));
check('fiecare lună marcată „test”', array_column($window['breakdown'], 'source') === ['test', 'test', 'test', 'test']);

$partial = new FuelFxAdjustmentService($db, $model, $tariffs);
$partial->enableTestMode(['2026-08' => 12.0], null);
$mixed = $partial->testDieselWindow('2026-07-21', '2026-08-31');
$realJuly = $mixed['breakdown'][0]['real'];
check('lună fără preț de test → media reală CardOil a acelei părți de lună', $mixed['breakdown'][0]['source'] === 'real' && near($mixed['breakdown'][0]['value'], $realJuly)
    && near($mixed['avg'], ($realJuly * 11 + 12.0 * 31) / 42), number_format((float) $mixed['avg'], 4));

$withRef = new FuelFxAdjustmentService($db, $model, $tariffs);
$withRef->enableTestMode(['2026-05' => 10.0, '2026-06' => 10.0, '2026-07' => 10.0, '2026-08' => 10.0, '2026-09' => 10.2, '2026-10' => 10.2], '2026-08-31');
$built = $withRef->build();
$ti = $built['indicators'];
check('referința de test vine din prețurile de test (10,0000 lei/l)', ($ti['baseline']['source'] ?? '') === 'test' && near((float) $ti['baseline']['diesel_ron'], 10.0));
// Fereastra curentă depinde de „Interval analiză” (1 / 3 / 6 / 12 luni): media se verifică pe zilele ei.
$cur = 0.0;
$curDays = 0;
foreach ($ti['test']['current_breakdown'] as $part) {
    $cur += $part['value'] * $part['days'];
    $curDays += $part['days'];
}
check('motorina curentă de test = Σ(preț lună × zile) / Σ zile pe fereastra activă', near($ti['diesel']['avg_ron'], $cur / $curDays) && array_unique(array_column($ti['test']['current_breakdown'], 'source')) === ['test'], number_format((float) $ti['diesel']['avg_ron'], 6));
check('variația motorină lei corespunde prețurilor de test', near($ti['comparison']['diesel_ron_pct'], ($cur / $curDays / 10.0 - 1) * 100));
check('în test niciun tarif nu e eligibil pentru aplicare', array_filter($built['simulation']['rules'], static fn (array $r): bool => $r['eligible']) === []);
$threw = '';
try {
    $withRef->applyApproval(['1|pret_km|0' => 1.0], '2099-01-01', null, null, 'test', false);
} catch (RuntimeException $exception) {
    $threw = $exception->getMessage();
}
check('aplicarea e refuzată în modul test', str_contains($threw, 'modul test'), $threw);
$threw = '';
try {
    $withRef->createBaselineFromWindow('2026-08-31', 'x', null, null, false);
} catch (RuntimeException $exception) {
    $threw = $exception->getMessage();
}
check('referințele reale nu se pot salva din modul test', str_contains($threw, 'modul test'));
$realAgain = (new FuelFxAdjustmentService($db, $model, $tariffs))->indicators();
check('fără ?test=1 indicatorii rămân pe datele reale', $realAgain['test'] === null && !isset($realAgain['diesel']['breakdown']));

// ====================================================================== calculator perioade
echo "\nCalculator comparație perioade\n";
$p = FuelFxAdjustmentService::calendarPeriod('2026-09', 3);
check('3 luni care se termină cu sep 2026 = 01.07 – 30.09.2026', $p['start'] === '2026-07-01' && $p['end'] === '2026-09-30', $p['label']);
$p = FuelFxAdjustmentService::calendarPeriod('2026-08', 1);
check('1 lună aug 2026 = 01.08 – 31.08.2026', $p['start'] === '2026-08-01' && $p['end'] === '2026-08-31');
$p = FuelFxAdjustmentService::calendarPeriod('2026-02', 3);
check('3 luni peste an: dec 2025 – feb 2026', $p['start'] === '2025-12-01' && $p['end'] === '2026-02-28');

$calc = new FuelFxAdjustmentService($db, $model, $tariffs);
$calc->enableTestMode(['2026-07' => 10.0, '2026-08' => 10.0, '2026-09' => 10.0, '2026-10' => 10.50], null);
$cmp = $calc->comparePeriods('2026-10', 1, '2026-09', 3);
$expectedLei = (10.50 / 10.0 - 1) * 100;
check('oct (1 lună, 10,50) față de iul–sep (3 luni, 10,00): motorină lei +5%', near($cmp['decomposition']['diesel_ron_pct'], $expectedLei, 1e-6), number_format($cmp['decomposition']['diesel_ron_pct'], 4) . '%');
$expectedEur = ((10.50 / $cmp['compared']['eur_ron']) / (10.0 / $cmp['reference']['eur_ron']) - 1) * 100;
check('variația în euro folosește cursurile medii BNR ale fiecărei perioade', near($cmp['variation_pct'], $expectedEur, 1e-6), number_format($cmp['variation_pct'], 4) . '%');
check('prag atins → tarifele se modifică cu același procent', $cmp['threshold_state'] === 'up' && near($cmp['tariff_change_pct'], $cmp['variation_pct']));
$calc->enableTestMode(['2026-09' => 10.0, '2026-10' => 10.10], null);
$small = $calc->comparePeriods('2026-10', 1, '2026-09', 1);
check('+1% (în lei) → sub prag, tarifele neschimbate', $small['threshold_state'] === 'within' && $small['tariff_change_pct'] === 0.0, number_format($small['variation_pct'], 4) . '%');
check('pragul implicit este 2%', (new FuelFxAdjustmentService($db, $model, $tariffs))->settings()['threshold'] >= 0.0 && FuelFxAdjustmentService::DEFAULT_THRESHOLD === 2.0);

// ====================================================================== instantanee + ACL
echo "\nInstantanee și drepturi\n";
$snapA = $model->storeSnapshot('auto', str_repeat('t', 64), null, ['x' => 1], [], null, null);
$snapB = $model->storeSnapshot('auto', str_repeat('t', 64), null, ['x' => 1], [], null, null);
check('22. același set de intrări → un singur instantaneu', $snapA['id'] === $snapB['id'] && !$snapB['created']);
$db->prepare('DELETE FROM fx_tariff_snapshots WHERE id = :id')->execute(['id' => $snapA['id']]);

$registry = PermissionRegistry::instance();
check('21. endpoint „apply” cere dreptul apply', $registry->endpointAction('ajustare_tarife', 'apply') === 'apply');
check('21b. operator nepersonalizat NU poate aplica', !access_evaluate('utilizator', false, [], 'ajustare_tarife', 'apply'));
check('21c. operator doar cu view NU poate aplica', !access_evaluate('utilizator', true, ['ajustare_tarife' => ['view' => true]], 'ajustare_tarife', 'apply'));
check('21d. dreptul apply se poate acorda explicit', access_evaluate('utilizator', true, ['ajustare_tarife' => ['view' => true, 'apply' => true]], 'ajustare_tarife', 'apply'));

printf("\n%d trecute, %d eșuate\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
