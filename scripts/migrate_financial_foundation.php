<?php
declare(strict_types=1);

/**
 * Aplica migrarea fundatiei financiare (aditiva, idempotenta) si verifica integritatea dupa.
 *
 *   php scripts/migrate_financial_foundation.php            # aplica ce lipseste
 *   php scripts/migrate_financial_foundation.php --check    # doar raporteaza starea, nu scrie
 *
 * Baza tinta vine din .env / variabila DB_NAME (ex. DB_NAME=fleet_fin_test pentru copia de test).
 * Faceti backup inainte (mysqldump). Anulare: database/migrations/*fundatie_financiara.rollback.sql
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/services/FinancialFoundationSchema.php';

$db = get_pdo();
$checkOnly = in_array('--check', $argv, true);
echo 'Baza: ' . DB_NAME . "\n";

if ($checkOnly) {
    $todo = 0;
    foreach (FinancialFoundationSchema::statements() as [$sql, $once]) {
        $ref = new ReflectionMethod(FinancialFoundationSchema::class, 'state');
        $state = $ref->invoke(null, $db, $sql, $once);
        if ($state !== 'done') {
            $todo++;
            echo '  [' . $state . '] ' . ($once !== null ? '@once ' . $once : preg_replace('/\s+/', ' ', mb_substr($sql, 0, 100))) . "\n";
        }
    }
    echo $todo === 0 ? "Schema completa.\n" : "$todo instructiuni de aplicat.\n";
    exit(0);
}

FinancialFoundationSchema::resetCache();
$report = FinancialFoundationSchema::ensure($db, true);
printf("Aplicate: %d, deja existente: %d, blocate: %d, completa: %s\n",
    count($report['applied']), $report['skipped'], count($report['pending']), $report['complete'] ? 'da' : 'NU');
foreach ($report['pending'] as $pending) {
    echo "  ! blocat (tabela tinta lipseste): $pending\n";
}

// Integritate: stocul fiecarei piese = suma cantitatilor ramase pe receptii.
$mismatch = $db->query(
    "SELECT p.id, p.cod_piesa, p.stoc_curent, COALESCE(SUM(r.cantitate_ramasa), 0) AS receptii
       FROM mentenanta_piese p
       LEFT JOIN mentenanta_piese_receptii r ON r.part_id = p.id
      WHERE p.mod_utilizare = 'stoc'
      GROUP BY p.id, p.cod_piesa, p.stoc_curent
     HAVING ABS(p.stoc_curent - receptii) > 0.001"
)->fetchAll();
echo 'Piese cu stoc diferit de receptii: ' . count($mismatch) . "\n";
foreach (array_slice($mismatch, 0, 20) as $row) {
    echo "  {$row['cod_piesa']}: stoc {$row['stoc_curent']} / receptii {$row['receptii']}\n";
}
$counts = $db->query(
    "SELECT
        (SELECT COUNT(*) FROM mentenanta) AS mentenanta,
        (SELECT COUNT(*) FROM mentenanta WHERE natura_cost IS NULL) AS fara_natura,
        (SELECT COUNT(*) FROM mentenanta WHERE natura_cost_de_revizuit = 1) AS de_revizuit,
        (SELECT COUNT(*) FROM mentenanta_piese_receptii) AS receptii,
        (SELECT COUNT(*) FROM mentenanta_piese_utilizari WHERE sursa_piesa IS NULL) AS utilizari_fara_sursa"
)->fetch();
foreach ($counts as $key => $value) {
    echo "  $key: $value\n";
}
exit($report['complete'] && $mismatch === [] ? 0 : 2);
