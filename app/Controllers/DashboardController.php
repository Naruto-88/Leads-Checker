<?php
namespace App\Controllers;

use App\Core\View;
use App\Security\Auth;
use App\Services\ImapService;
use App\Services\LeadScorer;
use App\Services\OpenAIClient;
use App\Models\Lead;
use App\Helpers;
use App\Core\DB;

class DashboardController
{
    public function __construct(private array $env) {}

    public function index(): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $pdo = DB::pdo();
        $seven = (new \DateTime('-7 days'))->format('Y-m-d H:i:s');
        $thirty = (new \DateTime('-30 days'))->format('Y-m-d H:i:s');
        $newEmails = $pdo->prepare('SELECT COUNT(*) FROM emails WHERE user_id = ? AND received_at >= ?');
        $newEmails->execute([$user['id'], $seven]);
        $genuine7 = $pdo->prepare("SELECT COUNT(*) FROM leads l JOIN emails e ON e.id=l.email_id WHERE l.user_id=? AND l.deleted_at IS NULL AND l.status='genuine' AND e.received_at>=?");
        $genuine7->execute([$user['id'], $seven]);
        $genuine30 = $pdo->prepare("SELECT COUNT(*) FROM leads l JOIN emails e ON e.id=l.email_id WHERE l.user_id=? AND l.deleted_at IS NULL AND l.status='genuine' AND e.received_at>=?");
        $genuine30->execute([$user['id'], $thirty]);
        $spam = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE user_id=? AND deleted_at IS NULL AND status='spam'");
        $spam->execute([$user['id']]);
        $queue = $pdo->prepare("SELECT COUNT(*) FROM emails e LEFT JOIN leads l ON l.email_id=e.id AND l.deleted_at IS NULL WHERE e.user_id=? AND (l.id IS NULL OR l.status='unknown')");
        $queue->execute([$user['id']]);

