<?php
declare(strict_types=1);

/**
 * Completare istoric (backfill) pentru Centru Kilometraj: aduce din SAS citirile de odometru
 * GPS (kmIndex) și CAN MAI VECHI decât cele deja salvate, pentru fiecare vehicul mapat.
 *
 *   php scripts/backfill_centru_kilometraj.php --from=2026-04-01            # toate vehiculele SAS
 *   php scripts/backfill_centru_kilometraj.php --from=2026-04-01 --only=61  # un vehicul
 *   php scripts/backfill_centru_kilometraj.php --from=2026-04-01 --dry-run  # doar planul, fără cereri
 *
 * - Pentru fiecare vehicul și sursă (sas, can) cere doar golul [--from, prima citire salvată],
 *   în ferestre de ≤ 30 de zile (limita rapoartelor SAS). Fără nicio citire salvată: până acum.
 * - Scrie DOAR în vehicle_odometer_readings (INSERT IGNORE, aceeași identitate ca sincronizarea:
 *   rulările repetate nu dublează) și apoi recalculează anomaliile pe toată seria (ca revalidateStored).
 * - Nu atinge vehicule.km_bord, T0, cuplaje, setări, last_checked_at sau cron-ul.
 * - Același lacăt ca sincronizarea (nu rulează în paralel cu ea), pauză între cereri, oprire
 *   după 3 erori consecutive.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/includes/helpers.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/models/OdometerCenterModel.php';
require_once $root . '/htdocs/services/SasFleetClient.php';
require_once $root . '/htdocs/services/FleetLivePositionService.php';
require_once $root . '/htdocs/services/OdometerCalculator.php';
require_once $root . '/htdocs/services/OdometerProviders.php';

/**
 * Marcajul de anomalii salvat EXACT ca OdometerCenterService::revalidateStored: lanțul valid pe
 * ultimele 400 de citiri. Citirile mai vechi rămân „valid” (cum le inserează sincronizarea) — altfel,
 * după o resetare a indexului GPS, seria veche (mai lungă) ar marca drept anomalii citirile recente
 * și ar schimba ce citesc Revizii V2 / Centru (validation_status). Caruselul își calculează singur seria.
 */
const CENTRU_REVALIDATE_WINDOW = 400;
function revalidateLikeCentru(OdometerCenterModel $model, int $vehicleId, string $provider): int
{
    $all = $model->getReadings($vehicleId, $provider, null, null, 20000);
    $recentIds = array_flip(array_map(static fn (array $r): string => (string) $r['source_reading_id'], array_slice($all, -CENTRU_REVALIDATE_WINDOW)));
    $flags = [];
    foreach (OdometerCalculator::flagAnomalies(array_map(static fn (array $row): array => [
        'id' => (string) $row['source_reading_id'], 'measured_at' => (string) $row['measured_at'], 'km' => (float) $row['odometer_km'],
    ], array_slice($all, -CENTRU_REVALIDATE_WINDOW))) as $reading) {
        $flags[$reading['id']] = $reading['anomaly'];
    }
    $flagged = 0;
    foreach ($all as $row) {
        $id = (string) $row['source_reading_id'];
        $wanted = isset($recentIds[$id]) ? $flags[$id] : null;
        if ($wanted !== $row['validation_note'] || ($wanted === null && $row['validation_status'] !== 'valid')) {
            $model->setReadingValidation($vehicleId, $provider, $id, $wanted);
        }
        $flagged += $wanted !== null ? 1 : 0;
    }

    return $flagged;
}

$from = '2026-04-01';
$only = null;
$dryRun = false;
$revalidateOnly = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--from=')) {
        $from = substr($arg, 7);
    } elseif (str_starts_with($arg, '--only=')) {
        $only = array_map('intval', array_filter(explode(',', substr($arg, 7))));
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--revalidate-only') {
        // Fără cereri SAS: doar refacerea marcajelor (regula Centru) pentru citirile deja salvate.
        $revalidateOnly = true;
    }
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    fwrite(STDERR, "--from trebuie sa fie YYYY-MM-DD\n");
    exit(1);
}
$from .= ' 00:00:00';

$log = static function (string $message): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
};

$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$model = new OdometerCenterModel($db);
$sas = new SasFleetOdometerSource($db);
if (!$sas->credentialsAvailable()) {
    $log('Credentialele SAS nu sunt configurate.');
    exit(1);
}

