<?php
namespace App\Models;

use App\Core\DB;
use PDO;

class User
{
    public static function find(int $id): ?array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function all(): array
    {
        return DB::pdo()->query('SELECT * FROM users WHERE deleted_at IS NULL ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function create(string $email, string $passwordHash): int
    {
        $stmt = DB::pdo()->prepare('INSERT INTO users (email, password_hash, role, created_at) VALUES (?,?,"user",NOW())');
        $stmt->execute([$email, $passwordHash]);
        return (int)DB::pdo()->lastInsertId();
    }

    public static function byEmail(string $email): ?array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function updateRole(int $id, string $role): void
    {
        $stmt = DB::pdo()->prepare('UPDATE users SET role = ? WHERE id = ?');
        $stmt->execute([$role, $id]);
    }

    public static function softDelete(int $id): void
    {
        $stmt = DB::pdo()->prepare('UPDATE users SET deleted_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function updatePassword(int $id, string $hash): void
    {
        $stmt = DB::pdo()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$hash, $id]);
    }
}
