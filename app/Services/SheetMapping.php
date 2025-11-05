<?php
namespace App\Services;

class SheetMapping
{
    public static function apply(?string $mappingJson, array $emailRow, ?array $outHeaders = null): ?array
    {
        if (!$mappingJson) return null;
        $cfg = json_decode($mappingJson, true);
        if (!is_array($cfg) || empty($cfg['headers']) || !is_array($cfg['headers'])) return null;
        $headers = array_values(array_map('strval', $cfg['headers']));
        $labelsMap = isset($cfg['labels']) && is_array($cfg['labels']) ? $cfg['labels'] : [];

        $plain = (string)($emailRow['body_plain'] ?? '');
        $html  = (string)($emailRow['body_html'] ?? '');
        $text  = LeadParser::htmlToText($plain, $html);

        $values = [];
        foreach ($headers as $h) {
            $syn = isset($labelsMap[$h]) && is_array($labelsMap[$h]) ? $labelsMap[$h] : [];
            $val = self::extractByLabels($text, $h, $syn);
            $values[] = $val;
        }
        if (is_array($outHeaders)) { $outHeaders = $headers; }
        return ['headers'=>$headers,'values'=>$values];
    }

    private static function extractByLabels(string $text, string $header, array $synonyms = []): string
    {
        $cands = array_values(array_filter(array_map('trim', array_merge([$header], $synonyms)), fn($s)=>$s!==''));
        foreach ($cands as $lab) {
            $rx = '/^\s*' . preg_quote($lab, '/') . '\s*:\s*(.+)$/im';
            if (preg_match($rx, $text, $m)) {
                return trim($m[1]);
            }
        }
        return '';
    }
}

