<?php
namespace App\Services;

class LeadScorer
{
    public static function compute(array $email): array
    {
        $subject = strtolower($email['subject'] ?? '');
        $body = strtolower(($email['body_plain'] ?? '') . ' ' . strip_tags($email['body_html'] ?? ''));
        $from = strtolower($email['from_email'] ?? '');

        $score = 50; $reasons = [];

        // Load user-specific and client-specific settings if available
        $thrGenuine = 70; $thrSpam = 40; $userPos = []; $userNeg = [];
        $clientPos = []; $clientNeg = []; $clientThrG = null; $clientThrS = null; $clientDomainTokens = [];
        try {
            if (!empty($email['user_id'])) {
                $settings = \App\Models\Setting::getByUser((int)$email['user_id']);
                $thrGenuine = max(0, min(100, (int)($settings['filter_threshold_genuine'] ?? 70)));
                $thrSpam = max(0, min(100, (int)($settings['filter_threshold_spam'] ?? 40)));
                if (!empty($settings['filter_pos_keywords'])) {
                    $userPos = array_values(array_filter(array_map('trim', preg_split('/[,\n\r]+/', (string)$settings['filter_pos_keywords']))));
                }
                if (!empty($settings['filter_neg_keywords'])) {
                    $userNeg = array_values(array_filter(array_map('trim', preg_split('/[,\n\r]+/', (string)$settings['filter_neg_keywords']))));
                }
            }
            if (!empty($email['client_id'])) {
                $pdo = \App\Core\DB::pdo();
                $stmt = $pdo->prepare('SELECT name, website, filter_threshold_genuine, filter_threshold_spam, filter_pos_keywords, filter_neg_keywords FROM clients WHERE id = ?');
                $stmt->execute([(int)$email['client_id']]);
                if ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $clientThrG = $row['filter_threshold_genuine'] !== null ? (int)$row['filter_threshold_genuine'] : null;
                    $clientThrS = $row['filter_threshold_spam'] !== null ? (int)$row['filter_threshold_spam'] : null;
                    if (!empty($row['filter_pos_keywords'])) { $clientPos = array_values(array_filter(array_map('trim', preg_split('/[,\n\r]+/', (string)$row['filter_pos_keywords'])))); }
                    if (!empty($row['filter_neg_keywords'])) { $clientNeg = array_values(array_filter(array_map('trim', preg_split('/[,\n\r]+/', (string)$row['filter_neg_keywords'])))); }
                    if (!empty($row['website'])) {
                        $host = parse_url((string)$row['website'], PHP_URL_HOST) ?: (string)$row['website'];
                        $host = strtolower($host);
                        $host = preg_replace('/^www\./', '', $host);
                        $parts = preg_split('/[\.-]+/', (string)$host);
                        foreach ($parts as $p) { if (strlen($p) >= 3) { $clientDomainTokens[] = $p; } }
                    }
                }
                if ($clientThrG !== null) { $thrGenuine = $clientThrG; }
                if ($clientThrS !== null) { $thrSpam = $clientThrS; }
            }
        } catch (\Throwable $e) {
            // ignore and keep defaults
        }

        // Positive signals
        $phrases = array_unique(array_filter(array_merge([
            'quote','pricing','buy','book','appointment','call me','need service','request','project','inquiry','interested in','estimate','proposal','consultation','demo','trial','meeting','schedule','availability'
        ], $userPos, $clientPos, $clientDomainTokens)));
        foreach ($phrases as $p) {
            if (str_contains($subject, $p) || str_contains($body, $p)) {
                $score += 8; $reasons[] = "+phrase:$p"; break;
            }
        }
        if (preg_match('/\b\+?\d[\d\s().-]{7,}\b/', $body)) { $score += 10; $reasons[] = '+phone'; }
        if (substr_count($body, '?') >= 1 && substr_count($body, '?') <= 5) { $score += 6; $reasons[] = '+question'; }
        if (preg_match('/^[A-Z][a-z]+\s[A-Z][a-z]+$/', $email['from_name'] ?? '')) { $score += 4; $reasons[] = '+human_name'; }

