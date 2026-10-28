<?php
declare(strict_types=1);

/**
 * Teste pentru programarile service pe WhatsApp (MaintenanceWhatsAppService), fara WhatsApp.
 *
 *   DB_NAME=fleet_wa_test php scripts/test_maintenance_whatsapp.php   (doar pe copia de test)
 *
 * Nu ruleaza intr-o tranzactie (InterventionWorkflowModel::updateFromPanel deschide una proprie):
 * tot ce se creeaza aici se sterge la final, dupa ID-urile maxime de la pornire.
 * Mesajele "primite" sunt simulate cu numarul soferului #48 (acelasi cu administratorul #1).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/includes/helpers.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/services/MaintenanceWhatsAppService.php';

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
        echo "\033[31m  FAIL\033[0m  $name" . ($detail !== '' ? "  -> $detail" : '') . "\n";
    }
}

$S = MaintenanceWhatsAppService::class;
$now = (int) strtotime('2026-10-28 10:00:00');

echo "\nComenzi\n";
check('PROGRAMARE SERVICE', $S::parseCommand('PROGRAMARE SERVICE') === ['command' => 'request', 'arg' => '']);
check('Reparație (cu diacritice) -> tip presetat', $S::parseCommand('Reparație') === ['command' => 'request', 'arg' => 'reparatie']);
check('revizie -> intretinere', $S::parseCommand(' revizie ') === ['command' => 'request', 'arg' => 'intretinere']);
check('STATUS SERVICE', $S::parseCommand('Status service') === ['command' => 'status']);
check('MENIU', $S::parseCommand('meniu') === ['command' => 'menu']);
check('CERERE #12', $S::parseCommand('CERERE #12') === ['command' => 'request_card', 'arg' => 12]);
check('"1" nu e comanda', $S::parseCommand('1') === null);
check('text liber nu e comanda', $S::parseCommand('am o problema cu service-ul') === null);
check('anulează', $S::isCancel('Anulează'));

echo "\nData preferata (azi = 28.10.2026)\n";
check('30.10', $S::parseDate('30.10', $now) === ['date' => '2026-10-30', 'time' => null]);
check('30.10 14:00', $S::parseDate('30.10 14:00', $now) === ['date' => '2026-10-30', 'time' => '14:00']);
check('12.11 e data, nu ora 12:11', $S::parseDate('12.11', $now) === ['date' => '2026-11-12', 'time' => null]);
check('30/10/2026 ora 9', $S::parseDate('30/10/2026 ora 9', $now) === ['date' => '2026-10-30', 'time' => '09:00']);
check('mâine 9:30', $S::parseDate('mâine 9:30', $now) === ['date' => '2026-10-29', 'time' => '09:30']);
check('azi', $S::parseDate('azi', $now) === ['date' => '2026-10-28', 'time' => null]);
check('05.01 -> anul urmator', $S::parseDate('05.01', $now) === ['date' => '2027-01-05', 'time' => null]);
check('27.10 (ieri, fara an) -> peste un an = prea departe', isset($S::parseDate('27.10', $now)['error']));
check('27.10.2026 -> in trecut', str_contains($S::parseDate('27.10.2026', $now)['error'] ?? '', 'trecut'));
check('31.02 -> invalida', isset($S::parseDate('31.02', $now)['error']));
check('text -> eroare', isset($S::parseDate('cand se poate', $now)['error']));

echo "\nCosturi si data executiei (coordonator)\n";
check('costuri: piese 250 manopera 120,5', $S::parseCosts('piese 250 manopera 120,5') === ['cost_piese' => 250.0, 'cost_manopera' => 120.5]);
check('costuri: doar manoperă', $S::parseCosts('Manoperă: 80') === ['cost_manopera' => 80.0]);
check('costuri: text fara sume -> null', $S::parseCosts('nu stiu') === null);
check('executie: azi', $S::parseExecutionDate('azi', $now) === '2026-10-28');
check('executie: 27.10', $S::parseExecutionDate('27.10', $now) === '2026-10-27');
check('executie: 30.10 (viitor apropiat) refuzata', $S::parseExecutionDate('30.10', $now) === null);
check('executie: 20.12 fara an -> anul trecut', $S::parseExecutionDate('20.12', $now) === '2025-12-20');

echo "\nMesajele catre sofer\n";
$row = ['id' => 7, 'status_interventie' => 'confirmata', 'data_programata' => '2026-10-30', 'ora_programata' => '10:00:00', 'furnizor' => 'Service X', 'nr_inmatriculare' => 'B 1 ABC'];
check('stare: confirmata = programata', $S::notifyState($row) === 'programata|2026-10-30|10:00');
check('aprobata: data, ora, service', str_contains((string) $S::driverMessage($row, 'de_verificat|2026-10-30|'), 'aprobată') && str_contains((string) $S::driverMessage($row, 'de_verificat|2026-10-30|'), '30.10.2026, ora 10:00') && str_contains((string) $S::driverMessage($row, 'de_verificat|2026-10-30|'), 'Service X'));
check('reprogramata', str_contains((string) $S::driverMessage($row, 'programata|2026-10-29|10:00'), 'Programarea #7'));
check('respinsa cu motiv', str_contains((string) $S::driverMessage(['status_interventie' => 'anulata'] + $row, 'de_verificat|2026-10-30|', 'Fără loc'), 'Motiv: Fără loc'));
check('in service', str_contains((string) $S::driverMessage(['status_interventie' => 'in_lucru'] + $row, 'programata|2026-10-30|10:00'), 'a intrat în service'));

// ------------------------------------------------------------------ baza de date
$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
echo "\nBaza: $dbName\n";
if ($dbName !== 'fleet_wa_test') {
    fwrite(STDERR, "Partea cu baza de date ruleaza doar pe fleet_wa_test (DB_NAME=fleet_wa_test).\n");
    exit($failed > 0 ? 1 : 0);
}

$outbox = new DriverWhatsAppModel($db);
$workflow = new InterventionWorkflowModel($db);
$config = ['enabled' => true, 'user_ids' => [1], 'session_minutes' => 30, 'driver_test_mode' => true, 'driver_ids' => [48]];
$service = new MaintenanceWhatsAppService($db, $outbox, $workflow, $config);
$service->ensureSchema();

$driver = $outbox->getActiveDriver(48);
$phone = (string) $driver['phone_e164'];
// mentenanta_piese: piesele-componenta din stoc pe care finalize() le creeaza la nevoie.
$tables = ['mentenanta_interventii_programate', 'mentenanta_interventii_istoric', 'mentenanta_interventii_lucrari', 'whatsapp_outbox', 'whatsapp_inbound_messages', 'audit_log',
    'mentenanta', 'mentenanta_piese_utilizari', 'mentenanta_piese'];
$max = [];
foreach ($tables as $table) {
    $max[$table] = (int) $db->query("SELECT COALESCE(MAX(id), 0) FROM $table")->fetchColumn();
}
$kmBefore = (int) $db->query('SELECT COUNT(*) FROM vehicule_service_km')->fetchColumn();
$db->prepare('DELETE FROM whatsapp_maintenance_sessions WHERE phone = :p')->execute([':p' => $phone]);

$seq = 0;
$send = static function (?string $text, ?string $action = null, ?MaintenanceWhatsAppService $svc = null, ?string $from = null) use (&$seq, $service, $phone): array {
    $svc ??= $service;
    return $svc->handleInbound(['wa_message_id' => 'TESTMNT' . getmypid() . '-' . (++$seq), 'phone' => $from ?? $phone,
        'kind' => $action !== null ? 'button' : 'text', 'action_id' => $action, 'text' => $text]);
};
$outSince = static function (int $id) use ($db): array {
    $stmt = $db->prepare('SELECT * FROM whatsapp_outbox WHERE id > :id ORDER BY id');
    $stmt->execute([':id' => $id]);
    return array_map(static function (array $r): array {
        $r['options'] = $r['options_json'] !== null ? json_decode((string) $r['options_json'], true) : [];
        return $r;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
};
$lastOutId = static fn(): int => (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM whatsapp_outbox')->fetchColumn();
$optionId = static function (array $message, string $suffix): ?string {
    foreach ($message['options'] as $o) {
        if (str_ends_with($o['id'], $suffix)) {
            return $o['id'];
        }
    }
    return null;
};
$intervention = static function (int $id) use ($db): array {
    $stmt = $db->prepare('SELECT * FROM mentenanta_interventii_programate WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};
$future = date('d.m', strtotime('+3 days'));
$futureIso = date('Y-m-d', strtotime('+3 days'));

/** Fluxul complet al soferului pana la rezumat; intoarce ultimul mesaj (rezumatul). */
$requestFlow = static function (string $description, string $date = '') use ($send, $outSince, $lastOutId, $future): array {
    $send('PROGRAMARE SERVICE');
    $result = null;
    $session = null;
    for ($i = 0; $i < 7; $i++) {
        $mark = $lastOutId();
        $messages = $outSince($mark - 1);
        $last = end($messages);
        $body = (string) $last['body'];
        if (str_contains($body, 'Pentru ce vehicul')) {
            $result = $send('1'); // primul vehicul alocat (cap tractor #48); al doilea e semiremorca cuplata
        } elseif (str_contains($body, 'intervenții deschise')) {
            $result = $send('1'); // Cerere noua
        } elseif (str_contains($body, 'Ce tip de intervenție')) {
            $result = $send('2'); // Reparatie
        } elseif (str_contains($body, 'Ce dată preferați')) {
            $result = $send($date !== '' ? $date : $future . ' 14:00');
        } elseif (str_contains($body, 'Descrieți pe scurt')) {
            $result = $send($description);
        } elseif (str_contains($body, 'Verificați cererea')) {
            return $last;
        } else {
            return $last;
        }
    }
    return [];
};

