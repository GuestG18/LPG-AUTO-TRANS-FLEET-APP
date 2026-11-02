<?php
declare(strict_types=1);

/**
 * Teste pentru comenzile vocale pe WhatsApp (VoiceCommandWhatsAppService), fara WhatsApp si fara Whisper.
 *
 *   DB_NAME=fleet_wa_test php scripts/test_voice_commands.php   (doar pe copia de test)
 *
 * Textul transcris e simulat; mesajele "primite" vin de la administratorul #1.
 * Tot ce se creeaza (coada, mesaje primite, jurnal) se sterge la final, dupa ID-urile maxime de la pornire.
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
require_once $root . '/htdocs/services/VoiceCommandWhatsAppService.php';

if (DB_NAME !== 'fleet_wa_test') {
    fwrite(STDERR, "Rulati doar pe baza de test: DB_NAME=fleet_wa_test\n");
    exit(1);
}

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

$S = VoiceCommandWhatsAppService::class;

echo "Intelegerea textului\n";
$cases = [
    ['Talon 435.', 'talon', '435', null],
    ['Talonul pentru patru sute treizeci și cinci', 'talon', '435', null],
    ['RCA-ul de la patru trei cinci', 'rca', '435', null],
    ['Rovinieta B 435 NET', 'rovinieta', '435', 'B435NET'],
    ['copie conformă 112', 'copie conforma', '112', null],
    ['Vreau talonul de la o sută cinci', 'talon', '105', null],
    ['ITP două sute optsprezece', 'itp', '218', null],
    ['Tahograf 4 3 5', 'tahograf', '435', null],
];
foreach ($cases as [$text, $word, $number, $plate]) {
    $p = $S::parse($text);
    check("„{$text}”", $p !== null && $p['word'] === $word && $p['number'] === $number && $p['plate'] === $plate, json_encode($p, JSON_UNESCAPED_UNICODE));
}
check('fara cuvant de document: nimic', $S::parse('Ne dăm cu barca.') === null);
check('fara numar: nimic', $S::parse('talon') === null);
check('talon = ITP', $S::parse('talon 1')['types'] === ['ITP']);
check('numar: patruzeci si doi', $S::number('patruzeci si doi') === '42');
check('numar: unsprezece', $S::number('unsprezece') === '11');

$db = get_pdo();
$model = new DriverWhatsAppModel($db);
$model->ensureSchema();
(new MaintenanceWhatsAppService($db, $model))->ensureSchema();
$max = static fn(string $table): int => (int) $db->query("SELECT COALESCE(MAX(id), 0) FROM $table")->fetchColumn();
$start = ['whatsapp_outbox' => $max('whatsapp_outbox'), 'whatsapp_inbound_messages' => $max('whatsapp_inbound_messages'), 'audit_log' => $max('audit_log')];

$bossPhone = NotificationRuleModel::normalizePhone((string) $db->query('SELECT telefon FROM utilizatori WHERE id = 1')->fetchColumn());
$service = new VoiceCommandWhatsAppService($db, $model, ['enabled' => true, 'user_ids' => [1]]);
$n = 0;
$msg = static function (string $transcript, array $extra = []) use (&$n, $bossPhone): array {
    return $extra + ['wa_message_id' => 'TESTVOICECMD' . getmypid() . '_' . (++$n), 'phone' => $bossPhone, 'kind' => 'media',
        'media_type' => 'audio', 'media_seconds' => 2, 'transcript' => $transcript];
};
$lastOutbox = static function () use ($db): array {
    return $db->query('SELECT * FROM whatsapp_outbox ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
};

try {
    echo "\nCine si ce mesaje\n";
    check('oprit: nu preia nimic', !(new VoiceCommandWhatsAppService($db, $model, ['enabled' => false, 'user_ids' => [1]]))->claims($msg('talon 435')));
    check('text scris: nu e comanda vocala', !$service->claims($msg('talon 435', ['kind' => 'text'])));
    check('mesaj vocal fara text: nu', !$service->claims($msg('')));
    check('alt numar: nu', !$service->claims($msg('talon 435', ['phone' => '+40700000001'])));
    check('utilizator in afara listei: nu', !(new VoiceCommandWhatsAppService($db, $model, ['enabled' => true, 'user_ids' => [999999]]))->claims($msg('talon 435')));
    check('conducerea, mesaj vocal cu text: da', $service->claims($msg('talon 435')));

    $db->prepare("REPLACE INTO whatsapp_maintenance_sessions (phone, role, actor_id, flow, step, data_json, version, expires_at, updated_at)
        VALUES (:p, 'user', 1, 'voice', 'record', '{}', 1, DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW())")->execute([':p' => $bossPhone]);
    check('in pasul "inregistreaza mesaj": e anuntul, nu comanda', !$service->claims($msg('talon 435')));
    $db->prepare('DELETE FROM whatsapp_maintenance_sessions WHERE phone = :p')->execute([':p' => $bossPhone]);

    echo "\nTalon 435\n";
    $m = $msg('Talon 435.');
    $result = $service->handleInbound($m);
    check('documentul pleaca', $result['outcome'] === 'voice_cmd_document_sent', $result['outcome']);
    check('mesajul vocal se sterge din bot', ($result['discard_audio'] ?? false) === true);
    $row = $lastOutbox();
    $doc = $db->prepare('SELECT d.*, v.nr_inmatriculare FROM documente d JOIN vehicule v ON v.id = d.vehicle_id WHERE d.id = :id');
    $doc->execute([':id' => (int) $row['related_id']]);
    $doc = $doc->fetch(PDO::FETCH_ASSOC);
    check('in coada ca fisier pentru administrator', $row['purpose'] === 'vehicle_document' && $row['related_type'] === 'vehicle_document' && (int) $row['user_id'] === 1);
    check('documentul ITP al B 435 NET', $doc && strtoupper($doc['tip_document']) === 'ITP' && $doc['nr_inmatriculare'] === 'B 435 NET', json_encode($doc ? [$doc['tip_document'], $doc['nr_inmatriculare']] : null));
    check('textul spune ce a inteles', str_contains($row['body'], '„Talon 435.”') && str_contains($row['body'], 'Talon (ITP)') && str_contains($row['body'], 'B 435 NET'), $row['body']);
    $inbound = $db->prepare('SELECT text_content, outcome FROM whatsapp_inbound_messages WHERE wa_message_id = :w');
    $inbound->execute([':w' => $m['wa_message_id']]);
    $inbound = $inbound->fetch(PDO::FETCH_ASSOC);
    check('jurnalul pastreaza rezultatul, nu textul', $inbound['text_content'] === null && $inbound['outcome'] === 'voice_cmd_document_sent');
    check('scris in jurnalul de audit', (int) $db->query("SELECT COUNT(*) FROM audit_log WHERE modul = 'documente' AND id > {$start['audit_log']}")->fetchColumn() === 1);
    check('acelasi mesaj de doua ori: o singura data', $service->handleInbound($m)['outcome'] === 'duplicate' && $lastOutbox()['id'] === $row['id']);

    echo "\nFisierul pentru bot\n";
    check('fara lock: nimic', $service->outboxFile((int) $row['id'], 'x') === null);
    $lock = bin2hex(random_bytes(16));
    $db->prepare("UPDATE whatsapp_outbox SET status = 'sending', lock_token = :t WHERE id = :id")->execute([':t' => $lock, ':id' => $row['id']]);
    $file = $service->outboxFile((int) $row['id'], $lock);
    check('cu lock-ul randului: fisierul', $file !== null && base64_decode($file['data']) === file_get_contents(BASE_PATH . '/uploads/documente/' . $doc['fisier_stocat']));
    check('nume curat, fara numele original', $file !== null && preg_match('/^B_435_NET_ITP\.\w+$/', $file['filename']) === 1, (string) ($file['filename'] ?? ''));
    $text = $db->query("SELECT id FROM whatsapp_outbox WHERE purpose = 'voice_command' ORDER BY id DESC LIMIT 1")->fetchColumn();
    check('alt rand din coada: nimic', $service->outboxFile((int) $text, $lock) === null);

    echo "\nRaspunsuri cand nu se poate\n";
    $service->handleInbound($msg('Ne dăm cu barca.'));
    check('neinteles: exemplu de comanda', str_contains($lastOutbox()['body'], 'Nu am recunoscut') && $lastOutbox()['purpose'] === 'voice_command');
    $service->handleInbound($msg('talon 987654'));
    check('masina inexistenta', str_contains($lastOutbox()['body'], 'Nu am găsit nicio mașină cu numărul *987654*'));
    $missing = $db->query("SELECT v.id, v.nr_inmatriculare FROM vehicule v WHERE v.status = 'activ' AND NOT EXISTS
        (SELECT 1 FROM documente d WHERE d.vehicle_id = v.id AND LOWER(d.tip_document) = 'metrologie' AND d.fisier_stocat <> '') LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($missing) {
        preg_match('/\d+/', $missing['nr_inmatriculare'], $digits);
        $result = $service->handleInbound($msg('metrologie ' . $missing['nr_inmatriculare']));
        check('document lipsa: spune care', $result['outcome'] === 'voice_cmd_document_missing' && str_contains($lastOutbox()['body'], 'nu are *METROLOGIE*'), $lastOutbox()['body']);
    }
} finally {
    foreach ($start as $table => $id) {
        $db->prepare("DELETE FROM $table WHERE id > :id")->execute([':id' => $id]);
    }
    $db->prepare('DELETE FROM whatsapp_maintenance_sessions WHERE phone = :p')->execute([':p' => $bossPhone]);
}

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