        // If any user/client-defined negative keyword matches, force spam
        foreach ([$userNeg => 'user', $clientNeg => 'client'] as $list => $src) {}
        // Above empty loop is placeholder to allow PHP syntax highlighting in editors; real logic below
        $forcedSpam = false; $forcedReason = null;
        foreach ($userNeg as $kw) {
            $kw = trim((string)$kw); if ($kw==='') continue;
            $pattern = '/\b' . preg_quote($kw, '/') . '\b/i';
            if (preg_match($pattern, $subject) || preg_match($pattern, $body)) { $forcedSpam = true; $forcedReason = '-user_neg:' . $kw; break; }
        }
        if (!$forcedSpam) {
            foreach ($clientNeg as $kw) {
                $kw = trim((string)$kw); if ($kw==='') continue;
                $pattern = '/\b' . preg_quote($kw, '/') . '\b/i';
                if (preg_match($pattern, $subject) || preg_match($pattern, $body)) { $forcedSpam = true; $forcedReason = '-client_neg:' . $kw; break; }
            }
        }
        if ($forcedSpam) {
            return [
                'score' => 0,
                'reason' => $forcedReason,
                'status' => 'spam',
                'mode' => 'algorithmic',
            ];
        }

        // Negative signals (defaults + any remaining)
        $negKeywords = array_unique(array_filter(array_merge([
            'crypto','casino','guest post','backlinks','seo offers','viagra','loan approval','porn','betting','win big','adult','escort','blackhat','mlm'
        ], $userNeg, $clientNeg)));
        $negHits = 0; $hardHit = false;
        // Hard negatives (strong marketing/spam patterns)
        $hardNeg = [
            'traffic','increase traffic','drive traffic','website traffic','targeted traffic','organic traffic',
            'guaranteed ranking','seo service','domain authority','da','pa','link building','guest post','backlinks'
        ];
        foreach ($negKeywords as $k) {
            if ($k === '') continue;
            $pattern = '/\b' . preg_quote($k, '/') . '\b/i';
            if (preg_match($pattern, $subject) || preg_match($pattern, $body)) {
                $negHits++; $score -= 15; $reasons[] = "-keyword:$k";
            }
        }
        foreach ($hardNeg as $hk) {
            $pattern = '/\b' . preg_quote($hk, '/') . '\b/i';
            if (preg_match($pattern, $subject) || preg_match($pattern, $body)) {
                $hardHit = true; $score -= 20; $reasons[] = "-hard:$hk";
            }
        }
        // Heuristics typical to marketing/spam
        if (preg_match('/unsubscribe|opt\s?out/i', $body)) { $score -= 8; $reasons[] = '-unsubscribe'; }
        $links = preg_match_all('/https?:\/\//i', $body);
        if ($links >= 3) { $score -= 10; $reasons[] = '-many_links'; }
        if (preg_match('/bit\.ly|t\.co|goo\.gl|ow\.ly|tinyurl\./i', $body)) { $score -= 10; $reasons[] = '-shortener'; }
        if (strlen(strip_tags($email['body_html'] ?? '')) < 0.3 * strlen($email['body_html'] ?? '')) { $score -= 6; $reasons[] = '-high_html_ratio'; }
        if (preg_match('/\b(noreply|no-reply)@/i', $from)) { $score -= 8; $reasons[] = '-noreply'; }

        // Disposable domains blocklist (tiny list shipped)
        $disposable = ['mailinator.com','10minutemail.com','guerrillamail.com'];
        foreach ($disposable as $dom) {
            if (str_ends_with($from, '@'.$dom)) { $score -= 20; $reasons[] = '-disposable'; break; }
        }

        $score = max(0, min(100, $score));
        // Decide status; avoid 'unknown' by using tie-breakers
        if ($score >= $thrGenuine) {
            $status = 'genuine';
        } elseif ($score <= $thrSpam) {
            $status = 'spam';
        } else {
            // Tie-break: if hard negatives or multiple negatives hit, prefer spam
            if ($hardHit || $negHits >= 2) {
                $status = 'spam';
            } else {
                // else domain tokens and positive phrases push to genuine, otherwise spam
                $tiePositive = false;
                foreach (array_merge($phrases, $clientDomainTokens) as $p) {
                    if ($p && (str_contains($subject, $p) || str_contains($body, $p))) { $tiePositive = true; break; }
                }
                $status = $tiePositive ? 'genuine' : 'spam';
            }
        }

        return [
            'score' => (int)$score,
            'reason' => implode(', ', $reasons) ?: 'neutral',
            'status' => $status,
            'mode' => 'algorithmic',
        ];
    }
}

