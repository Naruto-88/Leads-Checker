<?php
namespace App\Models;

use App\Core\DB;
use PDO;

class Email
{
    public static function insert(array $data): int
    {
        $stmt = DB::pdo()->prepare('INSERT INTO emails (user_id, email_account_id, client_id, message_id, from_email, from_name, to_email, subject, body_plain, body_html, received_at, fetched_at, hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $data['user_id'], $data['email_account_id'] ?? null, $data['client_id'] ?? null, $data['message_id'] ?? null, $data['from_email'], $data['from_name'] ?? null, $data['to_email'] ?? null, $data['subject'] ?? '', $data['body_plain'] ?? null, $data['body_html'] ?? null, $data['received_at'], $data['fetched_at'], $data['hash']
        ]);
        return (int)DB::pdo()->lastInsertId();
    }

    public static function existsByMessageIdOrHash(?string $messageId, string $hash): bool
    {
        if ($messageId) {
            $stmt = DB::pdo()->prepare('SELECT id FROM emails WHERE message_id = ? OR hash = ? LIMIT 1');
            $stmt->execute([$messageId, $hash]);
        } else {
            $stmt = DB::pdo()->prepare('SELECT id FROM emails WHERE hash = ? LIMIT 1');
            $stmt->execute([$hash]);
        }
        return (bool)$stmt->fetchColumn();
    }

    public static function listByUser(int $userId, array $opts = []): array
    {
        $sql = 'SELECT * FROM emails WHERE user_id = :uid';
        $params = ['uid'=>$userId];
        if (!empty($opts['client_id'])) {
            $sql .= ' AND client_id = :client_id';
            $params['client_id'] = (int)$opts['client_id'];
        }
        if (!empty($opts['search'])) {
            $sql .= ' AND (subject LIKE :q OR from_email LIKE :q OR body_plain LIKE :q)';
            $params['q'] = '%' . $opts['search'] . '%';
        }
        if (!empty($opts['start']) && !empty($opts['end'])) {
            $sql .= ' AND received_at BETWEEN :start AND :end';
            $params['start'] = $opts['start'];
            $params['end'] = $opts['end'];
        }
        $sql .= ' ORDER BY received_at DESC LIMIT :limit OFFSET :offset';
        $stmt = DB::pdo()->prepare($sql);
        $limit = (int)($opts['limit'] ?? 25);
        $offset = (int)($opts['offset'] ?? 0);
        foreach ($params as $k=>$v) {
            $type = is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue(':' . $k, $v, $type);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function countByUser(int $userId, array $opts = []): int
    {
        $sql = 'SELECT COUNT(*) FROM emails WHERE user_id = :uid';
        $params = ['uid'=>$userId];
        if (!empty($opts['client_id'])) {
            $sql .= ' AND client_id = :client_id';
            $params['client_id'] = (int)$opts['client_id'];
        }
        if (!empty($opts['search'])) {
            $sql .= ' AND (subject LIKE :q OR from_email LIKE :q OR body_plain LIKE :q)';
            $params['q'] = '%' . $opts['search'] . '%';
        }
        if (!empty($opts['start']) && !empty($opts['end'])) {
            $sql .= ' AND received_at BETWEEN :start AND :end';
            $params['start'] = $opts['start'];
            $params['end'] = $opts['end'];
        }
        $stmt = DB::pdo()->prepare($sql);
        foreach ($params as $k=>$v) {
            $stmt->bindValue(':' . $k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    public static function find(int $userId, int $id): ?array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM emails WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function updateClient(int $emailId, ?int $clientId): void
    {
        $stmt = DB::pdo()->prepare('UPDATE emails SET client_id = ? WHERE id = ?');
        $stmt->execute([$clientId, $emailId]);
    }
}
