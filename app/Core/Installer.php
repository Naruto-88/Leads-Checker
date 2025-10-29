<?php
namespace App\Core;

use App\Models\User;
use PDO;

class Installer
{
    public static function installIfNeeded(): void
    {
        $pdo = DB::pdo();
        // Check if users table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
        $exists = $stmt->fetchColumn();
        if ($exists) {
            self::ensureMigrations($pdo);
            return;
        }
        self::runMigrations($pdo);
        self::seed($pdo);
    }

    private static function runMigrations(PDO $pdo): void
    {
        $sql = file_get_contents(BASE_PATH . '/migrations/schema.sql');
        if ($sql === false) {
            throw new \RuntimeException('Missing migrations/schema.sql');
        }
        $pdo->exec($sql);
    }

    private static function seed(PDO $pdo): void
    {
        // Seed admin and user
        $now = date('Y-m-d H:i:s');
        $adminPass = self::hashPassword('Admin123!');
        $userPass = self::hashPassword('User123!');
        $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, role, created_at) VALUES (?,?,?,?)");
        $stmt->execute(['admin@example.com', $adminPass, 'admin', $now]);
        $stmt->execute(['user@example.com', $userPass, 'user', $now]);

        // Seed settings rows
        $stmt = $pdo->prepare("INSERT INTO settings (user_id, filter_mode, timezone, page_size) VALUES (?,?,?,?)");
        $stmt->execute([1, 'algorithmic', DB::env('DEFAULT_TIMEZONE', 'UTC'), (int)DB::env('DEFAULT_PAGE_SIZE', 25)]);
        $stmt->execute([2, 'algorithmic', DB::env('DEFAULT_TIMEZONE', 'UTC'), (int)DB::env('DEFAULT_PAGE_SIZE', 25)]);

        // Seed sample emails
        $emails = [
            [
                'from_email' => 'jane.doe@example.com',
                'from_name' => 'Jane Doe',
                'to_email' => 'info@youragency.com',
                'subject' => 'Request for a website redesign quote',
                'body_plain' => 'Hello, I need a quote for redesigning our company website. Can we schedule a call this week?',
                'body_html' => null,
                'received_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
                'user_id' => 1,
            ],
            [
                'from_email' => 'promo@casino-bonus.biz',
                'from_name' => 'Casino Promo',
                'to_email' => 'info@youragency.com',
                'subject' => 'Earn crypto fast! Exclusive casino offer',
                'body_plain' => 'Get instant earnings. Click here now: http://short.ly/abc123',
                'body_html' => '<p>Get instant earnings. Click <a href="http://short.ly/abc123">here</a> now.</p>',
                'received_at' => date('Y-m-d H:i:s', strtotime('-1 days')),
                'user_id' => 1,
            ],
        ];

