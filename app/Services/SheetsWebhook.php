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

        $clientShort = null; $clientName = '';
        if (!empty($email['client_id'])) {
            try {
                $st = DB::pdo()->prepare('SELECT shortcode, name FROM clients WHERE id = ?');
                $st->execute([(int)$email['client_id']]);
                $rowC = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
                $clientShort = $rowC['shortcode'] ?? null;
                $clientName = $rowC['name'] ?? '';
            } catch (\Throwable $e) {}
        }

        $secret = (string)($settings['sheets_webhook_secret'] ?? '');

        // Try structured mapping using LeadParser (same as CSV export)
        try {
            $headers = \App\Services\LeadParser::headersFor((string)($clientShort ?? ''), (string)$clientName);
            if ($headers && $headers[0] !== 'From') {
                // Ensure we have bodies; if not, fetch from DB
                $plain = (string)($email['body_plain'] ?? '');
                $html  = (string)($email['body_html'] ?? '');
                if ($plain === '' && $html === '' && !empty($email['id'])) {
                    try {
                        $st = DB::pdo()->prepare('SELECT body_plain, body_html FROM emails WHERE id = ?');
                        $st->execute([(int)$email['id']]);
                        $e2 = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
                        $plain = (string)($e2['body_plain'] ?? '');
                        $html  = (string)($e2['body_html'] ?? '');
                    } catch (\Throwable $e) {}
                }
                $row = [
                    'from_email' => $email['from_email'] ?? '',
                    'subject' => $email['subject'] ?? '',
                    'received_at' => $email['received_at'] ?? '',
                    'body_plain' => $plain,
                    'body_html' => $html,
                ];
                $parsed = \App\Services\LeadParser::parseFor((string)($clientShort ?? ''), (string)$clientName, $row);
                if (is_array($parsed)) {
                    $values = [];
                    foreach ($headers as $h) { $values[] = $parsed[$h] ?? ''; }
                    $payload = [
                        'type' => 'lead_upsert_structured',
                        'user_id' => $userId,
                        'client_shortcode' => $clientShort,
                        'client_name' => $clientName,
                        'headers' => $headers,
                        'values' => $values,
                    ];
                    self::postJson($url, $payload, $secret);
                    return;
                }
            }
        } catch (\Throwable $e) { /* fallback below */ }

        // Fallback generic payload
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
        self::postJson($url, $payload, $secret);
    }

    public static function sendLeadById(int $leadId): void
    {
        $sql = 'SELECT l.*, e.from_email, e.subject, e.received_at, e.body_plain, e.body_html, c.shortcode AS client_shortcode, c.name AS client_name
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
        $secret = (string)($settings['sheets_webhook_secret'] ?? '');

        // Try structured first
        try {
            $headers = \App\Services\LeadParser::headersFor((string)($r['client_shortcode'] ?? ''), (string)($r['client_name'] ?? ''));
            if ($headers && $headers[0] !== 'From') {
                $parsed = \App\Services\LeadParser::parseFor((string)($r['client_shortcode'] ?? ''), (string)($r['client_name'] ?? ''), $r);
                if (is_array($parsed)) {
                    $values = [];
                    foreach ($headers as $h) { $values[] = $parsed[$h] ?? ''; }
                    $payload = [
                        'type' => 'lead_update_structured',
                        'user_id' => (int)$r['user_id'],
                        'client_shortcode' => $r['client_shortcode'] ?? null,
                        'client_name' => $r['client_name'] ?? '',
                        'headers' => $headers,
                        'values' => $values,
                    ];
                    self::postJson($url, $payload, $secret);
                    return;
                }
            }
        } catch (\Throwable $e) {}

        // Fallback generic
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
        self::postJson($url, $payload, $secret);
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
