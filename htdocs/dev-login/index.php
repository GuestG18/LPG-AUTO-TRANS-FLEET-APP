<?php
declare(strict_types=1);

$_GET['page'] = 'login';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require dirname(__DIR__) . '/index.php';