        $stmt = $pdo->prepare("INSERT INTO emails (user_id, email_account_id, message_id, from_email, from_name, to_email, subject, body_plain, body_html, received_at, fetched_at, hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($emails as $em) {
            $hash = hash('sha256', $em['from_email'] . '|' . $em['subject'] . '|' . $em['received_at']);
            $stmt->execute([
                $em['user_id'], null, null, $em['from_email'], $em['from_name'], $em['to_email'], $em['subject'], $em['body_plain'], $em['body_html'], $em['received_at'], $now, $hash
            ]);
        }
    }

    private static function hashPassword(string $password): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID);
        }
        return password_hash($password, PASSWORD_BCRYPT);
    }

    private static function ensureMigrations(PDO $pdo): void
    {
        // Create clients table if missing
        $q = $pdo->query("SHOW TABLES LIKE 'clients'");
        if (!$q->fetchColumn()) {
            $pdo->exec("CREATE TABLE clients (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, name VARCHAR(255) NOT NULL, website VARCHAR(255) NULL, shortcode VARCHAR(50) NOT NULL, created_at DATETIME NOT NULL, UNIQUE KEY unique_user_shortcode (user_id, shortcode), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)");
        }
        // Add client_id columns
        $cols = $pdo->query("SHOW COLUMNS FROM email_accounts LIKE 'client_id'")->fetch();
        if (!$cols) { $pdo->exec("ALTER TABLE email_accounts ADD COLUMN client_id INT NULL AFTER user_id"); }
        $cols = $pdo->query("SHOW COLUMNS FROM emails LIKE 'client_id'")->fetch();
        if (!$cols) { $pdo->exec("ALTER TABLE emails ADD COLUMN client_id INT NULL AFTER email_account_id"); }
        $cols = $pdo->query("SHOW COLUMNS FROM leads LIKE 'client_id'")->fetch();
        if (!$cols) { $pdo->exec("ALTER TABLE leads ADD COLUMN client_id INT NULL AFTER email_id"); }
        // Soft delete columns
        $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'deleted_at'")->fetch();
        if (!$cols) { $pdo->exec("ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL AFTER last_login_at"); }
        $cols = $pdo->query("SHOW COLUMNS FROM leads LIKE 'deleted_at'")->fetch();
        if (!$cols) { $pdo->exec("ALTER TABLE leads ADD COLUMN deleted_at DATETIME NULL AFTER updated_at"); }

        // Extend settings with filter tuning fields
        $cols = $pdo->query("SHOW COLUMNS FROM settings LIKE 'filter_threshold_genuine'")->fetch();
        if (!$cols) { $pdo->exec("ALTER TABLE settings ADD COLUMN filter_threshold_genuine INT NOT NULL DEFAULT 70 AFTER filter_mode"); }
        $cols = $pdo->query("SHOW COLUMNS FROM settings LIKE 'filter_threshold_spam'")->fetch();
        if (!$cols) { $pdo->exec("ALTER TABLE settings ADD COLUMN filter_threshold_spam INT NOT NULL DEFAULT 40 AFTER filter_threshold_genuine"); }
        $cols = $pdo->query("SHOW COLUMNS FROM settings LIKE 'filter_pos_keywords'")->fetch();
        if (!$cols) { $pdo->exec("ALTER TABLE settings ADD COLUMN filter_pos_keywords TEXT NULL AFTER filter_threshold_spam"); }
        $cols = $pdo->query("SHOW COLUMNS FROM settings LIKE 'filter_neg_keywords'")->fetch();
        if (!$cols) { $pdo->exec("ALTER TABLE settings ADD COLUMN filter_neg_keywords TEXT NULL AFTER filter_pos_keywords"); }

        // Per-client filtering fields
        $cols = $pdo->query("SHOW COLUMNS FROM clients LIKE 'filter_threshold_genuine'")->fetch();
        if (!$cols) { $pdo->exec("ALTER TABLE clients ADD COLUMN filter_threshold_genuine INT NULL AFTER website"); }
        $cols = $pdo->query("SHOW COLUMNS FROM clients LIKE 'filter_threshold_spam'")->fetch();
        if (!$cols) { $pdo->exec("ALTER TABLE clients ADD COLUMN filter_threshold_spam INT NULL AFTER filter_threshold_genuine"); }
        $cols = $pdo->query("SHOW COLUMNS FROM clients LIKE 'filter_pos_keywords'")->fetch();
        if (!$cols) { $pdo->exec("ALTER TABLE clients ADD COLUMN filter_pos_keywords TEXT NULL AFTER filter_threshold_spam"); }
        $cols = $pdo->query("SHOW COLUMNS FROM clients LIKE 'filter_neg_keywords'")->fetch();
        if (!$cols) { $pdo->exec("ALTER TABLE clients ADD COLUMN filter_neg_keywords TEXT NULL AFTER filter_pos_keywords"); }

        // Performance indexes for faster filtering and fetching
        try {
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_emails_user_received ON emails (user_id, received_at)");
        } catch (\Throwable $e) {}
        try {
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_emails_user_id ON emails (user_id, id)");
        } catch (\Throwable $e) {}
        try {
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_leads_user_email ON leads (user_id, email_id, deleted_at)");
        } catch (\Throwable $e) {}
        try {
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_leads_user_status ON leads (user_id, status)");
        } catch (\Throwable $e) {}
    }
}
