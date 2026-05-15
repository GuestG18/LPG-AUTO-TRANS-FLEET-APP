<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Bucharest');
ini_set('default_charset', 'UTF-8');

define('BASE_PATH', dirname(__DIR__));

$scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
if ($scriptDirectory === '/' || $scriptDirectory === '.' || $scriptDirectory === '\\') {
    $scriptDirectory = '';
}
define('BASE_URL', rtrim($scriptDirectory, '/'));

define('APP_NAME', 'Fleet Management MVP');
define('APP_DEBUG', false);
define('ITEMS_PER_PAGE', 10);
define('AUTH_EMAIL_FROM', (string) (getenv('AUTH_EMAIL_FROM') ?: 'no-reply@fleet.local'));
define('AUTH_EMAIL_FROM_NAME', (string) (getenv('AUTH_EMAIL_FROM_NAME') ?: APP_NAME));
define('AUTH_EMAIL_TRANSPORT', strtolower((string) (getenv('AUTH_EMAIL_TRANSPORT') ?: 'smtp'))); // smtp | mail
define('AUTH_EMAIL_FALLBACK_TRANSPORT', strtolower((string) (getenv('AUTH_EMAIL_FALLBACK_TRANSPORT') ?: ''))); // smtp | mail | resend | ''
define('SMTP_HOST', (string) (getenv('SMTP_HOST') ?: 'smtp.migadu.com'));
define('SMTP_PORT', (int) (getenv('SMTP_PORT') ?: 587));
define('SMTP_ENCRYPTION', strtolower((string) (getenv('SMTP_ENCRYPTION') ?: 'tls'))); // tls | ssl | none
define('SMTP_USERNAME', (string) (getenv('SMTP_USERNAME') ?: ''));
define('SMTP_PASSWORD', (string) (getenv('SMTP_PASSWORD') ?: ''));
define('SMTP_TIMEOUT', (int) (getenv('SMTP_TIMEOUT') ?: 90));
define('SMTP_ALLOW_SELF_SIGNED', filter_var((string) (getenv('SMTP_ALLOW_SELF_SIGNED') ?: 'false'), FILTER_VALIDATE_BOOLEAN));
define('RESEND_API_BASE_URL', (string) (getenv('RESEND_API_BASE_URL') ?: 'https://api.resend.com'));
define('RESEND_API_KEY', (string) (getenv('RESEND_API_KEY') ?: ''));
define('RESEND_TIMEOUT', (int) (getenv('RESEND_TIMEOUT') ?: 15));
define('RESEND_FROM', (string) (getenv('RESEND_FROM') ?: ''));
define('VEHICLE_REQUIRED_DOCUMENT_TYPES', ['RCA', 'ITP', 'Rovinieta']);
define('DRIVER_REQUIRED_DOCUMENT_TYPES', ['Carte identitate', 'Atestat profesional', 'Aviz medical']);

// Actualizeaza valorile pentru baza de date dupa deploy.
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'if0_41456552_aplicatie_flota');
define('DB_USER', 'root');
define('DB_PASS', 'root123');
define('DB_CHARSET', 'utf8mb4');

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$httpHost = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
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
