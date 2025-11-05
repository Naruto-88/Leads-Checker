<?php
namespace App\Controllers;

use App\Core\View;
use App\Security\Auth;
use App\Helpers;
use App\Core\DB;

class ClientMessageController
{
    public function __construct(private array $env) {}

    public function compose(): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $pdo = DB::pdo();

        $range = $_GET['range'] ?? 'last_24';
        $clientCode = trim($_GET['client'] ?? '');
        $client = $clientCode ? \App\Models\Client::findByShortcode($user['id'], $clientCode) : null;
        [$start, $end] = \App\Helpers::dateRangeQuick($range);

        $clientId = $client['id'] ?? null;
        if ($clientId) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM leads l JOIN emails e ON e.id=l.email_id WHERE l.user_id=? AND l.deleted_at IS NULL AND l.status='genuine' AND e.received_at BETWEEN ? AND ? AND l.client_id = ?");
            $q->execute([$user['id'], $start, $end, $clientId]);
        } else {
            $q = $pdo->prepare("SELECT COUNT(*) FROM leads l JOIN emails e ON e.id=l.email_id WHERE l.user_id=? AND l.deleted_at IS NULL AND l.status='genuine' AND e.received_at BETWEEN ? AND ?");
            $q->execute([$user['id'], $start, $end]);
        }
        $genuineCount = (int)$q->fetchColumn();

        $clients = \App\Models\Client::listByUser($user['id']);
        $clientName = $client['name'] ?? 'All Clients';
        $subject = 'Leads Summary for ' . $clientName . ' - ' . date('M j, Y H:i', strtotime($start)) . ' to ' . date('M j, Y H:i', strtotime($end));

        $body = "Hi " . ($clientName !== 'All Clients' ? $clientName : 'there') . ",\n\n" .
                "We recorded $genuineCount genuine lead" . ($genuineCount===1?'':'s') . " in the selected period (" . $start . " → " . $end . ").\n" .
                "I've attached a CSV export of these leads for your review.\n\n" .
                "Could you confirm that you received all of these leads on your end and let us know the outcomes?\n\n" .
                "If anything is missing or looks off, reply and we’ll investigate.\n\n" .
                "Thanks,\n" . ($this->senderFor($client) ?: ($user['email'] ?? ''));

        $to = $this->recipientsFor($client);
        $mailto = $this->mailtoLink($to, $subject, $body);

        $data = [
            'range' => $range,
            'start' => $start,
            'end' => $end,
            'clients' => $clients,
            'activeClient' => $clientCode,
            'subject' => $subject,
            'body' => $body,
            'mailto' => $mailto,
            'to' => $to,
            'genuineCount' => $genuineCount,
        ];
        $isPartial = isset($_GET['partial']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'])==='xmlhttprequest');
        if ($isPartial) { \App\Core\View::partial('client_message/compose', $data); return; }
        View::render('client_message/compose', $data);
    }

    private function recipientsFor(?array $client): string
    {
        $raw = trim((string)($client['contact_emails'] ?? ''));
        if ($raw === '') return '';
        // Normalize comma/newline separated values
        $raw = str_replace(["\r\n","\r"], "\n", $raw);
        $parts = preg_split('/[\n,]+/', $raw) ?: [];
        $emails = array_values(array_filter(array_map('trim', $parts), fn($e)=>$e!==''));
        return implode(',', $emails);
    }

    private function senderFor(?array $client): ?string
    {
        $s = trim((string)($client['sender_email'] ?? ''));
        return $s !== '' ? $s : null;
    }

    private function mailtoLink(string $to, string $subject, string $body): string
    {
        $q = [];
        $q['subject'] = $subject;
        $q['body'] = $body . "\n\n(Please attach the CSV you downloaded from the app.)";
        $query = http_build_query(array_map('rawurlencode', $q));
        // We must not double-encode; build manually
        $query = 'subject=' . rawurlencode($subject) . '&body=' . rawurlencode($q['body']);
        return 'mailto:' . rawurlencode($to) . '?' . $query;
    }
}
