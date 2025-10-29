<?php
// Train a simple hashed Naive Bayes classifier from leads labeled as genuine/spam.
// CLI: php tools/train_local_model.php [USER_ID]
// Also used by SettingsController action via include.

declare(strict_types=1);

namespace Tools\TrainLocalModel;

use App\Core\DB;

function tokenize(string $text): array {
    $text = strtolower($text);
    preg_match_all('/[a-z0-9]+/i', $text, $m);
    $toks = $m[0] ?? [];
    $out = [];
    $n = count($toks);
    for ($i=0; $i<$n; $i++) {
        $out[] = $toks[$i];
        if ($i+1 < $n) { $out[] = $toks[$i] . '_' . $toks[$i+1]; }
    }
    return $out;
}

function stable_hash(string $s, int $D): int {
    $h = sha1($s, true); // raw bytes
    $num = unpack('P', substr($h, 0, 8))[1]; // little-endian 8 bytes
    if ($num < 0) { $num = $num & 0x7fffffffffffffff; }
    return (int)($num % $D);
}

function strip_html(?string $html): string {
    if (!$html) return '';
    return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function run(?int $userId = null): array {
    $pdo = DB::pdo();
    if ($userId === null) {
        // Pick first user (or default 1)
        $userId = (int)($pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 1);
    }
    $sql = "SELECT l.id, l.client_id, COALESCE(c.shortcode, '') AS shortcode, e.subject, e.body_plain, e.body_html, l.status
            FROM leads l
            JOIN emails e ON e.id = l.email_id
            LEFT JOIN clients c ON c.id = l.client_id
            WHERE l.user_id = :uid AND l.deleted_at IS NULL AND l.status IN ('genuine','spam')";
    $st = $pdo->prepare($sql);
    $st->execute([':uid'=>$userId]);
    $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
    $docs = []; $labels = []; $clients = [];
    foreach ($rows as $r) {
        $text = (string)($r['subject'] ?? '') . ' ' . ((string)($r['body_plain'] ?? '') ?: strip_html($r['body_html'] ?? ''));
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if ($text === '') continue;
        $docs[] = $text;
        $labels[] = ($r['status'] === 'genuine') ? 1 : 0;
        $clients[] = (string)($r['shortcode'] ?? '');
    }
    $n = count($labels);
    if ($n < 50) {
        return ['ok'=>false,'error'=>'Not enough labeled examples (needs >= 50)','count'=>$n];
    }
    // Train/test split
    $idx = range(0, $n-1);
    // deterministic shuffle
    mt_srand(42);
    for ($i=$n-1; $i>0; $i--) { $j = mt_rand(0, $i); [$idx[$i], $idx[$j]] = [$idx[$j], $idx[$i]]; }
    $cut = (int)floor(0.8*$n);
    $tr = array_slice($idx, 0, $cut); $te = array_slice($idx, $cut);

    $D = 1<<18; $alpha = 1.0;
    $cls_counts = [0,0];
    $feat0 = []; $feat1 = []; // associative key=>count
    $total_tokens = [0,0];

    foreach ($tr as $i) {
        $y = $labels[$i]; $cls_counts[$y]++;
        $toks = tokenize($docs[$i]);
        foreach ($toks as $t) {
            $k = stable_hash($t, $D);
            if ($y === 0) { $feat0[$k] = ($feat0[$k] ?? 0) + 1; }
            else { $feat1[$k] = ($feat1[$k] ?? 0) + 1; }
            $total_tokens[$y]++;
        }
    }

    $acc = 0; $perClient = [];
    foreach ($te as $i) {
        $y = $labels[$i];
        $toks = tokenize($docs[$i]);
        $logp = [0.0, 0.0];
        $total_cls = $cls_counts[0] + $cls_counts[1];
        for ($c=0; $c<=1; $c++) {
            $prior = ($cls_counts[$c] + $alpha) / ($total_cls + 2*$alpha);
            $logp[$c] = log($prior);
        }
        foreach ($toks as $t) {
            $k = stable_hash($t, $D);
            for ($c=0; $c<=1; $c++) {
                $fc = ($c===0) ? ($feat0[$k] ?? 0) : ($feat1[$k] ?? 0);
                $num = $fc + $alpha; $den = $total_tokens[$c] + $alpha*$D;
                $logp[$c] += log($num/$den);
            }
        }
        $m = max($logp[0], $logp[1]);
        $ex0 = exp($logp[0]-$m); $ex1 = exp($logp[1]-$m); $p1 = $ex1/($ex0+$ex1);
        $yh = ($p1 >= 0.5) ? 1 : 0;
        if ($yh === $y) $acc++;
        $ckey = $clients[$i] !== '' ? $clients[$i] : 'NONE';
        if (!isset($perClient[$ckey])) { $perClient[$ckey] = ['TP'=>0,'TN'=>0,'FP'=>0,'FN'=>0,'N'=>0]; }
        if ($y==1 && $yh==1) $perClient[$ckey]['TP']++;
        elseif ($y==0 && $yh==0) $perClient[$ckey]['TN']++;
        elseif ($y==0 && $yh==1) $perClient[$ckey]['FP']++;
        elseif ($y==1 && $yh==0) $perClient[$ckey]['FN']++;
        $perClient[$ckey]['N']++;
    }
    $accuracy = $acc / max(1, count($te));

    // Save model
    $outDir = BASE_PATH . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ml';
    if (!is_dir($outDir)) @mkdir($outDir, 0777, true);
    $model = [
        'D'=>$D,
        'alpha'=>$alpha,
        'cls_counts'=>$cls_counts,
        'total_tokens'=>$total_tokens,
        'feat_counts_0'=>array_map('intval', $feat0),
        'feat_counts_1'=>array_map('intval', $feat1),
    ];
    file_put_contents($outDir . DIRECTORY_SEPARATOR . 'model_nb.json', json_encode($model));

    // Build confusion lines (top by N)
    arsort($perClient);
    $conf = [];
    foreach ($perClient as $code=>$m) {
        $prec = ($m['TP'] + $m['FP'])>0 ? $m['TP']/($m['TP']+$m['FP']) : null;
        $rec = ($m['TP'] + $m['FN'])>0 ? $m['TP']/($m['TP']+$m['FN']) : null;
        $conf[] = [ 'client'=>$code, 'N'=>$m['N'], 'TP'=>$m['TP'],'TN'=>$m['TN'],'FP'=>$m['FP'],'FN'=>$m['FN'], 'precision'=>$prec, 'recall'=>$rec ];
    }

    return [
        'ok'=>true,
        'count'=>$n,
        'train_size'=>count($tr),
        'test_size'=>count($te),
        'accuracy'=>$accuracy,
        'per_client'=>$conf,
        'model_path'=>$outDir . DIRECTORY_SEPARATOR . 'model_nb.json',
    ];
}

// CLI entry
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['argv'][0])) {
    define('BASE_PATH', dirname(__DIR__));
    // bootstrap
    spl_autoload_register(function ($class) {
        $prefix = 'App\\'; $base_dir = BASE_PATH . '/app/'; $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) return; $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php'; if (file_exists($file)) require $file;
    });
    $envFile = BASE_PATH . '/config/.env.php'; if (!file_exists($envFile)) { $envFile = BASE_PATH . '/config/config.sample.env.php'; }
    $ENV = require $envFile; \App\Core\DB::init($ENV);
    $uid = isset($argv[1]) ? (int)$argv[1] : null;
    $res = run($uid);
    if (!$res['ok']) { fwrite(STDERR, ($res['error'] ?? 'error') . "\n"); exit(2); }
    echo 'Trained on ' . $res['count'] . ' rows. Accuracy: ' . number_format((float)$res['accuracy']*100,2) . "%\n";
    $top = array_slice($res['per_client'], 0, 10);
    foreach ($top as $r) {
        echo ($r['client'] ?: 'NONE') . ': N=' . $r['N'] . ' TP=' . $r['TP'] . ' TN=' . $r['TN'] . ' FP=' . $r['FP'] . ' FN=' . $r['FN'] . "\n";
    }
}

