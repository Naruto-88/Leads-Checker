<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
$env = require BASE_PATH . '/config/.env.php';
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$env['DB_HOST'],$env['DB_PORT'],$env['DB_NAME']);
try {
    $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    echo "Connected OK\n";
} catch (Throwable $e) {
    echo "Connection failed: ".$e->getMessage()."\n";
}

