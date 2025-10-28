<?php
namespace App\Services;

class LocalMLClassifier
{
    // Returns null if the local model or python env is not available
    public static function classify(array $email): ?array
    {
        $root = dirname(__DIR__, 2);
        $script = $root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'predict_local.py';
        $modelJson = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ml' . DIRECTORY_SEPARATOR . 'model_nb.json';
        if (!is_file($script) || !is_file($modelJson)) {
            return null;
        }
        $python = 'python'; // Adjust if you use a venv: e.g., $root.'/.venv/Scripts/python.exe'
        $payload = [
            'subject' => (string)($email['subject'] ?? ''),
            'body_plain' => (string)($email['body_plain'] ?? ''),
            'body_html' => (string)($email['body_html'] ?? ''),
        ];
        $cmd = $python . ' ' . escapeshellarg($script);
        $desc = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
        $proc = @proc_open($cmd, $desc, $pipes, $root);
        if (!is_resource($proc)) { return null; }
        fwrite($pipes[0], json_encode($payload));
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0 || !$out) { return null; }
        $res = json_decode($out, true);
        if (!is_array($res)) { return null; }
        // Ensure required fields
        $status = in_array(($res['status'] ?? 'unknown'), ['genuine','spam','unknown'], true) ? $res['status'] : 'unknown';
        $score = (int)max(0, min(100, (int)($res['score'] ?? 50)));
        $reason = (string)($res['reason'] ?? 'local_ml');
        return ['status'=>$status,'score'=>$score,'reason'=>$reason,'mode'=>'local_ml'];
    }
}
