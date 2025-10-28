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
            // BHR classic move form
            return ['Name','Contact Number','Email','Preferred Time','From Suburb','To Suburb','About Move','Bedrooms','Move Date','Comments'];
        }
        if ($code === 'EAH' || stripos($clientName, 'extend a home') !== false || stripos($clientName, 'extend-a-home') !== false) {
            // Extend A Home form (EAH)
            return ['Council Area','First Name','Last Name','Street Address','Suburb','Postcode','Mobile','Home Contact Number','Email','Type of Renovation Work','How did you hear about us'];
        }
        if ($code === 'AA' || stripos($clientName, 'Australian Account') !== false) {
            return ['Name','Email','Phone Number','Message'];
        }
        if ($code === 'DB' || stripos($clientName, 'dream boat') !== false || stripos($clientName, 'dream boats') !== false) {
            return ['First Name','Email','Mobile','Guests','Preferred Date','Message'];
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
        if ($code === 'EAH' || stripos($clientName, 'extend a home') !== false || stripos($clientName, 'extend-a-home') !== false) {
            $out = [
                'Council Area' => self::matchFirst($text, '/^\s*Council\s*Area\s*:\s*(.+)$/im'),
                'First Name' => self::matchFirst($text, '/^\s*First\s*Name\s*:\s*(.+)$/im'),
                'Last Name' => self::matchFirst($text, '/^\s*Last\s*Name\s*:\s*(.+)$/im'),
                'Street Address' => self::matchFirst($text, '/^\s*Street\s*Address\s*:\s*(.+)$/im'),
                'Suburb' => self::matchFirst($text, '/^\s*Suburb\s*:\s*(.+)$/im'),
                'Postcode' => self::matchFirst($text, '/^\s*Postcode\s*:\s*(.+)$/im'),
                'Mobile' => self::matchFirst($text, '/^\s*Mobile\s*:\s*([\d\s+().-]{6,})$/im'),
                'Home Contact Number' => self::matchFirst($text, '/^\s*Home\s*Contact\s*Number\s*:\s*([\d\s+().-]{6,})$/im'),
                'Email' => self::matchFirst($text, '/^\s*Email\s*:\s*([^\s]+)\s*$/im'),
                'Type of Renovation Work' => self::matchFirst($text, '/^\s*Type\s*of\s*Renovation\s*Work\s*:\s*(.+)$/im'),
                'How did you hear about us' => self::matchFirst($text, '/^\s*How\s*did\s*you\s*hear\s*about\s*us\s*:\s*(.+)$/im'),
            ];
            return $out;
        }
        if ($code === 'AA' || stripos($clientName, 'Australian Account') !== false) {
            // Typical AA contact form fields can appear as: Name/From, Email, Phone or Phone Number, Message or Message Body
            $name = self::matchFirst($text, '/^\s*(Name|From)\s*:\s*(.+)$/im', 2);
            // Prefer explicit Email:, else first email in the text, else fallback to envelope from_email
            $email = self::matchFirst($text, '/^\s*Email\s*:\s*([^\s]+)\s*$/im');
            if ($email === '' && preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $text, $mE)) { $email = $mE[0]; }
            if ($email === '' && !empty($row['from_email'])) { $email = (string)$row['from_email']; }
            $phone = self::matchFirst($text, '/^\s*(Phone\s*Number|Phone|Contact)\s*:\s*([\d\s+().-]{6,})$/im', 2);
            $msg = self::extractMessageAfterLabel($text, 'Message');
            if ($msg === '') { $msg = self::extractMessageAfterLabel($text, 'Message Body'); }
            if ($msg === '') { $msg = self::extractTailAfterKnownLabels($text); }
            $out = [
                'Name' => $name,
                'Email' => $email,
                'Phone Number' => $phone,
                'Message' => $msg,
            ];
            return $out;
        }
        if ($code === 'DB' || stripos($clientName, 'dream boat') !== false || stripos($clientName, 'dream boats') !== false) {
            // Dream Boats contact form
            $first = self::matchFirst($text, '/^\s*(First\s*Name|Name)\s*:\s*(.+)$/im', 2);
            $email = self::matchFirst($text, '/^\s*Email\s*:\s*([^\s]+)\s*$/im');
            if ($email === '' && preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $text, $mE)) { $email = $mE[0]; }
            if ($email === '' && !empty($row['from_email'])) { $email = (string)$row['from_email']; }
            $mobile = self::matchFirst($text, '/^\s*(Mobile|Phone|Phone\s*Number|Contact)\s*:\s*([\d\s+().-]{6,})$/im', 2);
            $guests = self::matchFirst($text, '/^\s*(How\s*many\s*guests|Guests)\s*:\s*(.+)$/im', 2);
            $date = self::matchFirst($text, '/^\s*(Preferred\s*Date|Date)\s*:\s*(.+)$/im', 2);
            // For Message, only include the "Enquiry and questions" block (forms often use header lines without colons)
            $message = self::extractHeaderBlock($text, ['Enquiry and questions','Enquiry & questions']);
            return [
                'First Name' => $first,
                'Email' => $email,
                'Mobile' => $mobile,
                'Guests' => $guests,
                'Preferred Date' => $date,
                'Message' => $message,
            ];
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

    private static function extractTailAfterKnownLabels(string $text): string
    {
        // Find the last occurrence of common labels, then take everything after
        $labels = [
            '/^\s*(Name|From)\s*:\s*.+$/im',
            '/^\s*Email\s*:\s*.+$/im',
            '/^\s*(Phone\s*Number|Phone|Contact)\s*:\s*.+$/im',
            '/^\s*Subject\s*:\s*.+$/im',
        ];
        $last = 0;
        foreach ($labels as $rx) {
            if (preg_match_all($rx, $text, $m, PREG_OFFSET_CAPTURE)) {
                $hit = end($m[0]);
                $pos = $hit[1] + strlen($hit[0]);
                if ($pos > $last) { $last = $pos; }
            }
        }
        $tail = $last > 0 ? substr($text, $last) : $text;
        // Strip labeled lines and footer
        $lines = preg_split('/\r?\n/', (string)$tail);
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

    private static function extractHeaderBlock(string $text, array $headers): string
    {
        // Matches a header as a standalone line (no colon), then captures subsequent lines until another known header is found
        $pos = -1; $matchLen = 0;
        foreach ($headers as $h) {
            $rx = '/^\s*' . preg_quote($h, '/') . '\s*:?\s*$/im';
            if (preg_match($rx, $text, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1]; $matchLen = strlen($m[0][0]);
                break;
            }
        }
        if ($pos < 0) { return ''; }
        $tail = substr($text, $pos + $matchLen);
        // Stop when hitting the next header/label line
        $stopHeaders = [
            'First Name','Name','Email','Mobile','Phone','Phone Number','Contact','How many guests','Guests','Preferred Date','Date','Subject'
        ];
        $lines = preg_split('/\r?\n/', (string)$tail);
        $out = [];
        foreach ($lines as $ln) {
            $s = trim($ln);
            if ($s === '') continue;
            foreach ($stopHeaders as $sh) {
                if (preg_match('/^\s*' . preg_quote($sh, '/') . '\s*:?\s*$/i', $s)) {
                    $s = '';
                }
            }
            if ($s === '') break;
            if (stripos($s, 'This email was sent from a contact form') !== false) break;
            // Skip lines that are pure labels
            if (preg_match('/^\s*[A-Za-z][A-Za-z \t]+:\s*.*$/', $s)) continue;
            $out[] = $s;
        }
        $msg = trim(implode(" \n", $out));
        if (mb_strlen($msg) > 5000) { $msg = mb_substr($msg, 0, 5000); }
        return $msg;
    }
}
