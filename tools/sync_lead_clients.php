<?php
// Sync leads.client_id from emails.client_id for all leads of the current user.
// CLI: php tools/sync_lead_clients.php [USER_ID]

declare(strict_types=1);

$base = dirname(__DIR__);
$envFile = $base . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . '.env.php';
if (!file_exists($envFile)) { $envFile = $base . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.sample.env.php'; }
$ENV = require $envFile;

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $ENV['DB_HOST'] ?? '127.0.0.1',
    $ENV['DB_PORT'] ?? '3306',
    $ENV['DB_NAME'] ?? ''
);
$pdo = new PDO($dsn, $ENV['DB_USER'] ?? '', $ENV['DB_PASS'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$userId = isset($argv[1]) ? (int)$argv[1] : null;
if (!$userId) {
    // Attempt to guess by counting users
    $userId = (int)$pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
}
if (!$userId) { fwrite(STDERR, "No user id resolved\n"); exit(1); }

$sql = "UPDATE leads l JOIN emails e ON e.id = l.email_id
        SET l.client_id = e.client_id
        WHERE l.user_id = :uid AND e.user_id = :uid AND e.client_id IS NOT NULL";
$stmt = $pdo->prepare($sql);
$stmt->execute(['uid'=>$userId]);
echo 'Synced leads.client_id from emails.client_id for user ', $userId, "\n";

