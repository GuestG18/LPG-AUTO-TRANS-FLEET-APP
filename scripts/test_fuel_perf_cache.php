<?php
declare(strict_types=1);

/**
 * Test pentru optimizarile de performanta din Carburanti:
 *  - refreshAutomaticAssociationsIfStale(): sare recalcularea doar cand nimic din ce citeste
 *    potrivirea nu s-a schimbat; orice modificare (cursa, asociere, alimentare) o reporneste;
 *  - recalcularea nu rescrie asocierile identice, dar repara una alterata / stearsa;
 *  - memo-ul de citire (enableRequestReadCache) da aceleasi cifre si se goleste la scriere.
 *
 * SIGURANTA: totul ruleaza intr-o tranzactie anulata la final (ROLLBACK) — datele raman
 * neatinse. ensureSchema() se apeleaza INAINTE de tranzactie (DDL = COMMIT implicit).
 * Rulare:  php scripts/test_fuel_perf_cache.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/htdocs/config/config.php';
require_once $projectRoot . '/htdocs/config/database.php';
require_once $projectRoot . '/htdocs/models/BaseModel.php';
require_once $projectRoot . '/htdocs/models/FuelModel.php';

$db = get_pdo();
(new FuelModel($db))->ensureSchema();

$passed = 0;
$failed = 0;
function check(string $name, bool $ok, string $observed = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '[OK]   ' : '[FAIL] ') . $name . ($observed !== '' ? ' — ' . $observed : '') . PHP_EOL;
}

$links = static fn (): array => $db->query(
    "SELECT fillup_id, trip_id, service_km_id, match_type, confidence FROM fuel_trip_links ORDER BY fillup_id"
)->fetchAll();
$linkIds = static fn (): array => $db->query("SELECT id FROM fuel_trip_links ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

// Luna cu cele mai multe asocieri automate.
$month = (string) $db->query("
    SELECT DATE_FORMAT(f.fillup_datetime, '%Y-%m-01') FROM fuel_trip_links l
    JOIN fuel_fillups f ON f.id = l.fillup_id WHERE l.match_type = 'automatic' AND l.trip_id IS NOT NULL
    GROUP BY 1 ORDER BY COUNT(*) DESC LIMIT 1
")->fetchColumn();
if ($month === '') {
    echo "Nu exista asocieri automate — testul nu are date.\n";
    exit(0);
}
$from = $month;
$to = (new DateTimeImmutable($month))->modify('last day of this month')->format('Y-m-d');
echo "Interval de test: {$from} .. {$to}\n";

$db->beginTransaction();
try {
    $model = new FuelModel($db);
    $model->refreshAutomaticAssociationsIfStale($from, $to);
    $baseline = $links();
    $baselineIds = $linkIds();

    $second = $model->refreshAutomaticAssociationsIfStale($from, $to);
    check('a doua deschidere fara modificari sare recalcularea', $second['skipped'] === true);

    // Recalcularea neconditionata nu rescrie randurile identice (id-urile raman).
    (new FuelModel($db))->refreshAutomaticAssociations($from, $to);
    check('recalcularea pe date neschimbate nu rescrie asocierile (aceleasi id-uri)', $linkIds() === $baselineIds);
    check('... si acelasi continut', $links() === $baseline);

    // 1) Asociere automata alterata (alta cursa) -> amprenta se schimba -> reparata.
    $auto = $db->query("
        SELECT l.fillup_id, l.trip_id FROM fuel_trip_links l JOIN fuel_fillups f ON f.id = l.fillup_id
        WHERE l.match_type = 'automatic' AND l.trip_id IS NOT NULL
          AND f.fillup_datetime BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'
        ORDER BY l.fillup_id LIMIT 1
    ")->fetch();
    $otherTrip = (int) $db->query('SELECT id FROM curse_dispecer WHERE id <> ' . (int) $auto['trip_id'] . ' ORDER BY id LIMIT 1')->fetchColumn();
    $db->exec('UPDATE fuel_trip_links SET trip_id = ' . $otherTrip . ' WHERE fillup_id = ' . (int) $auto['fillup_id']);
    $r = (new FuelModel($db))->refreshAutomaticAssociationsIfStale($from, $to);
    check('asociere alterata: recalcularea NU e sarita', $r['skipped'] === false);
    check('asociere alterata: refacuta identic cu starea corecta', $links() === $baseline);

    // 2) Asociere automata stearsa -> refacuta.
    $db->exec('DELETE FROM fuel_trip_links WHERE fillup_id = ' . (int) $auto['fillup_id']);
    $r = (new FuelModel($db))->refreshAutomaticAssociationsIfStale($from, $to);
    check('asociere stearsa: recalcularea NU e sarita', $r['skipped'] === false);
    check('asociere stearsa: refacuta', $links() === $baseline);

    // 3) Cursa modificata (mutata cu 60 de zile) -> asocierea ei automata dispare / se muta.
    $tripId = (int) $auto['trip_id'];
    $db->exec("UPDATE curse_dispecer SET
        data_inceput = DATE_ADD(data_inceput, INTERVAL 60 DAY),
        data_cursa = DATE_ADD(data_cursa, INTERVAL 60 DAY),
        data_sfarsit = DATE_ADD(data_sfarsit, INTERVAL 60 DAY)
        WHERE id = {$tripId}");
    $r = (new FuelModel($db))->refreshAutomaticAssociationsIfStale($from, $to);
    check('cursa editata: recalcularea NU e sarita', $r['skipped'] === false);
    $afterTrip = $links();
    $stillOnTrip = (int) $db->query("SELECT COUNT(*) FROM fuel_trip_links WHERE trip_id = {$tripId} AND match_type = 'automatic'
        AND fillup_id IN (SELECT id FROM fuel_fillups WHERE fillup_datetime BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59')")->fetchColumn();
    check('cursa editata: nicio alimentare din luna nu mai e legata automat de ea', $stillOnTrip === 0, "ramase={$stillOnTrip}");
    // Rezultatul = exact ce da recalcularea completa.
    (new FuelModel($db))->refreshAutomaticAssociations($from, $to);
    check('cursa editata: rezultatul = recalcularea completa', $links() === $afterTrip);
    $r = (new FuelModel($db))->refreshAutomaticAssociationsIfStale($from, $to);
    check('dupa recalculare, urmatoarea deschidere e din nou sarita', $r['skipped'] === true);

    // 4) Asociere manuala noua -> amprenta se schimba.
    $db->exec("UPDATE fuel_trip_links SET match_type = 'manual' WHERE fillup_id = (SELECT fillup_id FROM (SELECT fillup_id FROM fuel_trip_links WHERE match_type = 'automatic' ORDER BY fillup_id LIMIT 1) x)");
    $r = (new FuelModel($db))->refreshAutomaticAssociationsIfStale($from, $to);
    check('asociere manuala noua: recalcularea NU e sarita', $r['skipped'] === false);

    // 5) Memo de citire: aceleasi cifre, golit la scriere.
    $filters = ['date_from' => $from, 'date_to' => $to, 'vehicles' => [], 'transport_group' => '', 'fuel_type' => '', 'brand' => '', 'vehicle_class' => ''];
    $plain = (new FuelModel($db))->getDashboardData($filters);
    $cachedModel = new FuelModel($db);
    $cachedModel->enableRequestReadCache();
    $cached = $cachedModel->getDashboardData($filters);
    unset($plain['sync_logs'], $plain['last_sync'], $cached['sync_logs'], $cached['last_sync']);
    check('memo de citire: aceleasi date ca fara memo', $plain === $cached);
    $kmBefore = $cached['kpis']['motorina_liters'];
    $fillup = $db->query("SELECT id, quantity_liters FROM fuel_fillups WHERE fuel_type = 'motorina' AND fillup_datetime BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' LIMIT 1")->fetch();
    $db->exec('UPDATE fuel_fillups SET quantity_liters = quantity_liters + 100 WHERE id = ' . (int) $fillup['id']);
    $cachedModel->setFillupFull((int) $fillup['id'], true); // scriere prin model -> goleste memo-ul
    $again = $cachedModel->getDashboardData($filters);
    check('memo de citire: golit dupa o scriere prin model', abs($again['kpis']['motorina_liters'] - $kmBefore - 100) < 0.01,
        $kmBefore . ' -> ' . $again['kpis']['motorina_liters']);
} finally {
    $db->rollBack();
}

echo PHP_EOL . "Rezultat: {$passed} OK, {$failed} FAIL" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
