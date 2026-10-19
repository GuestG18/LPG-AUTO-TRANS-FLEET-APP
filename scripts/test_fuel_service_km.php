<?php
declare(strict_types=1);

/**
 * Test pentru asocierea alimentarilor cu Km service (Dispecer curse):
 * aceeasi regula ca la curse (interval plecare -> intoarcere, ±12h), cursa
 * castiga la egalitate, asocierea manuala rezista la refresh, stergerea
 * perioadei de service sterge asocierea.
 *
 * SIGURANTA: alimentari cu api_id "svctest-", perioade de service cu
 * motiv "SVCTEST"; toate se sterg la final. Foloseste o cursa reala doar la citire.
 * Rulare:  php scripts/test_fuel_service_km.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/htdocs/config/config.php';
require_once $projectRoot . '/htdocs/config/database.php';
require_once $projectRoot . '/htdocs/models/BaseModel.php';
require_once $projectRoot . '/htdocs/models/VehicleServiceKmModel.php';
require_once $projectRoot . '/htdocs/models/FuelModel.php';

$db = get_pdo();
new VehicleServiceKmModel($db);
$model = new FuelModel($db);
$model->ensureSchema();

$passed = 0;
$failed = 0;
function check(string $name, bool $ok, string $observed = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '[OK]   ' : '[FAIL] ') . $name . ($observed !== '' ? ' — ' . $observed : '') . PHP_EOL;
}

$cleanup = static function () use ($db): void {
    $db->exec("DELETE FROM fuel_fillups WHERE api_id LIKE 'svctest-%'");
    $db->exec("DELETE FROM vehicule_service_km WHERE motiv = 'SVCTEST'");
};
$cleanup();

// O cursa reala, inchisa, cu ore completate (doar citire).
$trip = $db->query("
    SELECT c.id, c.vehicle_id, v.nr_inmatriculare,
           TIMESTAMP(c.data_inceput, c.ora_inceput) AS t_start,
           TIMESTAMP(c.data_sfarsit, c.ora_sfarsit) AS t_end
    FROM curse_dispecer c
    INNER JOIN vehicule v ON v.id = c.vehicle_id
    WHERE c.deleted_at IS NULL AND c.data_inceput IS NOT NULL AND c.data_sfarsit IS NOT NULL
      AND c.ora_inceput IS NOT NULL AND c.ora_sfarsit IS NOT NULL
      AND TIMESTAMP(c.data_sfarsit, c.ora_sfarsit) > TIMESTAMP(c.data_inceput, c.ora_inceput)
      AND c.data_sfarsit < DATE_SUB(CURDATE(), INTERVAL 10 DAY)
      -- vehiculul nu mai are nicio cursa in urmatoarele 5 zile (perioada de service din test)
      AND NOT EXISTS (
          SELECT 1 FROM curse_dispecer c2
          WHERE c2.vehicle_id = c.vehicle_id AND c2.id <> c.id AND c2.deleted_at IS NULL
            AND c2.data_inceput BETWEEN c.data_inceput AND DATE_ADD(c.data_sfarsit, INTERVAL 5 DAY)
      )
      AND NOT EXISTS (SELECT 1 FROM vehicule_service_km s WHERE s.vehicle_id = c.vehicle_id
            AND s.data_plecare <= DATE_ADD(c.data_sfarsit, INTERVAL 5 DAY)
            AND COALESCE(s.data_intoarcere, CURDATE()) >= c.data_inceput)
    ORDER BY c.id DESC
    LIMIT 1
")->fetch();
if (!$trip) {
    fwrite(STDERR, "Nu exista nicio cursa locala potrivita pentru test.\n");
    exit(1);
}

$at = static fn (string $base, string $modify): string => (new DateTimeImmutable($base))->modify($modify)->format('Y-m-d H:i:s');
$fillup = static function (string $key, string $datetime) use ($model, $trip): void {
    $model->upsertFillups([[
        'api_id' => 'svctest-' . $key,
        'vehicle_registration' => (string) $trip['nr_inmatriculare'],
        'driver_name' => 'SVCTEST',
        'fuel_type' => 'motorina',
        'quantity_liters' => 50,
        'odometer_km' => null,
        'total_value' => 350,
        'station_name' => 'SVCTEST',
        'fillup_datetime' => $datetime,
    ]]);
};
$service = static function (string $from, ?string $to) use ($db, $trip): int {
    $db->prepare("
        INSERT INTO vehicule_service_km (vehicle_id, data_plecare, ora_plecare, data_intoarcere, ora_intoarcere, km, motiv, created_at)
        VALUES (:vehicle_id, :dp, :op, :di, :oi, 0, 'SVCTEST', NOW())
    ")->execute([
        ':vehicle_id' => (int) $trip['vehicle_id'],
        ':dp' => substr($from, 0, 10), ':op' => substr($from, 11, 8),
        ':di' => $to !== null ? substr($to, 0, 10) : null, ':oi' => $to !== null ? substr($to, 11, 8) : null,
    ]);

    return (int) $db->lastInsertId();
};
$link = static function (string $key) use ($db): array {
    $stmt = $db->prepare("
        SELECT l.trip_id, l.service_km_id, l.match_type
        FROM fuel_fillups f LEFT JOIN fuel_trip_links l ON l.fillup_id = f.id
        WHERE f.api_id = :api_id
    ");
    $stmt->execute([':api_id' => 'svctest-' . $key]);

    return $stmt->fetch() ?: [];
};
$refresh = static function () use ($model, $trip, $at): void {
    $model->refreshAutomaticAssociations(substr($at((string) $trip['t_start'], '-2 days'), 0, 10), substr($at((string) $trip['t_end'], '+6 days'), 0, 10));
};
$describe = static fn (array $l): string => 'trip=' . ($l['trip_id'] ?? 'null') . ' service=' . ($l['service_km_id'] ?? 'null') . ' ' . ($l['match_type'] ?? '');

try {
    $tEnd = (string) $trip['t_end'];
    // Service: pleaca la 3h dupa sfarsitul cursei, sta 2 zile.
    $svcFrom = $at($tEnd, '+3 hours');
    $svcTo = $at($tEnd, '+2 days');
    $svcId = $service($svcFrom, $svcTo);

    $fillup('in-trip', $at((string) $trip['t_start'], '+30 minutes'));
    $fillup('in-service', $at($tEnd, '+1 day'));
    $fillup('near-service', $at($tEnd, '+2 hours'));   // 1h inainte de service, 2h dupa cursa -> service
    $fillup('after-return', $at($svcTo, '+5 hours'));  // in toleranta dupa intoarcere
    $fillup('far', $at($svcTo, '+20 hours'));          // in afara tolerantei
    $refresh();

    $l = $link('in-trip');
    check('in interval cursa -> cursa', (int) ($l['trip_id'] ?? 0) === (int) $trip['id'] && ($l['service_km_id'] ?? null) === null, $describe($l));
    $l = $link('in-service');
    check('in perioada de service -> Km service', (int) ($l['service_km_id'] ?? 0) === $svcId && ($l['trip_id'] ?? null) === null, $describe($l));
    $l = $link('near-service');
    check('margine mai apropiata de service -> Km service', (int) ($l['service_km_id'] ?? 0) === $svcId, $describe($l));
    $l = $link('after-return');
    check('±12h dupa intoarcere -> Km service', (int) ($l['service_km_id'] ?? 0) === $svcId, $describe($l));
    $l = $link('far');
    $farOk = ($l['service_km_id'] ?? null) === null;
    check('peste 12h -> nu Km service', $farOk, $describe($l));

    // Intrare deschisa (inca in service): intervalul merge pana acum.
    $db->prepare('UPDATE vehicule_service_km SET data_intoarcere = NULL, ora_intoarcere = NULL WHERE id = :id')->execute([':id' => $svcId]);
    $refresh();
    $l = $link('far');
    check('inca in service -> se asociaza si dupa', (int) ($l['service_km_id'] ?? 0) === $svcId, $describe($l));

    // Asociere manuala la cursa, apoi refresh: ramane manuala.
    $fillupId = (int) $db->query("SELECT id FROM fuel_fillups WHERE api_id = 'svctest-in-service'")->fetchColumn();
    check('asociere manuala la cursa', $model->linkFillupToTrip($fillupId, (int) $trip['id']));
    $refresh();
    $l = $link('in-service');
    check('manual la cursa rezista la refresh', (int) ($l['trip_id'] ?? 0) === (int) $trip['id'] && ($l['service_km_id'] ?? null) === null && $l['match_type'] === 'manual', $describe($l));

    check('asociere manuala la Km service', $model->linkFillupToServiceKm($fillupId, $svcId));
    $l = $link('in-service');
    check('manual la service: trip_id golit', ($l['trip_id'] ?? null) === null && (int) $l['service_km_id'] === $svcId && $l['match_type'] === 'manual', $describe($l));

    // Lista din Dispecer curse vede alimentarile.
    $entries = (new VehicleServiceKmModel($db))->getRecentEntries(36500);
    $entry = array_values(array_filter($entries, static fn (array $e): bool => (int) $e['id'] === $svcId))[0] ?? [];
    check('Km service arata alimentarile asociate', (int) ($entry['alimentari']['nr'] ?? 0) >= 3, json_encode($entry['alimentari'] ?? null));

    // Stergerea perioadei de service sterge si asocierea (CASCADE).
    (new VehicleServiceKmModel($db))->delete($svcId);
    $l = $link('in-service');
    check('stergere Km service -> asocierea dispare', ($l['service_km_id'] ?? null) === null && ($l['trip_id'] ?? null) === null, $describe($l));
} finally {
    $cleanup();
    $refresh();
}

echo PHP_EOL . "Trecute: {$passed}, esuate: {$failed}" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
