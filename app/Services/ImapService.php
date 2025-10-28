<?php
namespace App\Services;

use App\Core\DB;
use App\Models\Email;

class ImapService
{
    public static function fetchForUser(int $userId, int $daysBack = 30, int $limit = 500): array
    {
        $pdo = DB::pdo();
        $accounts = $pdo->prepare('SELECT * FROM email_accounts WHERE user_id = ?');
        $accounts->execute([$userId]);
        $rows = $accounts->fetchAll(\PDO::FETCH_ASSOC);
        $count = 0; $errors = [];
        foreach ($rows as $acc) {
            try {
                $count += self::fetchFromAccount($userId, $acc, $daysBack, $limit);
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
                $dir = BASE_PATH . '/storage/logs';
                if (!is_dir($dir)) @mkdir($dir, 0775, true);
                $line = sprintf("%s [imap.fetch] account_id=%s error=%s\n", date('c'), $acc['id'] ?? 'unknown', $e->getMessage());
                @file_put_contents($dir . '/app.log', $line, FILE_APPEND);
            }
        }
        return ['fetched'=>$count, 'errors'=>$errors];
    }

    public static function fetchFromAccount(int $userId, array $acc, int $daysBack = 14, int $limit = 200): int
    {
        if (!function_exists('imap_open')) {
            throw new \RuntimeException('PHP IMAP extension not enabled.');
        }
        // Ensure we have a place to store last seen UID for incremental fetching
        self::ensureLastUidColumn();
        $lastUid = isset($acc['last_uid']) ? (int)$acc['last_uid'] : 0;

        // Be conservative with timeouts so we fail fast on slow servers
        if (function_exists('imap_set_timeout')) {
            @imap_set_timeout(1, 10); // open timeout
            @imap_set_timeout(2, 10); // read timeout
            @imap_set_timeout(3, 10); // write timeout
            @imap_set_timeout(4, 10); // close timeout
        }
        $encPass = $acc['password_enc'];
        $password = \App\Helpers::decryptSecret($encPass, DB::env('APP_KEY','')) ?? '';
        $transport = $acc['encryption'] === 'ssl' ? 'ssl' : ($acc['encryption']==='tls' ? 'tls' : 'notls');
        $mailbox = sprintf('{%s:%d/imap/%s/novalidate-cert}%s', $acc['imap_host'], (int)$acc['imap_port'], $transport, $acc['folder'] ?: 'INBOX');
        $inbox = @imap_open($mailbox, $acc['username'], $password, 0, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
        if (!$inbox) {
            throw new \RuntimeException('IMAP connect failed: ' . imap_last_error());
        }
        $uids = [];
        if ($lastUid > 0) {
            // Delta fetch: only messages with UID greater than last seen
            $uids = imap_search($inbox, 'UID ' . ($lastUid + 1) . ':*', SE_UID) ?: [];
        } else {
            // First-time or no marker: fall back to date-based query
            $sinceDate = date('d-M-Y', strtotime('-'.$daysBack.' days'));
            $uids = imap_search($inbox, 'ALL SINCE "' . $sinceDate . '"', SE_UID) ?: [];
        }
        sort($uids, SORT_NUMERIC);
        if ($limit > 0 && count($uids) > $limit) {
            $uids = array_slice($uids, -$limit);
        }
        $fetched = 0;
        $maxUid = $lastUid;
        foreach ($uids as $uid) {
            $header = imap_headerinfo($inbox, imap_msgno($inbox, $uid));
            $messageId = isset($header->message_id) ? trim($header->message_id, '<>') : null;
            $from_email = $header->from[0]->mailbox . '@' . $header->from[0]->host;
            $from_name = $header->from[0]->personal ?? null;
            $to_email = isset($header->to[0]) ? ($header->to[0]->mailbox . '@' . $header->to[0]->host) : null;
            $subject = imap_utf8($header->subject ?? '');
            $date = date('Y-m-d H:i:s', strtotime($header->date ?? 'now'));
            $hash = hash('sha256', $from_email . '|' . $subject . '|' . $date);
            if (Email::existsByMessageIdOrHash($messageId, $hash)) {
                if ($uid > $maxUid) { $maxUid = $uid; }
                continue;
            }

            // Lazily fetch body only when we know it's new
            $structure = imap_fetchstructure($inbox, $uid, FT_UID);
            $body_plain = null; $body_html = null;
            if (!isset($structure->parts)) {
                $text = imap_body($inbox, $uid, FT_UID);
                $body_plain = self::decodePart($inbox, $uid, $structure, 1) ?: $text;
            } else {
                // Fetch plain text first, only fall back to HTML if empty
                $body_plain = self::getPart($inbox, $uid, 'TEXT/PLAIN');
                if ($body_plain === null || $body_plain === '') {
                    $body_html = self::getPart($inbox, $uid, 'TEXT/HTML');
                } else {
                    // Optionally skip HTML to save bandwidth if plain exists
                    $body_html = null;
                }
            }
            // Determine client assignment: prefer account mapping; else heuristic assigner
            $clientId = isset($acc['client_id']) && $acc['client_id'] ? (int)$acc['client_id'] : null;
            if (!$clientId) {
                $assigned = \App\Services\ClientAssigner::assign($userId, [
                    'subject'=>$subject,
                    'body_plain'=>$body_plain,
                    'body_html'=>$body_html,
                    'to_email'=>$to_email,
                    'from_email'=>$from_email,
                ]);
                $clientId = $assigned['client_id'];
            }

            Email::insert([
                'user_id' => $userId,
                'email_account_id' => (int)$acc['id'],
                'client_id' => $clientId,
                'message_id' => $messageId,
                'from_email' => $from_email,
                'from_name' => $from_name,
                'to_email' => $to_email,
                'subject' => $subject,
                'body_plain' => $body_plain,
                'body_html' => $body_html,
                'received_at' => $date,
                'fetched_at' => date('Y-m-d H:i:s'),
                'hash' => $hash,
            ]);
            $fetched++;
            if ($uid > $maxUid) { $maxUid = $uid; }
        }
        imap_close($inbox);
        // Persist last seen UID marker
        if ($maxUid > $lastUid) {
            try {
                $stmt = DB::pdo()->prepare('UPDATE email_accounts SET last_uid = ? WHERE id = ? AND user_id = ?');
                $stmt->execute([(int)$maxUid, (int)$acc['id'], (int)$userId]);
            } catch (\Throwable $e) {
                // ignore
            }
        }
        return $fetched;
    }

    private static function ensureLastUidColumn(): void
    {
        try {
            DB::pdo()->exec('ALTER TABLE email_accounts ADD COLUMN last_uid INT NULL AFTER folder');
        } catch (\Throwable $e) {
            // ignore if exists or insufficient privileges
        }
    }

    private static function getPart($inbox, $uid, string $mimetype, $structure = null, $partNumber = null)
    {
        if (!$structure) {
            $structure = imap_fetchstructure($inbox, $uid, FT_UID);
        }
        if ($structure) {
            if ($mimetype === self::getMimeType($structure)) {
                if (!$partNumber) {
                    $partNumber = 1;
                }
                $text = imap_fetchbody($inbox, $uid, $partNumber, FT_UID);
                return self::decode($text, $structure->encoding);
            }
            if (!empty($structure->parts)) {
                foreach ($structure->parts as $index => $subStruct) {
                    $prefix = $partNumber ? $partNumber . '.' : '';
                    $data = self::getPart($inbox, $uid, $mimetype, $subStruct, $prefix . ($index + 1));
                    if ($data) return $data;
                }
            }
        }
        return null;
    }

    private static function getMimeType($structure): string
    {
        $primary = ['TEXT','MULTIPART','MESSAGE','APPLICATION','AUDIO','IMAGE','VIDEO','OTHER'];
        $type = $structure->type ?? 0;
        $subtype = $structure->subtype ?? 'PLAIN';
        return $primary[$type] . '/' . $subtype;
    }

    private static function decode($text, $encoding)
    {
        switch ($encoding) {
            case 3: return base64_decode($text);
            case 4: return quoted_printable_decode($text);
            default: return $text;
        }
    }

    private static function decodePart($inbox, $uid, $structure, $partNumber)
    {
        $text = imap_fetchbody($inbox, $uid, $partNumber, FT_UID);
        if ($structure && isset($structure->encoding)) {
            return self::decode($text, $structure->encoding);
        }
        return $text;
    }
}
