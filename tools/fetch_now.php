<?php
// CLI: php tools/fetch_now.php USER_ID
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

// Minimal bootstrap
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $base_dir = BASE_PATH . '/app/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) { return; }
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) { require $file; }
});

$envFile = BASE_PATH . '/config/.env.php';
if (!file_exists($envFile)) { $envFile = BASE_PATH . '/config/config.sample.env.php'; }
$ENV = require $envFile;
date_default_timezone_set($ENV['DEFAULT_TIMEZONE'] ?? 'UTC');

App\Core\DB::init($ENV);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Run from CLI\n"); exit(1); }
$userId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($userId <= 0) { fwrite(STDERR, "Missing user id\n"); exit(1); }

$pdo = App\Core\DB::pdo();
$stmt = $pdo->prepare('SELECT * FROM email_accounts WHERE user_id = ? ORDER BY id ASC');
$stmt->execute([$userId]);
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = count($accounts);
$done = 0; $fetchedTotal = 0;
$progressFile = BASE_PATH . '/storage/logs/fetch_progress_user_' . (int)$userId . '.json';
@file_put_contents($progressFile, json_encode(['started'=>date('c'),'done'=>false,'accounts_total'=>$total,'accounts_done'=>$done,'fetched_total'=>$fetchedTotal]));

foreach ($accounts as $acc) {
    $label = (string)($acc['label'] ?? ('Account #' . $acc['id']));
    try {
        $cnt = App\Services\ImapService::fetchFromAccount($userId, $acc, 14, 500);
        $fetchedTotal += (int)$cnt;
        $done++;
        @file_put_contents($progressFile, json_encode([
            'started'=>date('c'), 'done'=>false,
            'accounts_total'=>$total,'accounts_done'=>$done,
            'fetched_total'=>$fetchedTotal,'last_account'=>$label,
        ]));
    } catch (Throwable $e) {
        $done++;
        @file_put_contents($progressFile, json_encode([
            'started'=>date('c'), 'done'=>false,
            'accounts_total'=>$total,'accounts_done'=>$done,
            'fetched_total'=>$fetchedTotal,'last_account'=>$label,
            'error'=>substr($e->getMessage(),0,200),
        ]));
    }
}

@file_put_contents($progressFile, json_encode(['done'=>true,'accounts_total'=>$total,'accounts_done'=>$done,'fetched_total'=>$fetchedTotal]));
echo "Fetched $fetchedTotal emails across $done/$total accounts\n";

