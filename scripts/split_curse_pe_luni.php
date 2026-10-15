<?php

declare(strict_types=1);

/**
 * Imparte pe luni cursele existente care trec peste granita de luna (o singura data,
 * pe local si pe VPS). Cursele noi / editate se impart singure la salvare.
 *
 *   php scripts/split_curse_pe_luni.php              # doar arata ce s-ar face
 *   php scripts/split_curse_pe_luni.php --apply      # scrie fazele
 *   php scripts/split_curse_pe_luni.php --id=399     # o singura cursa
 *   php scripts/split_curse_pe_luni.php --no-gps     # ponderea dupa durata, fara SAS
 *
 * Km-ii, cantitatile si tariful curselor nu se schimba: doar se impart pe faze.
 */

if (PHP_SAPI !== 'cli') {
    exit("Doar din linia de comanda.\n");
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/includes/helpers.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/models/DispecerCurseModel.php';
require_once $root . '/htdocs/services/SasFleetClient.php';
require_once $root . '/htdocs/services/FleetLivePositionService.php';
require_once $root . '/htdocs/services/SasDashboardService.php';
require_once $root . '/htdocs/services/RaceMonthSplitService.php';

$options = getopt('', ['apply', 'id:', 'no-gps']);
$apply = isset($options['apply']);
$onlyId = isset($options['id']) ? (int) $options['id'] : 0;

$db = get_pdo();
$model = new DispecerCurseModel($db);
$service = new RaceMonthSplitService($db, $model, !isset($options['no-gps']));

// Curse care trec peste luna (dupa cursa sau dupa fazele ei) + cele cu faze automate
// ramase (de verificat: poate nu mai trec peste luna).
$sql = "
    SELECT DISTINCT c.id
    FROM curse_dispecer c
    LEFT JOIN curse_segmente seg ON seg.cursa_id = c.id AND seg.deleted_at IS NULL
    WHERE c.deleted_at IS NULL
      AND (
          DATE_FORMAT(c.data_inceput, '%Y-%m') <> DATE_FORMAT(c.data_sfarsit, '%Y-%m')
          OR DATE_FORMAT(seg.data_inceput, '%Y-%m') <> DATE_FORMAT(seg.data_sfarsit, '%Y-%m')
          OR seg.auto_luna > 0
      )
";
if ($onlyId > 0) {
    $sql .= ' AND c.id = ' . $onlyId;
}
$sql .= ' ORDER BY c.id';

// Coloana auto_luna apare la prima folosire a fazelor (DDL inainte de orice tranzactie).
$model->getRaceSegments(1);
$ids = array_map('intval', $db->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: []);

echo ($apply ? 'APLICARE' : 'SIMULARE (fara --apply nu se scrie nimic)') . ' - ' . count($ids) . " curse\n\n";
$changed = 0;
$failed = 0;
foreach ($ids as $id) {
    try {
        $plan = $service->sync($id, null, !$apply);
    } catch (Throwable $exception) {
        $failed++;
        echo "#{$id}: EROARE " . $exception->getMessage() . "\n";
        continue;
    }
    if ($plan === null) {
        continue;
    }
    $changed++;
    echo "#{$id} (" . $plan['source'] . "):\n";
    if ($plan['segments'] === []) {
        echo "   fazele automate se sterg (cursa nu mai trece peste luna)\n";
    }
    foreach ($plan['segments'] as $index => $segment) {
        printf(
            "   F%d %s %s -> %s %s  km=%s  cant=%s  auto=%d%s\n",
            $index + 1,
            $segment['data_inceput'],
            substr((string) $segment['ora_inceput'], 0, 5),
            $segment['data_sfarsit'],
            substr((string) $segment['ora_sfarsit'], 0, 8),
            $segment['km'] ?? '-',
            $segment['cantitate_incarcata'] ?? '-',
            (int) ($segment['auto_luna'] ?? 0),
            (int) ($segment['id'] ?? 0) > 0 ? '  (faza #' . (int) $segment['id'] . ')' : ''
        );
    }
}

echo "\n{$changed} curse " . ($apply ? 'impartite' : 'de impartit') . ($failed > 0 ? ", {$failed} erori" : '') . "\n";
