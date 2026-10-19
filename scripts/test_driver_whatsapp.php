<?php
declare(strict_types=1);

/**
 * Teste pentru notificarile WhatsApp catre soferi si suportul prin WhatsApp (fara SAS, fara WhatsApp).
 *
 *   DB_NAME=fleet_wa_test php scripts/test_driver_whatsapp.php   (recomandat: copie de test)
 *
 * Partea fara baza de date: prima miscare a zilei (zgomot GPS, miezul noptii, segmente
 * repetate / intarziate) si normalizarea raspunsurilor (butoane / 1 / 2 / text).
 * Partea cu baza de date ruleaza intr-o tranzactie anulata la final (ROLLBACK); schema se
 * pregateste inainte (DDL face commit implicit). Soferul de test (#48) e cel din lista de test.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

// Configuratia de test, inaintea config.php (variabilele din proces au prioritate fata de .env).
putenv('DRIVER_WA_ENABLED=1');
putenv('DRIVER_WA_TEST_MODE=1');
putenv('DRIVER_WA_TEST_DRIVER_IDS=48');
putenv('DRIVER_WA_SUPPORT_SESSION_MINUTES=30');

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/services/DriverFirstMovementService.php';

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

$S = DriverFirstMovementService::class;
$seg = static fn(string $start, ?string $end, float $km): array => ['start' => $start, 'end' => $end, 'km' => $km];

// -----------------------------------------------------------------------------
// 1. Prima miscare a zilei (pur)
// -----------------------------------------------------------------------------
echo "\nPrima miscare a zilei\n";
$d = '2026-10-19';
$r = $S::firstMovement([$seg("$d 06:00:00", "$d 06:05:00", 0.1), $seg("$d 06:10:00", "$d 06:12:00", 0.3), $seg("$d 07:00:00", "$d 07:01:00", 0.4)], $d, 1.0);
check('zgomot GPS (segmente < 0,5 km) -> prag neatins, 0 km', !$r['reached'] && $r['km'] === 0.0, json_encode($r));
$r = $S::firstMovement([$seg("$d 06:00:00", "$d 06:20:00", 3.2)], $d, 1.0);
check('un drum de 3,2 km -> prag atins, de la 06:00', $r['reached'] && $r['first_movement_at'] === "$d 06:00:00" && $r['km'] === 3.2);
check('momentul pragului interpolat in segment (1 km din 3,2 in 20 min ~ 06:06)', $r['threshold_reached_at'] === "$d 06:06:15", (string) $r['threshold_reached_at']);
$r = $S::firstMovement([$seg("$d 06:00:00", "$d 06:05:00", 0.6), $seg("$d 08:00:00", "$d 08:06:00", 0.6)], $d, 1.0);
check('doua deplasari scurte cu oprire intre ele (0,6 + 0,6) -> prag atins la a doua', $r['reached'] && $r['km'] === 1.2 && $r['first_movement_at'] === "$d 06:00:00");
$r = $S::firstMovement([$seg("$d 06:00:00", "$d 06:10:00", 1.0)], $d, 1.0);
check('exact 1 km nu "depaseste" pragul', !$r['reached']);
$r = $S::firstMovement([$seg("$d 06:00:00", "$d 06:05:00", 0.6), $seg("$d 06:00:00", "$d 06:05:00", 0.6)], $d, 1.0);
check('acelasi segment primit de doua ori se numara o data', !$r['reached'] && $r['km'] === 0.6);
$r = $S::firstMovement([$seg("$d 09:00:00", "$d 09:30:00", 5.0), $seg("$d 06:00:00", "$d 06:05:00", 0.7)], $d, 1.0);
check('date intarziate / in alta ordine -> sortate, prima miscare 06:00', $r['reached'] && $r['first_movement_at'] === "$d 06:00:00");
$r = $S::firstMovement([$seg('2026-10-18 22:00:00', "$d 02:00:00", 100.0)], $d, 1.0);
check('cursa peste miezul noptii: doar partea de azi (50 km), prima miscare 00:00', $r['reached'] && $r['first_movement_at'] === "$d 00:00:00" && abs($r['segments'][0]['km_in_day'] - 50.0) < 0.01, json_encode($r));
$r = $S::firstMovement([$seg('2026-10-18 20:00:00', '2026-10-18 23:00:00', 200.0)], $d, 1.0);
check('drum terminat ieri -> nu conteaza pentru azi', !$r['reached'] && $r['km'] === 0.0);
$r = $S::firstMovement([$seg("$d 23:50:00", '2026-10-20 00:10:00', 2.0)], $d, 1.0);
check('drum inceput azi la 23:50, peste miezul noptii: 1 km azi -> nu depaseste', !$r['reached'] && abs($r['km'] - 1.0) < 0.01);
$r = $S::firstMovement([$seg("$d 10:00:00", null, 4.0)], $d, 1.0, 0.5, strtotime("$d 10:20:00"));
check('segment in curs (fara sfarsit) -> pana acum', $r['reached']);
$r = $S::firstMovement([], $d, 1.0);
check('fara date GPS -> neatins', !$r['reached'] && $r['first_movement_at'] === null);

// -----------------------------------------------------------------------------
// 2. Raspunsuri: butoane si text
// -----------------------------------------------------------------------------
echo "\nNormalizarea raspunsurilor\n";
$C = DriverWhatsAppConversationService::class;
$cases = [
    [['driver_app_confirmed', null], 'driver_app_confirmed'],
    [['driver_support_requested', null], 'driver_support_requested'],
    [[null, '1'], 'driver_app_confirmed'], [[null, ' 1. '], 'driver_app_confirmed'], [[null, '1 — Am deschis aplicația'], 'driver_app_confirmed'],
    [[null, 'Am deschis aplicatia'], 'driver_app_confirmed'], [[null, 'AM DESCHIS APLICAȚIA'], 'driver_app_confirmed'],
    [[null, '2'], 'driver_support_requested'], [[null, 'Am nevoie de ajutor'], 'driver_support_requested'], [[null, 'ajutor!'], 'driver_support_requested'],
    [[null, '12'], null], [[null, 'ok'], null], [[null, 'Am deschis aplicatia dar nu merge'], null], [['alt_buton', 'x'], null],
];
foreach ($cases as [[$button, $text], $expected]) {
    check(sprintf('%s / %s -> %s', $button ?? '-', $text ?? '-', $expected ?? 'mesaj liber'), $C::normalizeAction($button, $text) === $expected);
}
check('"anulează" / "Anulare" / "stop" anuleaza', $C::isCancel('anulează') && $C::isCancel('Anulare') && $C::isCancel(' STOP '));
check('"nu anulez nimic" nu anuleaza', !$C::isCancel('nu anulez nimic'));
check('optiunile au ID-urile cerute', array_column($C::movementOptions(), 'id') === ['driver_app_confirmed', 'driver_support_requested']);

// -----------------------------------------------------------------------------
// 3. Cu baza de date
// -----------------------------------------------------------------------------
$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$model = new DriverWhatsAppModel($db);
$model->ensureSchema();
echo "\nBaza: " . $db->query('SELECT DATABASE()')->fetchColumn() . "\n";

$counts = static function () use ($db): array {
    $out = [];
    foreach (['vehicle_movement_days', 'driver_movement_notifications', 'whatsapp_outbox', 'whatsapp_inbound_messages', 'driver_support_tickets', 'driver_support_messages'] as $t) {
        $out[$t] = (int) $db->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    }

    return $out;
};
$before = $counts();
$testPhone = $model->getActiveDriver(48)['phone_e164'] ?? null;
if ($testPhone === null) {
    fwrite(STDERR, "Soferul de test #48 lipseste sau nu are telefon.\n");
    exit(1);
}
$config = DriverFirstMovementService::config();
$service = new DriverFirstMovementService($db, $model, null, null, $config);
$day = '2031-03-10'; // o zi fara curse: nimic real nu se suprapune
$now = time();
$moved = [$seg(date('Y-m-d H:i:s', strtotime("$day 07:00:00")), date('Y-m-d H:i:s', strtotime("$day 07:20:00")), 3.5)];

$db->beginTransaction();
try {
    echo "\nCine conduce vehiculul\n";
    $res = $service->resolveDrivers(23, '2026-10-09');
    check('ziua de predare (cursa 807): vehiculul 23 -> soferul 20 (segmentul lui)', $res['driver_ids'] === [20] && $res['source'] === 'cursa', json_encode($res));
    $res = $service->resolveDrivers(24, '2026-10-09');
    check('ziua de predare: vehiculul 24 -> soferul 19', $res['driver_ids'] === [19], json_encode($res));
    $res = $service->resolveDrivers(48, '2026-10-04');
    check('vehiculul 48 pe 4 oct (cursa cu soferul 14) -> 14, nu titularul', $res['driver_ids'] === [14] && $res['source'] === 'cursa', json_encode($res));
    $res = $service->resolveDrivers(48, $day);
    check('vehiculul 48 fara cursa -> alocarea implicita (48)', $res['driver_ids'] === [48] && $res['source'] === 'alocare', json_encode($res));
    $res = $service->resolveDrivers(50, $day);
    check('doi soferi alocati, un singur principal -> principalul', count($res['driver_ids']) === 1 && $res['source'] === 'alocare_principal', json_encode($res));
    $res = $service->resolveDrivers(17, $day);
    check('doi soferi principali -> ambiguu, nimeni', $res['driver_ids'] === [] && $res['source'] === 'ambiguous');
    $res = $service->resolveDrivers(10, $day);
    check('vehicul fara sofer alocat -> nimeni', $res['driver_ids'] === [] && $res['source'] === 'none');

    echo "\nDeclansare si idempotenta\n";
    $r = $service->processVehicle(48, 9110, $day, $moved, true);
    $n = $model->findNotification(48, 48, $day);
    check('3,5 km -> o notificare pentru soferul 48', $r['triggered'] && $r['notifications'] === 1 && $n !== null && $n['status'] === 'queued');
    $outbox = $db->query("SELECT * FROM whatsapp_outbox WHERE related_type = 'notification' AND related_id = " . (int) $n['id'])->fetch(PDO::FETCH_ASSOC);
    check('mesajul din outbox are textul si cele doua optiuni', $outbox !== false && $outbox['body'] === DriverWhatsAppConversationService::MOVEMENT_PROMPT
        && array_column(json_decode((string) $outbox['options_json'], true), 'id') === ['driver_app_confirmed', 'driver_support_requested']);
    $trigger = json_decode((string) $n['trigger_json'], true);
    check('datele GPS ale declansarii sunt salvate', $trigger['km'] === 3.5 && $trigger['first_movement_at'] === "$day 07:00:00");
    $r2 = $service->processVehicle(48, 9110, $day, $moved, true);
    check('a doua evaluare (worker repornit) -> nicio notificare noua', $r2['notifications'] === 0);
    $r3 = $service->processVehicle(48, 9110, $day, [$seg("$day 15:00:00", "$day 16:00:00", 40.0)], true);
    check('miscarea de dupa-amiaza nu mai declanseaza nimic', $r3['notifications'] === 0 && (int) $db->query("SELECT COUNT(*) FROM driver_movement_notifications WHERE local_date = '$day'")->fetchColumn() === 1);
    $again = $model->createNotificationWithOutbox(48, 48, $day, null, [], 'x', [], true);
    check('UNIQUE sofer + vehicul + zi: a doua inserare (worker concurent) -> null', $again === null);

    $r = $service->processVehicle(48, 9110, '2026-10-04', [$seg('2026-10-04 07:00:00', '2026-10-04 07:20:00', 3.5)], true);
    check('soferul real al zilei (14) nu e in lista de test -> nu e notificat', $r['notifications'] === 0 && str_contains(implode(' ', $r['skipped']), 'lista de test'), json_encode($r['skipped']));
    $r = $service->processVehicle(10, null, $day, $moved, true);
    check('vehicul fara sofer -> nicio notificare, motiv salvat', $r['notifications'] === 0 && str_contains((string) $model->getMovementDay(10, $day)['detail'], 'fara sofer'));
    $r = $service->processVehicle(17, null, $day, $moved, true);
    check('vehicul cu soferi ambigui -> nicio notificare', $r['notifications'] === 0);
    $r = $service->processVehicle(48, 9110, '2031-03-11', [$seg('2031-03-11 07:00:00', '2031-03-11 07:01:00', 0.3)], true);
    check('doar zgomot GPS -> nicio notificare', $r['notifications'] === 0 && $model->getMovementDay(48, '2031-03-11')['status'] === 'below_threshold');

    // Sofer fara telefon valid (inserat doar in tranzactie)
    $db->exec("INSERT INTO soferi (nume, telefon, permis_expira_la, status, employment_status, created_at, updated_at) VALUES ('Test Fara Telefon', '', '2030-01-01', 'activ', 'active', NOW(), NOW())");
    $noPhoneId = (int) $db->lastInsertId();
    $svcNoPhone = new DriverFirstMovementService($db, $model, null, null, ['test_driver_ids' => [48, $noPhoneId]] + $config);
    check('sofer fara numar de telefon -> nu e eligibil', $svcNoPhone->ineligibleReason($noPhoneId) === 'fara numar de telefon valid');

    echo "\nOutbox (BaileyBot)\n";
    $claim = $model->claimOutbox(10);
    $mine = array_values(array_filter($claim['messages'], static fn(array $m): bool => $m['id'] === (int) $outbox['id']));
    check('revendicare: mesajul vine cu telefonul E.164 al soferului', count($mine) === 1 && $mine[0]['phone'] === $testPhone && $mine[0]['options'] !== []);
    $claim2 = $model->claimOutbox(10);
    check('a doua revendicare nu reia mesajele blocate', !in_array((int) $outbox['id'], array_column($claim2['messages'], 'id'), true));
    check('rezultat cu lock gresit -> refuzat', $model->reportOutboxResult((int) $outbox['id'], 'gresit', true, 'X', null, true) === false);
    check('esec trecator -> inapoi in asteptare', $model->reportOutboxResult((int) $outbox['id'], $claim['lock_token'], false, null, 'Connection Closed', true)
        && $db->query('SELECT status FROM whatsapp_outbox WHERE id = ' . (int) $outbox['id'])->fetchColumn() === 'pending');
    $db->exec('UPDATE whatsapp_outbox SET next_attempt_at = NOW() WHERE id = ' . (int) $outbox['id']);
    $claim3 = $model->claimOutbox(10);
    $db->exec("UPDATE whatsapp_outbox SET locked_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE id = " . (int) $outbox['id']);
    $claim4 = $model->claimOutbox(10);
    check('bot oprit dupa revendicare -> mesajul se poate relua dupa 5 minute', in_array((int) $outbox['id'], array_column($claim4['messages'], 'id'), true));
    check('vechiul lock nu mai poate raporta', $model->reportOutboxResult((int) $outbox['id'], $claim3['lock_token'], true, 'X', null, true) === false);
    $model->reportOutboxResult((int) $outbox['id'], $claim4['lock_token'], true, '3EB0TESTSENT', null, true);
    $n = $model->getNotification((int) $n['id']);
    check('trimis -> notificarea "sent" cu Message ID-ul WhatsApp', $n['status'] === 'sent' && $n['wa_message_id'] === '3EB0TESTSENT' && $n['sent_at'] !== null);

    echo "\nRaspunsul soferului\n";
    // Conversatia lucreaza pe "azi": mutam notificarea de test pe azi (doar in tranzactie).
    $today = date('Y-m-d');
    $db->exec("DELETE FROM driver_movement_notifications WHERE driver_id = 48 AND local_date = '$today' AND id <> " . (int) $n['id']);
    $db->exec("UPDATE driver_movement_notifications SET local_date = '$today' WHERE id = " . (int) $n['id']);
    $db->exec("DELETE FROM driver_whatsapp_conversations WHERE driver_id = 48");
    $db->exec("UPDATE driver_support_tickets SET status = 'closed' WHERE driver_id = 48");
    $conv = new DriverWhatsAppConversationService($db, $model);
    $msg = static fn(string $id, ?string $text, ?string $button = null, string $kind = 'text', ?string $phone = null): array => [
        'wa_message_id' => $id, 'phone' => $phone ?? $testPhone, 'kind' => $button !== null ? 'button' : $kind, 'action_id' => $button, 'text' => $text,
    ];
    $replyFor = static fn(string $waId): ?string => ($v = $db->query('SELECT body FROM whatsapp_outbox WHERE dedupe_key = ' . $db->quote('reply:' . $waId))->fetchColumn()) === false ? null : (string) $v;

    $r = $conv->handleInbound($msg('T-UNKNOWN', '1', null, 'text', '+40700000001'));
    check('numar necunoscut -> ignorat, fara raspuns si fara jurnal', $r['outcome'] === 'unknown_sender' && $replyFor('T-UNKNOWN') === null);
    $other = $db->query("SELECT telefon FROM soferi WHERE id <> 48 AND status = 'activ' AND telefon <> '' LIMIT 1")->fetchColumn();
    $r = $conv->handleInbound($msg('T-OTHER', '1', null, 'text', NotificationRuleModel::normalizePhone((string) $other)));
    check('alt sofer, in afara listei de test -> ignorat', $r['outcome'] === 'not_in_test_allowlist');
    $r = $conv->handleInbound($msg('T-CHAT', 'Buna ziua'));
    check('mesaj liber fara flux deschis -> ignorat, fara raspuns', $r['outcome'] === 'ignored' && $replyFor('T-CHAT') === null);

    $r = $conv->handleInbound($msg('T-B1', 'Am deschis aplicația', 'driver_app_confirmed'));
    $n = $model->getNotification((int) $n['id']);
    check('buton "Am deschis aplicația" -> confirmat cu ora', $r['outcome'] === 'confirmed' && $n['status'] === 'confirmed' && $n['confirmed_at'] !== null && $n['response_action'] === 'driver_app_confirmed');
    check('raspunsul de multumire e in outbox', $replyFor('T-B1') === DriverWhatsAppConversationService::CONFIRMED_REPLY);
    $r = $conv->handleInbound($msg('T-B1', 'Am deschis aplicația', 'driver_app_confirmed'));
    check('acelasi mesaj primit de doua ori -> duplicat, un singur raspuns', $r['outcome'] === 'duplicate'
        && (int) $db->query("SELECT COUNT(*) FROM whatsapp_outbox WHERE dedupe_key = 'reply:T-B1'")->fetchColumn() === 1);
    $r = $conv->handleInbound($msg('T-T1', '1'));
    check('"1" dupa confirmare -> "deja inregistrata", confirmarea nu se schimba', $r['outcome'] === 'already_confirmed'
        && $model->getNotification((int) $n['id'])['confirmed_at'] === $n['confirmed_at']);

    // Scenariu nou: notificarea trece inapoi in "sent" (ca o zi noua).
    $db->exec("UPDATE driver_movement_notifications SET status = 'sent', confirmed_at = NULL, response_action = NULL WHERE id = " . (int) $n['id']);
    $r = $conv->handleInbound($msg('T-H1', '2'));
    check('"2" -> cererea de descriere, stare awaiting_problem_description', $r['outcome'] === 'support_requested'
        && $db->query('SELECT state FROM driver_whatsapp_conversations WHERE driver_id = 48')->fetchColumn() === 'awaiting_problem_description'
        && str_starts_with((string) $replyFor('T-H1'), DriverWhatsAppConversationService::SUPPORT_PROMPT));
    $r = $conv->handleInbound($msg('T-H2', null, null, 'media'));
    check('o poza in loc de descriere -> cerem text', $r['outcome'] === 'description_not_text');
    $r = $conv->handleInbound($msg('T-H3', 'anulează'));
    check('"anulează" -> anulat, fara tichet', $r['outcome'] === 'support_cancelled'
        && (int) $db->query('SELECT COUNT(*) FROM driver_support_tickets WHERE driver_id = 48 AND status <> \'closed\'')->fetchColumn() === 0);
    $r = $conv->handleInbound($msg('T-H4', 'Nu pot să mă autentific'));
    check('dupa anulare, textul liber nu mai devine tichet', $r['outcome'] === 'ignored');

    $conv->handleInbound($msg('T-H5', 'Am nevoie de ajutor', 'driver_support_requested'));
    $db->exec('UPDATE driver_whatsapp_conversations SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE driver_id = 48');
    $r = $conv->handleInbound($msg('T-H6', 'Nu pot să mă autentific'));
    check('sesiunea de asteptare expirata -> mesajul nu devine tichet', $r['outcome'] === 'ignored');

    $conv->handleInbound($msg('T-H7', '2'));
    $r = $conv->handleInbound($msg('T-H8', 'ok'));
    check('descriere prea scurta -> cerem detalii', $r['outcome'] === 'description_too_short');
    $r = $conv->handleInbound($msg('T-H9', 'Nu pot să mă autentific în aplicație. Îmi apare sesiune expirată.'));
    $ticket = $db->query("SELECT * FROM driver_support_tickets WHERE source_wa_message_id = 'T-H9'")->fetch(PDO::FETCH_ASSOC);
    check('descrierea -> tichet #SUP-xxxxxx', $r['outcome'] === 'ticket_created' && $ticket !== false && preg_match('/^SUP-\d{6}$/', (string) $ticket['reference']) === 1 && ($r['ticket'] ?? '') === $ticket['reference']);
    check('tichetul: sofer, vehicul, notificare, descriere, email in asteptare', (int) $ticket['driver_id'] === 48 && (int) $ticket['vehicle_id'] === 48
        && (int) $ticket['notification_id'] === (int) $n['id'] && $ticket['email_status'] === 'pending' && str_contains((string) $ticket['description'], 'sesiune expirată'));
    check('istoric: mesajul WhatsApp -> email, in asteptare', (int) $db->query("SELECT COUNT(*) FROM driver_support_messages WHERE ticket_id = {$ticket['id']} AND direction = 'whatsapp_to_email' AND delivery_status = 'pending'")->fetchColumn() === 1);
    check('confirmarea tichetului NU pleaca inainte de email (doua variante "held")', $replyFor('T-H9') === null
        && $db->query("SELECT GROUP_CONCAT(status ORDER BY dedupe_key) FROM whatsapp_outbox WHERE dedupe_key IN ('support_ack:{$ticket['id']}', 'support_ack_failed:{$ticket['id']}')")->fetchColumn() === 'held,held'
        && str_contains((string) $db->query("SELECT body FROM whatsapp_outbox WHERE dedupe_key = 'support_ack:{$ticket['id']}'")->fetchColumn(), '#' . $ticket['reference'] . ' și transmisă echipei de suport'));
    $heldClaim = $model->claimOutbox(20);
    check('mesajele "held" nu sunt revendicate de bot', !in_array('support_ack', array_column($heldClaim['messages'], 'purpose'), true));
    check('starea revine la idle', $db->query('SELECT state FROM driver_whatsapp_conversations WHERE driver_id = 48')->fetchColumn() === 'idle');
    $r = $conv->handleInbound($msg('T-H9', 'Nu pot să mă autentific în aplicație. Îmi apare sesiune expirată.'));
    check('aceeasi descriere primita din nou -> nu se dubleaza tichetul', $r['outcome'] === 'duplicate'
        && (int) $db->query("SELECT COUNT(*) FROM driver_support_tickets WHERE driver_id = 48 AND description LIKE '%sesiune expirată%' AND status = 'open'")->fetchColumn() === 1);
    $r = $conv->handleInbound($msg('T-H10', 'Am reinstalat aplicatia si tot nu merge.'));
    check('mesaj nou cu tichet deschis -> adaugat la tichet (va pleca pe email)', $r['outcome'] === 'ticket_followup' && ($r['ticket'] ?? '') === $ticket['reference']
        && (int) $db->query("SELECT COUNT(*) FROM driver_support_messages WHERE ticket_id = {$ticket['id']} AND direction = 'whatsapp_to_email'")->fetchColumn() === 2);

    echo "\nRaspunsul suportului nu se pierde daca WhatsApp esueaza\n";
    $db->exec("UPDATE whatsapp_outbox SET status = 'sent' WHERE status IN ('pending', 'sending')"); // doar mesajul de mai jos ramane de trimis
    $supportMessageId = $model->addSupportMessage((int) $ticket['id'], 'email_to_whatsapp', '<test-reply@client>', 'suport@test', 'Reinstalati aplicatia.', 'pending');
    $replyOutbox = $model->enqueue('support_reply', 48, '📩 Răspuns de la suport — #' . $ticket['reference'] . "\nReinstalati aplicatia.", [], 'support_reply:' . $supportMessageId, 'support_message', $supportMessageId);
    $supportStatus = static fn(): string => (string) $db->query('SELECT delivery_status FROM driver_support_messages WHERE id = ' . (int) $supportMessageId)->fetchColumn();
    $c = $model->claimOutbox(5);
    $model->reportOutboxResult($replyOutbox, $c['lock_token'], false, null, 'Connection Closed', true);
    check('WhatsApp picat -> raspunsul ramane in asteptare (se reincearca)', $supportStatus() === 'pending'
        && $db->query('SELECT status FROM whatsapp_outbox WHERE id = ' . $replyOutbox)->fetchColumn() === 'pending');
    $db->exec('UPDATE whatsapp_outbox SET next_attempt_at = NOW() WHERE id = ' . $replyOutbox);
    $c = $model->claimOutbox(5);
    $model->reportOutboxResult($replyOutbox, $c['lock_token'], true, '3EB0REPLY', null, true);
    check('a doua incercare reuseste -> istoricul tichetului: livrat', $supportStatus() === 'sent'
        && $db->query('SELECT delivered_at IS NOT NULL FROM driver_support_messages WHERE id = ' . (int) $supportMessageId)->fetchColumn() == 1);
    $again = $model->enqueue('support_reply', 48, 'x', [], 'support_reply:' . $supportMessageId, 'support_message', $supportMessageId);
    check('acelasi raspuns pus din nou in coada -> acelasi mesaj, nu unul nou', $again === $replyOutbox);
} finally {
    $db->rollBack();
}

check('ROLLBACK: nicio linie ramasa', $counts() === $before, json_encode([$before, $counts()]));
echo "\n" . ($failed === 0 ? "\033[32m" : "\033[31m") . "$passed trecute, $failed esuate\033[0m\n";
exit($failed === 0 ? 0 : 1);
