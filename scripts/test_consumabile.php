<?php
declare(strict_types=1);

/**
 * Teste Consumabile & Fluide Tehnice (etapa 1): stoc, transferuri, completari, idempotenta,
 * concurenta, stornari, compatibilitate si arborele de categorii.
 *
 *   php scripts/test_consumabile.php
 *
 * Partea A ruleaza intr-o tranzactie ANULATA la final (nimic nu ramane in baza).
 * Partea B (transferuri simultane) are nevoie de date reale comise: porneste doua procese PHP
 * care transfera in paralel din acelasi butoi, apoi sterge datele de test create.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/includes/helpers.php';
require_once $root . '/htdocs/services/AutoComponentCatalogService.php';
require_once $root . '/htdocs/services/ConsumabileStockService.php';
require_once $root . '/htdocs/services/VehicleFluidService.php';

// --- proces lucrator pentru testul de concurenta --------------------------------------
if (($argv[1] ?? '') === '--worker') {
    [$sourceId, $destId, $count, $tag] = [(int) $argv[2], (int) $argv[3], (int) $argv[4], (string) $argv[5]];
    $service = new ConsumabileStockService(get_pdo());
    $ok = 0;
    $blocked = 0;
    for ($i = 0; $i < $count; $i++) {
        try {
            $service->operate('transfer', ['recipient_sursa_id' => $sourceId, 'recipient_dest_id' => $destId, 'cantitate' => '1',
                'idempotency_key' => 'testconc' . $tag . $i . bin2hex(random_bytes(4))], null);
            $ok++;
        } catch (InvalidArgumentException) {
            $blocked++;
        }
    }
    echo json_encode(['ok' => $ok, 'blocked' => $blocked]);
    exit(0);
}

$passed = 0;
$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  ok   $name\n";
    } else {
        $failed++;
        echo "  FAIL $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}
function expectError(callable $fn, string $contains = ''): string
{
    try {
        $fn();
    } catch (InvalidArgumentException $exception) {
        return $contains === '' || str_contains($exception->getMessage(), $contains) ? 'ok' : 'mesaj: ' . $exception->getMessage();
    }

    return 'nu a fost blocat';
}

$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$service = new ConsumabileStockService($db);
$service->ensureSchema();                      // DDL inainte de tranzactie
$taxonomy = AutoComponentCatalogService::taxonomy($db);
(new AutoComponentCatalogService($db))->components();

$q = static function (int $id) use ($db): string {
    $stmt = $db->prepare('SELECT cantitate FROM consumabile_recipiente WHERE id = ?');
    $stmt->execute([$id]);

    return (string) $stmt->fetchColumn();
};
$key = static fn (): string => 'test' . bin2hex(random_bytes(10));
$vehicles = $db->query("SELECT id FROM vehicule WHERE status = 'activ' AND nr_inmatriculare <> 'STOC-ANVELOPE' ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
if (count($vehicles) < 2) {
    fwrite(STDERR, "Sunt necesare cel putin doua vehicule active.\n");
    exit(1);
}
[$vehicleA, $vehicleB] = array_map('intval', $vehicles);

echo "Partea A — tranzactie anulata la final\n";
$db->beginTransaction();
try {
    // --- arborele de categorii: existentul neafectat ------------------------------------
    echo "\n[Categorii]\n";
    $legacyOk = true;
    foreach (AutoComponentCatalogService::CATEGORY_NAMES as $id => $name) {
        $node = $taxonomy['categories'][$id] ?? null;
        $expectedSub = null;
        foreach (AutoComponentCatalogService::CATEGORY_GROUPS as $group => $ids) {
            if (in_array($id, $ids, true)) {
                $expectedSub = $group;
            }
        }
        if ($node === null || !$node['system'] || $node['subcategory'] !== $expectedSub || $node['name'] !== $name) {
            $legacyOk = false;
        }
    }
    check('categoriile 1-17 exista, sunt „sistem”, cu numele si subcategoria de dinainte', $legacyOk);
    $groups = AutoComponentCatalogService::categoryGroups();
    check('grupurile Sasiu / Hidraulic / Livrare Gaz identice', $groups['sasiu'] === [1, 2, 3, 4, 5, 6, 7, 8, 9] && $groups['hidraulic'] === [10] && $groups['livrare_gaz'] === [11, 12, 13, 14, 15, 16, 17]);
    check('Livrare Gaz ramane doar sub Rezervor', AutoComponentCatalogService::isValidPlacement('rezervor', 'livrare_gaz') && !AutoComponentCatalogService::isValidPlacement('sasiu', 'livrare_gaz'));
    check('cap tractor: Sasiu + Consumabile, fara Rezervor', AutoComponentCatalogService::primariesForVehicleType('cap_tractor') === ['sasiu', 'consumabile']);
    check('ramura „Consumabile & Fluide Tehnice” are cele 5 subcategorii', ($taxonomy['primaries']['consumabile']['subcategories'] ?? []) === ['uleiuri_lubrifianti', 'lichide_tehnice', 'vaseline_unsori', 'filtre_consumabile', 'garnituri_etansari']);
    $method = new ReflectionMethod(AutoComponentCatalogService::class, 'buildAutoComponentCode');
    $catalog = new AutoComponentCatalogService($db);
    check('codurile componentelor 1-17 neschimbate (ex. VAL-DIF-003)', $method->invoke($catalog, 'Valva Diferentiala', 3) === 'VAL-DIF-003' && $method->invoke($catalog, 'Hidraulic', 12) === 'HID-012');
    check('cod curat pentru categorie cu diacritice', $method->invoke($catalog, 'Uleiuri și lubrifianți', 1) === 'ULE-LUB-001');
    $componentsBefore = count($catalog->components(true));
    $existing = array_values(array_filter($catalog->components(), static fn ($c) => $c['category_id'] === 11))[0] ?? null;
    if ($existing !== null) {
        $catalog->setItemType($existing['key'], 'consumabil');
        $after = $catalog->components()[$existing['key']];
        check('o componentă din Livrare Gaz devine „Consumabil” fără să-și schimbe categoria', $after['item_type'] === 'consumabil' && $after['category_id'] === 11);
    }
    $newCategory = $catalog->addCategory('Ulei motor test', 'uleiuri_lubrifianti', 'bi-droplet-fill', 'fluid');
    $added = $catalog->addComponent($newCategory, 'Ulei 15W-40 test');
    $addedRow = $catalog->components()[$added['key']];
    check('categorie nouă (18+) creată din interfață, fără cod nou', $newCategory >= 18 && $addedRow['item_type'] === 'fluid');
    check('componentele existente rămân (doar +1)', count($catalog->components(true)) === $componentsBefore + 1);
    check('categoria inițială nu se poate dezactiva', expectError(fn () => $catalog->setNodeActive('category', '11', false), 'structura inițială') === 'ok');

    // --- produse si recipiente ---------------------------------------------------------------
    echo "\n[Recipiente și transferuri]\n";
    $oil = $service->saveProduct(['denumire' => 'TEST Ulei motor 15W-40', 'tip_articol' => 'fluid', 'tip_fluid' => 'ulei_motor', 'vascozitate' => '15W-40', 'um' => 'L'], null, null);
    $oilOther = $service->saveProduct(['denumire' => 'TEST Ulei motor 15W-40 alt brand', 'tip_articol' => 'fluid', 'tip_fluid' => 'ulei_motor', 'vascozitate' => '15W-40', 'um' => 'L'], null, null);
    $adblue = $service->saveProduct(['denumire' => 'TEST AdBlue', 'tip_articol' => 'fluid', 'tip_fluid' => 'adblue', 'um' => 'L', 'permite_retur' => '1'], null, null);
    $barrel = $service->saveContainer(['cod' => 'TEST-BUT-200', 'tip' => 'butoi', 'capacitate' => '200', 'produs_id' => $oil, 'cantitate_initiala' => '200', 'idempotency_key' => $key(), 'prag_minim' => '40'], null, null);
    $can = $service->saveContainer(['cod' => 'TEST-BID-20', 'tip' => 'bidon', 'capacitate' => '20'], null, null);
    check('butoi 200 L creat cu 200 L (intrare înregistrată)', $q($barrel) === '200.000');

    $service->operate('transfer', ['recipient_sursa_id' => $barrel, 'recipient_dest_id' => $can, 'cantitate' => '20', 'idempotency_key' => $key()], null);
    check('Butoi 200 L → transfer 20 L → rămân 180 L', $q($barrel) === '180.000');
    check('Bidon primește 20 L și produsul butoiului', $q($can) === '20.000' && (int) $service->container($can)['produs_id'] === $oil);

    $dispense = $service->operate('completare', ['recipient_sursa_id' => $can, 'vehicul_id' => $vehicleA, 'cantitate' => '5', 'idempotency_key' => $key()], null);
    check('Bidon 20 L → completare 5 L → rămân 15 L', $q($can) === '15.000');
    check('Butoiul rămâne 180 L (fără dublă scădere)', $q($barrel) === '180.000');
    $consumption = $db->prepare("SELECT COALESCE(SUM(cantitate),0) FROM consumabile_miscari WHERE produs_id = ? AND tip IN ('completare','schimb_complet') AND stornata_de_id IS NULL");
    $consumption->execute([$oil]);
    check('consum efectiv înregistrat: 5 L (transferul nu e consum)', (string) $consumption->fetchColumn() === '5.000');
    check('vehiculul are +5 L pe produs', (string) ($service->vehicleSummary(['vehicle_ids' => [$vehicleA]])[0]['cantitate_neta'] ?? '') === '5.000');

    check('transfer peste capacitate → blocat', expectError(fn () => $service->operate('transfer', ['recipient_sursa_id' => $barrel, 'recipient_dest_id' => $can, 'cantitate' => '6', 'idempotency_key' => $key()], null), 'Capacitate depășită') === 'ok');
    check('transfer peste stoc → blocat', expectError(fn () => $service->operate('transfer', ['recipient_sursa_id' => $can, 'recipient_dest_id' => $barrel, 'cantitate' => '16', 'idempotency_key' => $key()], null), 'Stoc insuficient') === 'ok');
    check('soldurile neschimbate după blocări', $q($barrel) === '180.000' && $q($can) === '15.000');
    check('cantitate negativă / invalidă → respinsă', expectError(fn () => $service->operate('pierdere', ['recipient_sursa_id' => $can, 'cantitate' => '-2', 'motiv' => 'x', 'idempotency_key' => $key()], null)) === 'ok');

    echo "\n[Idempotență]\n";
    $dupKey = $key();
    $first = $service->operate('transfer', ['recipient_sursa_id' => $barrel, 'recipient_dest_id' => $can, 'cantitate' => '1', 'idempotency_key' => $dupKey], null);
    $second = $service->operate('transfer', ['recipient_sursa_id' => $barrel, 'recipient_dest_id' => $can, 'cantitate' => '1', 'idempotency_key' => $dupKey], null);
    $count = $db->prepare('SELECT COUNT(*) FROM consumabile_miscari WHERE idempotency_key = ?');
    $count->execute([$dupKey]);
    check('aceeași operațiune trimisă de două ori → o singură înregistrare', (int) $count->fetchColumn() === 1 && $second['duplicate'] && $first['movement']['id'] === $second['movement']['id']);
    check('stocul scăzut o singură dată', $q($barrel) === '179.000' && $q($can) === '16.000');

    echo "\n[Compatibilitate]\n";
    $otherDrum = $service->saveContainer(['cod' => 'TEST-BUT-ALT', 'tip' => 'butoi', 'capacitate' => '60', 'produs_id' => $oilOther, 'cantitate_initiala' => '30', 'idempotency_key' => $key()], null, null);
    check('aceeași vâscozitate, alt produs → amestec blocat (nu e compatibil automat)', expectError(fn () => $service->operate('transfer', ['recipient_sursa_id' => $otherDrum, 'recipient_dest_id' => $can, 'cantitate' => '1', 'idempotency_key' => $key()], null), 'Amestec interzis') === 'ok');
    $service->setCompatible($oil, $oilOther, true, 'aprobat de producător', null);
    $service->operate('transfer', ['recipient_sursa_id' => $otherDrum, 'recipient_dest_id' => $can, 'cantitate' => '1', 'idempotency_key' => $key()], null);
    check('după declarare explicită → amestec permis', $q($can) === '17.000');
    $adDrum = $service->saveContainer(['cod' => 'TEST-IBC-AD', 'tip' => 'ibc', 'capacitate' => '1000', 'produs_id' => $adblue, 'cantitate_initiala' => '500', 'idempotency_key' => $key()], null, null);
    check('AdBlue în bidonul cu ulei → blocat', expectError(fn () => $service->operate('transfer', ['recipient_sursa_id' => $adDrum, 'recipient_dest_id' => $can, 'cantitate' => '1', 'idempotency_key' => $key()], null), 'Amestec interzis') === 'ok');

    echo "\n[Schimb complet, retur, pierdere, ajustare, stornare]\n";
    $change = $service->operate('schimb_complet', ['recipient_sursa_id' => $barrel, 'vehicul_id' => $vehicleA, 'cantitate' => '40', 'cantitate_scoasa' => '38', 'km_vehicul' => '150000', 'sistem' => 'Motor', 'idempotency_key' => $key()], null);
    check('schimb ulei → înregistrare separată de completare', $change['movement']['tip'] === 'schimb_complet' && $dispense['movement']['tip'] === 'completare');
    $summary = $service->vehicleSummary(['vehicle_ids' => [$vehicleA]]);
    $oilRow = array_values(array_filter($summary, static fn ($r) => (int) $r['produs_id'] === $oil))[0] ?? [];
    check('situația vehiculului: 1 completare + 1 schimb, nivel necunoscut', (int) ($oilRow['completari'] ?? 0) === 1 && (int) ($oilRow['schimburi'] ?? 0) === 1 && ($oilRow['status'] ?? '') === 'date_insuficiente');

    check('retur pentru produs care nu permite → blocat', expectError(fn () => $service->operate('retur', ['referinta_id' => $change['movement']['id'], 'recipient_dest_id' => $barrel, 'cantitate' => '1', 'motiv' => 'nefolosit', 'idempotency_key' => $key()], null), 'nu permite retur') === 'ok');
    $adOut = $service->operate('completare', ['recipient_sursa_id' => $adDrum, 'vehicul_id' => $vehicleB, 'cantitate' => '30', 'idempotency_key' => $key()], null);
    check('retur peste cantitatea ieșită → blocat', expectError(fn () => $service->operate('retur', ['referinta_id' => $adOut['movement']['id'], 'recipient_dest_id' => $adDrum, 'cantitate' => '31', 'motiv' => 'nefolosit', 'idempotency_key' => $key()], null), 'cel mult') === 'ok');
    $service->operate('retur', ['referinta_id' => $adOut['movement']['id'], 'recipient_dest_id' => $adDrum, 'cantitate' => '10', 'motiv' => 'canistră nefolosită', 'idempotency_key' => $key()], null);
    check('retur trasabil 10 L → IBC 480 L', $q($adDrum) === '480.000');

    $service->operate('pierdere', ['recipient_sursa_id' => $can, 'cantitate' => '2', 'motiv' => 'vărsare la umplere', 'idempotency_key' => $key()], null);
    check('pierdere 2 L → bidon 15 L, fără consum pe vehicul', $q($can) === '15.000');
    check('pierdere fără motiv → blocată', expectError(fn () => $service->operate('pierdere', ['recipient_sursa_id' => $can, 'cantitate' => '1', 'idempotency_key' => $key()], null), 'motivul') === 'ok');
    $adjust = $service->operate('ajustare', ['recipient_id' => $barrel, 'cantitate_numarata' => '138.5', 'motiv' => 'inventar lunar', 'idempotency_key' => $key()], null);
    check('ajustare inventar 139 → 138,5 L (mișcare -0,5 L)', $q($barrel) === '138.500' && $adjust['movement']['cantitate'] === '0.500');

    $t = $service->operate('transfer', ['recipient_sursa_id' => $barrel, 'recipient_dest_id' => $can, 'cantitate' => '3', 'idempotency_key' => $key()], null);
    $service->operate('stornare', ['referinta_id' => $t['movement']['id'], 'motiv' => 'cantitate greșită', 'idempotency_key' => $key()], null);
    check('stornarea transferului readuce soldurile', $q($barrel) === '138.500' && $q($can) === '15.000');
    check('originalul rămâne în istoric, marcat stornat', !empty($service->movement($t['movement']['id'])['stornata_de_id']));
    check('a doua stornare → blocată', expectError(fn () => $service->operate('stornare', ['referinta_id' => $t['movement']['id'], 'motiv' => 'x', 'idempotency_key' => $key()], null), 'deja stornată') === 'ok');
    check('stornarea iesirii cu retur activ → blocată', expectError(fn () => $service->operate('stornare', ['referinta_id' => $adOut['movement']['id'], 'motiv' => 'x', 'idempotency_key' => $key()], null), 'retururile') === 'ok');

    echo "\n[Produs în mai multe vehicule]\n";
    $service->operate('completare', ['recipient_sursa_id' => $barrel, 'vehicul_id' => $vehicleB, 'cantitate' => '2', 'idempotency_key' => $key()], null);
    $rows = array_values(array_filter($service->vehicleSummary(['vehicle_ids' => [$vehicleA, $vehicleB]]), static fn ($r) => (int) $r['produs_id'] === $oil));
    check('aceeași identitate de catalog, istoric separat per vehicul', count($rows) === 2 && count(array_unique(array_column($rows, 'vehicul_id'))) === 2 && count($service->products()) >= 3);
    $totalOil = $db->prepare("SELECT COUNT(*) FROM consumabile_produse WHERE denumire = 'TEST Ulei motor 15W-40'");
    $totalOil->execute();
    check('un singur rând de catalog pentru produs', (int) $totalOil->fetchColumn() === 1);

    echo "\n[Compatibilitate pe numere de înmatriculare]\n";
    $platesOnly = $service->saveProduct(['denumire' => 'TEST Ulei doar ' . $vehicleA, 'um' => 'L', 'compat_mode' => 'vehicule',
        'tipuri_vehicul' => ['camion'], 'vehicule_ids' => [(string) $vehicleA, '999999999', '0']], null, null);
    $platesRow = $service->products()[$platesOnly];
    check('se salvează doar vehiculele existente, tipurile se golesc', $platesRow['vehicule_list'] === [$vehicleA] && $platesRow['tipuri_vehicul_list'] === []);
    $platesCan = $service->saveContainer(['cod' => 'TEST-BID-NR', 'tip' => 'bidon', 'capacitate' => '20', 'produs_id' => $platesOnly, 'cantitate_initiala' => '10', 'idempotency_key' => $key()], null, null);
    check('completare blocată pe un vehicul nebifat', expectError(fn () => $service->operate('completare', ['recipient_sursa_id' => $platesCan, 'vehicul_id' => $vehicleB, 'cantitate' => '1', 'idempotency_key' => $key()], null), 'compatibil cu vehiculul') === 'ok');
    $service->operate('completare', ['recipient_sursa_id' => $platesCan, 'vehicul_id' => $vehicleA, 'cantitate' => '1', 'idempotency_key' => $key()], null);
    check('completare permisă pe vehiculul bifat', $q($platesCan) === '9.000');
    $service->saveProduct(['denumire' => 'TEST Ulei doar ' . $vehicleA, 'um' => 'L', 'compat_mode' => 'tipuri', 'vehicule_ids' => [(string) $vehicleA]], $platesOnly, null);
    check('modul „pe tip” șterge lista de numere', $service->products()[$platesOnly]['vehicule_list'] === []);
    $withCategory = array_filter($service->vehicles(), static fn ($v) => (int) ($v['categorie_capacitate_id'] ?? 0) > 0);
    check('vehiculele au categoria de capacitate pentru grupare', $withCategory !== []);

    echo "\n[Recipient]\n";
    check('capacitatea nu poate scădea sub cantitate', expectError(fn () => $service->saveContainer(['tip' => 'bidon', 'capacitate' => '10', 'produs_id' => $oil], $can, null), 'Capacitatea') === 'ok');
    check('produsul nu se schimbă cât recipientul are stoc', expectError(fn () => $service->saveContainer(['tip' => 'bidon', 'capacitate' => '20', 'produs_id' => $adblue], $can, null), 'gol') === 'ok');
    check('stocul nu devine negativ nicăieri', (int) $db->query('SELECT COUNT(*) FROM consumabile_recipiente WHERE cantitate < 0 OR cantitate > capacitate')->fetchColumn() === 0);
} catch (Throwable $exception) {
    check('fără excepții neașteptate', false, $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
} finally {
    $db->rollBack();
    AutoComponentCatalogService::resetCache();
}

// --- Partea B: transferuri simultane ---------------------------------------------------
echo "\nPartea B — două procese transferă simultan din același butoi\n";
$tag = bin2hex(random_bytes(3));
$created = ['produs' => null, 'recipiente' => []];
try {
    $product = $service->saveProduct(['denumire' => 'TESTCONC Ulei ' . $tag, 'um' => 'L'], null, null);
    $created['produs'] = $product;
    $source = $service->saveContainer(['cod' => 'TESTCONC-SRC-' . $tag, 'tip' => 'butoi', 'capacitate' => '10', 'produs_id' => $product, 'cantitate_initiala' => '10', 'idempotency_key' => $key()], null, null);
    $destA = $service->saveContainer(['cod' => 'TESTCONC-A-' . $tag, 'tip' => 'bidon', 'capacitate' => '20'], null, null);
    $destB = $service->saveContainer(['cod' => 'TESTCONC-B-' . $tag, 'tip' => 'bidon', 'capacitate' => '20'], null, null);
    $created['recipiente'] = [$source, $destA, $destB];

    $php = PHP_BINARY;
    $spawn = static function (int $dest, string $suffix) use ($php, $source, $tag): array {
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__FILE__) . " --worker $source $dest 8 $tag$suffix";
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        return [$proc, $pipes];
    };
    [$p1, $pipes1] = $spawn($destA, 'a');
    [$p2, $pipes2] = $spawn($destB, 'b');
    $out1 = stream_get_contents($pipes1[1]);
    $err1 = stream_get_contents($pipes1[2]);
    $out2 = stream_get_contents($pipes2[1]);
    $err2 = stream_get_contents($pipes2[2]);
    proc_close($p1);
    proc_close($p2);
    $r1 = json_decode((string) $out1, true) ?: ['ok' => -1, 'blocked' => 0];
    $r2 = json_decode((string) $out2, true) ?: ['ok' => -1, 'blocked' => 0];
    if ($err1 !== '' || $err2 !== '') {
        echo "  (stderr) " . trim($err1 . ' ' . $err2) . "\n";
    }
    $okTotal = (int) $r1['ok'] + (int) $r2['ok'];
    check('16 încercări de 1 L din 10 L → exact 10 reușite', $okTotal === 10, json_encode([$r1, $r2]));
    check('butoiul sursă = 0 L (nu negativ)', $q($source) === '0.000', $q($source));
    check('suma destinațiilor = 10 L (stoc consistent)', $service->milli($q($destA)) + $service->milli($q($destB)) === 10000, $q($destA) . ' + ' . $q($destB));
    $moves = $db->prepare("SELECT COUNT(*) FROM consumabile_miscari WHERE recipient_sursa_id = ? AND tip = 'transfer'");
    $moves->execute([$source]);
    check('10 mișcări de transfer în jurnal', (int) $moves->fetchColumn() === 10);
} catch (Throwable $exception) {
    check('concurență fără excepții', false, $exception->getMessage());
} finally {
    // Curatenie: datele de test (singurul loc unde se sterg miscari — jurnalul aplicatiei nu are stergere).
    if ($created['recipiente'] !== []) {
        $ids = implode(',', array_map('intval', $created['recipiente']));
        $db->exec("DELETE FROM consumabile_miscari WHERE recipient_sursa_id IN ($ids) OR recipient_dest_id IN ($ids)");
        $db->exec("DELETE FROM consumabile_recipiente WHERE id IN ($ids)");
    }
    if ($created['produs'] !== null) {
        $db->exec('DELETE FROM consumabile_produse WHERE id = ' . (int) $created['produs']);
    }
}

echo "\n$passed ok, $failed FAIL\n";
exit($failed > 0 ? 1 : 0);
