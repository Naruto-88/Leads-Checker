<?php
namespace App\Models;

use App\Core\DB;
use PDO;

class Setting
{
    public static function getByUser(int $userId): array
    {
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

    public static function saveFilter(int $userId, string $mode, ?string $openaiKeyEnc): void
    {
        $stmt = DB::pdo()->prepare('UPDATE settings SET filter_mode = ?, openai_api_key_enc = ? WHERE user_id = ?');
        $stmt->execute([$mode, $openaiKeyEnc, $userId]);
    }

    public static function saveGeneral(int $userId, string $timezone, int $pageSize): void
    {
        $stmt = DB::pdo()->prepare('UPDATE settings SET timezone = ?, page_size = ? WHERE user_id = ?');
        $stmt->execute([$timezone, $pageSize, $userId]);
    }
}

