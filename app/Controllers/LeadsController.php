<?php
namespace App\Controllers;

use App\Core\View;
use App\Security\Auth;
use App\Models\Lead;
use App\Helpers;

class LeadsController
{
    public function __construct(private array $env) {}

    public function index(): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $quick = $_GET['range'] ?? 'last_7';
        [$start, $end] = \App\Helpers::dateRangeQuick($quick);
        $search = trim($_GET['q'] ?? '');
        $sort = $_GET['sort'] ?? 'desc';
        $clientCode = trim($_GET['client'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $client = $clientCode ? \App\Models\Client::findByShortcode($user['id'], $clientCode) : null;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $settings = \App\Models\Setting::getByUser($user['id']);
        $limit = (int)($settings['page_size'] ?? 25);
        $offset = ($page-1)*$limit;
        $filters = [
            'start'=>$start,'end'=>$end,'search'=>$search,'limit'=>$limit,'offset'=>$offset,'sort'=>$sort,'client_id'=>$client['id'] ?? null
        ];
        if (in_array($status, ['genuine','spam'])) { $filters['status'] = $status; }
        $leads = Lead::listByUser($user['id'], $filters);
        $total = Lead::countByUser($user['id'], $filters);
        $clients = \App\Models\Client::listByUser($user['id']);
        $seenAtPrev = $_SESSION['leads_seen_at'] ?? '1970-01-01 00:00:00';
        $_SESSION['leads_seen_at'] = \App\Helpers::now();
        $genuineCounts = \App\Models\Lead::genuineCountsByClient($user['id'], $start, $end);
        $genuineTotal = \App\Models\Lead::genuineTotal($user['id'], $start, $end);
        $data = [
            'leads' => $leads,
            'range' => $quick,
            'start' => $start,
            'end' => $end,
            'q' => $search,
            'sort' => $sort,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'clients' => $clients,
            'activeClient' => $clientCode,
            'status' => $status,
            'genuineCounts' => $genuineCounts,
            'genuineTotal' => $genuineTotal,
            'seenAtPrev' => $seenAtPrev,
        ];
        $isPartial = isset($_GET['partial']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'])==='xmlhttprequest');
        if ($isPartial) {
            \App\Core\View::partial('leads/_rows', $data);
            return;
        }
        $data['filterMode'] = $settings['filter_mode'] ?? 'algorithmic';
        $data['strictGpt'] = (int)($settings['strict_gpt'] ?? 0);
        View::render('leads/index', $data);
    }

    public function reprocess(): void
    {
        Auth::requireLogin();
        if (!\App\Security\Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        $ids = array_map('intval', $_POST['ids'] ?? []);
        if ($ids) {
            $settings = \App\Models\Setting::getByUser(Auth::user()['id']);
            $mode = $settings['filter_mode'] ?? 'algorithmic';
            $openaiKey = \App\Helpers::decryptSecret($settings['openai_api_key_enc'] ?? null, \App\Core\DB::env('APP_KEY',''));
            $client = ($mode === 'gpt' && $openaiKey) ? new \App\Services\OpenAIClient($openaiKey) : null;
            foreach ($ids as $leadId) {
                $lead = \App\Models\Lead::findWithEmail(Auth::user()['id'], $leadId);
                if (!$lead) continue;
                $res = $client ? $client->classify($lead) : \App\Services\LeadScorer::compute($lead);
                $id = \App\Models\Lead::upsertFromEmail($lead, $res);
                \App\Models\Lead::addCheck($id, Auth::user()['id'], $res['mode'], (int)$res['score'], (string)$res['reason']);
            }
        }
        Helpers::redirect('/leads');
    }

    public function delete(): void
    {
        Auth::requireLogin();
        if (!\App\Security\Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        $leadId = (int)($_POST['id'] ?? 0);
        if ($leadId) { Lead::delete(Auth::user()['id'], $leadId); }
        Helpers::redirect('/leads');
    }

    public function bulk(): void
    {
        Auth::requireLogin();
        if (!\App\Security\Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        $action = $_POST['action'] ?? '';
        $ids = array_map('intval', $_POST['ids'] ?? []);
        if ($action && $ids) {
            if ($action === 'mark_genuine' || $action === 'mark_spam') {
                $status = $action === 'mark_genuine' ? 'genuine' : 'spam';
                foreach ($ids as $id) { Lead::manualMark($id, Auth::user()['id'], $status); }
            } elseif ($action === 'reprocess') {
                // Delegate to reprocess logic
                $_POST['ids'] = $ids;
                $this->reprocess();
                return;
            }
        }
        Helpers::redirect('/leads');
    }

    public function export(): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $quick = $_GET['range'] ?? 'last_7';
        [$start, $end] = \App\Helpers::dateRangeQuick($quick);
        $search = trim($_GET['q'] ?? '');
        $status = $_GET['status'] ?? 'genuine';
        $clientCode = trim($_GET['client'] ?? '');
        $client = $clientCode ? \App\Models\Client::findByShortcode($user['id'], $clientCode) : null;
        $rows = Lead::listByUserForExport($user['id'], [
            'start'=>$start,'end'=>$end,'search'=>$search,'status'=>$status,'client_id'=>$client['id'] ?? null
        ]);
        header('Content-Type: text/csv');
        $fname = 'leads_' . ($clientCode ?: 'all') . '_' . $status . '.csv';
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        $out = fopen('php://output', 'w');

        // If a specific client is selected, try structured export via LeadParser
        $didStructured = false;
        if ($client) {
            $headers = \App\Services\LeadParser::headersFor($client['shortcode'] ?? '', $client['name'] ?? '');
            if ($headers && $headers[0] !== 'From') {
                fputcsv($out, $headers);
                foreach ($rows as $r) {
                    $parsed = \App\Services\LeadParser::parseFor($client['shortcode'] ?? '', $client['name'] ?? '', $r);
                    if ($parsed !== null) {
                        $rowOut = [];
                        foreach ($headers as $h) { $rowOut[] = $parsed[$h] ?? ''; }
                        fputcsv($out, $rowOut);
                    } else {
                        // Fallback to basic mapping
                        fputcsv($out, array_fill(0, count($headers), ''));
                    }
                }
                $didStructured = true;
            }
        }

        if (!$didStructured) {
            // Generic fallback (now includes full Message text)
            fputcsv($out, ['From','Subject','Snippet','Received','Status','Score','Mode','Message']);
            foreach ($rows as $r) {
                $plain = (string)($r['body_plain'] ?? '');
                $html  = (string)($r['body_html'] ?? '');
                $looksHtmlPlain = ($plain !== '' && preg_match('/<[^>]+>/', $plain));
                $src = $plain !== '' ? $plain : $html;
                if ($looksHtmlPlain || ($plain === '' && $html !== '')) {
                    $t = $src;
                    $t = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $t);
                    $t = preg_replace('/<\/(p|div|li|tr|h[1-6])\s*>/i', "\n", $t);
                    $t = preg_replace('/<\/(ul|ol|table|thead|tbody|tfoot)\s*>/i', "\n\n", $t);
                    $t = strip_tags($t);
                    $t = html_entity_decode($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $t = preg_replace("/\n{3,}/", "\n\n", $t);
                    $t = preg_replace('/[\t\x{00A0}]+/u', ' ', $t);
                    $snip = trim($t);
                } else {
                    $snip = trim($src);
                }
                // Full message (plain text) for export consumers that need the entire body
                $full = \App\Services\LeadParser::htmlToText($plain, $html);
                if (mb_strlen($full) > 5000) { $full = mb_substr($full, 0, 5000); }
                fputcsv($out, [
                    $r['from_email'],
                    $r['subject'],
                    mb_substr($snip, 0, 160),
                    $r['received_at'],
                    $r['status'],
                    $r['score'],
                    $r['mode'],
                    $full
                ]);
            }
        }
        fclose($out);
    }

    public function syncSheets(): void
    {
        Auth::requireLogin();
        if (!\App\Security\Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        $user = Auth::user();
        $quick = $_POST['range'] ?? 'last_7';
        [$start, $end] = \App\Helpers::dateRangeQuick($quick);
        $clientCode = trim($_POST['client'] ?? '');
        $status = trim($_POST['status'] ?? 'genuine');
        $client = $clientCode ? \App\Models\Client::findByShortcode($user['id'], $clientCode) : null;
        $rows = Lead::listByUserForExport($user['id'], [
            'start'=>$start,'end'=>$end,'status'=>$status,'client_id'=>$client['id'] ?? null
        ]);
        $count = 0;
        foreach ($rows as $r) {
            try { \App\Services\SheetsWebhook::sendLeadById((int)$r['id']); $count++; } catch (\Throwable $e) {}
        }
        $_SESSION['flash'] = 'Sent ' . $count . ' leads to Google Sheets webhook' . ($clientCode? (' for ' . $clientCode) : '') . '.';
        \App\Helpers::redirect($_POST['return'] ?? '/leads');
    }
}
