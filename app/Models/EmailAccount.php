<?php
namespace App\Models;

use App\Core\DB;
use PDO;

class EmailAccount
{
    public static function listByUser(int $userId): array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM email_accounts WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function create(int $userId, array $data): int
    {
        $stmt = DB::pdo()->prepare('INSERT INTO email_accounts (user_id, client_id, label, imap_host, imap_port, encryption, username, password_enc, folder, created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())');
        $stmt->execute([$userId, $data['client_id'] ?? null, $data['label'], $data['imap_host'], (int)$data['imap_port'], $data['encryption'], $data['username'], $data['password_enc'], $data['folder'] ?? 'INBOX']);
        return (int)DB::pdo()->lastInsertId();
    }

    public static function delete(int $userId, int $id): void
    {
        $stmt = DB::pdo()->prepare('DELETE FROM email_accounts WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
    }

    public static function find(int $userId, int $id): ?array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM email_accounts WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
