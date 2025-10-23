<?php
// CLI script: initialize DB and run installer
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
$envFile = BASE_PATH . '/config/.env.php';
if (!file_exists($envFile)) $envFile = BASE_PATH . '/config/config.sample.env.php';
$ENV = require $envFile;

// Minimal autoload
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $base_dir = BASE_PATH . '/app/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) require $file;
});

App\Core\DB::init($ENV);
App\Core\Installer::installIfNeeded();
echo "Installer executed.\n";

