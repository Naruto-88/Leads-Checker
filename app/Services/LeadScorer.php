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

        // Load user-specific settings if available
        $thrGenuine = 70; $thrSpam = 40; $userPos = []; $userNeg = [];
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
        } catch (\Throwable $e) {
            // ignore and keep defaults
        }

        // Positive signals
        $phrases = array_unique(array_filter(array_merge([
            'quote','pricing','buy','book','appointment','call me','need service','request','project','inquiry','interested in','estimate','proposal','consultation','demo','trial','meeting','schedule','availability'
        ], $userPos)));
        foreach ($phrases as $p) {
            if (str_contains($subject, $p) || str_contains($body, $p)) {
                $score += 8; $reasons[] = "+phrase:$p"; break;
            }
        }
        if (preg_match('/\b\+?\d[\d\s().-]{7,}\b/', $body)) { $score += 10; $reasons[] = '+phone'; }
        if (substr_count($body, '?') >= 1 && substr_count($body, '?') <= 5) { $score += 6; $reasons[] = '+question'; }
        if (preg_match('/^[A-Z][a-z]+\s[A-Z][a-z]+$/', $email['from_name'] ?? '')) { $score += 4; $reasons[] = '+human_name'; }

        // Negative signals
        $negKeywords = array_unique(array_filter(array_merge([
            'crypto','casino','guest post','backlinks','seo offers','viagra','loan approval','porn','betting','win big','adult','escort','blackhat','mlm'
        ], $userNeg)));
        foreach ($negKeywords as $k) {
            if (str_contains($subject, $k) || str_contains($body, $k)) { $score -= 15; $reasons[] = "-keyword:$k"; }
        }
        $links = preg_match_all('/https?:\/\//i', $body);
        if ($links >= 3) { $score -= 10; $reasons[] = '-many_links'; }
        if (preg_match('/bit\.ly|t\.co|goo\.gl|ow\.ly|tinyurl\./', $body)) { $score -= 10; $reasons[] = '-shortener'; }
        if (strlen(strip_tags($email['body_html'] ?? '')) < 0.3 * strlen($email['body_html'] ?? '')) { $score -= 6; $reasons[] = '-high_html_ratio'; }
        if (preg_match('/\b(noreply|no-reply)@/i', $from)) { $score -= 8; $reasons[] = '-noreply'; }

        // Disposable domains blocklist (tiny list shipped)
        $disposable = ['mailinator.com','10minutemail.com','guerrillamail.com'];
        foreach ($disposable as $dom) {
            if (str_ends_with($from, '@'.$dom)) { $score -= 20; $reasons[] = '-disposable'; break; }
        }

        $score = max(0, min(100, $score));
        $status = 'unknown';
        if ($score >= $thrGenuine) $status = 'genuine';
        elseif ($score <= $thrSpam) $status = 'spam';

        return [
            'score' => (int)$score,
            'reason' => implode(', ', $reasons) ?: 'neutral',
            'status' => $status,
            'mode' => 'algorithmic',
        ];
    }
}

