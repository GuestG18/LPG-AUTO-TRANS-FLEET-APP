<?php
declare(strict_types=1);

/**
 * Teste Consumabile — etapa 4: alertele (deschidere, deduplicare, inchidere automata / manuala)
 * si conditiile calculate din stoc si din sistemele vehiculelor.
 *
 *   php scripts/test_consumabile_alerte.php
 *
 * Ruleaza intr-o tranzactie ANULATA la final (alertele reale deschise nu sunt atinse: testul
 * foloseste chei proprii, iar recalcularea completa se verifica doar pe conditii).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/includes/helpers.php';
require_once $root . '/htdocs/models/ReviziiModel.php';
require_once $root . '/htdocs/services/AutoComponentCatalogService.php';
require_once $root . '/htdocs/services/ConsumabileStockService.php';
require_once $root . '/htdocs/services/VehicleFluidService.php';
require_once $root . '/htdocs/services/AdBlueService.php';
require_once $root . '/htdocs/services/ConsumabileAlertService.php';
require_once $root . '/htdocs/controllers/NotificationRuleController.php';

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

$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$stock = new ConsumabileStockService($db);
$stock->ensureSchema();
(new AdBlueService($db))->ensureSchema();
AutoComponentCatalogService::taxonomy($db);
new ReviziiModel($db);
$alerts = new ConsumabileAlertService($db);
$alerts->ensureSchema();

echo "[Evenimente în page=notificari]\n";
$ref = new ReflectionClass(NotificationRuleController::class);
$labels = $ref->getConstant('EVENT_LABELS');
$entity = $ref->getConstant('EVENT_ENTITY');
$missing = array_diff(array_keys(ConsumabileAlertService::TYPES), array_keys($labels));
check('cele 8 tipuri de alertă sunt evenimente de notificare', $missing === [] && count(ConsumabileAlertService::TYPES) === 8, implode(',', $missing));
check('toate pe entitatea „consumabil”', count(array_filter(array_keys(ConsumabileAlertService::TYPES), static fn ($k) => ($entity[$k] ?? '') === 'consumabil')) === 8);

$count = static function (string $key) use ($db): array {
    $stmt = $db->prepare('SELECT status, COUNT(*) FROM consumabile_alerte WHERE cheie = ? GROUP BY status');
    $stmt->execute([$key]);

    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR) + ['deschisa' => 0, 'rezolvata' => 0];
};
$condition = static fn (string $amp = 'a1'): array => ['tip' => 'consumabil_completare', 'cheie' => 'test:sistem:1', 'amprenta' => $amp, 'vehicle_id' => null,
    'subiect' => 'TEST · Motor', 'detalii' => 'Nivel sub minim.', 'recomandare' => 'Completează', 'link_sub' => 'vehicule'];
$now = new DateTimeImmutable('now');

$db->beginTransaction();
try {
    // Alertele reale deschise raman deschise in test: le transmitem impreuna cu conditia de test.
    $realOpen = array_map(static fn (array $a): array => ['tip' => $a['tip'], 'cheie' => $a['cheie'], 'amprenta' => $a['amprenta'], 'vehicle_id' => $a['vehicle_id'],
        'subiect' => $a['subiect'], 'detalii' => (string) $a['detalii'], 'recomandare' => (string) $a['recomandare'], 'link_sub' => $a['link_sub']], $alerts->openAlerts());

    echo "\n[Ciclul de viață al unei alerte]\n";
    $alerts->evaluate($now, array_merge($realOpen, [$condition()]));
    check('condiție nouă → o alertă deschisă', $count('test:sistem:1')['deschisa'] === 1);
    $alerts->evaluate($now, array_merge($realOpen, [$condition(), $condition()]));
    $alerts->evaluate($now, array_merge($realOpen, [$condition()]));
    check('aceeași condiție la rulări repetate / duplicată → tot o singură alertă', $count('test:sistem:1')['deschisa'] === 1 && $count('test:sistem:1')['rezolvata'] === 0);
    $firstId = (int) $db->query("SELECT id FROM consumabile_alerte WHERE cheie = 'test:sistem:1' AND status = 'deschisa'")->fetchColumn();
    $alerts->evaluate($now, $realOpen);
    $row = $db->query("SELECT status, rezolvare FROM consumabile_alerte WHERE id = $firstId")->fetch(PDO::FETCH_ASSOC);
    check('condiția dispare la recalculare → alertă rezolvată automat (status actualizat)', $row['status'] === 'rezolvata' && $row['rezolvare'] === 'auto');
    $alerts->evaluate($now, array_merge($realOpen, [$condition('a2')]));
    $secondId = (int) $db->query("SELECT id FROM consumabile_alerte WHERE cheie = 'test:sistem:1' AND status = 'deschisa'")->fetchColumn();
    check('problema reapare → alertă nouă (notificare nouă), istoricul rămâne', $secondId > $firstId && $count('test:sistem:1')['rezolvata'] === 1);
    $alerts->close($secondId, 'inspecție fără scurgeri', null);
    $alerts->evaluate($now, array_merge($realOpen, [$condition('a2')]));
    check('închisă manual după verificare → nu se redeschide fără date noi', $count('test:sistem:1')['deschisa'] === 0);
    $alerts->evaluate($now, array_merge($realOpen, [$condition('a3')]));
    check('date noi (amprentă diferită) → se redeschide', $count('test:sistem:1')['deschisa'] === 1);
    $blocked = false;
    try {
        $alerts->close($secondId, 'x', null);
    } catch (InvalidArgumentException) {
        $blocked = true;
    }
    check('o alertă deja închisă nu se mai închide', $blocked);

    echo "\n[Condiții calculate din date]\n";
    $vehicleId = (int) $db->query("SELECT id FROM vehicule WHERE status = 'activ' AND nr_inmatriculare <> 'STOC-ANVELOPE'
        AND id NOT IN (SELECT vehicle_id FROM vehicule_sisteme_fluide WHERE sistem = 'transmisie') ORDER BY id LIMIT 1")->fetchColumn();
    $oil = $stock->saveProduct(['denumire' => 'TEST4 Ulei cutie', 'tip_fluid' => 'ulei_transmisie', 'um' => 'L'], null, null);
    $can = $stock->saveContainer(['cod' => 'TEST4-BID', 'tip' => 'bidon', 'capacitate' => '20', 'produs_id' => $oil, 'cantitate_initiala' => '3', 'prag_minim' => '5',
        'idempotency_key' => 'test4' . bin2hex(random_bytes(8))], null, null);
    $fluids = new VehicleFluidService($db);
    $system = $fluids->saveSystem(['vehicle_id' => $vehicleId, 'sistem' => 'transmisie', 'produs_id' => $oil, 'capacitate_nominala' => '12', 'sursa_km' => 'none'], null, null);
    $fluids->recordEvent(['sistem_id' => $system['id'], 'tip' => 'verificare', 'nivel_status' => 'sub_minim', 'idempotency_key' => 'test4' . bin2hex(random_bytes(8))], null);
    $conditions = $alerts->currentConditions($now);
    $byKey = [];
    foreach ($conditions as $c) {
        $byKey[$c['tip'] . '|' . $c['cheie']] = $c;
    }
    check('nivel sub minim confirmat → alertă „consumabil sub minim”', isset($byKey['consumabil_completare|sistem:' . $system['id']]));
    check('recipient sub prag → alertă „recipient cu stoc minim”', isset($byKey['consumabil_recipient_minim|recipient:' . $can]));
    // Necesar 10% din 12 L = 1,2 L, disponibil 3 L → fara alerta de stoc insuficient.
    check('stoc suficient pentru completare → fără alertă de stoc', !isset($byKey['consumabil_stoc_insuficient|stoc:sistem:' . $system['id']]));
    $stock->operate('pierdere', ['recipient_sursa_id' => $can, 'cantitate' => '2.5', 'motiv' => 'test', 'idempotency_key' => 'test4' . bin2hex(random_bytes(8))], null);
    $keys = array_map(static fn ($c) => $c['tip'] . '|' . $c['cheie'], $alerts->currentConditions($now));
    check('stocul scade sub necesar → „stoc insuficient pentru intervenție”', in_array('consumabil_stoc_insuficient|stoc:sistem:' . $system['id'], $keys, true));
    $stock->operate('completare', ['recipient_sursa_id' => $can, 'vehicul_id' => $vehicleId, 'sistem_id' => $system['id'], 'cantitate' => '0.5', 'pana_la_reper' => '1',
        'idempotency_key' => 'test4' . bin2hex(random_bytes(8))], null);
    $keys = array_map(static fn ($c) => $c['tip'] . '|' . $c['cheie'], $alerts->currentConditions($now));
    check('după completarea la reper condiția dispare (alerta s-ar închide)', !in_array('consumabil_completare|sistem:' . $system['id'], $keys, true));
    check('sistem fără sursă de km → fără alertă de telemetrie pentru el', !in_array('consumabil_date_indisponibile|telemetrie:' . $vehicleId, $keys, true)
        || (int) $db->query("SELECT COUNT(*) FROM vehicule_sisteme_fluide WHERE vehicle_id = $vehicleId AND sursa_km <> 'none' AND activ = 1")->fetchColumn() > 0);
} catch (Throwable $exception) {
    check('fără excepții neașteptate', false, $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
} finally {
    $db->rollBack();
}

echo "\n$passed ok, $failed FAIL\n";
exit($failed > 0 ? 1 : 0);
