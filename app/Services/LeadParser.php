<?php
namespace App\Services;

class LeadParser
{
    public static function htmlToText(string $plain, string $html): string
    {
        $looksHtmlPlain = ($plain !== '' && preg_match('/<[^>]+>/', $plain));
        $src = $plain !== '' ? $plain : $html;
        if ($looksHtmlPlain || ($plain === '' && $html !== '')) {
            $t = $src;
            $t = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $t);
            $t = preg_replace('/<\/(p|div|li|tr|h[1-6])\s*>/i', "\n", $t);
            $t = preg_replace('/<\/(ul|ol|table|thead|tbody|tfoot)\s*>/i', "\n\n", $t);
            $t = strip_tags($t);
            $t = html_entity_decode($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $t = preg_replace("/\n{3,}/", "\n\n", $t);
            $t = preg_replace('/[\t\x{00A0}]+/u', ' ', $t);
            return trim($t);
        }
        return trim($src);
    }

    public static function headersFor(string $clientShortcode, string $clientName = ''): array
    {
        $code = strtoupper(trim($clientShortcode));
        if ($code === 'BHR' || stripos($clientName, 'Better Home Removals') !== false) {
            return ['Name','Contact Number','Email','Preferred Time','From Suburb','To Suburb','About Move','Bedrooms','Move Date','Comments'];
        }
        if ($code === 'AA' || stripos($clientName, 'Australian Account') !== false) {
            return ['Name','Email','Phone Number','Message'];
        }
        // Default/generic
        return ['From','Subject','Snippet','Received','Status','Score','Mode'];
    }

    public static function parseFor(string $clientShortcode, string $clientName, array $row): ?array
    {
        $code = strtoupper(trim($clientShortcode));
        $plain = (string)($row['body_plain'] ?? '');
        $html  = (string)($row['body_html'] ?? '');
        $text = self::htmlToText($plain, $html);

        if ($code === 'BHR' || stripos($clientName, 'Better Home Removals') !== false) {
            $out = [
                'Name' => self::matchFirst($text, '/^\s*Name\s*:\s*(.+)$/im'),
                'Contact Number' => self::matchFirst($text, '/^\s*(Contact\s*number|Phone|Phone Number|Contact)\s*:\s*([\d\s+().-]{6,})$/im', 2),
                'Email' => self::matchFirst($text, '/^\s*Email\s*:\s*([^\s]+)\s*$/im'),
                'Preferred Time' => self::matchFirst($text, '/^\s*Preferred\s*time\s*to\s*contact\s*you\s*:\s*(.+)$/im'),
                'From Suburb' => self::matchFirst($text, '/^\s*From\s*Suburb\s*:\s*(.+)$/im'),
                'To Suburb' => self::matchFirst($text, '/^\s*To\s*Suburb\s*:\s*(.+)$/im'),
                'About Move' => self::matchFirst($text, '/^\s*About\s*your\s*move\s*:\s*(.+)$/im'),
                'Bedrooms' => self::matchFirst($text, '/^\s*Number\s*of\s*bedrooms\s*:\s*(.+)$/im'),
                'Move Date' => self::matchFirst($text, '/^\s*Date\s*of\s*your\s*move\s*\(.*\)\s*:\s*(.+)$/im'),
                'Comments' => self::extractCommentsBHR($text),
            ];
            return $out;
        }
        if ($code === 'AA' || stripos($clientName, 'Australian Account') !== false) {
            // Typical AA contact form fields: Name, Email, Phone Number, Message
            $out = [
                'Name' => self::matchFirst($text, '/^\s*Name\s*:\s*(.+)$/im'),
                'Email' => self::matchFirst($text, '/^\s*Email\s*:\s*([^\s]+)\s*$/im'),
                'Phone Number' => self::matchFirst($text, '/^\s*(Phone\s*Number|Phone|Contact)\s*:\s*([\d\s+().-]{6,})$/im', 2),
                'Message' => self::extractMessageAfterLabel($text, 'Message'),
            ];
            return $out;
        }
        return null; // unknown client -> use generic export
    }

    private static function matchFirst(string $text, string $regex, int $group = 1): string
    {
        if (preg_match($regex, $text, $m)) {
            return trim((string)$m[$group] ?? '');
        }
        return '';
    }

    private static function extractComments(string $text): string
    {
        // Comments/messages often appear after the known labeled fields; as a simple heuristic, take lines that do not match Label: Value.
        $lines = preg_split('/\r?\n/', $text);
        $outLines = [];
        foreach ($lines as $ln) {
            if (preg_match('/^\s*[A-Za-z][A-Za-z \t]+:\s+.+$/', $ln)) { continue; }
            $ln = trim($ln);
            if ($ln !== '') { $outLines[] = $ln; }
        }
        // Avoid returning the entire message if everything looked like labels; cap length
        $comment = trim(implode(" \n", $outLines));
        if (mb_strlen($comment) > 5000) { $comment = mb_substr($comment, 0, 5000); }
        return $comment;
    }

    private static function extractCommentsBHR(string $text): string
    {
        // For BHR forms, free-text usually follows the Move Date line
        $pos = null;
        if (preg_match('/^\s*Date\s*of\s*your\s*move.*?:\s*.+$/im', $text, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
        } elseif (preg_match('/^\s*Number\s*of\s*bedrooms\s*:\s*.+$/im', $text, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
        } elseif (preg_match('/^\s*About\s*your\s*move\s*:\s*.+$/im', $text, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
        }
        $tail = $pos !== null ? substr($text, $pos) : $text;
        // Remove common footer and labeled lines
        $lines = preg_split('/\r?\n/', (string)$tail);
        $outLines = [];
        foreach ($lines as $ln) {
            $s = trim($ln);
            if ($s === '') continue;
            if (stripos($s, 'This email was sent from a contact form') !== false) break;
            if (preg_match('/^\s*[A-Za-z][A-Za-z \t]+:\s+.+$/', $s)) continue;
            // Skip tracking/single-pixel artifacts
            if (preg_match('/^<img\b/i', $s)) continue;
            $outLines[] = $s;
        }
        $comment = trim(implode(" \n", $outLines));
        if ($comment === '') { $comment = self::extractComments($text); }
        if (mb_strlen($comment) > 5000) { $comment = mb_substr($comment, 0, 5000); }
        return $comment;
    }

    private static function extractMessageAfterLabel(string $text, string $label): string
    {
        // Extract everything after a specific label (e.g., Message:)
        $pattern = '/^\s*' . preg_quote($label, '/') . '\s*:\s*(.*)$/im';
        if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
            $startOffset = $m[0][1];
            $startLen = strlen($m[0][0]);
            $tail = substr($text, $startOffset + $startLen);
            $tail = trim($m[1][0] . "\n" . $tail);
            // Remove any trailing labeled lines or footers
            $lines = preg_split('/\r?\n/', $tail);
            $out = [];
            foreach ($lines as $ln) {
                $s = trim($ln);
                if ($s === '') continue;
                if (preg_match('/^\s*[A-Za-z][A-Za-z \t]+:\s+.+$/', $s)) continue;
                if (stripos($s, 'This email was sent from a contact form') !== false) break;
                $out[] = $s;
            }
            $msg = trim(implode(" \n", $out));
            if (mb_strlen($msg) > 5000) { $msg = mb_substr($msg, 0, 5000); }
            return $msg;
        }
        // Fallback to generic comments extractor
        return self::extractComments($text);
    }
}
