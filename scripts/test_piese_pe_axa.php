<?php
declare(strict_types=1);

/**
 * Teste pentru „Piese pe axă” (pagina Auto): bife pe numarul axei, nu pe tipul ei, si copierea
 * axelor pe alt vehicul de acelasi tip.
 *
 *   DB_NAME=fleet_fin_test php scripts/test_piese_pe_axa.php
 *
 * Scrie in baza de test; tabelele atinse se salveaza la inceput si se restaureaza la final.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
if (!str_contains(DB_NAME, 'test')) {
    fwrite(STDERR, 'Refuz: baza „' . DB_NAME . "” nu este o copie de test (DB_NAME=fleet_fin_test).\n");
    exit(1);
}
require_once $root . '/htdocs/includes/helpers.php';
spl_autoload_register(static function (string $class) use ($root): void {
    foreach (['services', 'models', 'controllers'] as $dir) {
        $file = $root . '/htdocs/' . $dir . '/' . $class . '.php';
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

$passed = 0;
$failed = 0;
/** Matricea unei componente pe axe intregi: axa -> toate rotile ei. */
function full(array $axles, array $wheels): array
{
    $result = [];
    foreach ($axles as $axle) {
        $result[$axle] = $wheels[$axle];
    }
    return $result;
}

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok || $detail === '' ? '' : ' — ' . $detail) . "\n";
}

$db = get_pdo();
$service = new VehicleStructureService($db);
$service->prepare();

$tables = ['mentenanta_auto_configurari', 'vehicule_structura_diferente', 'mentenanta_auto_structuri_componente', 'mentenanta_auto_structuri_sisteme'];
foreach ($tables as $table) {
    $db->exec("DROP TABLE IF EXISTS _bk_$table");
    $db->exec("CREATE TABLE _bk_$table AS SELECT * FROM $table");
}

