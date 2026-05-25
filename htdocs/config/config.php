<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Bucharest');
ini_set('default_charset', 'UTF-8');

define('BASE_PATH', dirname(__DIR__));
$projectRoot = dirname(BASE_PATH);
$composerAutoload = $projectRoot . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

if (class_exists(\Dotenv\Dotenv::class)) {
    // Load local environment variables from project root .env when available.
    \Dotenv\Dotenv::createUnsafeImmutable($projectRoot)->safeLoad();
}

$scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
if ($scriptDirectory === '/' || $scriptDirectory === '.' || $scriptDirectory === '\\') {
    $scriptDirectory = '';
}
define('BASE_URL', rtrim($scriptDirectory, '/'));

$httpHost = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
$appEnvRaw = strtolower(trim((string) (getenv('APP_ENV') ?: 'development')));

define('APP_NAME', 'Fleet Management MVP');
define('APP_DEBUG', filter_var((string) (getenv('APP_DEBUG') ?: 'false'), FILTER_VALIDATE_BOOLEAN));
define('APP_ENV', $appEnvRaw);
define('ITEMS_PER_PAGE', 10);
define('AUTH_REQUIRE_EMAIL_VERIFICATION', filter_var((string) (getenv('AUTH_REQUIRE_EMAIL_VERIFICATION') ?: 'true'), FILTER_VALIDATE_BOOLEAN));
define('AUTH_VERIFY_RESEND_COOLDOWN_SECONDS', max(15, (int) (getenv('AUTH_VERIFY_RESEND_COOLDOWN_SECONDS') ?: 30)));

// SMTP settings used by PHPMailer for authentication emails.
// MAIL_USERNAME is the sender account used by the app to authenticate to SMTP.
// Recipient is always the authenticated user's users.email (or utilizatori.email on legacy schema).
// The app never requires recipient email credentials, only sender SMTP credentials.
define('MAIL_HOST', (string) (getenv('MAIL_HOST') ?: getenv('SMTP_HOST') ?: ''));
define('MAIL_PORT', (int) (getenv('MAIL_PORT') ?: getenv('SMTP_PORT') ?: 587));
define('MAIL_USERNAME', (string) (getenv('MAIL_USERNAME') ?: getenv('SMTP_USERNAME') ?: ''));
define('MAIL_PASSWORD', (string) (getenv('MAIL_PASSWORD') ?: getenv('SMTP_PASSWORD') ?: ''));
define('MAIL_ENCRYPTION', strtolower((string) (getenv('MAIL_ENCRYPTION') ?: getenv('SMTP_ENCRYPTION') ?: 'tls'))); // tls | ssl | none
define('MAIL_FROM_ADDRESS', (string) (getenv('MAIL_FROM_ADDRESS') ?: 'gigel.trandafir@lpg-auto.ro'));
define('MAIL_FROM_NAME', (string) (getenv('MAIL_FROM_NAME') ?: 'LPG AUTO TRANS'));
define('MAIL_TIMEOUT', (int) (getenv('MAIL_TIMEOUT') ?: 45));
define('MAIL_CONNECT_TIMEOUT', (int) (getenv('MAIL_CONNECT_TIMEOUT') ?: 15));
define('MAIL_RETRY_ATTEMPTS', max(1, (int) (getenv('MAIL_RETRY_ATTEMPTS') ?: 2)));
define('MAIL_RETRY_DELAY_MS', max(0, (int) (getenv('MAIL_RETRY_DELAY_MS') ?: 400)));
define('VEHICLE_REQUIRED_DOCUMENT_TYPES', ['RCA', 'ITP', 'Rovinieta']);
define('DRIVER_REQUIRED_DOCUMENT_TYPES', ['Carte identitate', 'Atestat profesional', 'Aviz medical']);

// Actualizeaza valorile pentru baza de date dupa deploy.
define('DB_HOST', (string) (getenv('DB_HOST') ?: '127.0.0.1'));
define('DB_PORT', (string) (getenv('DB_PORT') ?: '3306'));
define('DB_NAME', (string) (getenv('DB_NAME') ?: 'if0_41456552_aplicatie_flota'));
define('DB_USER', (string) (getenv('DB_USER') ?: 'root'));
define('DB_PASS', (string) (getenv('DB_PASS') ?: 'root123'));
define('DB_CHARSET', (string) (getenv('DB_CHARSET') ?: 'utf8mb4'));

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$defaultAppUrl = $httpHost !== ''
    ? (($isHttps ? 'https://' : 'http://') . $httpHost . BASE_URL)
    : 'http://127.0.0.1:8000';

define('APP_URL', rtrim($defaultAppUrl, '/'));

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_name('fleet_mvp_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (PHP_SAPI !== 'cli' && !isset($_SESSION['session_initialized'])) {
    session_regenerate_id(true);
    $_SESSION['session_initialized'] = time();
}
