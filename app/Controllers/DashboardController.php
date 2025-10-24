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

    public function dashboard2(): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $quick = $_GET['range'] ?? 'last_7';
        [$startDefault, $endDefault] = \App\Helpers::dateRangeQuick($quick);
        $start = trim($_GET['start'] ?? '') ?: $startDefault;
        $end = trim($_GET['end'] ?? '') ?: $endDefault;

        $pdo = DB::pdo();

        // Header KPIs
        $qNew = $pdo->prepare('SELECT COUNT(*) FROM emails WHERE user_id=? AND received_at BETWEEN ? AND ?');
        $qNew->execute([$user['id'], $start, $end]);
        $newEmails = (int)$qNew->fetchColumn();

        $qProcessed = $pdo->prepare("SELECT COUNT(*) FROM leads l JOIN emails e ON e.id=l.email_id WHERE l.user_id=? AND l.deleted_at IS NULL AND l.status <> 'unknown' AND e.received_at BETWEEN ? AND ?");
        $qProcessed->execute([$user['id'], $start, $end]);
        $processed = (int)$qProcessed->fetchColumn();

        $qGenuine = $pdo->prepare("SELECT COUNT(*) FROM leads l JOIN emails e ON e.id=l.email_id WHERE l.user_id=? AND l.deleted_at IS NULL AND l.status='genuine' AND e.received_at BETWEEN ? AND ?");
        $qGenuine->execute([$user['id'], $start, $end]);
        $genuine = (int)$qGenuine->fetchColumn();

        $qSpam = $pdo->prepare("SELECT COUNT(*) FROM leads l JOIN emails e ON e.id=l.email_id WHERE l.user_id=? AND l.deleted_at IS NULL AND l.status='spam' AND e.received_at BETWEEN ? AND ?");
        $qSpam->execute([$user['id'], $start, $end]);
        $spam = (int)$qSpam->fetchColumn();

        $genuineRate = $processed > 0 ? round($genuine * 100 / $processed, 1) : 0.0;
        $spamRate = $processed > 0 ? round($spam * 100 / $processed, 1) : 0.0;

        // Leads over time (per day)
        $qDaily = $pdo->prepare("SELECT DATE(e.received_at) d, l.status, COUNT(*) c
                                 FROM leads l JOIN emails e ON e.id=l.email_id
                                 WHERE l.user_id=? AND l.deleted_at IS NULL AND e.received_at BETWEEN ? AND ?
                                 GROUP BY DATE(e.received_at), l.status ORDER BY d ASC");
        $qDaily->execute([$user['id'], $start, $end]);
        $dailyRows = $qDaily->fetchAll(\PDO::FETCH_ASSOC);
        $series = [];
        foreach ($dailyRows as $r) { $series[$r['d']][$r['status']] = (int)$r['c']; }
        // Build continuous date range
        $dates = [];
        $dt = new \DateTime(substr($start,0,10));
        $dtEnd = new \DateTime(substr($end,0,10));
        while ($dt <= $dtEnd) { $dates[] = $dt->format('Y-m-d'); $dt->modify('+1 day'); }
        $chart = [ 'labels'=>$dates, 'genuine'=>[], 'spam'=>[], 'unknown'=>[] ];
        foreach ($dates as $d) {
            $chart['genuine'][] = (int)($series[$d]['genuine'] ?? 0);
            $chart['spam'][] = (int)($series[$d]['spam'] ?? 0);
            $chart['unknown'][] = (int)($series[$d]['unknown'] ?? 0);
        }

        // Status split
        $statusSplit = ['genuine'=>$genuine,'spam'=>$spam,'unknown'=>max(0,$newEmails - ($genuine+$spam))];

        // Top sender domains (from emails in range)
        $qDomains = $pdo->prepare("SELECT LOWER(SUBSTRING_INDEX(from_email,'@',-1)) dom, COUNT(*) c
                                   FROM emails WHERE user_id=? AND received_at BETWEEN ? AND ? AND from_email LIKE '%@%'
                                   GROUP BY dom ORDER BY c DESC LIMIT 10");
        $qDomains->execute([$user['id'], $start, $end]);
        $domains = $qDomains->fetchAll(\PDO::FETCH_ASSOC);

        // Recent leads
        $qRecent = $pdo->prepare("SELECT l.*, e.from_email, e.subject, e.received_at
                                  FROM leads l JOIN emails e ON e.id=l.email_id
                                  WHERE l.user_id=? AND l.deleted_at IS NULL AND e.received_at BETWEEN ? AND ?
                                  ORDER BY e.received_at DESC LIMIT 10");
        $qRecent->execute([$user['id'], $start, $end]);
        $recent = $qRecent->fetchAll(\PDO::FETCH_ASSOC);

        // System health
        $qQueue = $pdo->prepare("SELECT COUNT(*) FROM emails e LEFT JOIN leads l ON l.email_id=e.id AND l.deleted_at IS NULL WHERE e.user_id=? AND (l.id IS NULL OR l.status='unknown')");
        $qQueue->execute([$user['id']]);
        $queue = (int)$qQueue->fetchColumn();
        $lastFetch = $pdo->prepare('SELECT MAX(received_at) FROM emails WHERE user_id=?');
        $lastFetch->execute([$user['id']]);
        $lastFetchAt = (string)$lastFetch->fetchColumn();
        $lastProcess = $pdo->prepare('SELECT MAX(updated_at) FROM leads WHERE user_id=?');
        $lastProcess->execute([$user['id']]);
        $lastProcessAt = (string)$lastProcess->fetchColumn();

        View::render('dashboard2/index', [
            'range'=>$quick,
            'start'=>$start,
            'end'=>$end,
            'newEmails'=>$newEmails,
            'processed'=>$processed,
            'genuine'=>$genuine,
            'spam'=>$spam,
            'genuineRate'=>$genuineRate,
            'spamRate'=>$spamRate,
            'chart'=>$chart,
            'statusSplit'=>$statusSplit,
            'domains'=>$domains,
            'recent'=>$recent,
            'queue'=>$queue,
            'lastFetchAt'=>$lastFetchAt,
            'lastProcessAt'=>$lastProcessAt,
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
        if (!class_exists('App\\Services\\LeadScorer')) {
            require_once BASE_PATH . '/app/Services/LeadScorer.php';
        }
        $user = Auth::user();
        $settings = \App\Models\Setting::getByUser($user['id']);
        $pdo = DB::pdo();
        $batch = (int)($_POST['batch'] ?? 0);
        if ($batch <= 0) { $batch = 500; }
        $all = !empty($_POST['all']);
        $cap = max($batch, (int)($_POST['cap'] ?? $batch));
        $processed = 0;
        // For "all" mode, iterate deterministically without repeating the same top-N rows
        $allLastId = 0;
        $fetchBatch = function(int $limit) use ($pdo, $user, $all, &$allLastId) {
            if ($all) {
                // Walk forward by id to avoid re-reading the same set each loop
                $stmt = $pdo->prepare('SELECT e.* FROM emails e WHERE e.user_id = :uid AND e.id > :lastId ORDER BY e.id ASC LIMIT :limit');
                $stmt->bindValue(':uid', (int)$user['id'], \PDO::PARAM_INT);
                $stmt->bindValue(':lastId', (int)$allLastId, \PDO::PARAM_INT);
                $stmt->bindValue(':limit', (int)$limit, \PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                if ($rows) { $allLastId = (int)end($rows)['id']; }
                return $rows;
            } else {
                $stmt = $pdo->prepare('SELECT e.* FROM emails e LEFT JOIN leads l ON l.email_id=e.id AND l.deleted_at IS NULL WHERE e.user_id = :uid AND (l.id IS NULL OR l.status = "unknown") ORDER BY e.received_at DESC LIMIT :limit');
                $stmt->bindValue(':uid', (int)$user['id'], \PDO::PARAM_INT);
                $stmt->bindValue(':limit', (int)$limit, \PDO::PARAM_INT);
                $stmt->execute();
                return $stmt->fetchAll(\PDO::FETCH_ASSOC);
            }
        };
        $emails = $fetchBatch($batch);
        $mode = $settings['filter_mode'] ?? 'algorithmic';
        $openaiKey = \App\Helpers::decryptSecret($settings['openai_api_key_enc'] ?? null, DB::env('APP_KEY',''));
        $client = ($mode === 'gpt' && $openaiKey) ? new OpenAIClient($openaiKey) : null;
        $processEmails = function(array $list) use (&$processed, $client, $user) {
            if (!class_exists('App\\Services\\LeadScorer')) {
                require_once BASE_PATH . '/app/Services/LeadScorer.php';
            }
            foreach ($list as $em) {
                if (empty($em['client_id'])) {
                    $assign = \App\Services\ClientAssigner::assign($user['id'], $em);
                    if ($assign['client_id']) {
                        \App\Models\Email::updateClient((int)$em['id'], (int)$assign['client_id']);
                        $em['client_id'] = (int)$assign['client_id'];
                    }
                }
                $res = $client ? $client->classify($em) : \App\Services\LeadScorer::compute($em);
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
                // Respect cap even if the last batch would overflow
                if ($processed + count($next) > $cap) {
                    $next = array_slice($next, 0, max(0, $cap - $processed));
                }
                if ($next) { $processEmails($next); }
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
        if (!class_exists('App\\Services\\LeadScorer')) {
            require_once BASE_PATH . '/app/Services/LeadScorer.php';
        }
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
            if (!class_exists('App\\Services\\LeadScorer')) {
                require_once BASE_PATH . '/app/Services/LeadScorer.php';
            }
            foreach ($emails as $em) {
                if (empty($em['client_id'])) {
                    $assign = \App\Services\ClientAssigner::assign($user['id'], $em);
                    if ($assign['client_id']) {
                        \App\Models\Email::updateClient((int)$em['id'], (int)$assign['client_id']);
                        $em['client_id'] = (int)$assign['client_id'];
                    }
                }
                $res = $client ? $client->classify($em) : \App\Services\LeadScorer::compute($em);
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
