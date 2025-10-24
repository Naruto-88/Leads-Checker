<?php
namespace App\Controllers;

use App\Services\ImapService;
use App\Services\LeadScorer;
use App\Services\OpenAIClient;
use App\Core\DB;
use App\Models\Lead;

class CronController
{
    public function __construct(private array $env) {}

    private function checkToken(): bool
    {
        $token = $_GET['token'] ?? '';
        return hash_equals($this->env['CRON_TOKEN'] ?? '', $token);
    }

    public function fetch(): void
    {
        header('Content-Type: application/json');
        if (!$this->checkToken()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); return; }
        if (!$this->rateLimit('fetch', 60)) { http_response_code(429); echo json_encode(['ok'=>false,'error'=>'rate_limited']); return; }
        // For all users (admin scope), iterate users
        $users = DB::pdo()->query('SELECT id FROM users')->fetchAll(\PDO::FETCH_ASSOC);
        $total = 0; $errs = [];
        foreach ($users as $u) {
            try {
                $res = ImapService::fetchForUser((int)$u['id']);
                $total += $res['fetched'];
            } catch (\Throwable $e) { $errs[] = $e->getMessage(); }
        }
        self::log('cron.fetch', 'fetched='.$total);
        echo json_encode(['ok'=>true,'fetched'=>$total,'errors'=>$errs]);
    }

    public function process(): void
    {
        header('Content-Type: application/json');
        if (!$this->checkToken()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); return; }
        if (!$this->rateLimit('process', 60)) { http_response_code(429); echo json_encode(['ok'=>false,'error'=>'rate_limited']); return; }
        $users = DB::pdo()->query('SELECT id FROM users')->fetchAll(\PDO::FETCH_ASSOC);
        $processed = 0;
        foreach ($users as $u) {
            $settings = \App\Models\Setting::getByUser((int)$u['id']);
            $mode = $settings['filter_mode'] ?? 'algorithmic';
            $openaiKey = \App\Helpers::decryptSecret($settings['openai_api_key_enc'] ?? null, DB::env('APP_KEY',''));
            $client = ($mode === 'gpt' && $openaiKey) ? new OpenAIClient($openaiKey) : null;
            $stmt = DB::pdo()->prepare('SELECT e.* FROM emails e LEFT JOIN leads l ON l.email_id=e.id AND l.deleted_at IS NULL WHERE e.user_id = ? AND (l.id IS NULL OR l.status = "unknown") ORDER BY e.received_at DESC LIMIT 500');
            $stmt->execute([(int)$u['id']]);
            $emails = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($emails as $em) {
                $res = $client ? $client->classify($em) : LeadScorer::compute($em);
                $leadId = Lead::upsertFromEmail($em, $res);
                Lead::addCheck($leadId, null, $res['mode'], (int)$res['score'], (string)$res['reason']);
                $processed++;
            }
        }
        self::log('cron.process', 'processed='.$processed);
        echo json_encode(['ok'=>true,'processed'=>$processed]);
    }

    private static function log(string $tag, string $message): void
    {
        $line = sprintf("%s [%s] %s\n", date('c'), $tag, $message);
        $dir = BASE_PATH . '/storage/logs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/app.log', $line, FILE_APPEND);
    }

    private function rateLimit(string $key, int $seconds): bool
    {
        $dir = BASE_PATH . '/storage/logs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $file = $dir . '/cron.' . $key . '.lock';
        $now = time();
        if (file_exists($file)) {
            $last = (int)@file_get_contents($file);
            if ($last && ($now - $last) < $seconds) {
                return false;
            }
        }
        @file_put_contents($file, (string)$now, LOCK_EX);
        return true;
    }
}