$lockFile = dirname(BASE_PATH) . '/storage/cache/centru_kilometraj_sync.lock';
$lock = @fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    $log('O sincronizare Centru Kilometraj este in curs: incearca mai tarziu.');
    exit(2);
}

$sources = $db->query("SELECT s.vehicle_id, s.provider_code, s.external_provider_vehicle_id, v.nr_inmatriculare
    FROM vehicle_odometer_sources s JOIN vehicule v ON v.id = s.vehicle_id
    WHERE s.provider_code IN ('sas', 'can') AND s.enabled = 1 ORDER BY v.nr_inmatriculare, s.provider_code")->fetchAll(PDO::FETCH_ASSOC);
$first = [];
foreach ($db->query("SELECT vehicle_id, provider_code, MIN(measured_at) AS first_at FROM vehicle_odometer_readings
    WHERE provider_code IN ('sas', 'can') GROUP BY vehicle_id, provider_code")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $first[(int) $row['vehicle_id']][(string) $row['provider_code']] = (string) $row['first_at'];
}

$chunkSeconds = 30 * 86400;
$totals = ['calls' => 0, 'inserted' => 0, 'errors' => 0, 'vehicles' => 0];
$consecutiveErrors = 0;
$log(sprintf('Backfill de la %s%s, %d surse.', $from, $dryRun ? ' (dry-run)' : '', count($sources)));

foreach ($sources as $source) {
    $vehicleId = (int) $source['vehicle_id'];
    $provider = (string) $source['provider_code'];
    if ($only !== null && !in_array($vehicleId, $only, true)) {
        continue;
    }
    if ($revalidateOnly) {
        $log(sprintf('%s %s: %d anomalii in ultimele %d citiri.', $source['nr_inmatriculare'], strtoupper($provider),
            revalidateLikeCentru($model, $vehicleId, $provider), CENTRU_REVALIDATE_WINDOW));
        continue;
    }
    $carId = (int) $source['external_provider_vehicle_id'];
    // Doar golul de dinaintea primei citiri salvate (+1 h suprapunere: segmentul taiat la margine).
    $until = isset($first[$vehicleId][$provider])
        ? date('Y-m-d H:i:s', strtotime($first[$vehicleId][$provider]) + 3600)
        : date('Y-m-d H:i:s');
    if ($until <= $from) {
        continue;
    }
    $windows = [];
    for ($start = strtotime($from); $start < strtotime($until); $start += $chunkSeconds) {
        $windows[] = [date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', min(strtotime($until), $start + $chunkSeconds))];
    }
    $label = sprintf('%s %s (carId %d): %s → %s, %d ferestre', $source['nr_inmatriculare'], strtoupper($provider), $carId, $from, $until, count($windows));
    if ($dryRun) {
        $log($label);
        continue;
    }

    $inserted = 0;
    foreach ($windows as [$wFrom, $wTo]) {
        $totals['calls']++;
        try {
            $data = $provider === 'can' ? $sas->fetchCanReadings($carId, $wFrom, $wTo) : $sas->fetchReadings($carId, $wFrom, $wTo);
            $inserted += $model->storeReadings($vehicleId, $provider, $data['readings']);
            $consecutiveErrors = 0;
        } catch (Throwable $exception) {
            $totals['errors']++;
            $log('  eroare ' . $source['nr_inmatriculare'] . ' ' . $provider . ' ' . $wFrom . ': ' . $exception->getMessage());
            if (++$consecutiveErrors >= 3) {
                $log('SAS nu raspunde (3 erori consecutive): oprire. Rularea se poate relua, citirile salvate raman.');
                break 2;
            }
        }
    }

    $flagged = revalidateLikeCentru($model, $vehicleId, $provider);
    $totals['inserted'] += $inserted;
    $totals['vehicles']++;
    $log(sprintf('%s: %d citiri noi, %d anomalii in ultimele %d citiri.', $label, $inserted, $flagged, CENTRU_REVALIDATE_WINDOW));
}

flock($lock, LOCK_UN);
fclose($lock);
$log(sprintf('Gata: %d surse, %d cereri, %d citiri noi, %d erori.', $totals['vehicles'], $totals['calls'], $totals['inserted'], $totals['errors']));
exit($totals['errors'] > 0 ? 3 : 0);
