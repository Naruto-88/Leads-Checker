<?php
namespace App\Services;

class OpenAIClient
{
    private string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    public function classify(array $email): array
    {
        // Build context: allowed client domains and positive keywords
        $allowedDomains = [];
        $posKeywords = [];
        try {
            $uid = (int)($email['user_id'] ?? 0);
            if ($uid) {
                $settings = \App\Models\Setting::getByUser($uid);
                if (!empty($settings['filter_pos_keywords'])) {
                    $posKeywords = array_values(array_filter(array_map('trim', preg_split('/[,\n\r]+/', (string)$settings['filter_pos_keywords']))));
                }
            }
            if (!empty($email['client_id'])) {
                $pdo = \App\Core\DB::pdo();
                $stmt = $pdo->prepare('SELECT name, shortcode, website, filter_pos_keywords FROM clients WHERE id = ? LIMIT 1');
                $stmt->execute([(int)$email['client_id']]);
                if ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $host = parse_url((string)$row['website'], PHP_URL_HOST) ?: (string)$row['website'];
                    $host = strtolower($host);
                    $host = preg_replace('/^www\./', '', $host);
                    if ($host) { $allowedDomains[] = $host; }
                    if (!empty($row['filter_pos_keywords'])) {
                        $posKeywords = array_merge($posKeywords, array_values(array_filter(array_map('trim', preg_split('/[,\n\r]+/', (string)$row['filter_pos_keywords'])))));
                    }
                }
            }
            foreach ($posKeywords as $kw) {
                if (preg_match('/([a-z0-9][a-z0-9.-]+\.[a-z]{2,})/i', $kw, $m)) {
                    $h = strtolower($m[1]); $h = preg_replace('/^www\./','',$h);
                    $allowedDomains[] = $h;
                }
            }
            $allowedDomains = array_values(array_unique(array_filter($allowedDomains)));
        } catch (\Throwable $e) {}

        $allowedStr = $allowedDomains ? ('Allowed client domains: ' . implode(', ', $allowedDomains) . '.') : '';
        $posStr = $posKeywords ? (' Positive keywords: ' . implode(', ', array_slice($posKeywords, 0, 20)) . '.') : '';

        $system = "You are a strict lead-qualification assistant for marketing websites. Return JSON with fields: status ∈ {genuine, spam, unknown}, score 0-100, reason.\nRules:\n1) If content is not English ⇒ spam (reason=non_english).\n2) Client positives (brand/website/keywords) take precedence over generic negatives like http/https/www.\n3) If URLs appear and any are NOT under the allowed client domains, prefer unknown only when there is no clear inquiry intent; do NOT mark unknown solely because links exist.\n4) Mark genuine only when there is clear inquiry intent (request/quote/booking/timeline/budget/phone/etc.).\n5) Obvious marketing/backlink/SEO offers are spam, not unknown.\n" . $allowedStr . $posStr;

        $user = "From: {$email['from_email']}\nSubject: {$email['subject']}\nBody:\n" . substr(($email['body_plain'] ?? strip_tags($email['body_html'] ?? '')), 0, 2000);

        $payload = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'response_format' => [ 'type' => 'json_object' ],
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($err || $code >= 300) {
            return [
                'score' => 50,
                'reason' => 'OpenAI error: ' . ($err ?: ('HTTP ' . $code)),
                'status' => 'unknown',
                'mode' => 'gpt',
            ];
        }
        $data = json_decode($resp, true);
        $content = $data['choices'][0]['message']['content'] ?? '{}';
        $parsed = json_decode($content, true);
        $status = $parsed['status'] ?? 'unknown';
        $score = (int)($parsed['score'] ?? 50);
        $reason = $parsed['reason'] ?? 'n/a';
        return [
            'score' => max(0, min(100, $score)),
            'reason' => $reason,
            'status' => in_array($status, ['genuine','spam','unknown']) ? $status : 'unknown',
            'mode' => 'gpt',
        ];
    }
}
