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

    public function index(): void
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
        $mode = in_array($_POST['filter_mode'] ?? 'algorithmic', ['algorithmic','gpt']) ? $_POST['filter_mode'] : 'algorithmic';
        $apiKey = trim($_POST['openai_api_key'] ?? '');
        $openaiEnc = $apiKey ? \App\Helpers::encryptSecret($apiKey, DB::env('APP_KEY','')) : null;
        Setting::saveFilter(Auth::user()['id'], $mode, $openaiEnc);
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
        Helpers::redirect('/settings');
    }

    public function deleteImap(): void
    {
        Auth::requireLogin();
        if (!Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        $id = (int)($_POST['id'] ?? 0);
        if ($id) { EmailAccount::delete(Auth::user()['id'], $id); }
        Helpers::redirect('/settings');
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
        if (!$name || !$short) { $_SESSION['flash'] = 'Client name and shortcode required.'; Helpers::redirect('/settings'); }
        \App\Models\Client::create(Auth::user()['id'], $name, $website ?: null, $short);
        Helpers::redirect('/settings');
    }

    public function deleteClient(): void
    {
        Auth::requireLogin();
        if (!Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        $id = (int)($_POST['id'] ?? 0);
        if ($id) { \App\Models\Client::delete(Auth::user()['id'], $id); }
        Helpers::redirect('/settings');
    }
}
