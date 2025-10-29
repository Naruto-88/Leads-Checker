<?php
namespace App\Controllers;

use App\Core\View;
use App\Security\Auth;
use App\Models\Email;
use App\Helpers;

class EmailsController
{
    public function __construct(private array $env) {}

    public function index(): void
    {
        Auth::requireLogin();
        $userId = Auth::user()['id'];
        $quick = $_GET['range'] ?? 'last_7';
        [$start, $end] = \App\Helpers::dateRangeQuick($quick);
        $search = trim($_GET['q'] ?? '');
        $clientCode = trim($_GET['client'] ?? '');
        $client = $clientCode ? \App\Models\Client::findByShortcode($userId, $clientCode) : null;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $settings = \App\Models\Setting::getByUser($userId);
        $limit = (int)($settings['page_size'] ?? 25);
        $offset = ($page-1)*$limit;
        $filters = [
            'start'=>$start,'end'=>$end,'search'=>$search,'limit'=>$limit,'offset'=>$offset,'client_id'=>$client['id'] ?? null
        ];
        $emails = Email::listByUser($userId, $filters);
        $total = Email::countByUser($userId, $filters);
        $clients = \App\Models\Client::listByUser($userId);
        $seenAtPrevEmails = $_SESSION['emails_seen_at'] ?? '1970-01-01 00:00:00';
        $_SESSION['emails_seen_at'] = \App\Helpers::now();
        $data = [
            'emails' => $emails,
            'range' => $quick,
            'q' => $search,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'clients' => $clients,
            'activeClient' => $clientCode,
            'seenAtPrevEmails' => $seenAtPrevEmails,
        ];
        $data['filterMode'] = $settings['filter_mode'] ?? 'algorithmic';
        $data['strictGpt'] = (int)($settings['strict_gpt'] ?? 0);
        $isPartial = isset($_GET['partial']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'])==='xmlhttprequest');
        if ($isPartial) {
            \App\Core\View::partial('emails/_rows', $data);
            return;
        }
        View::render('emails/index', $data);
    }

    public function processSelected(): void
    {
        Auth::requireLogin();
        if (!\App\Security\Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        // Left as simplified no-op in MVP
        Helpers::redirect('/emails');
    }
}
