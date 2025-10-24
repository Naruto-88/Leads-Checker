<?php
namespace App\Models;

use App\Core\DB;
use PDO;

class Lead
{
    public static function upsertFromEmail(array $email, array $result): int
    {
        $pdo = DB::pdo();
        // Check existing lead for this email
        $stmt = $pdo->prepare('SELECT id FROM leads WHERE email_id = ? LIMIT 1');
        $stmt->execute([$email['id']]);
        $leadId = (int)$stmt->fetchColumn();
        $now = date('Y-m-d H:i:s');
        if ($leadId) {
            $stmt = $pdo->prepare('UPDATE leads SET status = ?, score = ?, mode = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([$result['status'], $result['score'], $result['mode'], $now, $leadId]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO leads (user_id, email_id, client_id, status, score, mode, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->execute([$email['user_id'], $email['id'], $email['client_id'] ?? null, $result['status'], $result['score'], $result['mode'], $now, $now]);
            $leadId = (int)$pdo->lastInsertId();
        }
        return $leadId;
    }

    public static function addCheck(int $leadId, ?int $userId, string $mode, int $score, string $reason): void
    {
        $stmt = DB::pdo()->prepare('INSERT INTO lead_checks (lead_id, checked_by_user_id, mode, score, reason, created_at) VALUES (?,?,?,?,?,NOW())');
        $stmt->execute([$leadId, $userId, $mode, $score, $reason]);
    }

    public static function listByUser(int $userId, array $opts = []): array
    {
        $sql = 'SELECT l.*, e.from_email, e.subject, e.body_plain, e.received_at FROM leads l JOIN emails e ON e.id = l.email_id WHERE l.user_id = :uid AND l.deleted_at IS NULL';
        $params = ['uid'=>$userId];
        if (!empty($opts['status'])) {
            $sql .= ' AND l.status = :status';
            $params['status'] = $opts['status'];
        }
        if (!empty($opts['client_id'])) {
            $sql .= ' AND l.client_id = :client_id';
            $params['client_id'] = (int)$opts['client_id'];
        }
        if (!empty($opts['search'])) {
            $sql .= ' AND (e.subject LIKE :q1 OR e.from_email LIKE :q2 OR e.body_plain LIKE :q3)';
            $params['q1'] = '%' . $opts['search'] . '%';
            $params['q2'] = '%' . $opts['search'] . '%';
            $params['q3'] = '%' . $opts['search'] . '%';
        }
        if (!empty($opts['start']) && !empty($opts['end'])) {
            $sql .= ' AND e.received_at BETWEEN :start AND :end';
            $params['start'] = $opts['start'];
            $params['end'] = $opts['end'];
        }
        $sort = ($opts['sort'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $sql .= ' ORDER BY e.received_at ' . $sort . ' LIMIT :limit OFFSET :offset';
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
        $sql = 'SELECT COUNT(*) FROM leads l JOIN emails e ON e.id = l.email_id WHERE l.user_id = :uid AND l.deleted_at IS NULL';
        $params = ['uid'=>$userId];
        if (!empty($opts['status'])) {
            $sql .= ' AND l.status = :status';
            $params['status'] = $opts['status'];
        }
        if (!empty($opts['client_id'])) {
            $sql .= ' AND l.client_id = :client_id';
            $params['client_id'] = (int)$opts['client_id'];
        }
        if (!empty($opts['search'])) {
            $sql .= ' AND (e.subject LIKE :q1 OR e.from_email LIKE :q2 OR e.body_plain LIKE :q3)';
            $params['q1'] = '%' . $opts['search'] . '%';
            $params['q2'] = '%' . $opts['search'] . '%';
            $params['q3'] = '%' . $opts['search'] . '%';
        }
        if (!empty($opts['start']) && !empty($opts['end'])) {
            $sql .= ' AND e.received_at BETWEEN :start AND :end';
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

    public static function findWithEmail(int $userId, int $leadId): ?array
    {
        // Alias lead id to avoid collision with email id; keep email id as 'id'.
        $sql = 'SELECT l.id AS lead_id, l.*, e.*
                FROM leads l
                JOIN emails e ON e.id = l.email_id
                WHERE l.id = ? AND l.user_id = ? AND l.deleted_at IS NULL';
        $stmt = DB::pdo()->prepare($sql);
        $stmt->execute([$leadId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function checks(int $leadId): array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM lead_checks WHERE lead_id = ? ORDER BY created_at DESC');
        $stmt->execute([$leadId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function delete(int $userId, int $leadId): void
    {
        $pdo = DB::pdo();
        $pdo->prepare('UPDATE leads SET deleted_at = NOW() WHERE id = ? AND user_id = ?')->execute([$leadId, $userId]);
    }

    public static function manualMark(int $leadId, int $userId, string $status): void
    {
        $pdo = DB::pdo();
        $pdo->prepare('UPDATE leads SET status = ?, updated_at = NOW() WHERE id = ? AND user_id = ?')->execute([$status, $leadId, $userId]);
        $pdo->prepare('INSERT INTO lead_checks (lead_id, checked_by_user_id, mode, score, reason, created_at) VALUES (?,?,?,?,?,NOW())')
            ->execute([$leadId, $userId, 'manual', 100, 'Manual override to ' . $status]);
    }

    public static function listByUserForExport(int $userId, array $opts = []): array
    {
        $sql = 'SELECT l.*, e.from_email, e.subject, e.body_plain, e.received_at FROM leads l JOIN emails e ON e.id = l.email_id WHERE l.user_id = :uid AND l.deleted_at IS NULL';
        $params = ['uid'=>$userId];
        if (!empty($opts['status'])) {
            $sql .= ' AND l.status = :status';
            $params['status'] = $opts['status'];
        }
        if (!empty($opts['client_id'])) {
            $sql .= ' AND l.client_id = :client_id';
            $params['client_id'] = (int)$opts['client_id'];
        }
        if (!empty($opts['search'])) {
            $sql .= ' AND (e.subject LIKE :q1 OR e.from_email LIKE :q2 OR e.body_plain LIKE :q3)';
            $params['q1'] = '%' . $opts['search'] . '%';
            $params['q2'] = '%' . $opts['search'] . '%';
            $params['q3'] = '%' . $opts['search'] . '%';
        }
        if (!empty($opts['start']) && !empty($opts['end'])) {
            $sql .= ' AND e.received_at BETWEEN :start AND :end';
            $params['start'] = $opts['start'];
            $params['end'] = $opts['end'];
        }
        $sql .= ' ORDER BY e.received_at DESC';
        $stmt = DB::pdo()->prepare($sql);
        foreach ($params as $k=>$v) {
            $stmt->bindValue(':' . $k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function genuineCountsByClient(int $userId, string $start, string $end): array
    {
        $sql = 'SELECT l.client_id, COUNT(*) AS cnt
                FROM leads l
                JOIN emails e ON e.id = l.email_id
                WHERE l.user_id = :uid AND l.deleted_at IS NULL AND l.status = \'' . "genuine" . '\'
                  AND e.received_at BETWEEN :start AND :end
                GROUP BY l.client_id';
        $stmt = DB::pdo()->prepare($sql);
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':start', $start, PDO::PARAM_STR);
        $stmt->bindValue(':end', $end, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $r) {
            $cid = $r['client_id'] !== null ? (int)$r['client_id'] : 0;
            $map[$cid] = (int)$r['cnt'];
        }
        return $map;
    }

    public static function genuineTotal(int $userId, string $start, string $end): int
    {
        $sql = 'SELECT COUNT(*) FROM leads l JOIN emails e ON e.id = l.email_id
                WHERE l.user_id = :uid AND l.deleted_at IS NULL AND l.status = \'' . "genuine" . '\'
                  AND e.received_at BETWEEN :start AND :end';
        $stmt = DB::pdo()->prepare($sql);
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':start', $start, PDO::PARAM_STR);
        $stmt->bindValue(':end', $end, PDO::PARAM_STR);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }
}
