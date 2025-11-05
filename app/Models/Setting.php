<?php
namespace App\Models;

use App\Core\DB;
use PDO;

class Setting
{
    private static function ensureWebhookColumns(): void
    {
        try { DB::pdo()->exec("ALTER TABLE settings ADD COLUMN sheets_webhook_url TEXT NULL"); } catch (\Throwable $e) {}
        try { DB::pdo()->exec("ALTER TABLE settings ADD COLUMN sheets_webhook_secret VARCHAR(255) NULL"); } catch (\Throwable $e) {}
    }
    public static function getByUser(int $userId): array
    {
        self::ensureWebhookColumns();
        $stmt = DB::pdo()->prepare('SELECT * FROM settings WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $stmt2 = DB::pdo()->prepare('INSERT INTO settings (user_id, filter_mode, timezone, page_size) VALUES (?,?,?,?)');
            $stmt2->execute([$userId, 'algorithmic', DB::env('DEFAULT_TIMEZONE','UTC'), (int)DB::env('DEFAULT_PAGE_SIZE',25)]);
            return self::getByUser($userId);
        }
        return $row;
    }

    public static function saveFilter(int $userId, string $mode, ?string $openaiKeyEnc, int $thrGenuine = 70, int $thrSpam = 40, ?string $pos = null, ?string $neg = null, int $strictGpt = 0): void
    {
        $stmt = DB::pdo()->prepare('UPDATE settings SET filter_mode = ?, openai_api_key_enc = ?, filter_threshold_genuine = ?, filter_threshold_spam = ?, filter_pos_keywords = ?, filter_neg_keywords = ?, strict_gpt = ? WHERE user_id = ?');
        $stmt->execute([$mode, $openaiKeyEnc, $thrGenuine, $thrSpam, $pos, $neg, $strictGpt, $userId]);
    }

    public static function saveGeneral(int $userId, string $timezone, int $pageSize, ?string $sheetsWebhookUrl = null, ?string $sheetsWebhookSecret = null): void
    {
        self::ensureWebhookColumns();
        $stmt = DB::pdo()->prepare('UPDATE settings SET timezone = ?, page_size = ?, sheets_webhook_url = ?, sheets_webhook_secret = ? WHERE user_id = ?');
        $stmt->execute([$timezone, $pageSize, $sheetsWebhookUrl, $sheetsWebhookSecret, $userId]);
    }
}

