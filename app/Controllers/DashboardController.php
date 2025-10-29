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
        // Client filter (by shortcode like Leads page)
        $clientCode = trim($_GET['client'] ?? '');
        $client = $clientCode ? \App\Models\Client::findByShortcode($user['id'], $clientCode) : null;
        $clientId = $client['id'] ?? null;
        // Normalize and validate range/start/end
        $allowed = ['last_week','last_7','last_month','last_30','all','custom'];
        if (!in_array($quick, $allowed, true)) { $quick = 'last_7'; }
        [$startDefault, $endDefault] = \App\Helpers::dateRangeQuick($quick === 'custom' ? 'last_7' : $quick);
        $startRaw = trim($_GET['start'] ?? '');
        $endRaw = trim($_GET['end'] ?? '');
        if ($quick === 'custom') {
            // Accept YYYY-MM-DD or full datetime; fill missing times
            $start = $startRaw !== '' ? (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startRaw) ? $startRaw.' 00:00:00' : $startRaw) : $startDefault;
            $end = $endRaw !== '' ? (preg_match('/^\d{4}-\d{2}-\d{2}$/', $endRaw) ? $endRaw.' 23:59:59' : $endRaw) : $endDefault;
        } else {
            $start = $startDefault; $end = $endDefault;
        }
        // Safety: ensure chronological order
        try {
            $ds = new \DateTime($start); $de = new \DateTime($end);
            if ($ds > $de) { $tmp = $start; $start = $end; $end = $tmp; }
        } catch (\Throwable $e) {}

        $pdo = DB::pdo();

        // If All Time selected, derive actual bounds from user's data to avoid huge ranges
        if ($quick === 'all') {
            if ($clientId) {
                $mm = $pdo->prepare('SELECT MIN(received_at) AS mn, MAX(received_at) AS mx FROM emails WHERE user_id = ? AND client_id = ?');
                $mm->execute([$user['id'], $clientId]);
            } else {
                $mm = $pdo->prepare('SELECT MIN(received_at) AS mn, MAX(received_at) AS mx FROM emails WHERE user_id = ?');
                $mm->execute([$user['id']]);
            }
            $row = $mm->fetch(\PDO::FETCH_ASSOC) ?: [];
            if (!empty($row['mn'])) { $start = (new \DateTime($row['mn']))->format('Y-m-d 00:00:00'); }
            if (!empty($row['mx'])) { $end = (new \DateTime($row['mx']))->format('Y-m-d 23:59:59'); }
        }

        // Header KPIs
        if ($clientId) {
            $qNew = $pdo->prepare('SELECT COUNT(*) FROM emails WHERE user_id=? AND client_id=? AND received_at BETWEEN ? AND ?');
            $qNew->execute([$user['id'], $clientId, $start, $end]);
        } else {
            $qNew = $pdo->prepare('SELECT COUNT(*) FROM emails WHERE user_id=? AND received_at BETWEEN ? AND ?');
            $qNew->execute([$user['id'], $start, $end]);
        }
        $newEmails = (int)$qNew->fetchColumn();

        if ($clientId) {
            $qProcessed = $pdo->prepare("SELECT COUNT(*) FROM leads l JOIN emails e ON e.id=l.email_id WHERE l.user_id=? AND l.client_id=? AND l.deleted_at IS NULL AND l.status <> 'unknown' AND e.received_at BETWEEN ? AND ?");
            $qProcessed->execute([$user['id'], $clientId, $start, $end]);
        } else {
            $qProcessed = $pdo->prepare("SELECT COUNT(*) FROM leads l JOIN emails e ON e.id=l.email_id WHERE l.user_id=? AND l.deleted_at IS NULL AND l.status <> 'unknown' AND e.received_at BETWEEN ? AND ?");
            $qProcessed->execute([$user['id'], $start, $end]);
        }
        $processed = (int)$qProcessed->fetchColumn();

        if ($clientId) {
            $qGenuine = $pdo->prepare("SELECT COUNT(*) FROM leads l JOIN emails e ON e.id=l.email_id WHERE l.user_id=? AND l.client_id=? AND l.deleted_at IS NULL AND l.status='genuine' AND e.received_at BETWEEN ? AND ?");
            $qGenuine->execute([$user['id'], $clientId, $start, $end]);
        } else {
            $qGenuine = $pdo->prepare("SELECT COUNT(*) FROM leads l JOIN emails e ON e.id=l.email_id WHERE l.user_id=? AND l.deleted_at IS NULL AND l.status='genuine' AND e.received_at BETWEEN ? AND ?");
            $qGenuine->execute([$user['id'], $start, $end]);
        }
        $genuine = (int)$qGenuine->fetchColumn();

        if ($clientId) {
            $qSpam = $pdo->prepare("SELECT COUNT(*) FROM leads l JOIN emails e ON e.id=l.email_id WHERE l.user_id=? AND l.client_id=? AND l.deleted_at IS NULL AND l.status='spam' AND e.received_at BETWEEN ? AND ?");
            $qSpam->execute([$user['id'], $clientId, $start, $end]);
        } else {
            $qSpam = $pdo->prepare("SELECT COUNT(*) FROM leads l JOIN emails e ON e.id=l.email_id WHERE l.user_id=? AND l.deleted_at IS NULL AND l.status='spam' AND e.received_at BETWEEN ? AND ?");
            $qSpam->execute([$user['id'], $start, $end]);
        }
        $spam = (int)$qSpam->fetchColumn();

        $genuineRate = $processed > 0 ? round($genuine * 100 / $processed, 1) : 0.0;
        $spamRate = $processed > 0 ? round($spam * 100 / $processed, 1) : 0.0;

        // Leads over time (per day)
        // Choose grouping granularity by span to avoid memory blowups
        $ds = new \DateTime($start); $de = new \DateTime($end);
        $spanDays = (int)$ds->diff($de)->format('%a');
        $groupMonthly = $spanDays > 370; // if more than ~1 year, group by month

        if ($groupMonthly) {
            if ($clientId) {
                $q = $pdo->prepare("SELECT DATE_FORMAT(e.received_at,'%Y-%m') p, l.status, COUNT(*) c
                                     FROM leads l JOIN emails e ON e.id=l.email_id
                                     WHERE l.user_id=? AND l.client_id=? AND l.deleted_at IS NULL AND e.received_at BETWEEN ? AND ?
                                     GROUP BY p, l.status ORDER BY p ASC");
                $q->execute([$user['id'], $clientId, $start, $end]);
            } else {
                $q = $pdo->prepare("SELECT DATE_FORMAT(e.received_at,'%Y-%m') p, l.status, COUNT(*) c
                                     FROM leads l JOIN emails e ON e.id=l.email_id
                                     WHERE l.user_id=? AND l.deleted_at IS NULL AND e.received_at BETWEEN ? AND ?
                                     GROUP BY p, l.status ORDER BY p ASC");
                $q->execute([$user['id'], $start, $end]);
            }
            $rows = $q->fetchAll(\PDO::FETCH_ASSOC);
            $series = [];
            foreach ($rows as $r) { $series[$r['p']][$r['status']] = (int)$r['c']; }
            $labels = array_keys($series);
            sort($labels);
            $chart = [ 'labels'=>$labels, 'genuine'=>[], 'spam'=>[], 'unknown'=>[] ];
            foreach ($labels as $p) {
                $chart['genuine'][] = (int)($series[$p]['genuine'] ?? 0);
                $chart['spam'][] = (int)($series[$p]['spam'] ?? 0);
                $chart['unknown'][] = (int)($series[$p]['unknown'] ?? 0);
            }
        } else {
            if ($clientId) {
                $qDaily = $pdo->prepare("SELECT DATE(e.received_at) d, l.status, COUNT(*) c
                                         FROM leads l JOIN emails e ON e.id=l.email_id
                                         WHERE l.user_id=? AND l.client_id=? AND l.deleted_at IS NULL AND e.received_at BETWEEN ? AND ?
                                         GROUP BY DATE(e.received_at), l.status ORDER BY d ASC");
                $qDaily->execute([$user['id'], $clientId, $start, $end]);
            } else {
                $qDaily = $pdo->prepare("SELECT DATE(e.received_at) d, l.status, COUNT(*) c
                                         FROM leads l JOIN emails e ON e.id=l.email_id
                                         WHERE l.user_id=? AND l.deleted_at IS NULL AND e.received_at BETWEEN ? AND ?
                                         GROUP BY DATE(e.received_at), l.status ORDER BY d ASC");
                $qDaily->execute([$user['id'], $start, $end]);
            }
            $dailyRows = $qDaily->fetchAll(\PDO::FETCH_ASSOC);
            $series = [];
            foreach ($dailyRows as $r) { $series[$r['d']][$r['status']] = (int)$r['c']; }
            // Build continuous date range but cap to safety
            $dates = [];
            $dt = new \DateTime(substr($start,0,10));
            $dtEnd = new \DateTime(substr($end,0,10));
            $safe = 0; $limit = 400; // cap at ~400 points
            while ($dt <= $dtEnd && $safe < $limit) { $dates[] = $dt->format('Y-m-d'); $dt->modify('+1 day'); $safe++; }
            $chart = [ 'labels'=>$dates, 'genuine'=>[], 'spam'=>[], 'unknown'=>[] ];
            foreach ($dates as $d) {
                $chart['genuine'][] = (int)($series[$d]['genuine'] ?? 0);
                $chart['spam'][] = (int)($series[$d]['spam'] ?? 0);
                $chart['unknown'][] = (int)($series[$d]['unknown'] ?? 0);
            }
        }

        // Status split
        $statusSplit = ['genuine'=>$genuine,'spam'=>$spam,'unknown'=>max(0,$newEmails - ($genuine+$spam))];

        // Top sender domains (from emails in range) with optional client filter
        $clientCode = trim($_GET['client'] ?? '');
        $client = $clientCode ? \App\Models\Client::findByShortcode($user['id'], $clientCode) : null;
        $clientId = $client['id'] ?? null;
        if ($clientId) {
            $qDomains = $pdo->prepare("SELECT LOWER(SUBSTRING_INDEX(from_email,'@',-1)) dom, COUNT(*) c
                                       FROM emails WHERE user_id=? AND client_id=? AND received_at BETWEEN ? AND ? AND from_email LIKE '%@%'
                                       GROUP BY dom ORDER BY c DESC LIMIT 10");
            $qDomains->execute([$user['id'], $clientId, $start, $end]);
        } else {
            $qDomains = $pdo->prepare("SELECT LOWER(SUBSTRING_INDEX(from_email,'@',-1)) dom, COUNT(*) c
                                       FROM emails WHERE user_id=? AND received_at BETWEEN ? AND ? AND from_email LIKE '%@%'
                                       GROUP BY dom ORDER BY c DESC LIMIT 10");
            $qDomains->execute([$user['id'], $start, $end]);
        }
        $domains = $qDomains->fetchAll(\PDO::FETCH_ASSOC);

        // Recent leads
        if ($clientId) {
            $qRecent = $pdo->prepare("SELECT l.*, e.from_email, e.subject, e.received_at
                                      FROM leads l JOIN emails e ON e.id=l.email_id
                                      WHERE l.user_id=? AND l.deleted_at IS NULL AND e.received_at BETWEEN ? AND ? AND l.client_id = ?
                                      ORDER BY e.received_at DESC LIMIT 10");
            $qRecent->execute([$user['id'], $start, $end, $clientId]);
        } else {
            $qRecent = $pdo->prepare("SELECT l.*, e.from_email, e.subject, e.received_at
                                      FROM leads l JOIN emails e ON e.id=l.email_id
                                      WHERE l.user_id=? AND l.deleted_at IS NULL AND e.received_at BETWEEN ? AND ?
                                      ORDER BY e.received_at DESC LIMIT 10");
            $qRecent->execute([$user['id'], $start, $end]);
        }
        $recent = $qRecent->fetchAll(\PDO::FETCH_ASSOC);

        // System health (respect client filter if provided)
        if (!empty($clientId)) {
            $qQueue = $pdo->prepare("SELECT COUNT(*) FROM emails e LEFT JOIN leads l ON l.email_id=e.id AND l.deleted_at IS NULL WHERE e.user_id=? AND e.client_id=? AND (l.id IS NULL OR l.status='unknown')");
            $qQueue->execute([$user['id'], $clientId]);
            $lastFetch = $pdo->prepare('SELECT MAX(received_at) FROM emails WHERE user_id=? AND client_id=?');
            $lastFetch->execute([$user['id'], $clientId]);
            $lastProcess = $pdo->prepare('SELECT MAX(updated_at) FROM leads WHERE user_id=? AND client_id=?');
            $lastProcess->execute([$user['id'], $clientId]);
        } else {
            $qQueue = $pdo->prepare("SELECT COUNT(*) FROM emails e LEFT JOIN leads l ON l.email_id=e.id AND l.deleted_at IS NULL WHERE e.user_id=? AND (l.id IS NULL OR l.status='unknown')");
            $qQueue->execute([$user['id']]);
            $lastFetch = $pdo->prepare('SELECT MAX(received_at) FROM emails WHERE user_id=?');
            $lastFetch->execute([$user['id']]);
            $lastProcess = $pdo->prepare('SELECT MAX(updated_at) FROM leads WHERE user_id=?');
            $lastProcess->execute([$user['id']]);
        }
        $queue = (int)$qQueue->fetchColumn();
        $lastFetchAt = (string)$lastFetch->fetchColumn();
        $lastProcessAt = (string)$lastProcess->fetchColumn();

        $clients = \App\Models\Client::listByUser($user['id']);
        $settings = \App\Models\Setting::getByUser($user['id']);
        // Genuine counts per client for selected range (for badges)
        $genuineCounts = \App\Models\Lead::genuineCountsByClient($user['id'], $start, $end);
        $genuineTotal = \App\Models\Lead::genuineTotal($user['id'], $start, $end);
        View::render('dashboard2/index', [
            'range'=>$quick,
            'start'=>$start,
            'end'=>$end,
            'clients'=>$clients,
            'activeClient'=>$clientCode,
            'filterMode'=>$settings['filter_mode'] ?? 'algorithmic',
            'strictGpt'=>(int)($settings['strict_gpt'] ?? 0),
            'genuineCounts'=>$genuineCounts ?? [],
            'genuineTotal'=>$genuineTotal ?? 0,
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
            $settings = \App\Models\Setting::getByUser($user['id']);
            $strict = (int)($settings['strict_gpt'] ?? 0) === 1;
            foreach ($list as $em) {
                if (empty($em['client_id'])) {
                    $assign = \App\Services\ClientAssigner::assign($user['id'], $em);
                    if ($assign['client_id']) {
                        \App\Models\Email::updateClient((int)$em['id'], (int)$assign['client_id']);
                        $em['client_id'] = (int)$assign['client_id'];
                    }
                }
                if ($client) {
                    $res = $client->classify($em);
                    if (!$strict && isset($res['mode']) && $res['mode']==='gpt' && isset($res['reason']) && str_starts_with((string)$res['reason'], 'OpenAI error')) {
                        $res = \App\Services\LeadScorer::compute($em);
                    }
                } else {
                    $res = \App\Services\LeadScorer::compute($em);
                }
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
        // Mark leads as seen after processing to clear "New" badges on next load
        $_SESSION['leads_seen_at'] = \App\Helpers::now();
        $return = trim($_POST['return'] ?? '');
        if ($return && str_starts_with($return, '/')) {
            Helpers::redirect($return);
        }
        Helpers::redirect('/');
    }

    public function fetchNowAsync(): void
    {
        Auth::requireLogin();
        if (!\App\Security\Csrf::validate()) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Bad CSRF']); return; }
        header('Content-Type: application/json');
        $user = Auth::user();
        $progressFile = BASE_PATH . '/storage/logs/fetch_progress_user_' . (int)$user['id'] . '.json';
        @file_put_contents($progressFile, json_encode(['started'=>date('c'),'done'=>false,'accounts_total'=>0,'accounts_done'=>0,'fetched_total'=>0]));
        $cmd = 'php ' . escapeshellarg(BASE_PATH . '/tools/fetch_now.php') . ' ' . (int)$user['id'] . ' > /dev/null 2>&1 &';
        @chdir(BASE_PATH);
        // best-effort spawn
        if (stripos(PHP_OS, 'WIN') === 0) {
            pclose(popen('start /B ' . $cmd, 'r'));
        } else {
            @exec($cmd);
        }
        echo json_encode(['ok'=>true]);
    }

    public function fetchProgressNow(): void
    {
        Auth::requireLogin();
        header('Content-Type: application/json');
        $user = Auth::user();
        $file = BASE_PATH . '/storage/logs/fetch_progress_user_' . (int)$user['id'] . '.json';
        if (!file_exists($file)) { echo json_encode(['done'=>true,'accounts_total'=>0,'accounts_done'=>0,'fetched_total'=>0]); return; }
        $json = @file_get_contents($file);
        echo $json !== false ? $json : json_encode(['done'=>false]);
    }

    public function backfillAssign(): void
    {
        Auth::requireLogin();
        if (!\App\Security\Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        @set_time_limit(300);
        $user = Auth::user();
        $pdo = DB::pdo();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM emails WHERE user_id = ?');
        $stmt->execute([$user['id']]);
        $total = (int)$stmt->fetchColumn();

        $batch = 500; $offset = 0; $assigned = 0; $cleared = 0; $unchanged = 0;
        while ($offset < $total) {
            $q = $pdo->prepare('SELECT * FROM emails WHERE user_id = ? ORDER BY id ASC LIMIT ? OFFSET ?');
            $q->bindValue(1, (int)$user['id'], \PDO::PARAM_INT);
            $q->bindValue(2, (int)$batch, \PDO::PARAM_INT);
            $q->bindValue(3, (int)$offset, \PDO::PARAM_INT);
            $q->execute();
            $rows = $q->fetchAll(\PDO::FETCH_ASSOC);
            if (!$rows) break;
            foreach ($rows as $em) {
                // Strong-only assignment via current ClientAssigner (thresholded)
                $assign = \App\Services\ClientAssigner::assign($user['id'], $em);
                $newClientId = $assign['client_id'] ?? null;
                $oldClientId = $em['client_id'] ?? null;
                if ($newClientId !== null && (int)$newClientId !== (int)$oldClientId) {
                    \App\Models\Email::updateClient((int)$em['id'], (int)$newClientId);
                    $assigned++;
                } elseif ($newClientId === null && $oldClientId !== null) {
                    \App\Models\Email::updateClient((int)$em['id'], null);
                    $cleared++;
                } else {
                    $unchanged++;
                }
            }
            $offset += $batch;
        }

        // Sync leads.client_id from emails.client_id
        $sync = $pdo->prepare('UPDATE leads l JOIN emails e ON e.id = l.email_id
                               SET l.client_id = e.client_id
                               WHERE l.user_id = ? AND e.user_id = ?');
        $sync->execute([$user['id'], $user['id']]);
        $synced = $sync->rowCount();

        $_SESSION['flash'] = 'Backfill complete. Assigned: ' . $assigned . ', Cleared: ' . $cleared . ', Unchanged: ' . $unchanged . ', Leads synced: ' . $synced . '.';
        $return = trim($_POST['return'] ?? '/dashboard2');
        if ($return && str_starts_with($return, '/')) { Helpers::redirect($return); }
        Helpers::redirect('/dashboard2');
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
        $_SESSION['leads_seen_at'] = \App\Helpers::now();
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

    public function reprocessGptExisting(): void
    {
        Auth::requireLogin();
        if (!\App\Security\Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        @set_time_limit(300);
        $user = Auth::user();
        $pdo = DB::pdo();
        $settings = \App\Models\Setting::getByUser($user['id']);
        $mode = $settings['filter_mode'] ?? 'algorithmic';
        $openaiKey = \App\Helpers::decryptSecret($settings['openai_api_key_enc'] ?? null, DB::env('APP_KEY',''));
        if ($mode !== 'gpt' || !$openaiKey) {
            $_SESSION['flash'] = 'GPT mode is not enabled or API key missing.';
            Helpers::redirect('/leads');
        }
        $client = new OpenAIClient($openaiKey);
        $strict = (int)($settings['strict_gpt'] ?? 0) === 1;

        $batch = max(50, (int)($_POST['batch'] ?? 200));
        $cap = max($batch, (int)($_POST['cap'] ?? 1000));
        $processed = 0; $offset = 0;

        while ($processed < $cap) {
            // Pick leads whose current mode is algorithmic, not deleted, not previously GPT, and no manual check present
            $sql = "SELECT l.id AS lead_id, l.*, e.*
                    FROM leads l
                    JOIN emails e ON e.id = l.email_id
                    WHERE l.user_id = :uid AND l.deleted_at IS NULL
                      AND (l.mode IS NULL OR l.mode = 'algorithmic')
                      AND NOT EXISTS (
                          SELECT 1 FROM lead_checks lc WHERE lc.lead_id = l.id AND lc.mode = 'manual'
                      )
                    ORDER BY e.received_at DESC
                    LIMIT :lim OFFSET :off";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':uid', (int)$user['id'], \PDO::PARAM_INT);
            $stmt->bindValue(':lim', (int)$batch, \PDO::PARAM_INT);
            $stmt->bindValue(':off', (int)$offset, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if (!$rows) break;

            foreach ($rows as $em) {
                $res = $client->classify($em);
                if (!$strict && isset($res['mode']) && $res['mode']==='gpt' && isset($res['reason']) && str_starts_with((string)$res['reason'], 'OpenAI error')) {
                    // fallback to algorithmic only if strict is off
                    $res = \App\Services\LeadScorer::compute($em);
                }
                $leadId = Lead::upsertFromEmail($em, $res);
                Lead::addCheck($leadId, $user['id'], $res['mode'], (int)$res['score'], (string)$res['reason']);
                $processed++;
                if ($processed >= $cap) break;
            }
            $offset += $batch;
        }
        $_SESSION['flash'] = 'Reprocessed ' . $processed . ' existing leads with GPT.';
        Helpers::redirect('/leads');
    }
}
