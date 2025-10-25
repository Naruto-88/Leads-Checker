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
        $system = "You are a strict lead-qualification assistant for marketing websites. Classify emails as 'genuine', 'spam', or 'unknown'. Provide a score 0–100 and a concise explanation. Rules: (1) If the content is not English -> spam with reason 'non_english'. (2) Positive client indicators (their brand/website/keywords) take precedence over generic negatives like 'http'/'https'. (3) If the email contains URLs that point to websites other than the client's domain(s), prefer 'unknown' so a human can review. Be conservative: only mark 'genuine' when it is clearly a real inquiry in English.";
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
            CURLOPT_TIMEOUT => 30,
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

