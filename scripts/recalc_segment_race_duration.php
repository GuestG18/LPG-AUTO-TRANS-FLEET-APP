<?php
declare(strict_types=1);

/**
 * Recalculeaza durata cursei pentru cursele reluate (cu mai multe segmente).
 *
 *   php scripts/recalc_segment_race_duration.php            (doar raport)
 *   php scripts/recalc_segment_race_duration.php --apply    (aplica)
 *
 * Pana la 07.10.2026 durata unei curse cu segmente era intervalul inceput primul
 * segment -> sfarsit ultimul segment, deci includea pauzele dintre oprire si
 * "Reia cursa". Acum durata = suma duratelor segmentelor
 * (DispecerCurseModel::sumSegmentDurationMinutes).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/models/DispecerCurseModel.php';

$apply = in_array('--apply', $argv, true);

$db = get_pdo();

$raceIds = $db->query("
    SELECT seg.cursa_id
    FROM curse_segmente seg
    JOIN curse_dispecer c ON c.id = seg.cursa_id AND c.deleted_at IS NULL
    WHERE seg.deleted_at IS NULL
    GROUP BY seg.cursa_id
    HAVING COUNT(*) > 1
")->fetchAll(PDO::FETCH_COLUMN) ?: [];

$segmentStmt = $db->prepare('
    SELECT * FROM curse_segmente
    WHERE cursa_id = :cursa_id AND deleted_at IS NULL
    ORDER BY ordine ASC, id ASC
');
$currentStmt = $db->prepare('SELECT durata_cursa_minute FROM curse_dispecer WHERE id = :id');
$updateStmt = $db->prepare('UPDATE curse_dispecer SET durata_cursa_minute = :durata WHERE id = :id');

$formatMinutes = static fn (?int $m): string => $m === null ? '-' : intdiv($m, 60) . 'h ' . ($m % 60) . 'm';

$changed = 0;
foreach ($raceIds as $raceId) {
    $raceId = (int) $raceId;
    $segmentStmt->execute([':cursa_id' => $raceId]);
    $segments = $segmentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $newDuration = DispecerCurseModel::sumSegmentDurationMinutes($segments);

    $currentStmt->execute([':id' => $raceId]);
    $currentRaw = $currentStmt->fetchColumn();
    $current = $currentRaw === null || $currentRaw === false ? null : (int) $currentRaw;

    if ($current === $newDuration) {
        continue;
    }
    $changed++;
    echo sprintf("Cursa #%d (%d segmente): %s -> %s\n", $raceId, count($segments), $formatMinutes($current), $formatMinutes($newDuration));

    if ($apply) {
        $updateStmt->bindValue(':durata', $newDuration, $newDuration === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $updateStmt->bindValue(':id', $raceId, PDO::PARAM_INT);
        $updateStmt->execute();
    }
}

echo sprintf("\n%d curse cu segmente, %d de corectat%s.\n", count($raceIds), $changed, $apply ? ' (aplicat)' : ' (doar raport, ruleaza cu --apply)');