$created = [];
try {
    echo "\nMeniu si identificare\n";
    $mark = $lastOutId();
    $r = $send('MENIU');
    $out = $outSince($mark);
    check('MENIU: tratat', $r['handled'] === true && $r['outcome'] === 'menu', json_encode($r));
    check('MENIU: sofer + operator (acelasi numar) -> 4 optiuni (botul le trimite ca lista)', count($out) === 1
        && array_column($out[0]['options'], 'title') === ['📅 Programare service', '📋 Status service', '📥 Cereri service', '🛠️ Intervențiile mele'], json_encode($out[0]['options'] ?? null, JSON_UNESCAPED_UNICODE));
    $r = $send('2');
    check('MENIU: raspunsul "2" = Status service', $r['outcome'] === 'status_empty' || $r['outcome'] === 'status', json_encode($r));
    $r = $send('3');
    check('dupa un raspuns informativ meniul ramane deschis: "3" = Cereri service', in_array($r['outcome'], ['requests', 'requests_empty'], true), json_encode($r));
    $r = $send('4');
    check('...si "4" = Intervențiile mele (nu un mesaj liber)', in_array($r['outcome'], ['mine', 'mine_empty'], true), json_encode($r));
    $send('anulează');
    check('MENIU: mesaj catre sofer #48', (int) ($out[0]['driver_id'] ?? 0) === 48);
    check('numar necunoscut: netratat, fara raspuns', $send('MENIU', null, null, '+40700000001') === ['handled' => false, 'outcome' => 'unknown_sender']);
    check('text liber fara conversatie: nu e pentru acest flux', $service->claims(['phone' => $phone, 'text' => 'buna ziua']) === false);
    check('comanda: e pentru acest flux', $service->claims(['phone' => $phone, 'text' => 'programare service']) === true);
    $off = new MaintenanceWhatsAppService($db, $outbox, $workflow, ['enabled' => false] + $config);
    check('dezactivat: nimic nu e preluat', $off->claims(['phone' => $phone, 'text' => 'meniu', 'action_id' => 'mnt:m:status']) === false);

    echo "\nCererea soferului\n";
    $summary = $requestFlow('Frâna scârțâie la oprire, test automat');
    check('rezumat: vehicul, tip, data, descriere', str_contains((string) ($summary['body'] ?? ''), 'Reparație') && str_contains((string) $summary['body'], 'ora 14:00') && str_contains((string) $summary['body'], 'Frâna scârțâie'), (string) ($summary['body'] ?? ''));
    check('rezumat: Trimite / Modifică / Anulează', array_column($summary['options'] ?? [], 'title') === ['✅ Trimite', '✏️ Modifică', '❌ Anulează']);
    $sendButton = $optionId($summary, ':send');
    $mark = $lastOutId();
    $r = $send('✅ Trimite', $sendButton);
    $id1 = (int) ($r['intervention_id'] ?? 0);
    $created[] = $id1;
    check('Trimite -> interventie creata', $r['outcome'] === 'request_created' && $id1 > 0, json_encode($r));
    $row1 = $intervention($id1);
    check('interventie: De verificat, reparatie, sofer #48, vehicul alocat', ($row1['status_interventie'] ?? '') === 'de_verificat' && $row1['tip_interventie'] === 'reparatie' && (int) $row1['driver_id'] === 48 && (int) $row1['vehicle_id'] === 48, json_encode($row1));
    check('interventie: data si ora preferate', $row1['data_programata'] === $futureIso && substr((string) $row1['ora_programata'], 0, 5) === '14:00');
    check('sesizarea initiala = descrierea soferului', $row1['sesizare_initiala'] === 'Frâna scârțâie la oprire, test automat');
    check('fara responsabil inca (Nealocat)', $row1['responsabil_user_id'] === null);
    $events = $db->query('SELECT tip FROM mentenanta_interventii_istoric WHERE intervention_id = ' . $id1 . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    check('istoric: creare + whatsapp', in_array('creare', $events, true) && in_array('whatsapp', $events, true), json_encode($events));
    check('audit_log: create', (int) $db->query("SELECT COUNT(*) FROM audit_log WHERE modul = 'mentenanta_interventii' AND record_id = $id1 AND actiune = 'create'")->fetchColumn() === 1);
    check('legatura WhatsApp salvata', (int) $db->query("SELECT driver_id FROM whatsapp_maintenance_requests WHERE intervention_id = $id1")->fetchColumn() === 48);
    $out = $outSince($mark);
    $ack = array_values(array_filter($out, static fn(array $m): bool => $m['purpose'] === 'maintenance_request_ack'));
    $cards = array_values(array_filter($out, static fn(array $m): bool => $m['purpose'] === 'maintenance_request'));
    check('confirmare catre sofer cu numarul cererii', count($ack) === 1 && str_contains($ack[0]['body'], '#' . $id1) && (int) $ack[0]['driver_id'] === 48);
    check('card catre operatorul #1 cu Aprobă / Respinge / Alte acțiuni', count($cards) === 1 && (int) $cards[0]['user_id'] === 1 && array_column($cards[0]['options'], 'title') === ['✅ Aprobă', '❌ Respinge', '⚙️ Alte acțiuni'], json_encode($cards, JSON_UNESCAPED_UNICODE));
    $card1 = $cards[0];
    check('nimic in Km service', (int) $db->query('SELECT COUNT(*) FROM vehicule_service_km')->fetchColumn() === $kmBefore);

    $r = $send('✅ Trimite', $sendButton);
    check('butonul Trimite apasat din nou: optiune expirata, fara a doua interventie', $r['outcome'] === 'stale_option' && (int) $db->query('SELECT COUNT(*) FROM mentenanta_interventii_programate WHERE id > ' . $max['mentenanta_interventii_programate'])->fetchColumn() === 1, json_encode($r));
    $mark = $lastOutId();
    $r = $service->handleInbound(['wa_message_id' => 'TESTMNT' . getmypid() . '-' . $seq, 'phone' => $phone, 'kind' => 'text', 'text' => 'MENIU']);
    check('acelasi mesaj primit de doua ori: duplicat, fara raspuns', $r['outcome'] === 'duplicate' && $outSince($mark) === []);

    $summary = $requestFlow('Frâna scârțâie la oprire, test automat');
    $r = $send(null, $optionId($summary, ':send'));
    check('aceeasi cerere a doua oara: refuzata ca duplicat', $r['outcome'] === 'duplicate_request', json_encode($r));

    echo "\nModificare si anulare\n";
    $summary = $requestFlow('Bec far stanga ars, test automat');
    $send('2'); // Modifica
    $r = $send('2'); // Descrierea
    check('Modifică -> Descrierea', $r['outcome'] === 'asked_description', json_encode($r));
    $mark = $lastOutId();
    $r = $send('Bec far dreapta ars, test automat');
    $out = $outSince($mark);
    check('dupa modificare se revine la rezumat, cu textul nou', $r['outcome'] === 'asked_confirm' && str_contains((string) end($out)['body'], 'far dreapta'), json_encode($r));
    $r = $send('Anulează');
    check('anulează: conversatia se inchide', $r['outcome'] === 'cancelled' && $service->claims(['phone' => $phone, 'text' => '1']) === false);

    echo "\nDecizia operatorului: aprobare\n";
    $r = $send(null, $card1['options'][0]['id']);
    check('Aprobă -> alege responsabilul', $r['outcome'] === 'asked_coordinator', json_encode($r));
    $session = $db->query("SELECT data_json FROM whatsapp_maintenance_sessions WHERE phone = " . $db->quote($phone))->fetchColumn();
    $choices = json_decode((string) $session, true)['choices'] ?? [];
    $index = array_search('c1', array_column($choices, 0), true);
    check('responsabilii: utilizatorii cu dreptul de coordonare (inclusiv #1)', $index !== false);
    $r = $send((string) ($index + 1));
    check('responsabil ales -> data propusa', $r['outcome'] === 'asked_approve_date', json_encode($r));
    $mark = $lastOutId();
    $r = $send('1'); // Pastreaza data
    $row1 = $intervention($id1);
    check('aprobata: Programată, responsabil #1, aceeasi data', $r['outcome'] === 'approved' && $row1['status_interventie'] === 'programata' && (int) $row1['responsabil_user_id'] === 1 && $row1['data_programata'] === $futureIso, json_encode($r));
    $out = $outSince($mark);
    $status = array_values(array_filter($out, static fn(array $m): bool => $m['purpose'] === 'maintenance_status'));
    check('soferul afla imediat: aprobată, cu data', count($status) === 1 && str_contains($status[0]['body'], 'aprobată') && (int) $status[0]['driver_id'] === 48, json_encode($status, JSON_UNESCAPED_UNICODE));
    check('istoric: status + responsabil + whatsapp', count(array_intersect(['status', 'responsabil', 'whatsapp'], $db->query("SELECT tip FROM mentenanta_interventii_istoric WHERE intervention_id = $id1")->fetchAll(PDO::FETCH_COLUMN))) === 3);
    check('sincronizarea nu retrimite', $service->syncDriverNotifications() === 0);
    $r = $send(null, $card1['options'][1]['id']);
    check('butonul vechi Respinge pe o cerere deja aprobata: refuzat', $r['outcome'] === 'not_decidable' && $intervention($id1)['status_interventie'] === 'programata', json_encode($r));

    echo "\nSchimbare din aplicatie, buton vechi, respingere\n";
    $summary = $requestFlow('Verificare suspensie, test automat');
    $mark = $lastOutId();
    $r = $send(null, $optionId($summary, ':send'));
    $id2 = (int) ($r['intervention_id'] ?? 0);
    $created[] = $id2;
    $card2 = array_values(array_filter($outSince($mark), static fn(array $m): bool => $m['purpose'] === 'maintenance_request'))[0] ?? ['options' => []];
    $newDate = date('Y-m-d', strtotime('+5 days'));
    $workflow->updateFromPanel($id2, ['data_programata' => $newDate], 1, false); // ca din pagina Interventii, in aceeasi secunda
    $mark = $lastOutId();
    check('schimbarea din aplicatie ajunge la sofer (sincronizare)', $service->syncDriverNotifications() === 1 && str_contains((string) ($outSince($mark)[0]['body'] ?? ''), 'modificată'));
    $mark = $lastOutId();
    $r = $send(null, $card2['options'][0]['id'] ?? 'x');
    $out = $outSince($mark);
    check('butonul de pe cardul vechi: refuzat si cardul actual retrimis', $r['outcome'] === 'stale_intervention' && count($out) === 2 && str_contains($out[1]['body'], date('d.m.Y', strtotime($newDate))), json_encode($r));
    $fresh = $out[1];
    $send(null, $fresh['options'][1]['id']);
    $r = $send('x');
    check('motiv prea scurt: cerut din nou', $r['outcome'] === 'reason_too_short');
    $mark = $lastOutId();
    $r = $send('Nu avem loc în service săptămâna aceasta');
    $status = array_values(array_filter($outSince($mark), static fn(array $m): bool => $m['purpose'] === 'maintenance_status'));
    check('respinsa: Anulată, soferul primeste motivul', $r['outcome'] === 'rejected' && $intervention($id2)['status_interventie'] === 'anulata' && str_contains($status[0]['body'] ?? '', 'Motiv: Nu avem loc'), json_encode($r));

    echo "\nSolicita detalii\n";
    $summary = $requestFlow('Martor motor aprins, test automat');
    $mark = $lastOutId();
    $r = $send(null, $optionId($summary, ':send'));
    $id3 = (int) ($r['intervention_id'] ?? 0);
    $created[] = $id3;
    $card3 = array_values(array_filter($outSince($mark), static fn(array $m): bool => $m['purpose'] === 'maintenance_request'))[0];
    $send(null, $card3['options'][2]['id']); // Alte actiuni
    $send('2'); // Solicita detalii
    $mark = $lastOutId();
    $r = $send('Ce culoare are martorul?');
    $toDriver = array_values(array_filter($outSince($mark), static fn(array $m): bool => (int) $m['driver_id'] === 48 && str_contains($m['body'], 'solicită detalii')));
    check('intrebarea ajunge la sofer', $r['outcome'] === 'details_requested' && count($toDriver) === 1, json_encode($r));
    $mark = $lastOutId();
    $r = $send('Este portocaliu');
    $out = $outSince($mark);
    check('raspunsul soferului: in istoric si la operator, cu cardul actual', $r['outcome'] === 'details_answered'
        && (int) $db->query("SELECT COUNT(*) FROM mentenanta_interventii_istoric WHERE intervention_id = $id3 AND descriere LIKE '%portocaliu%'")->fetchColumn() === 1
        && count(array_filter($out, static fn(array $m): bool => (int) $m['user_id'] === 1 && str_contains($m['body'], 'portocaliu'))) === 1, json_encode($r));

    echo "\nDrepturi\n";
    $noReviewer = new MaintenanceWhatsAppService($db, $outbox, $workflow, ['user_ids' => []] + $config);
    $mark = $lastOutId();
    $r = $send(null, 'mnt:r:' . $id3 . ':ok:1', $noReviewer);
    check('fara drept de operator: butonul de aprobare refuzat', $r['outcome'] === 'not_a_reviewer' && $intervention($id3)['status_interventie'] === 'de_verificat', json_encode($r));
    $r = $send('CERERI SERVICE', null, $noReviewer);
    check('fara drept de operator: CERERI SERVICE refuzat', $r['outcome'] === 'not_a_reviewer');
    $notDriver = new MaintenanceWhatsAppService($db, $outbox, $workflow, ['driver_ids' => []] + $config);
    $r = $send('PROGRAMARE SERVICE', null, $notDriver);
    check('sofer in afara listei de test: nu poate cere programare', $r['outcome'] === 'not_a_driver', json_encode($r));
    $r = $send('CERERI SERVICE');
    check('operator: CERERI SERVICE listeaza cererea in asteptare', $r['outcome'] === 'requests');

    echo "\nStatus\n";
    $mark = $lastOutId();
    $r = $send('STATUS SERVICE');
    $body = (string) ($outSince($mark)[0]['body'] ?? '');
    check('STATUS SERVICE: cererile soferului cu statusul lor', $r['outcome'] === 'status' && str_contains($body, '#' . $id1) && str_contains($body, 'Programată') && str_contains($body, '#' . $id3), $body);
    check('Km service neatins', (int) $db->query('SELECT COUNT(*) FROM vehicule_service_km')->fetchColumn() === $kmBefore);

    // ================================================================== etapa 2: coordonatorul
    $sessionData = static function () use ($db, $phone): array {
        return json_decode((string) $db->query('SELECT data_json FROM whatsapp_maintenance_sessions WHERE phone = ' . $db->quote($phone))->fetchColumn(), true) ?: [];
    };
    /** Raspunde cu numarul optiunii care are cheia data (sau primul prefix). */
    $pick = static function (string $key, bool $prefix = false) use ($sessionData, $send): array {
        foreach ($sessionData()['choices'] ?? [] as $i => $choice) {
            if ($choice[0] === $key || ($prefix && str_starts_with($choice[0], $key))) {
                return $send((string) ($i + 1));
            }
        }
        return ['outcome' => 'option_missing:' . $key];
    };
    $itemsOf = static function (int $id) use ($db): array {
        return $db->query("SELECT * FROM mentenanta_interventii_lucrari WHERE intervention_id = $id ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    };
    $lastCard = static function (int $since) use ($outSince): ?array {
        $cards = array_values(array_filter($outSince($since), static fn(array $m): bool => $m['purpose'] === 'maintenance_intervention'));
        return $cards === [] ? null : end($cards);
    };

    echo "\nCoordonator: notificare si card\n";
    $mark = $lastOutId();
    check('responsabilul (#1) primeste cardul interventiei aprobate', $service->notifyAssignments($id1) === 1);
    $card = $lastCard($mark);
    check('card: Lucrări / Detalii / Reasignare (interventia e a lui)', $card !== null && array_column($card['options'], 'title') === ['🔧 Lucrări', '🔍 Detalii', '↗️ Reasignare'] && str_contains($card['body'], 'desemnat responsabil'), json_encode($card['options'] ?? null, JSON_UNESCAPED_UNICODE));
    check('a doua oara nu se retrimite', $service->notifyAssignments($id1) === 0);
    $r = $send(null, $card['options'][0]['id']);
    check('Lucrări -> meniul de lucru', $r['outcome'] === 'work_menu', json_encode($r));

    echo "\nRegistru: adaugare componenta\n";
    $registry = $service->vehicleRegistry(48);
    check('cap tractor: fara Livrare Gaz (doar ramura Sasiu)', !isset($registry['livrare_gaz']) && isset($registry['sasiu']), json_encode(array_keys($registry)));
    $pick('add');
    $session = $sessionData();
    if (isset($session['choices'][0]) && $session['choices'][0][0] === 'main') {
        $pick('main');
    }
    if (isset($sessionData()['choices']) && in_array('sasiu', array_column($sessionData()['choices'], 0), true)) {
        $pick('sasiu');
    }
    $r = $pick('k3');
    check('categoria Franare -> lista componentelor', $r['outcome'] === 'asked_component', json_encode($r));
    $firstComponent = $sessionData()['choices'][0] ?? ['', ''];
    $pick('p', true);
    $pick('inlocuire');
    $mark = $lastOutId();
    $r = $send('fata stanga');
    check('rezumatul inainte de salvare', $r['outcome'] === 'asked_confirm_change' && str_contains((string) (array_reverse($outSince($mark))[0]['body'] ?? ''), $firstComponent[1]), json_encode($r));
    check('nimic salvat inainte de confirmare', $itemsOf($id1) === []);
    $r = $pick('save');
    $items = $itemsOf($id1);
    check('salvat: componenta din registru, Înlocuire, confirmată, poziția', $r['outcome'] === 'saved' && count($items) === 1 && $items[0]['component_key'] === substr($firstComponent[0], 1)
        && $items[0]['operatiune'] === 'inlocuire' && $items[0]['stare_evaluare'] === 'confirmata' && $items[0]['pozitie'] === 'fata stanga', json_encode($items));
    $item1 = (int) ($items[0]['id'] ?? 0);
    check('istoric: componenta_adaugata + whatsapp; audit', (int) $db->query("SELECT COUNT(*) FROM mentenanta_interventii_istoric WHERE intervention_id = $id1 AND tip = 'componenta_adaugata'")->fetchColumn() === 1
        && (int) $db->query("SELECT COUNT(*) FROM audit_log WHERE modul = 'mentenanta_interventii' AND record_id = $id1 AND descriere LIKE '%prin WhatsApp%'")->fetchColumn() >= 2);
    check('uzura nu se atinge la adaugare (fara montare)', (int) $db->query('SELECT COUNT(*) FROM mentenanta_piese_utilizari WHERE id > ' . $max['mentenanta_piese_utilizari'])->fetchColumn() === 0);

    echo "\nLucrare: progres, costuri, informatii\n";
    $pick('prog');
    $pick('in_lucru');
    $r = $pick('save');
    check('progres: În lucru', $r['outcome'] === 'saved' && $itemsOf($id1)[0]['progres'] === 'in_lucru', json_encode($r));
    $pick('cost');
    $r = $send('cât costă?');
    check('costuri neintelese: cerute din nou', $r['outcome'] === 'invalid_costs');
    $send('piese 250 manopera 120,5');
    $r = $pick('save');
    check('costuri salvate', $r['outcome'] === 'saved' && (float) $itemsOf($id1)[0]['cost_piese'] === 250.0 && (float) $itemsOf($id1)[0]['cost_manopera'] === 120.5, json_encode($itemsOf($id1)[0]));
    $mark = $lastOutId();
    $r = $pick('cinfo');
    $info = (string) ($outSince($mark)[0]['body'] ?? '');
    check('informatii componenta: configurare, uzura nu se calculeaza aici, istoric', str_contains($info, 'Neconfigurat') && str_contains($info, 'Uzură') && str_contains($info, 'Istoric'), $info);

    echo "\nLucrare exclusa, diagnostic, status\n";
    $pick('back');
    $pick('add');
    if (($sessionData()['choices'][0][0] ?? '') === 'main') {
        $pick('main');
    }
    if (in_array('sasiu', array_column($sessionData()['choices'] ?? [], 0), true)) {
        $pick('sasiu');
    }
    $pick('k1');
    $pick('p', true);
    $pick('verificare');
    $send('-');
    $pick('save');
    $item2 = (int) (array_reverse($itemsOf($id1))[0]['id'] ?? 0);
    $pick('exclude');
    $send('Verificată vizual, fără probleme');
    $r = $pick('save');
    $row2 = $db->query("SELECT * FROM mentenanta_interventii_lucrari WHERE id = $item2")->fetch(PDO::FETCH_ASSOC);
    check('exclusă cu motiv (ramane in istoric, nu se sterge)', $r['outcome'] === 'saved' && $row2['stare_evaluare'] === 'exclusa' && str_contains((string) $row2['observatii'], 'Verificată vizual'), json_encode($row2));
    $pick('back');
    $pick('diag');
    $send('Plăcuțe uzate pe axa față, disc OK');
    $r = $pick('save');
    $fresh = $intervention($id1);
    check('diagnostic tehnic salvat, sesizarea initiala neschimbata', $r['outcome'] === 'saved' && $fresh['diagnostic_tehnic'] === 'Plăcuțe uzate pe axa față, disc OK' && $fresh['sesizare_initiala'] === 'Frâna scârțâie la oprire, test automat');
    $pick('status');
    $pick('in_service');
    $r = $pick('save');
    check('status: În service', $r['outcome'] === 'saved' && $intervention($id1)['status_interventie'] === 'in_lucru', json_encode($r));
    $mark = $lastOutId();
    check('soferul afla ca vehiculul a intrat in service', $service->syncDriverNotifications() === 1 && str_contains((string) ($outSince($mark)[0]['body'] ?? ''), 'a intrat în service'));

    echo "\nModificare concurenta\n";
    $pick('diag');
    $send('Text care nu trebuie salvat');
    $workflow->updateFromPanel($id1, ['ora_programata' => '08:15'], 1, false); // altcineva, din pagina
    $mark = $lastOutId();
    $r = $pick('save');
    check('Salvează dupa o modificare din aplicatie: refuzat, cardul actual retrimis', $r['outcome'] === 'stale_intervention' && $intervention($id1)['diagnostic_tehnic'] === 'Plăcuțe uzate pe axa față, disc OK' && $lastCard($mark) !== null, json_encode($r));

    echo "\nReasignare si preluare\n";
    $other = (int) $db->query("SELECT id FROM utilizatori WHERE id <> 1 AND status = 'activ' ORDER BY id LIMIT 1")->fetchColumn();
    $card = $lastCard($mark);
    $send(null, $card['options'][2]['id']);
    $pick('u' . $other);
    $r = $pick('save');
    check('reasignat altui coordonator', $r['outcome'] === 'saved' && (int) $intervention($id1)['responsabil_user_id'] === $other, json_encode($r));
    $r = $send(null, $card['options'][0]['id']);
    check('cardul vechi „Lucrări”: refuzat (alt responsabil)', in_array($r['outcome'], ['not_workable', 'stale_intervention'], true) && (int) $intervention($id1)['responsabil_user_id'] === $other, json_encode($r));
    $mark = $lastOutId();
    $send('INTERVENTIE ' . $id1);
    $card = $lastCard($mark);
    check('INTERVENTIE N: cardul cu „Preiau” (nu e a lui)', $card !== null && $card['options'][0]['title'] === '✅ Preiau');
    $send(null, $card['options'][0]['id']);
    $r = $pick('save');
    check('Preiau -> responsabil #1', $r['outcome'] === 'saved' && (int) $intervention($id1)['responsabil_user_id'] === 1, json_encode($r));

    echo "\nFinalizare controlata\n";
    $pick('fin');
    $r = $pick('executata');
    check('inlocuire executata -> confirmare explicita separata', $r['outcome'] === 'asked_replacement', json_encode($r));
    $r = $pick('no');
    check('„Alt rezultat” -> intrebarea se repeta, nimic confirmat', $r['outcome'] === 'asked_result');
    $pick('executata');
    $r = $pick('yes');
    check('inlocuire confirmata -> data executiei', $r['outcome'] === 'asked_execution_date', json_encode($r));
    $r = $send(date('d.m', strtotime('+2 days')));
    check('data in viitor: refuzata', $r['outcome'] === 'invalid_date');
    $pick('today');
    $send('412500');
    $mark = $lastOutId();
    $r = $send('9'); // optiune inexistenta
    check('raspuns invalid la confirmare: cerut din nou, nefinalizata', $r['outcome'] === 'unexpected_reply' && $intervention($id1)['status_interventie'] === 'in_lucru');
    $r = $pick('finish');
    $fresh = $intervention($id1);
    $usage = (int) $db->query('SELECT COUNT(*) FROM mentenanta_piese_utilizari WHERE id > ' . $max['mentenanta_piese_utilizari'])->fetchColumn();
    check('finalizata prin finalize(): inregistrare in Intretinere / Reparatii', $r['outcome'] === 'finalized' && $fresh['status_interventie'] === 'finalizata' && (int) $fresh['converted_maintenance_id'] > 0, json_encode($r));
    check('o singura montare (doar inlocuirea confirmata; lucrarea exclusa nu)', $usage === 1 && (int) ($r['montari'] ?? -1) === 1, "usage=$usage");
    check('lucrarea: Executată, km la montare', ($itemsOf($id1)[0]['rezultat'] ?? '') === 'executata' && (int) $itemsOf($id1)[0]['km_montare'] === 412500);
    $mark = $lastOutId();
    check('soferul afla ca interventia e finalizata', $service->syncDriverNotifications() === 0 && $service->notifyDriver($id1) === false
        && (int) $db->query("SELECT COUNT(*) FROM whatsapp_outbox WHERE dedupe_key LIKE 'mnt:notify:$id1:finalizata%'")->fetchColumn() === 1);
    $r = $send(null, $card['options'][0]['id']);
    check('dupa finalizare, butoanele vechi nu mai fac nimic', in_array($r['outcome'], ['not_workable', 'stale_intervention'], true), json_encode($r));

    echo "\nDrepturi coordonator\n";
    $r = $send(null, 'mnt:c:' . $id3 . ':work:1', $noReviewer);
    check('fara drept: butoanele coordonatorului refuzate', $r['outcome'] === 'not_a_reviewer');
    check('fara drept: nicio notificare de responsabil', $noReviewer->notifyAssignments() === 0);
    check('Km service neatins (etapa 2)', (int) $db->query('SELECT COUNT(*) FROM vehicule_service_km')->fetchColumn() === $kmBefore);
} finally {
    // Curatenie: doar randurile create de acest test.
    $db->prepare('DELETE FROM whatsapp_maintenance_sessions WHERE phone = :p')->execute([':p' => $phone]);
    $db->exec('DELETE FROM whatsapp_maintenance_assignments WHERE intervention_id > ' . $max['mentenanta_interventii_programate']);
    $db->exec('DELETE FROM whatsapp_maintenance_requests WHERE intervention_id > ' . $max['mentenanta_interventii_programate']);
    foreach ($tables as $table) {
        $db->exec("DELETE FROM $table WHERE id > " . $max[$table]);
    }
}

echo "\n" . ($failed === 0 ? "\033[32m" : "\033[31m") . "$passed passed, $failed failed\033[0m\n";
exit($failed > 0 ? 1 : 0);
