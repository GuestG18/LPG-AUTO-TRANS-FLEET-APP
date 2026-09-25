<?php
declare(strict_types=1);

/**
 * Converteste cursele-copil ramase din vechiul mecanism "Reia cursa" in segmente.
 *
 *   php scripts/convert_resume_children_to_segments.php            (doar raport)
 *   php scripts/convert_resume_children_to_segments.php --apply    (aplica)
 *
 * CONTEXT
 *   Pana la 18.09.2026, "Reia cursa (segment nou)" crea o CURSA NOUA legata prin
 *   parent_cursa_id. Cursa reluata era astfel facturata de doua ori (tariful se
 *   calcula din nou pentru copil) si numarata de doua ori in rapoarte.
 *   Acum reluarea adauga un segment la aceeasi cursa, iar cursele-copil ramase
 *   trebuie convertite.
 *
 * CE FACE PENTRU FIECARE PERECHE parinte -> copil
 *   1. materializeaza segmentul 1 al parintelui (soferul/vehiculul/intervalul lui),
 *      daca parintele nu are inca segmente;
 *   2. adauga copilul ca segment urmator (sofer, vehicul, interval, km);
 *   3. prelungeste intervalul parintelui pana la sfarsitul ultimului segment;
 *   4. sterge (soft delete) cursa-copil, ca sa nu mai fie facturata a doua oara.
 *
 * Km-ii de bord ai vehiculelor NU sunt atinsi: ei au fost deja adunati cand au
 * fost create cele doua curse, iar suma pe vehicule ramane aceeasi dupa conversie
 * (copilul isi pastreaza km-ii pe vehiculul lui, prin segment).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';

$apply = in_array('--apply', $argv, true);

$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$hasColumn = (int) $db->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'curse_dispecer'
        AND COLUMN_NAME = 'parent_cursa_id'"
)->fetchColumn() > 0;

if (!$hasColumn) {
    echo "Coloana parent_cursa_id nu exista: nu e nimic de convertit.\n";
    exit(0);
}

$pairs = $db->query(
    "SELECT copil.id AS copil_id,
            copil.parent_cursa_id AS parinte_id,
            copil.vehicle_id,
            copil.driver_id,
            copil.data_inceput,
            copil.ora_inceput,
            copil.data_sfarsit,
            copil.ora_sfarsit,
            copil.km_cursa,
            copil.km_totali,
            copil.total_facturare,
            parinte.id AS parinte_exista
       FROM curse_dispecer copil
       LEFT JOIN curse_dispecer parinte
              ON parinte.id = copil.parent_cursa_id
             AND parinte.deleted_at IS NULL
      WHERE copil.parent_cursa_id IS NOT NULL
        AND copil.deleted_at IS NULL
      ORDER BY copil.parent_cursa_id ASC, copil.id ASC"
)->fetchAll(PDO::FETCH_ASSOC);

if ($pairs === []) {
    echo "Nicio cursa-copil activa: nu e nimic de convertit.\n";
    exit(0);
}

echo "Curse-copil active gasite: " . count($pairs) . "\n\n";
foreach ($pairs as $pair) {
    printf(
        "  cursa #%d  ->  parinte #%d  |  vehicul %s, km %s, facturat %s lei%s\n",
        (int) $pair['copil_id'],
        (int) $pair['parinte_id'],
        (string) ($pair['vehicle_id'] ?? '-'),
        (string) ($pair['km_cursa'] ?? $pair['km_totali'] ?? '-'),
        (string) ($pair['total_facturare'] ?? '0'),
        $pair['parinte_exista'] === null ? '  [PARINTE LIPSA - se sare]' : ''
    );
}

if (!$apply) {
    echo "\nRulare in mod raport. Adauga --apply ca sa faci conversia.\n";
    exit(0);
}

require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/models/DispecerCurseModel.php';

$model = new DispecerCurseModel($db);
$converted = 0;
$skipped = 0;
$toReview = [];

foreach ($pairs as $pair) {
    $childId = (int) $pair['copil_id'];
    $parentId = (int) $pair['parinte_id'];
    if ($pair['parinte_exista'] === null) {
        $skipped++;
        continue;
    }

    $km = $pair['km_totali'] ?? null;
    if ($km === null || $km === '' || (int) $km <= 0) {
        $km = $pair['km_cursa'] ?? null;
    }

    try {
        $model->addRaceSegment($parentId, [
            'vehicle_id' => (int) ($pair['vehicle_id'] ?? 0),
            'driver_id' => (int) ($pair['driver_id'] ?? 0),
            'data_inceput' => (string) ($pair['data_inceput'] ?? ''),
            'ora_inceput' => (string) ($pair['ora_inceput'] ?? ''),
            'data_sfarsit' => (string) ($pair['data_sfarsit'] ?? ''),
            'ora_sfarsit' => (string) ($pair['ora_sfarsit'] ?? ''),
            'km' => ($km === null || $km === '') ? null : (int) $km,
            'observatii' => 'Convertit din cursa #' . $childId,
        ], null);

        $model->deleteRaceAndSyncVehicleKm($childId, null);
        $converted++;
        $toReview[$parentId] = ($toReview[$parentId] ?? 0) + (int) ($km ?? 0);
        echo "  OK  cursa #$childId a devenit segment al cursei #$parentId\n";
    } catch (Throwable $exception) {
        $skipped++;
        echo "  EROARE cursa #$childId: " . $exception->getMessage() . "\n";
    }
}

echo "\nConvertite: $converted   Sarite: $skipped\n";

if ($toReview !== []) {
    echo "\nDE VERIFICAT - curse care acopera acum si portiunea fostei curse-copil.\n";
    echo "Deschide fiecare cursa, pune km-ii intregii curse si salveaz-o (salvarea recalculeaza tariful):\n";
    foreach ($toReview as $parentId => $kmCopil) {
        printf("  cursa #%d  (km adusi de segmentul nou: %d)\n", (int) $parentId, (int) $kmCopil);
    }
}
