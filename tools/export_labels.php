<?php
// CLI: php tools/export_labels.php
// Writes labeled examples to storage/ml/labels.jsonl for local ML training.

declare(strict_types=1);

$base = dirname(__DIR__);
$envFile = $base . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . '.env.php';
if (!file_exists($envFile)) {
    $envFile = $base . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.sample.env.php';
}
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

$sql = "
SELECT l.id, e.subject, e.body_plain, e.body_html, l.status AS label
FROM leads l
JOIN emails e ON e.id=l.email_id
WHERE l.deleted_at IS NULL AND l.status IN ('genuine','spam')
";
$stmt = $pdo->query($sql);

$outDir = $base . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ml';
if (!is_dir($outDir)) { mkdir($outDir, 0777, true); }
$outFile = $outDir . DIRECTORY_SEPARATOR . 'labels.jsonl';
$fh = fopen($outFile, 'w');
while ($row = $stmt->fetch()) {
    $label = $row['label'] ?? null;
    $rec = [
        'subject' => (string)($row['subject'] ?? ''),
        'body_plain' => (string)($row['body_plain'] ?? ''),
        'body_html' => (string)($row['body_html'] ?? ''),
        'label' => $label,
    ];
    fwrite($fh, json_encode($rec, JSON_UNESCAPED_UNICODE) . "\n");
}
fclose($fh);
echo "Wrote labels to $outFile\n";
