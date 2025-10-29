<?php
// CLI: php tools/reprocess_gpt.php USER_ID [--client=SHORTCODE] [--start=YYYY-mm-dd HH:MM:SS] [--end=YYYY-mm-dd HH:MM:SS] [--batch=N] [--cap=N]
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(function ($class) {
    $prefix = 'App\\'; $base_dir = BASE_PATH . '/app/'; $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return; $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php'; if (file_exists($file)) require $file;
});

$envFile = BASE_PATH . '/config/.env.php'; if (!file_exists($envFile)) $envFile = BASE_PATH . '/config/config.sample.env.php';
$ENV = require $envFile; date_default_timezone_set($ENV['DEFAULT_TIMEZONE'] ?? 'UTC');
\App\Core\DB::init($ENV);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Run from CLI\n"); exit(1); }
if ($argc < 2) { fwrite(STDERR, "Usage: php tools/reprocess_gpt.php USER_ID [--client=CODE] [--start=...] [--end=...]\n"); exit(1); }

$userId = (int)$argv[1];
$clientCode = null; $start = null; $end = null; $batch = 200; $cap = 1000;
for ($i=2; $i<$argc; $i++) {
    if (preg_match('/^--client=(.+)$/', $argv[$i], $m)) { $clientCode = $m[1]; }
    elseif (preg_match('/^--start=(.+)$/', $argv[$i], $m)) { $start = $m[1]; }
    elseif (preg_match('/^--end=(.+)$/', $argv[$i], $m)) { $end = $m[1]; }
    elseif (preg_match('/^--batch=(\d+)$/', $argv[$i], $m)) { $batch = (int)$m[1]; }
    elseif (preg_match('/^--cap=(\d+)$/', $argv[$i], $m)) { $cap = (int)$m[1]; }
}

$pdo = \App\Core\DB::pdo();
$settings = \App\Models\Setting::getByUser($userId);
$key = \App\Helpers::decryptSecret($settings['openai_api_key_enc'] ?? null, \App\Core\DB::env('APP_KEY',''));
$progressFile = BASE_PATH . '/storage/logs/repgpt_user_' . $userId . '.json';
if (!$key) {
    @file_put_contents($progressFile, json_encode(['processed'=>0,'total'=>0,'done'=>true,'error'=>'Missing OpenAI key for user']));
    fwrite(STDERR, "Missing OpenAI key for user\n");
    exit(2);
}
$strict = (int)($settings['strict_gpt'] ?? 0) === 1;
$client = new \App\Services\OpenAIClient($key);

$clientId = null;
if ($clientCode) {
    $c = \App\Models\Client::findByShortcode($userId, $clientCode);
    if ($c) { $clientId = (int)$c['id']; }
}

// Count total
$where = 'l.user_id = :uid AND l.deleted_at IS NULL AND (l.mode IS NULL OR l.mode = \"algorithmic\") AND NOT EXISTS (SELECT 1 FROM lead_checks lc WHERE lc.lead_id=l.id AND lc.mode=\"manual\")';
$params = [':uid'=>$userId];
if ($clientId) { $where .= ' AND l.client_id = :cid'; $params[':cid'] = $clientId; }
if ($start && $end) { $where .= ' AND e.received_at BETWEEN :start AND :end'; $params[':start']=$start; $params[':end']=$end; }

$qCount = $pdo->prepare("SELECT COUNT(*) FROM leads l JOIN emails e ON e.id=l.email_id WHERE $where");
foreach ($params as $k=>$v) { $qCount->bindValue($k, $v); }
$qCount->execute(); $total = (int)$qCount->fetchColumn();

@file_put_contents($progressFile, json_encode(['processed'=>0,'total'=>$total,'done'=>false]));

$processed = 0; $offset = 0;
while ($processed < $cap) {
    $sql = "SELECT l.id AS lead_id, l.*, e.* FROM leads l JOIN emails e ON e.id=l.email_id WHERE $where ORDER BY e.received_at DESC LIMIT :lim OFFSET :off";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k=>$v) { $stmt->bindValue($k, $v, is_int($v)?\PDO::PARAM_INT:\PDO::PARAM_STR); }
    $stmt->bindValue(':lim', (int)$batch, \PDO::PARAM_INT);
    $stmt->bindValue(':off', (int)$offset, \PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    if (!$rows) break;
    foreach ($rows as $em) {
        $res = $client->classify($em);
        if (!$strict && isset($res['mode']) && $res['mode']==='gpt' && isset($res['reason']) && str_starts_with((string)$res['reason'], 'OpenAI error')) {
            $res = \App\Services\LeadScorer::compute($em);
        }
        $leadId = \App\Models\Lead::upsertFromEmail($em, $res);
        \App\Models\Lead::addCheck($leadId, $userId, $res['mode'], (int)$res['score'], (string)$res['reason']);
        $processed++;
        // Update progress frequently so UI doesn't look stuck on small sets
        @file_put_contents($progressFile, json_encode(['processed'=>$processed,'total'=>$total,'done'=>false,'updated_at'=>date('c')]));
        if ($processed >= $cap) break;
    }
    $offset += $batch;
}

@file_put_contents($progressFile, json_encode(['processed'=>$processed,'total'=>$total,'done'=>true]));
echo "Reprocessed $processed of $total leads\n";