try {
    // Doua semiremorci de acelasi tip, cu cel putin 3 axe.
    $byType = [];
    foreach ($db->query('SELECT DISTINCT vehicle_id FROM vehicule_anvelope_pozitii WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $profile = $service->profile((int) $id);
        if ($profile !== null && $profile['axles'] >= 3 && $service->effective((int) $id) !== null) {
            $byType[$profile['type']][] = (int) $id;
        }
    }
    $pair = null;
    foreach ($byType as $ids) {
        if (count($ids) >= 2) {
            $pair = $ids;
            break;
        }
    }
    if ($pair === null) {
        throw new RuntimeException('Nu exista doua vehicule de acelasi tip cu 3+ axe.');
    }
    [$a, $b] = $pair;
    echo "Sursa #$a, tinta #$b\n";

    $effective = $service->effective($a);
    $filter = $service->schemeFilter($a);
    $axles = array_keys($effective['axle_kinds']);
    sort($axles);
    // O componenta de pe axe (franare etc.).
    $sections = $service->sections($service->profile($a)['type'], $effective['systems'], $effective['components']);
    $key = null;
    $matrixKeys = [];
    foreach ($sections['sections'] as $section) {
        if ($section['axle_scoped']) {
            foreach ($section['components'] as $row) {
                $matrixKeys[$row['key']] = true;
                $key ??= $row['key'];
            }
        }
    }
    check('vehiculul are componente pe axe', $key !== null);

    // 1. Doua axe de acelasi tip primesc piese diferite.
    $sameKind = null;
    foreach ($effective['axle_kinds'] as $no => $kind) {
        foreach ($effective['axle_kinds'] as $no2 => $kind2) {
            if ($no < $no2 && $kind === $kind2) {
                $sameKind ??= [$no, $no2];
            }
        }
    }
    $W = $effective['axle_wheels'];
    $WB = $service->effective($b)['axle_wheels'];
    $matrix = [];
    foreach (array_keys($matrixKeys) as $k) {
        $matrix[$k] = full($axles, $W);
    }
    $onlyOn = $sameKind !== null ? [$sameKind[0]] : [$axles[0]];
    $matrix[$key] = full($onlyOn, $W);
    $result = $service->apply(['op' => 'axle_matrix_save', 'vehicle_id' => $a, 'matrix_keys' => array_keys($matrixKeys), 'matrix' => $matrix]);
    check('salvare tabel: un mesaj de succes', str_contains($result['message'], 'salvate'), $result['message']);
    $filter = $service->schemeFilter($a);
    check('schema: componenta doar pe axa aleasa', $filter['components'][$key] === $onlyOn, json_encode($filter['components'][$key]));
    if ($sameKind !== null) {
        check('axe de acelasi tip (A' . $sameKind[0] . ', A' . $sameKind[1] . ') au piese diferite', !in_array($sameKind[1], $filter['components'][$key], true));
    }
    $others = array_diff(array_keys($matrixKeys), [$key]);
    $other = reset($others);
    check('celelalte componente raman pe toate axele', $other === false || $filter['components'][$other] === null, json_encode($filter['components'][$other] ?? null));

    // 2. Toate bifate inapoi = null (fara rand inutil daca e identic cu generalul).
    $matrix[$key] = full($axles, $W);
    $service->apply(['op' => 'axle_matrix_save', 'vehicle_id' => $a, 'matrix_keys' => array_keys($matrixKeys), 'matrix' => $matrix]);
    check('toate axele bifate = toate', $service->schemeFilter($a)['components'][$key] === null);

    // 3. Copiere: sursa are componenta doar pe ultima axa; tinta o primeste la fel pe axele copiate.
    $last = end($axles);
    $matrix[$key] = full([$last], $W);
    $service->apply(['op' => 'axle_matrix_save', 'vehicle_id' => $a, 'matrix_keys' => array_keys($matrixKeys), 'matrix' => $matrix]);
    $targetAxles = array_keys($service->effective($b)['axle_kinds']);
    sort($targetAxles);
    $copy = array_values(array_intersect($axles, $targetAxles));
    $result = $service->apply(['op' => 'axle_copy', 'vehicle_id' => $a, 'copy_axles' => $copy, 'copy_targets' => [$b]]);
    check('copiere: mesaj', str_contains($result['message'], 'copiate pe 1 vehicul'), $result['message']);
    $targetFilter = $service->schemeFilter($b);
    $expected = array_values(array_merge(array_diff($targetAxles, $copy), in_array($last, $copy, true) ? [$last] : []));
    sort($expected);
    check('tinta: componenta pe aceleasi axe', ($targetFilter['components'][$key] ?? null) === ($expected === $targetAxles ? null : $expected), json_encode($targetFilter['components'][$key] ?? 'lipsa'));
    check('tinta: alta componenta tot pe toate axele', $other === false || (array_key_exists($other, $targetFilter['components']) && $targetFilter['components'][$other] === null), json_encode($targetFilter['components'][$other] ?? 'lipsa'));

    // 4. Copiere doar a unei axe: celelalte axe ale tintei raman neatinse.
    $matrixB = array_map(static fn () => full($targetAxles, $WB), $matrix);
    $service->apply(['op' => 'axle_matrix_save', 'vehicle_id' => $b, 'matrix_keys' => array_keys($matrixKeys), 'matrix' => $matrixB]);
    $service->apply(['op' => 'axle_copy', 'vehicle_id' => $a, 'copy_axles' => [$axles[0]], 'copy_targets' => [$b]]);
    $after = $service->schemeFilter($b)['components'][$key] ?? 'lipsa';
    $want = array_values(array_diff($targetAxles, [$axles[0]]));
    check('copiere A' . $axles[0] . ': tinta pierde componenta doar pe A' . $axles[0], $after === $want, json_encode($after));

    // 5. Componenta scoasa de pe toate axele copiate dispare din tinta, iar copierea inapoi o readuce.
    $service->apply(['op' => 'axle_copy', 'vehicle_id' => $b, 'copy_axles' => $axles, 'copy_targets' => [$a]]);
    $back = $service->schemeFilter($a)['components'][$key] ?? 'lipsa';
    check('copiere inversa: sursa primeste axele tintei', $back === $want, json_encode($back));

    // 5b. Tinta fara componenta: copierea o adauga, cu axele sursei.
    $service->apply(['op' => 'component_remove', 'vehicle_id' => $b, 'component_key' => $key, 'category' => '']);
    check('tinta: componenta eliminata', !array_key_exists($key, $service->schemeFilter($b)['components']));
    $service->apply(['op' => 'axle_copy', 'vehicle_id' => $a, 'copy_axles' => $copy, 'copy_targets' => [$b]]);
    $added = $service->schemeFilter($b)['components'];
    check('tinta: componenta readusa pe axele sursei', array_key_exists($key, $added) && $added[$key] === $want, json_encode($added[$key] ?? 'lipsa'));

    // 6. Configuratie generala: tabelul se salveaza pe axe.
    $structure = $service->effective($a)['structure'];
    $gMatrix = [];
    $gKeys = array_values(array_filter(array_keys($structure['components']), static fn ($k) => isset($matrixKeys[$k])));
    $gAxles = array_keys($structure['axle_kinds']);
    foreach ($gKeys as $k) {
        $gMatrix[$k] = full($gAxles, $structure['axle_wheels']);
    }
    if ($gKeys !== []) {
        $gMatrix[$gKeys[0]] = full([$gAxles[0]], $structure['axle_wheels']);
        $service->apply(['op' => 'axle_matrix_save', 'structura' => $structure['id'], 'matrix_keys' => $gKeys, 'matrix' => $gMatrix]);
        $fresh = new VehicleStructureService($db);
        check('generala: axe salvate', $fresh->structure($structure['id'])['components'][$gKeys[0]]['axles'] === [(string) $gAxles[0]]);
    }

    // 8. Pe roti: pe o axa cu roti duble, componenta doar pe exterior (SE, DE).
    $dualAxle = null;
    foreach ($W as $no => $wheels) {
        if (count($wheels) === 4) {
            $dualAxle ??= $no;
        }
    }
    if ($dualAxle === null) {
        echo "  (sursa nu are axa cu roti duble: testele pe roti sar)\n";
    } else {
        $wheelKey = null;
        foreach ($sections['sections'] as $section) {
            if ($section['key'] === 'franare' && $section['components'] !== []) {
                $wheelKey = $section['components'][0]['key'];
            }
        }
        $wheelKey ??= $key;
        $matrix = [];
        foreach (array_keys($matrixKeys) as $k) {
            $matrix[$k] = full($axles, $W);
        }
        $matrix[$wheelKey][$dualAxle] = ['LO', 'RO'];
        $service->apply(['op' => 'axle_matrix_save', 'vehicle_id' => $a, 'matrix_keys' => array_keys($matrixKeys), 'matrix' => $matrix]);
        $filter = $service->schemeFilter($a);
        check('roti: componenta ramane pe axa A' . $dualAxle, in_array($dualAxle, (array) $filter['components'][$wheelKey], true), json_encode($filter['components'][$wheelKey]));
        check('roti: doar SE + DE pe A' . $dualAxle, ($filter['wheels'][$wheelKey][$dualAxle] ?? null) === ['LO', 'RO'], json_encode($filter['wheels'][$wheelKey] ?? null));
        $eff = $service->effective($a);
        $text = VehicleStructureService::describePlacement($eff['components'][$wheelKey], $eff['axle_wheels'], $eff['axle_kinds']);
        check('roti: textul arata (SE, DE)', str_contains($text, 'A' . $dualAxle . ' (SE, DE)'), $text);

        // Schema: capatul stang al axei listeaza piesa cu nota „doar exterior”.
        $positions = [];
        foreach ($service->profile($a)['layout'] as $axle) {
            $positions[] = ['axle' => $axle['no'], 'axle_type' => $axle['type'], 'dual' => $axle['dual']];
        }
        $member = ['type' => $service->profile($a)['type'], 'positions' => $positions];
        $slots = VehicleComponentRegistry::memberSlots($member, [], $service->catalogMap(), $filter);
        $note = null;
        foreach ($slots as $slot) {
            if (($slot['axle'] ?? null) === $dualAxle && ($slot['side'] ?? '') === 'L' && in_array($wheelKey, $slot['components'] ?? [], true)) {
                $note = $slot['wheel_notes'][$wheelKey] ?? '';
            }
        }
        check('schema: nota „doar exterior” pe A' . $dualAxle . ' stânga', $note === 'doar exterior', var_export($note, true));

        // Doar interior stanga: capatul drept nu mai are piesa.
        $matrix[$wheelKey][$dualAxle] = ['LI'];
        $service->apply(['op' => 'axle_matrix_save', 'vehicle_id' => $a, 'matrix_keys' => array_keys($matrixKeys), 'matrix' => $matrix]);
        $slots = VehicleComponentRegistry::memberSlots($member, [], $service->catalogMap(), $service->schemeFilter($a));
        $right = false;
        foreach ($slots as $slot) {
            if (($slot['axle'] ?? null) === $dualAxle && ($slot['side'] ?? '') === 'R' && in_array($wheelKey, $slot['components'] ?? [], true)) {
                $right = true;
            }
        }
        check('schema: doar SI => dreapta A' . $dualAxle . ' fara piesa', !$right);

        // Copiere roata cu roata pe tinta.
        if (isset($WB[$dualAxle])) {
            $service->apply(['op' => 'axle_copy', 'vehicle_id' => $a, 'copy_axles' => [$dualAxle], 'copy_targets' => [$b]]);
            $wantB = count($WB[$dualAxle]) === 4 ? ['LI'] : ['L'];
            $gotB = $service->schemeFilter($b)['wheels'][$wheelKey][$dualAxle] ?? null;
            check('copiere roti: tinta are ' . implode(',', $wantB) . ' pe A' . $dualAxle, $gotB === $wantB, json_encode($gotB));
        }

        // Fara nicio roata bifata: eroare.
        $matrix[$wheelKey] = [];
        try {
            $service->apply(['op' => 'axle_matrix_save', 'vehicle_id' => $a, 'matrix_keys' => array_keys($matrixKeys), 'matrix' => $matrix]);
            check('fara roti: eroare', false);
        } catch (InvalidArgumentException $e) {
            check('fara roti: eroare', true);
        }
    }

    // 7. Erori.
    try {
        $service->apply(['op' => 'axle_copy', 'vehicle_id' => $a, 'copy_axles' => [], 'copy_targets' => [$b]]);
        check('fara axe: eroare', false);
    } catch (InvalidArgumentException $e) {
        check('fara axe: eroare', true);
    }
} catch (Throwable $exception) {
    check('exceptie', false, $exception->getMessage() . ' @' . $exception->getLine());
} finally {
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $table) {
        $db->exec("DELETE FROM $table");
        $db->exec("INSERT INTO $table SELECT * FROM _bk_$table");
        $db->exec("DROP TABLE _bk_$table");
    }
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
}

echo "\n$passed ok, $failed esuate\n";
exit($failed > 0 ? 1 : 0);
