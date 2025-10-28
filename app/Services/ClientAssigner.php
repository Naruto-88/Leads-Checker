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

        // Common freemail domains: do not use domain-only matches against these
        $freemail = ['gmail.com','yahoo.com','outlook.com','hotmail.com','live.com','msn.com','aol.com','icloud.com','proton.me','protonmail.com','ymail.com','me.com','pm.me'];

        foreach ($rows as $c) {
            $score = 0; $reasons = [];
            $short = strtolower($c['shortcode'] ?? '');
            $name = strtolower($c['name'] ?? '');
            $site = strtolower($c['website'] ?? '');
            $host = $site ? parse_url((str_starts_with($site,'http')?$site:'https://'.$site), PHP_URL_HOST) : null;
            if ($host) { $host = preg_replace('/^www\./','',$host); }
            $strong = false;

            // Domain/website signals
            if ($host) {
                $hpat = "#(?<![a-z0-9])(?:https?://)?(?:www\\.)?" . preg_quote($host, '#') . "(?=/|\\?|\\s|$|[\"'<>])#i";
                if ($subject && preg_match($hpat, $subject)) { $score += 8; $reasons[] = 'subject:host'; $strong = true; }
                if ($body && preg_match($hpat, $body)) { $score += 10; $reasons[] = 'body:host'; $strong = true; }
                // To: prefer exact domain equality
                $toHost = $to && str_contains($to, '@') ? preg_replace('/^www\./','', substr(strrchr($to, '@') ?: '', 1)) : '';
                if ($toHost && $toHost === $host) { $score += 6; $reasons[] = 'to:host_eq'; $strong = true; }
                $fromHost = substr(strrchr($from, '@') ?: '', 1);
                if ($fromHost) { $fromHost = preg_replace('/^www\./','',$fromHost); }
                if ($fromHost && $host && $fromHost === $host) { $score += 12; $reasons[] = 'from:host_eq'; $strong = true; }
            }

            // Name / shortcode signals
            if ($short && ($subject && str_contains($subject, $short) || $body && str_contains($body, $short) || $to && str_contains($to, $short))) {
                $score += 5; $reasons[] = 'shortcode';
            }
            if ($name) {
                $nameWords = preg_split('/\s+/', $name);
                foreach ($nameWords as $w) {
                    if (strlen($w) >= 4 && (str_contains($subject, $w) || str_contains($body, $w))) {
                        $score += 2; $reasons[] = 'name:' . $w; break;
                    }
                }
            }

            // Contact emails matching (exact from match is strong; domain match only if not freemail and equals domain)
            $emailsList = [];
            if (!empty($c['contact_emails'])) {
                $emailsList = array_values(array_filter(array_map('trim', preg_split('/[,\n\r]+/', (string)$c['contact_emails']))));
            }
            if ($from && $emailsList) {
                foreach ($emailsList as $eml) {
                    $eml = strtolower($eml);
                    if ($eml === '') continue;
                    if ($from === $eml) { $score += 30; $reasons[] = 'from:eq_contact'; $strong = true; break; }
                    // domain match (only if domain equals and not a freemail domain)
                    $dom = substr(strrchr($eml, '@') ?: '', 1);
                    $fromDom = substr(strrchr($from, '@') ?: '', 1);
                    if ($dom) { $dom = preg_replace('/^www\./','',$dom); }
                    if ($fromDom) { $fromDom = preg_replace('/^www\./','',$fromDom); }
                    if ($dom && $fromDom && $fromDom === $dom && !in_array($dom, $freemail, true)) {
                        $score += 15; $reasons[] = 'from:dom_contact'; $strong = true; break;
                    }
                }
            }

            // If we only have weak signals (name/shortcode) and no strong domain/email/host match, cap the score
            if (!$strong) {
                if ($score > 9) { $score = 9; }
            }

            if ($score > $best['score']) {
                $best = ['client_id'=>(int)$c['id'], 'score'=>$score, 'reason'=>implode(',', $reasons)];
            }
        }

        if ($best['score'] >= 12) {
            return $best;
        }
        return ['client_id'=>null,'score'=>$best['score'],'reason'=>'low_confidence'];
    }
}
