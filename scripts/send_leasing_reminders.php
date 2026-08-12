<?php
declare(strict_types=1);

require_once __DIR__ . '/../htdocs/config/config.php';
require_once __DIR__ . '/../htdocs/config/database.php';
require_once __DIR__ . '/../htdocs/includes/helpers.php';
require_once __DIR__ . '/../htdocs/models/BaseModel.php';
require_once __DIR__ . '/../htdocs/models/NotificationDeliveryModel.php';
require_once __DIR__ . '/../htdocs/models/LeasingSchedulerModel.php';
require_once __DIR__ . '/../htdocs/services/EmailService.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Acest script ruleaza doar din CLI.');
}

$db = get_pdo();
$model = new LeasingSchedulerModel($db);
$email = new EmailService($db);

try {
    $summary = $model->sendDueReminders($email);
    echo json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, '[send_leasing_reminders] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
