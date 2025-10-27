<?php
namespace App\Services;

use App\Core\DB;

class ClientAssigner
{
    public static function assign(int $userId, array $email): array
    {
        $pdo = DB::pdo();
        $clients = $pdo->prepare('SELECT id, name, website, shortcode, contact_emails FROM clients WHERE user_id = ?');
        $clients->execute([$userId]);
        $rows = $clients->fetchAll(\PDO::FETCH_ASSOC);
        if (!$rows) return ['client_id'=>null,'score'=>0,'reason'=>'no_clients'];

        $subject = strtolower($email['subject'] ?? '');
        $body = strtolower(($email['body_plain'] ?? '') . ' ' . strip_tags($email['body_html'] ?? ''));
        $to = strtolower($email['to_email'] ?? '');
        $from = strtolower($email['from_email'] ?? '');

        $best = ['client_id'=>null,'score'=>0,'reason'=>''];

        foreach ($rows as $c) {
            $score = 0; $reasons = [];
            $short = strtolower($c['shortcode'] ?? '');
            $name = strtolower($c['name'] ?? '');
            $site = strtolower($c['website'] ?? '');
            $host = $site ? parse_url((str_starts_with($site,'http')?$site:'https://'.$site), PHP_URL_HOST) : null;

            // Domain/website signals
            if ($host) {
                if ($subject && str_contains($subject, $host)) { $score += 8; $reasons[] = 'subject:host'; }
                if ($body && str_contains($body, $host)) { $score += 10; $reasons[] = 'body:host'; }
                if ($to && str_contains($to, $host)) { $score += 6; $reasons[] = 'to:host'; }
                $fromHost = substr(strrchr($from, '@') ?: '', 1);
                if ($fromHost && $host && $fromHost === $host) { $score += 4; $reasons[] = 'from:host_eq'; }
            }

            // Name / shortcode signals
            if ($short && ($subject && str_contains($subject, $short) || $body && str_contains($body, $short) || $to && str_contains($to, $short))) {
                $score += 7; $reasons[] = 'shortcode';
            }
            if ($name) {
                $nameWords = preg_split('/\s+/', $name);
                foreach ($nameWords as $w) {
                    if (strlen($w) >= 3 && (str_contains($subject, $w) || str_contains($body, $w))) {
                        $score += 3; $reasons[] = 'name:' . $w; break;
                    }
                }
            }

            if ($score > $best['score']) {
                $best = ['client_id'=>(int)$c['id'], 'score'=>$score, 'reason'=>implode(',', $reasons)];
            }
        }

        if ($best['score'] >= 10) {
            return $best;
        }
        return ['client_id'=>null,'score'=>$best['score'],'reason'=>'low_confidence'];
    }
}

            // Contact emails matching (exact from match or domain match)
            $emailsList = [];
            if (!empty($c['contact_emails'])) {
                $emailsList = array_values(array_filter(array_map('trim', preg_split('/[,\n\r]+/', (string)$c['contact_emails']))));
            }
            if ($from && $emailsList) {
                foreach ($emailsList as $eml) {
                    $eml = strtolower($eml);
                    if ($eml === '') continue;
                    if ($from === $eml) { $score += 20; $reasons[] = 'from:eq_contact'; break; }
                    // domain match
                    $dom = substr(strrchr($eml, '@') ?: '', 1);
                    if ($dom && str_contains($from, '@'.$dom)) { $score += 12; $reasons[] = 'from:dom_contact'; break; }
                }
            }
