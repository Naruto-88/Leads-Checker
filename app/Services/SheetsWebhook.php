<?php
namespace App\Services;

use App\Core\DB;

class SheetsWebhook
{
    public static function sendLeadFromEmail(array $email, array $result): void
    {
        $userId = (int)($email['user_id'] ?? 0);
        $settings = \App\Models\Setting::getByUser($userId);
        $url = trim((string)($settings['sheets_webhook_url'] ?? ''));
        if ($url === '') return;

        $clientShort = null;
        if (!empty($email['client_id'])) {
            try {
                $st = DB::pdo()->prepare('SELECT shortcode FROM clients WHERE id = ?');
                $st->execute([(int)$email['client_id']]);
                $clientShort = (string)$st->fetchColumn() ?: null;
            } catch (\Throwable $e) {}
        }

        $payload = [
            'type' => 'lead_upsert',
            'user_id' => $userId,
            'client_shortcode' => $clientShort,
            'from_email' => $email['from_email'] ?? '',
            'subject' => $email['subject'] ?? '',
            'received_at' => $email['received_at'] ?? '',
            'status' => $result['status'] ?? 'unknown',
            'score' => (int)($result['score'] ?? 0),
            'mode' => $result['mode'] ?? '',
        ];
        self::postJson($url, $payload, (string)($settings['sheets_webhook_secret'] ?? ''));
    }

    public static function sendLeadById(int $leadId): void
    {
        $sql = 'SELECT l.*, e.from_email, e.subject, e.received_at, c.shortcode AS client_shortcode
                FROM leads l
                JOIN emails e ON e.id = l.email_id
                LEFT JOIN clients c ON c.id = l.client_id
                WHERE l.id = :id';
        $st = DB::pdo()->prepare($sql); $st->execute([':id'=>$leadId]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$r) return;
        $settings = \App\Models\Setting::getByUser((int)$r['user_id']);
        $url = trim((string)($settings['sheets_webhook_url'] ?? ''));
        if ($url === '') return;
        $payload = [
            'type' => 'lead_update',
            'user_id' => (int)$r['user_id'],
            'client_shortcode' => $r['client_shortcode'] ?? null,
            'from_email' => $r['from_email'] ?? '',
            'subject' => $r['subject'] ?? '',
            'received_at' => $r['received_at'] ?? '',
            'status' => $r['status'] ?? 'unknown',
            'score' => (int)($r['score'] ?? 0),
            'mode' => $r['mode'] ?? '',
        ];
        self::postJson($url, $payload, (string)($settings['sheets_webhook_secret'] ?? ''));
    }

    private static function postJson(string $url, array $data, string $secret = ''): void
    {
        // Append secret as query param for Apps Script (headers are not available in doPost)
        if ($secret !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?') . '_sec=' . rawurlencode($secret);
        }
        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n" . ($secret !== '' ? ('X-Webhook-Secret: ' . $secret . "\r\n") : ''),
                'content' => json_encode($data),
                'timeout' => 5,
            ]
        ];
        try { @file_get_contents($url, false, stream_context_create($opts)); } catch (\Throwable $e) {}
    }
}
