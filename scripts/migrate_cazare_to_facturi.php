<?php
declare(strict_types=1);

/**
 * Aduce cazarile existente (cheltuieli_cazare) in pagina "Facturi".
 *
 *   php scripts/migrate_cazare_to_facturi.php             dry-run (implicit): doar raporteaza
 *   php scripts/migrate_cazare_to_facturi.php --apply     scrie in `facturi`
 *   php scripts/migrate_cazare_to_facturi.php --apply --verbose
 *
 * Ce face, per cazare: un rand in `facturi` cu sursa = legacy_cazare si
 * sursa_key = legacy_cazare:<id> (date, sofer, total / total cu TVA, cursa si status,
 * prima factura atasata). Randul de cheltuiala existent de pe cursa (cazare_id) se
 * LEAGA prin curse_cheltuiala_id, nu se dubleaza.
 *
 * Ce NU face: nu scrie in cheltuieli_cazare, curse_cheltuieli sau
 * curse_cheltuieli_documente si nu muta fisiere. Rularea repetata e sigura.
 * Cazarile noi (importul orar din Google Sheet) sunt aduse ulterior automat, la
 * deschiderea paginii Facturi si de scripts/rematch_facturi.php.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/models/InvoiceModel.php';

$apply = false;
$verbose = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--apply') {
        $apply = true;
    } elseif ($argument === '--dry-run') {
        $apply = false;
    } elseif ($argument === '--verbose') {
        $verbose = true;
    } else {
        fwrite(STDERR, "Argument necunoscut: $argument\nFolosire: php scripts/migrate_cazare_to_facturi.php [--dry-run|--apply] [--verbose]\n");
        exit(2);
    }
}

$db = get_pdo();
$model = new InvoiceModel($db);
$model->ensureFacturiSchema();

$count = static fn(string $sql): int => (int) $db->query($sql)->fetchColumn();
$before = [
    'cheltuieli_cazare' => $count('SELECT COUNT(*) FROM cheltuieli_cazare'),
    'randuri_cazare_pe_curse' => $count('SELECT COUNT(*) FROM curse_cheltuieli WHERE cazare_id IS NOT NULL'),
    'facturi_legacy' => $count("SELECT COUNT(*) FROM facturi WHERE sursa = 'legacy_cazare' AND deleted_at IS NULL"),
];

echo ($apply ? 'APLICARE' : 'DRY-RUN (nimic nu se scrie; adauga --apply)') . "\n";
printf("Cazari in cheltuieli_cazare: %d | randuri de cazare pe curse: %d | deja in Facturi: %d\n\n",
    $before['cheltuieli_cazare'], $before['randuri_cazare_pe_curse'], $before['facturi_legacy']);

if ($verbose) {
    $rows = $db->query("
        SELECT z.id, z.data, s.nume, z.total_cu_tva, z.status, z.cursa_id,
               (SELECT m.id FROM curse_cheltuieli m WHERE m.cazare_id = z.id LIMIT 1) AS mirror_id,
               (SELECT COUNT(*) FROM cheltuieli_cazare_documente d WHERE d.cazare_id = z.id) AS docs,
               f.id AS factura_id
        FROM cheltuieli_cazare z
        INNER JOIN soferi s ON s.id = z.sofer_id
        LEFT JOIN facturi f ON f.legacy_cazare_id = z.id
        ORDER BY z.id
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        printf("  cazare #%-4d %s  %-28s %9s lei  %-9s cursa=%-5s rand cursa=%-5s documente=%d  %s\n",
            $row['id'], $row['data'], mb_strimwidth((string) $row['nume'], 0, 28), number_format((float) $row['total_cu_tva'], 2, '.', ''),
            $row['status'], $row['cursa_id'] ?? '-', $row['mirror_id'] ?? '-', $row['docs'],
            $row['factura_id'] !== null ? '(exista: factura #' . $row['factura_id'] . ')' : '(nou)');
    }
    echo "\n";
}

try {
    $summary = $model->syncLegacyCazare($apply);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Eroare: ' . $exception->getMessage() . "\n");
    exit(1);
}

$verb = $apply ? '' : ' (s-ar face)';
printf("Analizate:                    %d\n", $summary['total']);
printf("Migrate (noi)%s:%s %d\n", $verb, str_repeat(' ', max(1, 16 - strlen($verb))), $summary['noi']);
printf("Actualizate%s:%s %d\n", $verb, str_repeat(' ', max(1, 18 - strlen($verb))), $summary['actualizate']);
printf("Sarite (deja la zi):          %d\n", $summary['neschimbate']);
printf("Deja legate de cursa:         %d  (randul existent din curse_cheltuieli e refolosit)\n", $summary['legate_de_cursa']);
printf("Scoase (cazare stearsa)%s: %d\n", $verb, $summary['sterse']);
printf("Erori:                        %d\n", count($summary['erori']));
foreach ($summary['erori'] as $error) {
    echo '  - ' . $error . "\n";
}

$after = [
    'cheltuieli_cazare' => $count('SELECT COUNT(*) FROM cheltuieli_cazare'),
    'randuri_cazare_pe_curse' => $count('SELECT COUNT(*) FROM curse_cheltuieli WHERE cazare_id IS NOT NULL'),
    'facturi_legacy' => $count("SELECT COUNT(*) FROM facturi WHERE sursa = 'legacy_cazare' AND deleted_at IS NULL"),
];
echo "\nVerificare:\n";
printf("  cheltuieli_cazare neschimbat:        %s (%d)\n", $after['cheltuieli_cazare'] === $before['cheltuieli_cazare'] ? 'DA' : 'NU', $after['cheltuieli_cazare']);
printf("  randuri de cazare pe curse neschimbate: %s (%d)\n", $after['randuri_cazare_pe_curse'] === $before['randuri_cazare_pe_curse'] ? 'DA' : 'NU', $after['randuri_cazare_pe_curse']);
if ($apply) {
    printf("  facturi legacy = cazari:              %s (%d / %d)\n", $after['facturi_legacy'] === $after['cheltuieli_cazare'] ? 'DA' : 'NU', $after['facturi_legacy'], $after['cheltuieli_cazare']);
}

exit($summary['erori'] === [] ? 0 : 1);
