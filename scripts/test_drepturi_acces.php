<?php
declare(strict_types=1);

/**
 * Teste pentru registrul de permisiuni + Drepturi de acces.
 *
 *   php scripts/test_drepturi_acces.php
 *
 * Acopera: paritatea registrului, regula de decizie (rol / personalizat / pagina
 * oprita / admin_only), garda de endpoint-uri, auto-descoperirea unui modul nou
 * (fisier temporar permissions/modules/test_acl.php), schimbarea etichetei,
 * retragerea unei permisiuni (non-distructiva), salvarea care pastreaza istoricul.
 *
 * Scrie in BD doar randuri de test (un utilizator inactiv temporar + cheile test_acl.*)
 * si le sterge la final, inclusiv la eroare.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/includes/helpers.php';
require_once $root . '/htdocs/includes/auth.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/models/AccessRightsModel.php';
require_once $root . '/htdocs/includes/access.php';

$passed = 0;
$failed = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "\033[32m  PASS\033[0m  $name\n";
    } else {
        $failed++;
        echo "\033[31m  FAIL\033[0m  $name" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    }
}

function fresh_registry(): PermissionRegistry
{
    PermissionRegistry::reset();

    return PermissionRegistry::instance();
}

$testFile = $root . '/htdocs/permissions/modules/test_acl.php';
$writeTestModule = static function (array $actions, array $endpoints = []) use ($testFile): void {
    $module = [
        'key' => 'test_acl', 'label' => 'Test ACL', 'description' => 'Modul temporar de test',
        'section' => 'administrare', 'icon' => 'bi-bug', 'order' => 9999, 'scope' => 'all',
        'actions' => $actions, 'endpoints' => $endpoints,
    ];
    file_put_contents($testFile, "<?php\nreturn " . var_export($module, true) . ";\n");
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($testFile, true);
    }
};

$pdo = get_pdo();
$model = new AccessRightsModel($pdo);
$model->ensureSchema(); // DDL in afara oricarei tranzactii
$testUserId = 0;

try {
    // ------------------------------------------------------------ 1. registru
    echo "\nRegistru\n";
    $registry = fresh_registry();
    check('fără erori de încărcare', $registry->errors() === [], implode('; ', $registry->errors()));
    check('38 de module înregistrate', count($registry->modules()) === 38, (string) count($registry->modules()));
    $keysOk = true;
    foreach ($registry->flatPermissions() as $p) {
        $keysOk = $keysOk && preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $p['permission_key']) === 1;
    }
    check('toate cheile au forma modul.actiune', $keysOk);
    check('fiecare modul are „view”', array_filter($registry->modules(), static fn($m) => !isset($m['actions']['view'])) === []);
    check('ruta comună „vehicule” → ambele module', $registry->modulesForRoute('vehicule') === ['vehicule_usoare', 'vehicule_grele']);
    check('endpoint dispecer update → edit', $registry->endpointAction('dispecer_curse', 'update') === 'edit');
    check('dispecer_curse.config e admin_only', $registry->isAdminOnly('dispecer_curse', 'config'));
    check('utilizatori.view e admin_only (modul admin)', $registry->isAdminOnly('utilizatori', 'view'));
    check('eticheta fără mojibake (leasing)', str_contains($registry->module('scadentar_leasing')['label'], 'Scadențar'));

    // ------------------------------------------------------------ 2. regula de decizie
    echo "\nRegula de decizie\n";
    check('admin: orice, inclusiv admin_only', access_evaluate('admin', false, [], 'dispecer_curse', 'config'));
    check('operator nepersonalizat: dispecer view', access_evaluate('utilizator', false, [], 'dispecer_curse', 'view'));
    check('operator nepersonalizat: dispecer create', access_evaluate('utilizator', false, [], 'dispecer_curse', 'create'));
    check('operator nepersonalizat: NU restore (admin_only)', !access_evaluate('utilizator', false, [], 'dispecer_curse', 'restore'));
    check('operator nepersonalizat: NU cheltuieli (scope accountancy)', !access_evaluate('utilizator', false, [], 'cheltuieli', 'view'));
    check('contabil nepersonalizat: cheltuieli', access_evaluate('contabilitate', false, [], 'cheltuieli', 'view'));
    check('operator nepersonalizat: NU soferi.end_employment (default_accountancy)', !access_evaluate('utilizator', false, [], 'soferi', 'end_employment'));
    check('contabil nepersonalizat: soferi.end_employment', access_evaluate('contabilitate', false, [], 'soferi', 'end_employment'));
    check('operator nepersonalizat: NU tarife_transport.manage (default_admin)', !access_evaluate('utilizator', false, [], 'tarife_transport', 'manage'));
    check('pagină nedeclarată: fail-open ca înainte', access_evaluate('utilizator', false, [], 'pagina_inexistenta', 'view'));

    $perms = ['dispecer_curse' => ['view' => true, 'create' => true, 'restore' => true], 'harta_flota' => ['view' => true]];
    check('personalizat: create acordat', access_evaluate('utilizator', true, $perms, 'dispecer_curse', 'create'));
    check('personalizat: edit neacordat', !access_evaluate('utilizator', true, $perms, 'dispecer_curse', 'edit'));
    check('personalizat: admin_only ignorat chiar dacă e în BD', !access_evaluate('utilizator', true, $perms, 'dispecer_curse', 'restore'));
    check('personalizat: pagină oprită → acțiuni inactive', !access_evaluate('utilizator', true, ['dispecer_curse' => ['create' => true]], 'dispecer_curse', 'create'));
    check('personalizat: tarifele ascunse = admin', !access_evaluate('utilizator', true, [], 'tarife_transport', 'view'));
    check('personalizat: default_admin se poate acorda', access_evaluate('utilizator', true, ['tarife_transport' => ['view' => true, 'manage' => true]], 'tarife_transport', 'manage'));

    $defaults = access_role_defaults('utilizator');
    check('drepturile rolului Operator nu conțin admin_only', !isset($defaults['dispecer_curse']['config']) && !isset($defaults['utilizatori']));
    check('drepturile rolului Operator conțin dispecer create', isset($defaults['dispecer_curse']['create']));

    // ------------------------------------------------------------ 3. catalog BD idempotent
    echo "\nCatalog BD\n";
    $model->syncCatalog($registry->flatPermissions());
    $second = $model->syncCatalog($registry->flatPermissions());
    check('a doua sincronizare nu schimbă nimic', array_sum($second) === 0, json_encode($second));
    $count = (int) $pdo->query('SELECT COUNT(*) FROM access_permission_catalog WHERE is_active = 1')->fetchColumn();
    check('catalogul activ = registrul (151)', $count === count($registry->flatPermissions()), (string) $count);

    // utilizator temporar (inactiv, fara parola utilizabila)
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO utilizatori (nume, email, parola, rol, status, created_at, updated_at) VALUES (?, ?, ?, 'utilizator', 'inactiv', ?, ?)")
        ->execute(['Test ACL (temporar)', 'test-acl-' . bin2hex(random_bytes(4)) . '@example.invalid', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $now, $now]);
    $testUserId = (int) $pdo->lastInsertId();

    // ------------------------------------------------------------ 4. TEST 7 — modul nou
    echo "\nAuto-descoperire\n";
    $writeTestModule([
        'view' => ['label' => 'Vizualizare'],
        'edit' => ['label' => 'Editare', 'group' => 'operare'],
    ], ['update' => 'edit']);
    $registry = fresh_registry();
    check('TEST 7: „Test ACL” apare fără a modifica Drepturi de acces', $registry->module('test_acl') !== null);
    check('TEST 7: test_acl.view + test_acl.edit', $registry->has('test_acl', 'view') && $registry->has('test_acl', 'edit'));
    $s = $model->syncCatalog($registry->flatPermissions());
    check('TEST 7: catalogul BD primește 2 chei noi', $s['inserted'] === 2, json_encode($s));

    // ------------------------------------------------------------ 5. TEST 8 — actiune noua
    $writeTestModule([
        'view' => ['label' => 'Vizualizare'],
        'edit' => ['label' => 'Editare', 'group' => 'operare'],
        'export' => ['label' => 'Export', 'group' => 'export'],
    ], ['update' => 'edit', 'export' => 'export']);
    $registry = fresh_registry();
    check('TEST 8: acțiunea Export apare automat', $registry->has('test_acl', 'export'));
    $s = $model->syncCatalog($registry->flatPermissions());
    check('TEST 8: catalogul BD primește test_acl.export', $s['inserted'] === 1, json_encode($s));

    $model->saveUserPermissions($testUserId, ['test_acl' => ['view', 'export']], null, null);
    $idBefore = (int) $pdo->query("SELECT id FROM access_permission_catalog WHERE permission_key = 'test_acl.export'")->fetchColumn();

    // ------------------------------------------------------------ 6. TEST 9 — eticheta
    $writeTestModule([
        'view' => ['label' => 'Vizualizare'],
        'edit' => ['label' => 'Editare', 'group' => 'operare'],
        'export' => ['label' => 'Exportă date', 'group' => 'export'],
    ], ['update' => 'edit', 'export' => 'export']);
    $registry = fresh_registry();
    $s = $model->syncCatalog($registry->flatPermissions());
    $row = $pdo->query("SELECT id, label FROM access_permission_catalog WHERE permission_key = 'test_acl.export'")->fetch(PDO::FETCH_ASSOC);
    check('TEST 9: eticheta nouă în registru', $registry->action('test_acl', 'export')['label'] === 'Exportă date');
    check('TEST 9: eticheta actualizată în BD, același id', $row['label'] === 'Exportă date' && (int) $row['id'] === $idBefore && $s['updated'] === 1, json_encode($s));
    check('TEST 9: dreptul acordat a rămas', isset($model->getUserPermissions($testUserId)['test_acl']['export']));

    // ------------------------------------------------------------ 7. TEST 10 — retragere
    $writeTestModule([
        'view' => ['label' => 'Vizualizare'],
        'edit' => ['label' => 'Editare', 'group' => 'operare'],
    ], ['update' => 'edit']);
    $registry = fresh_registry();
    $s = $model->syncCatalog($registry->flatPermissions());
    $row = $pdo->query("SELECT is_active, deprecated_at FROM access_permission_catalog WHERE permission_key = 'test_acl.export'")->fetch(PDO::FETCH_ASSOC);
    check('TEST 10: test_acl.export devine inactivă, nu e ștearsă', $row !== false && (int) $row['is_active'] === 0 && $row['deprecated_at'] !== null && $s['deprecated'] === 1);
    check('TEST 10: dreptul istoric rămâne în access_permissions', isset($model->getUserPermissions($testUserId)['test_acl']['export']));

    // salvarea din UI (cu setul gestionat) nu atinge cheile retrase
    $managed = [];
    foreach ($registry->modules() as $mk => $m) {
        foreach ($m['actions'] as $ak => $a) {
            if (!$a['admin_only']) {
                $managed[$mk][$ak] = true;
            }
        }
    }
    $model->saveUserPermissions($testUserId, ['test_acl' => ['view', 'edit']], null, $managed);
    $after = $model->getUserPermissions($testUserId);
    check('salvare: cheia retrasă e păstrată', isset($after['test_acl']['export']));
    check('salvare: cheile gestionate sunt înlocuite', isset($after['test_acl']['edit']) && isset($after['test_acl']['view']));
    check('retrasă: listată în deprecatedPermissions()', in_array('test_acl.export', array_column($model->deprecatedPermissions(), 'permission_key'), true));

    // readaugare -> reactivare
    $writeTestModule(['view' => ['label' => 'Vizualizare'], 'export' => ['label' => 'Exportă date']]);
    $registry = fresh_registry();
    $s = $model->syncCatalog($registry->flatPermissions());
    check('cheia readăugată se reactivează', $s['reactivated'] === 1, json_encode($s));

    // ------------------------------------------------------------ 8. validare: register() + chei gresite
    echo "\nValidare\n";
    PermissionRegistry::reset();
    PermissionRegistry::register(['key' => 'Bad-Key', 'label' => 'x']);
    PermissionRegistry::register(['key' => 'dispecer_curse', 'label' => 'Duplicat']);
    PermissionRegistry::register(['key' => 'runtime_mod', 'label' => 'Runtime', 'section' => 'sectiune_noua', 'actions' => ['go' => ['label' => 'Go']], 'endpoints' => ['x' => 'lipsa']]);
    $registry = PermissionRegistry::instance();
    check('cheie invalidă respinsă', $registry->module('Bad-Key') === null);
    check('modul duplicat ignorat (primul câștigă)', $registry->module('dispecer_curse')['label'] === 'Dispecer curse');
    check('register(): modul programatic acceptat', $registry->has('runtime_mod', 'go') && $registry->has('runtime_mod', 'view'));
    check('secțiune nedeclarată creată automat', isset($registry->sections()['sectiune_noua']));
    check('endpoint spre acțiune nedeclarată ignorat', $registry->endpointAction('runtime_mod', 'x') === null);
    check('erorile sunt raportate', count($registry->errors()) === 3, json_encode($registry->errors(), JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    check('fără excepții', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    @unlink($testFile);
    PermissionRegistry::reset();
    if ($testUserId > 0) {
        $pdo->prepare('DELETE FROM utilizatori WHERE id = ?')->execute([$testUserId]); // cascade pe access_*
    }
    $pdo->exec("DELETE FROM access_permissions WHERE page_key IN ('test_acl', 'runtime_mod')");
    $pdo->exec("DELETE FROM access_permission_catalog WHERE module_key IN ('test_acl', 'runtime_mod')");
}

echo "\n{$passed} trecute, {$failed} eșuate\n";
exit($failed === 0 ? 0 : 1);
