<?php
declare(strict_types=1);

/**
 * Teste Revizii → ștergere intervenție din istoric (ReviziiIstoricModel::deleteIntervention).
 *
 *   php scripts/test_revizii_stergere.php
 *
 * Totul ruleaza intr-o tranzactie ANULATA la final, pe un vehicul real ale carui plan /
 * istoric de schimb ulei sunt scoase temporar. Schema se creeaza inainte (DDL = COMMIT implicit).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/includes/helpers.php';
require_once $root . '/htdocs/models/ReviziiIstoricModel.php';

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

$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$istoric = new ReviziiIstoricModel($db); // DDL inainte de tranzactie
$revizii = new ReviziiModel($db);

$vehicleId = (int) $db->query("SELECT id FROM vehicule WHERE status = 'activ' AND tip_vehicul IN ('cap_tractor', 'camion') ORDER BY id LIMIT 1")->fetchColumn();
if ($vehicleId <= 0) {
    fwrite(STDERR, "Niciun vehicul activ cu motor.\n");
    exit(1);
}
$tip = ReviziiModel::TIP_SCHIMB_ULEI;
$plan = static function () use ($db, $vehicleId, $tip): ?array {
    $stmt = $db->prepare('SELECT * FROM revizii_planuri WHERE vehicle_id = ? AND tip_revizie = ?');
    $stmt->execute([$vehicleId, $tip]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
};
$lastId = static function () use ($db, $vehicleId, $tip): int {
    $stmt = $db->prepare('SELECT MAX(id) FROM revizii_istoric WHERE vehicle_id = ? AND tip_revizie = ?');
    $stmt->execute([$vehicleId, $tip]);

    return (int) $stmt->fetchColumn();
};
$archived = static function (int $id) use ($db): ?array {
    $stmt = $db->prepare('SELECT * FROM revizii_istoric_sterse WHERE istoric_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$id]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
};
$record = static function (string $at, ?int $km) use ($revizii, $vehicleId, $tip): void {
    $revizii->recordService($vehicleId, $at, $km, 10000, ['complet' => true], 40.0, 'ore_aspirare', 'test', null, $tip);
};

$db->beginTransaction();
try {
    $db->prepare('DELETE FROM revizii_planuri WHERE vehicle_id = ? AND tip_revizie = ?')->execute([$vehicleId, $tip]);
    $db->prepare('DELETE FROM revizii_istoric WHERE vehicle_id = ? AND tip_revizie = ?')->execute([$vehicleId, $tip]);

    echo "1. Configurare, apoi două revizii; ștergerea ultimei readuce T0 la prima\n";
    $revizii->savePlan($vehicleId, 10000, null, 0, null, 1500, $tip);
    $configured = $plan();
    $record('2026-05-01 08:00:00', 100000);
    $first = $lastId();
    $record('2026-09-01 08:00:00', 110000);
    $second = $lastId();
    check('T0 = a doua revizie', $plan()['baseline_at'] === '2026-09-01 08:00:00');
    $result = $istoric->deleteIntervention($second, 'test', null);
    check('rândul a dispărut din revizii_istoric', !$istoric->exists($second));
    check('T0 revine la prima revizie', $plan()['baseline_at'] === '2026-05-01 08:00:00', (string) $plan()['baseline_at']);
    check('km la ultima revizie revine la 100.000', (int) $plan()['km_ultima_revizie'] === 100000);
    check('rezultatul raportează T0 restaurat', $result['t0_restaurat'] === '2026-05-01 08:00:00');
    check('intervalul și pragul nu se schimbă', (int) $plan()['interval_km'] === 10000 && (int) $plan()['prag_avertizare_km'] === 1500);
    $copy = $archived($second);
    check('copia e în arhivă cu motivul', $copy !== null && $copy['motiv'] === 'test');
    check('copia conține rândul complet', $copy !== null && (json_decode((string) $copy['date_json'], true)['istoric']['km_bord'] ?? null) == 110000);

    echo "2. Acum se poate înregistra o revizie mai veche decât cea ștearsă\n";
    $record('2026-07-15 10:00:00', 105000);
    check('T0 = revizia nouă (mai veche decât cea ștearsă)', $plan()['baseline_at'] === '2026-07-15 10:00:00');
    $istoric->deleteIntervention($lastId(), null, null);

    echo "3. Ștergerea singurei revizii readuce T0 la configurare (exact, din plan_anterior)\n";
    $istoric->deleteIntervention($first, null, null);
    check('T0 revine la momentul configurării', $plan()['baseline_at'] === $configured['baseline_at'], (string) $plan()['baseline_at']);
    check('sursa revine la cea a configurării', $plan()['consum_initial_sursa'] === $configured['consum_initial_sursa']);

    echo "4. O revizie mai veche (nu ultima) se șterge fără să schimbe T0\n";
    $record('2026-06-01 08:00:00', null);
    $older = $lastId();
    $record('2026-08-01 08:00:00', null);
    $result = $istoric->deleteIntervention($older, null, null);
    check('T0 rămâne pe ultima revizie', $plan()['baseline_at'] === '2026-08-01 08:00:00');
    check('nu raportează T0 restaurat', $result['t0_restaurat'] === null);
    $istoric->deleteIntervention($lastId(), null, null);

    echo "5. Ulei fără configurare: revizia a creat planul, ștergerea îl scoate\n";
    $db->prepare('DELETE FROM revizii_planuri WHERE vehicle_id = ? AND tip_revizie = ?')->execute([$vehicleId, $tip]);
    $record('2026-09-10 08:00:00', null);
    check('planul a fost creat de revizie', $plan() !== null);
    $result = $istoric->deleteIntervention($lastId(), null, null);
    check('planul a fost scos (neconfigurat)', $plan() === null && $result['plan_sters'] === true);

    echo "6. Revizie veche fără plan_anterior (înainte de coloană): fallback pe revizia precedentă\n";
    $revizii->savePlan($vehicleId, 10000, null, 0, null, 1500, $tip);
    $record('2026-03-01 08:00:00', 90000);
    $record('2026-04-01 08:00:00', 95000);
    $db->prepare('UPDATE revizii_istoric SET plan_anterior = NULL WHERE id = ?')->execute([$lastId()]);
    $istoric->deleteIntervention($lastId(), null, null);
    check('T0 revine la revizia precedentă', $plan()['baseline_at'] === '2026-03-01 08:00:00');

    echo "7. Intervenție inexistentă\n";
    try {
        $istoric->deleteIntervention(999999999, null, null);
        check('refuz pentru id inexistent', false);
    } catch (InvalidArgumentException $exception) {
        check('refuz pentru id inexistent', true);
    }
    check('tranzacția de test e încă deschisă', $db->inTransaction());
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

echo "\n$passed ok, $failed eșuate (tranzacție anulată)\n";
exit($failed > 0 ? 1 : 0);
