<?php
declare(strict_types=1);

/**
 * Raportul saptamanal Revizii pe email (regulile „Raport săptămânal” din page=notificari).
 * Ruleaza din cron, de exemplu la fiecare 15 minute; trimite doar cand a trecut ora programata
 * din saptamana curenta si doar o data pe saptamana per destinatar.
 *
 *   php scripts/revizii_raport_saptamanal.php              trimite rapoartele scadente
 *   php scripts/revizii_raport_saptamanal.php --dry-run    arata ce s-ar trimite, fara email
 *   php scripts/revizii_raport_saptamanal.php --rule=12 --force           trimite acum regula 12 (test)
 *   php scripts/revizii_raport_saptamanal.php --rule=12 --to=eu@firma.ro  trimite acum doar la o adresa
 *   php scripts/revizii_raport_saptamanal.php --rule=12 --html=raport.html  salveaza HTML-ul, fara email
 *   ... --no-sms   fara SMS-ul catre soferi (regulile cu „SMS șoferilor” bifat)
 *
 * Trimiterile cu --force / --to se inregistreaza ca test (nu consuma trimiterea saptamanii).
 * SMS-urile catre soferi doar se pun in coada; le trimite worker-ul de notificari (SMSAlert).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
if (is_file($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
}
require_once $root . '/htdocs/includes/helpers.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/models/NotificationDeliveryModel.php';
require_once $root . '/htdocs/services/EmailService.php';
require_once $root . '/htdocs/services/ReviziiWeeklyReportService.php';

$options = getopt('', ['dry-run', 'force', 'rule:', 'to:', 'html:', 'no-sms']);

try {
    $db = get_pdo();
    $service = new ReviziiWeeklyReportService($db);

    if (isset($options['html'])) {
        $ruleId = (int) ($options['rule'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM notification_rules WHERE event_type = :event' . ($ruleId > 0 ? ' AND id = :id' : '') . ' ORDER BY id LIMIT 1');
        $stmt->execute($ruleId > 0 ? [':event' => ReviziiWeeklyReportService::EVENT, ':id' => $ruleId] : [':event' => ReviziiWeeklyReportService::EVENT]);
        $rule = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $metadata = json_decode((string) ($rule['metadata_json'] ?? ''), true);
        $config = ReviziiWeeklyReportService::normalizeConfig(is_array($metadata['report'] ?? null) ? $metadata['report'] : ['history' => true], (string) ($rule['document_type'] ?? ''));
        $report = $service->build($config);
        file_put_contents((string) $options['html'], $report['html']);
        echo 'Subiect: ' . $report['subject'] . PHP_EOL . 'HTML salvat in ' . $options['html'] . PHP_EOL;
        exit(0);
    }

    $log = $service->runDue([
        'dry_run' => isset($options['dry-run']),
        'force' => isset($options['force']),
        'rule_id' => (int) ($options['rule'] ?? 0),
        'to' => (string) ($options['to'] ?? ''),
        'no_sms' => isset($options['no-sms']),
    ]);

    if ($log === []) {
        echo date('Y-m-d H:i:s') . ' Nicio regula „Raport săptămânal revizii” activa.' . PHP_EOL;
    }
    $failed = false;
    foreach ($log as $entry) {
        echo date('Y-m-d H:i:s') . ' #' . $entry['rule_id'] . ' ' . $entry['rule'] . ' [' . $entry['program'] . ']: ' . $entry['status'] . PHP_EOL;
        foreach ($entry['failed'] as $error) {
            echo '    esuat: ' . $error . PHP_EOL;
            $failed = true;
        }
    }
    exit($failed ? 2 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
