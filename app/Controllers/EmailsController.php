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
        $emails = Email::listByUser($userId, [
            'start'=>$start,'end'=>$end,'search'=>$search,'limit'=>$limit,'offset'=>$offset,'client_id'=>$client['id'] ?? null
        ]);
        $clients = \App\Models\Client::listByUser($userId);
        View::render('emails/index', [
            'emails' => $emails,
            'range' => $quick,
            'q' => $search,
            'page' => $page,
            'clients' => $clients,
            'activeClient' => $clientCode,
        ]);
    }

    public function processSelected(): void
    {
        Auth::requireLogin();
        if (!\App\Security\Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        // Left as simplified no-op in MVP
        Helpers::redirect('/emails');
    }
}
