<?php
namespace App\Models;

use App\Core\DB;
use PDO;

class Client
{
    public static function listByUser(int $userId): array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM clients WHERE user_id = ? ORDER BY name ASC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function create(int $userId, string $name, ?string $website, string $shortcode): int
    {
        $stmt = DB::pdo()->prepare('INSERT INTO clients (user_id, name, website, shortcode, created_at) VALUES (?,?,?,?,NOW())');
        $stmt->execute([$userId, $name, $website, $shortcode]);
        return (int)DB::pdo()->lastInsertId();
    }

    public static function delete(int $userId, int $id): void
    {
        $stmt = DB::pdo()->prepare('DELETE FROM clients WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
    }

    public static function findByShortcode(int $userId, string $code): ?array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM clients WHERE user_id = ? AND shortcode = ?');
        $stmt->execute([$userId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

