<?php
namespace App\Models;

use App\Core\DB;
use PDO;

class PasswordReset
{
    public static function createToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        DB::pdo()->prepare('INSERT INTO password_resets (user_id, token, created_at) VALUES (?,?,NOW())')->execute([$userId, $token]);
        return $token;
    }

    public static function find(string $token): ?array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM password_resets WHERE token = ? LIMIT 1');
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function consume(string $token): void
    {
        DB::pdo()->prepare('DELETE FROM password_resets WHERE token = ?')->execute([$token]);
    }
}

