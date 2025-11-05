<?php
namespace App\Models;

use App\Core\DB;
use PDO;

class Client
{
    private static function ensureContactEmails(): void
    {
        try {
            DB::pdo()->exec("ALTER TABLE clients ADD COLUMN contact_emails TEXT NULL AFTER website");
        } catch (\Throwable $e) {
            // ignore if exists or no permission
        }
        // Also ensure sender_email exists for outbound messaging defaults
        try {
            DB::pdo()->exec("ALTER TABLE clients ADD COLUMN sender_email VARCHAR(255) NULL AFTER contact_emails");
        } catch (\Throwable $e) {
            // ignore if exists or no permission
        }
    }
    public static function listByUser(int $userId): array
    {
        self::ensureContactEmails();
        $stmt = DB::pdo()->prepare('SELECT * FROM clients WHERE user_id = ? ORDER BY name ASC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function create(int $userId, string $name, ?string $website, string $shortcode): int
    {
        self::ensureContactEmails();
        $stmt = DB::pdo()->prepare('INSERT INTO clients (user_id, name, website, shortcode, created_at) VALUES (?,?,?,?,NOW())');
        $stmt->execute([$userId, $name, $website, $shortcode]);
        return (int)DB::pdo()->lastInsertId();
    }

    public static function delete(int $userId, int $id): void
    {
        $stmt = DB::pdo()->prepare('DELETE FROM clients WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
    }

    public static function update(int $userId, int $id, string $name, ?string $website, string $shortcode): void
    {
        self::ensureContactEmails();
        $stmt = DB::pdo()->prepare('UPDATE clients SET name = ?, website = ?, shortcode = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$name, $website, $shortcode, $id, $userId]);
    }

    public static function updateContactEmails(int $userId, int $id, ?string $emails): void
    {
        self::ensureContactEmails();
        $stmt = DB::pdo()->prepare('UPDATE clients SET contact_emails = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$emails, $id, $userId]);
    }

    public static function updateSenderEmail(int $userId, int $id, ?string $senderEmail): void
    {
        self::ensureContactEmails();
        $stmt = DB::pdo()->prepare('UPDATE clients SET sender_email = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$senderEmail, $id, $userId]);
    }

    public static function findByShortcode(int $userId, string $code): ?array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM clients WHERE user_id = ? AND shortcode = ?');
        $stmt->execute([$userId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function bulkImport(int $userId, array $rows): void
    {
        if (!$rows) return;
        $pdo = DB::pdo();
        $sql = 'INSERT INTO clients (user_id, name, website, shortcode, created_at) VALUES ';
        $vals = [];
        $params = [];
        foreach ($rows as $r) {
            $vals[] = '(?,?,?,?,NOW())';
            $params[] = $userId;
            $params[] = $r['name'];
            $params[] = $r['website'];
            $params[] = $r['shortcode'];
        }
        $sql .= implode(',', $vals) . ' ON DUPLICATE KEY UPDATE name=VALUES(name), website=VALUES(website)';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }
}

