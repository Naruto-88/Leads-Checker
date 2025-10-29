<?php
namespace App\Controllers;

use App\Core\View;
use App\Security\Auth;
use App\Security\Csrf;
use App\Models\Setting;
use App\Models\EmailAccount;
use App\Helpers;
use App\Core\DB;

class SettingsController
{
    public function __construct(private array $env) {}
    public function trainLocalMl(): void
    {
        Auth::requireLogin();
        if (!\App\Security\Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        require_once BASE_PATH . '/tools/train_local_model.php';
        try {
            $res = \Tools\TrainLocalModel\run(Auth::user()['id']);
            if (!($res['ok'] ?? false)) {
                $_SESSION['flash'] = 'Training failed: ' . ($res['error'] ?? 'unknown');
            } else {
                $_SESSION['flash'] = 'Local model trained: ' . ($res['count'] ?? 0) . ' rows; Accuracy: ' . number_format((float)$res['accuracy']*100,2) . '%';
                $log = BASE_PATH . '/storage/logs/train_local_ml.txt';
                @file_put_contents($log, json_encode($res, JSON_PRETTY_PRINT));
            }
        } catch (\Throwable $e) {
            $_SESSION['flash'] = 'Training error: ' . $e->getMessage();
        }
        \App\Helpers::redirect('/settings?tab=filter');
    }

    
    public function compareLocalVsGpt(): void
    {
        Auth::requireLogin();
        if (!Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        $userId = Auth::user()['id'];
        $clientCode = trim($_POST['client'] ?? '');
        $range = $_POST['range'] ?? 'last_30';
        [$start, $end] = \App\Helpers::dateRangeQuick($range === 'all' ? 'last_365' : $range);
        if ($range === 'all') { $start = '1970-01-01 00:00:00'; $end = date('Y-m-d 23:59:59'); }

        $pdo = \App\Core\DB::pdo();
        $clientId = null;
        if ($clientCode !== '') {
            $c = \App\Models\Client::findByShortcode($userId, $clientCode);
            if ($c) { $clientId = (int)$c['id']; }
        }

        $sql = "SELECT l.id AS lead_id, l.client_id, COALESCE(c.shortcode,'') AS shortcode,
                       e.subject, e.body_plain, e.body_html,
                       (SELECT lc.status FROM lead_checks lc WHERE lc.lead_id=l.id AND lc.mode='gpt' ORDER BY lc.created_at DESC LIMIT 1) AS gpt_label
                FROM leads l
                JOIN emails e ON e.id = l.email_id
                LEFT JOIN clients c ON c.id = l.client_id
                WHERE l.user_id = :uid AND l.deleted_at IS NULL AND e.received_at BETWEEN :start AND :end";
        $params = [':uid'=>$userId, ':start'=>$start, ':end'=>$end];
        if ($clientId) { $sql .= ' AND l.client_id = :cid'; $params[':cid'] = $clientId; }
        $sql .= ' ORDER BY e.received_at DESC LIMIT 500';
        $st = $pdo->prepare($sql); $st->execute($params);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

        $tested = 0; $agree = 0; $by = [];
        foreach ($rows as $r) {
            $gpt = $r['gpt_label'] ?? null; if (!$gpt) continue;
            $res = \App\Services\LocalMLClassifier::classify($r);
            if ($res === null) { continue; }
            $lm = $res['status'] ?? 'unknown';
            $tested++;
            $key = (string)($r['shortcode'] ?? ''); if ($key === '') { $key = 'NONE'; }
            if (!isset($by[$key])) { $by[$key] = ['agree'=>0,'tested'=>0]; }
            $by[$key]['tested']++;
            if ($lm === $gpt) { $agree++; $by[$key]['agree']++; }
        }
        $acc = $tested>0 ? ($agree/$tested) : 0.0;
        $report = ['tested'=>$tested,'agree'=>$agree,'accuracy'=>$acc,'by_client'=>$by,'range'=>$range,'client'=>$clientCode,'start'=>$start,'end'=>$end];
        $file = BASE_PATH . '/storage/logs/compare_local_vs_gpt.json';
        @file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT));
        $_SESSION['flash'] = 'Compare Local vs GPT: ' . $agree . '/' . $tested . ' agree (Accuracy ' . number_format($acc*100,2) . '%).';
        Helpers::redirect('/settings?tab=filter');
    }public function index(): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $settings = Setting::getByUser($user['id']);
        $accounts = EmailAccount::listByUser($user['id']);
        $clients = \App\Models\Client::listByUser($user['id']);
        View::render('settings/index', [
            'settings' => $settings,
            'accounts' => $accounts,
            'clients' => $clients,
        ]);
    }

    public function saveFilter(): void
    {
        Auth::requireLogin();
        if (!Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        $mode = in_array($_POST['filter_mode'] ?? 'algorithmic', ['algorithmic','gpt','local_ml']) ? $_POST['filter_mode'] : 'algorithmic';
        $apiKey = trim($_POST['openai_api_key'] ?? '');
        $openaiEnc = $apiKey ? \App\Helpers::encryptSecret($apiKey, DB::env('APP_KEY','')) : null;
        $thrG = max(0, min(100, (int)($_POST['threshold_genuine'] ?? 70)));
        $thrS = max(0, min(100, (int)($_POST['threshold_spam'] ?? 40)));
        $strict = isset($_POST['strict_gpt']) ? 1 : 0;
        // store as comma-separated; allow multi-line too
        $pos = trim((string)($_POST['pos_keywords'] ?? ''));
        $neg = trim((string)($_POST['neg_keywords'] ?? ''));
        $pos = $pos !== '' ? preg_replace('/[\r\n]+/', ',', $pos) : null;
        $neg = $neg !== '' ? preg_replace('/[\r\n]+/', ',', $neg) : null;
        Setting::saveFilter(Auth::user()['id'], $mode, $openaiEnc, $thrG, $thrS, $pos, $neg, $strict);
        Helpers::redirect('/settings');
    }

    public function saveImap(): void
    {
        Auth::requireLogin();
        if (!Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        $data = [
            'label' => trim($_POST['label'] ?? ''),
            'client_id' => ($_POST['client_id'] ?? '') !== '' ? (int)$_POST['client_id'] : null,
            'imap_host' => trim($_POST['imap_host'] ?? ''),
            'imap_port' => (int)($_POST['imap_port'] ?? 993),
            'encryption' => in_array($_POST['encryption'] ?? 'ssl', ['ssl','tls','none']) ? $_POST['encryption'] : 'ssl',
            'username' => trim($_POST['username'] ?? ''),
            'password_enc' => \App\Helpers::encryptSecret($_POST['password'] ?? '', DB::env('APP_KEY','')),
            'folder' => trim($_POST['folder'] ?? 'INBOX'),
        ];
        if (!$data['label'] || !$data['imap_host'] || !$data['username']) {
            $_SESSION['flash'] = 'Missing required IMAP fields';
            Helpers::redirect('/settings');
        }
        EmailAccount::create(Auth::user()['id'], $data);
        Helpers::redirect('/settings?tab=imap');
    }

    public function deleteImap(): void
    {
        Auth::requireLogin();
        if (!Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        $id = (int)($_POST['id'] ?? 0);
        if ($id) { EmailAccount::delete(Auth::user()['id'], $id); }
        Helpers::redirect('/settings?tab=imap');
    }

    public function updateImap(): void
    {
        Auth::requireLogin();
        if (!Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { $_SESSION['flash'] = 'Missing account id'; Helpers::redirect('/settings?tab=imap'); }
        $data = [
            'label' => trim($_POST['label'] ?? ''),
            'client_id' => ($_POST['client_id'] ?? '') !== '' ? (int)$_POST['client_id'] : null,
            'imap_host' => trim($_POST['imap_host'] ?? ''),
            'imap_port' => (int)($_POST['imap_port'] ?? 993),
            'encryption' => in_array($_POST['encryption'] ?? 'ssl', ['ssl','tls','none']) ? $_POST['encryption'] : 'ssl',
            'username' => trim($_POST['username'] ?? ''),
            'folder' => trim($_POST['folder'] ?? 'INBOX'),
        ];
        $pwd = trim($_POST['password'] ?? '');
        if ($pwd !== '') {
            $data['password_enc'] = \App\Helpers::encryptSecret($pwd, DB::env('APP_KEY',''));
        }
        if (!$data['label'] || !$data['imap_host'] || !$data['username']) {
            $_SESSION['flash'] = 'Missing required IMAP fields';
            Helpers::redirect('/settings?tab=imap');
        }
        EmailAccount::update(Auth::user()['id'], $id, $data);
        Helpers::redirect('/settings?tab=imap');
    }

    public function saveGeneral(): void
    {
        Auth::requireLogin();
        if (!Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        $tz = trim($_POST['timezone'] ?? 'UTC');
        $ps = max(5, min(200, (int)($_POST['page_size'] ?? 25)));
        Setting::saveGeneral(Auth::user()['id'], $tz, $ps);
        Helpers::redirect('/settings');
    }

    public function saveClient(): void
    {
        Auth::requireLogin();
        if (!Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        $name = trim($_POST['name'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $short = strtoupper(trim($_POST['shortcode'] ?? ''));
        $emails = isset($_POST['contact_emails']) ? trim((string)$_POST['contact_emails']) : null;
        if (!$name || !$short) { $_SESSION['flash'] = 'Client name and shortcode required.'; Helpers::redirect('/settings'); }
        \App\Models\Client::create(Auth::user()['id'], $name, $website ?: null, $short);
        // Update contact emails if provided
        try {
            $pdo = \App\Core\DB::pdo();
            $id = (int)$pdo->lastInsertId();
            if ($id && $emails !== '') { \App\Models\Client::updateContactEmails(Auth::user()['id'], $id, $emails); }
        } catch (\Throwable $e) {}
        // Stay on Clients tab after adding
        Helpers::redirect('/settings?tab=clients');
    }

    public function deleteClient(): void
    {
        Auth::requireLogin();
        if (!Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        $id = (int)($_POST['id'] ?? 0);
        if ($id) { \App\Models\Client::delete(Auth::user()['id'], $id); }
        Helpers::redirect('/settings');
    }

    public function updateClient(): void
    {
        Auth::requireLogin();
        if (!Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $short = strtoupper(trim($_POST['shortcode'] ?? ''));
        $emails = trim((string)($_POST['contact_emails'] ?? ''));
        if (!$id || !$name || !$short) {
            $_SESSION['flash'] = 'Client id, name and shortcode are required.';
            Helpers::redirect('/settings?tab=clients');
        }
        \App\Models\Client::update(Auth::user()['id'], $id, $name, $website ?: null, $short);
        \App\Models\Client::updateContactEmails(Auth::user()['id'], $id, ($emails === '' ? null : $emails));
        Helpers::redirect('/settings?tab=clients');
    }

    public function importClients(): void
    {
        Auth::requireLogin();
        if (!Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        if (!isset($_FILES['clients_csv']) || $_FILES['clients_csv']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['flash'] = 'Please upload a CSV file.';
            Helpers::redirect('/settings?tab=clients');
        }
        $tmp = $_FILES['clients_csv']['tmp_name'];
        $rows = [];
        if (($h = fopen($tmp, 'r')) !== false) {
            $first = true; $headers = [];
            while (($data = fgetcsv($h)) !== false) {
                if ($first) {
                    $first = false;
                    // Detect header row if strings
                    $lower = array_map(fn($v)=>strtolower(trim((string)$v)), $data);
                    if (in_array('name', $lower) || in_array('shortcode', $lower)) {
                        $headers = $lower; // has header
                        continue;
                    } else {
                        // No header; fall through using fixed positions
                        $headers = ['name','website','shortcode'];
                    }
                }
                $row = [];
                foreach ($headers as $i=>$key) { $row[$key] = $data[$i] ?? null; }
                $name = trim((string)($row['name'] ?? ''));
                $website = trim((string)($row['website'] ?? '')) ?: null;
                $short = strtoupper(trim((string)($row['shortcode'] ?? '')));
                if ($name && $short) {
                    $rows[] = ['name'=>$name, 'website'=>$website, 'shortcode'=>$short];
                }
            }
            fclose($h);
        }
        if (!$rows) {
            $_SESSION['flash'] = 'No valid rows found in CSV.';
            Helpers::redirect('/settings?tab=clients');
        }
        \App\Models\Client::bulkImport(Auth::user()['id'], $rows);
        $_SESSION['flash'] = 'Imported ' . count($rows) . ' clients (existing shortcodes updated).';
        Helpers::redirect('/settings?tab=clients');
    }
}