        View::render('dashboard/index', [
            'newEmails' => (int)$newEmails->fetchColumn(),
            'genuine7' => (int)$genuine7->fetchColumn(),
            'genuine30' => (int)$genuine30->fetchColumn(),
            'spam' => (int)$spam->fetchColumn(),
            'queue' => (int)$queue->fetchColumn(),
        ]);
    }

    public function fetchNow(): void
    {
        Auth::requireLogin();
        if (!\App\Security\Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        $res = ImapService::fetchForUser(Auth::user()['id']);
        $msg = 'Fetched ' . $res['fetched'] . ' emails';
        if (!empty($res['errors'])) {
            $msg .= ' (errors: ' . count($res['errors']) . ')';
            $msg .= '. Example: ' . substr((string)$res['errors'][0], 0, 180);
        }
        $_SESSION['flash'] = $msg;
        $return = trim($_POST['return'] ?? '');
        if ($return && str_starts_with($return, '/')) {
            Helpers::redirect($return);
        }
        Helpers::redirect('/');
    }

    public function runFilter(): void
    {
        Auth::requireLogin();
        if (!\App\Security\Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        $user = Auth::user();
        $settings = \App\Models\Setting::getByUser($user['id']);
        $pdo = DB::pdo();
        $batch = (int)($_POST['batch'] ?? 0);
        if ($batch <= 0) { $batch = 500; }
        $all = !empty($_POST['all']);
        $cap = max($batch, (int)($_POST['cap'] ?? $batch));
        $processed = 0;
        $fetchBatch = function(int $limit) use ($pdo, $user) {
            $stmt = $pdo->prepare('SELECT e.* FROM emails e LEFT JOIN leads l ON l.email_id=e.id AND l.deleted_at IS NULL WHERE e.user_id = ? AND (l.id IS NULL OR l.status = "unknown") ORDER BY e.received_at DESC LIMIT ' . (int)$limit);
            $stmt->execute([$user['id']]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        };
        $emails = $fetchBatch($batch);
        $mode = $settings['filter_mode'] ?? 'algorithmic';
        $openaiKey = \App\Helpers::decryptSecret($settings['openai_api_key_enc'] ?? null, DB::env('APP_KEY',''));
        $client = ($mode === 'gpt' && $openaiKey) ? new OpenAIClient($openaiKey) : null;
        $processEmails = function(array $list) use (&$processed, $client, $user) {
            foreach ($list as $em) {
                if (empty($em['client_id'])) {
                    $assign = \App\Services\ClientAssigner::assign($user['id'], $em);
                    if ($assign['client_id']) {
                        \App\Models\Email::updateClient((int)$em['id'], (int)$assign['client_id']);
                        $em['client_id'] = (int)$assign['client_id'];
                    }
                }
                $res = $client ? $client->classify($em) : LeadScorer::compute($em);
                $leadId = Lead::upsertFromEmail($em, $res);
                Lead::addCheck($leadId, $user['id'], $res['mode'], (int)$res['score'], (string)$res['reason']);
                $processed++;
            }
        };

        $processEmails($emails);
        if ($all) {
            @set_time_limit(300);
            while ($processed < $cap) {
                $next = $fetchBatch($batch);
                if (!$next) break;
                $processEmails($next);
            }
        }
        $_SESSION['flash'] = 'Processed ' . $processed . ' emails.';
        $return = trim($_POST['return'] ?? '');
        if ($return && str_starts_with($return, '/')) {
            Helpers::redirect($return);
        }
        Helpers::redirect('/');
    }

    public function runFilterAll(): void
    {
        Auth::requireLogin();
        if (!\App\Security\Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        @set_time_limit(300);
        $user = Auth::user();
        $pdo = DB::pdo();

        $settings = \App\Models\Setting::getByUser($user['id']);
        $mode = $settings['filter_mode'] ?? 'algorithmic';
        $openaiKey = \App\Helpers::decryptSecret($settings['openai_api_key_enc'] ?? null, DB::env('APP_KEY',''));
        $client = ($mode === 'gpt' && $openaiKey) ? new OpenAIClient($openaiKey) : null;

        $batch = max(100, (int)($_POST['batch'] ?? 500));
        $cap = max($batch, (int)($_POST['cap'] ?? 5000));
        $processed = 0; $loops = 0;

        // Determine initial queue size for progress
        $qStmt0 = $pdo->prepare('SELECT COUNT(*) FROM emails e LEFT JOIN leads l ON l.email_id=e.id AND l.deleted_at IS NULL WHERE e.user_id=? AND (l.id IS NULL OR l.status="unknown")');
        $qStmt0->execute([$user['id']]);
        $total = (int)$qStmt0->fetchColumn();
        $progressFile = BASE_PATH . '/storage/logs/progress_user_' . (int)$user['id'] . '.json';
        @file_put_contents($progressFile, json_encode(['processed'=>0,'total'=>$total,'done'=>false]));

        $fetchBatch = function(int $limit) use ($pdo, $user) {
            $stmt = $pdo->prepare('SELECT e.* FROM emails e LEFT JOIN leads l ON l.email_id=e.id AND l.deleted_at IS NULL WHERE e.user_id = ? AND (l.id IS NULL OR l.status = "unknown") ORDER BY e.received_at DESC LIMIT ' . (int)$limit);
            $stmt->execute([$user['id']]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        };

        while ($processed < $cap) {
            $emails = $fetchBatch($batch);
            if (!$emails) break;
            foreach ($emails as $em) {
                if (empty($em['client_id'])) {
                    $assign = \App\Services\ClientAssigner::assign($user['id'], $em);
                    if ($assign['client_id']) {
                        \App\Models\Email::updateClient((int)$em['id'], (int)$assign['client_id']);
                        $em['client_id'] = (int)$assign['client_id'];
                    }
                }
                $res = $client ? $client->classify($em) : LeadScorer::compute($em);
                $leadId = Lead::upsertFromEmail($em, $res);
                Lead::addCheck($leadId, $user['id'], $res['mode'], (int)$res['score'], (string)$res['reason']);
                $processed++;
                if ($processed % 20 === 0 || $processed === $total) {
                    @file_put_contents($progressFile, json_encode(['processed'=>$processed,'total'=>$total,'done'=>false]));
                }
                if ($processed >= $cap) { break; }
            }
            $loops++;
        }

        $qStmt = $pdo->prepare('SELECT COUNT(*) FROM emails e LEFT JOIN leads l ON l.email_id=e.id AND l.deleted_at IS NULL WHERE e.user_id=? AND (l.id IS NULL OR l.status="unknown")');
        $qStmt->execute([$user['id']]);
        $remaining = (int)$qStmt->fetchColumn();

        @file_put_contents($progressFile, json_encode(['processed'=>$processed,'total'=>$total,'done'=>true,'remaining'=>$remaining]));

        $_SESSION['flash'] = 'Processed ' . $processed . ' emails in ' . $loops . ' pass(es). Remaining queue: ' . $remaining . '.';
        $return = trim($_POST['return'] ?? '');
        if ($return && str_starts_with($return, '/')) {
            Helpers::redirect($return);
        }
        Helpers::redirect('/');
    }

    public function filterProgress(): void
    {
        Auth::requireLogin();
        header('Content-Type: application/json');
        $user = Auth::user();
        $file = BASE_PATH . '/storage/logs/progress_user_' . (int)$user['id'] . '.json';
        if (!file_exists($file)) { echo json_encode(['processed'=>0,'total'=>0,'done'=>false]); return; }
        $json = @file_get_contents($file);
        echo $json !== false ? $json : json_encode(['processed'=>0,'total'=>0,'done'=>false]);
    }
}
