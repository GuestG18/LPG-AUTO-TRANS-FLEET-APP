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
