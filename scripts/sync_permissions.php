<?php
declare(strict_types=1);

/**
 * Sincronizeaza catalogul de permisiuni din BD (access_permission_catalog) cu
 * PermissionRegistry (htdocs/permissions/modules/*.php).
 *
 *   php scripts/sync_permissions.php            # sincronizeaza si afiseaza rezumatul
 *   php scripts/sync_permissions.php --check    # doar valideaza registrul, fara BD
 *
 * Idempotent: a doua rulare nu schimba nimic. Nu sterge nimic — permisiunile
 * disparute din registru devin is_active = 0, iar drepturile acordate raman in
 * access_permissions. Aceeasi sincronizare ruleaza automat la deschiderea paginii
 * "Drepturi de acces"; scriptul e util dupa deploy.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/services/PermissionRegistry.php';

$registry = PermissionRegistry::instance();
$modules = $registry->modules();
$permissions = $registry->flatPermissions();

echo 'Registru: ' . count($modules) . ' module, ' . count($permissions) . ' permisiuni, '
    . count(array_filter($permissions, static fn(array $p): bool => (bool) $p['admin_only'])) . " doar admin.\n";

$errors = $registry->errors();
foreach ($errors as $error) {
    echo "  ! {$error}\n";
}

if (in_array('--check', $argv, true)) {
    exit($errors === [] ? 0 : 1);
}

require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/models/AccessRightsModel.php';

$model = new AccessRightsModel(get_pdo());
$stats = $model->syncCatalog($permissions);
printf(
    "Catalog BD: %d adăugate, %d actualizate, %d retrase, %d reactivate.\n",
    $stats['inserted'],
    $stats['updated'],
    $stats['deprecated'],
    $stats['reactivated']
);

foreach ($model->deprecatedPermissions() as $row) {
    printf("  retrasă: %s (%s — %s), %d utilizatori în istoric\n", $row['permission_key'], $row['module_label'], $row['label'], (int) $row['users']);
}

exit($errors === [] ? 0 : 1);
