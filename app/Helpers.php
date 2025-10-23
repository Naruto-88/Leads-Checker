<?php
namespace App;

class Helpers
{
    public static function e(?string $s): string
    {
        return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function redirect(string $path): void
    {
        header('Location: ' . $path);
        exit;
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function encryptSecret(string $plaintext, string $key): string
    {
        $key = substr(hash('sha256', $key, true), 0, 32);
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv) . ':' . base64_encode($cipher ?: '');
    }

    public static function decryptSecret(?string $encoded, string $key): ?string
    {
        if (!$encoded) return null;
        $parts = explode(':', $encoded, 2);
        if (count($parts) !== 2) return null;
        [$ivB64, $cipherB64] = $parts;
        $iv = base64_decode($ivB64);
        $cipher = base64_decode($cipherB64);
        $key = substr(hash('sha256', $key, true), 0, 32);
        $plain = openssl_decrypt($cipher ?: '', 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv ?: '');
        return $plain === false ? null : $plain;
    }

    public static function dateRangeQuick(string $label): array
    {
        $today = new \DateTime('today');
        switch ($label) {
            case 'last_week':
                $dt = new \DateTime('monday last week');
                $start = $dt->format('Y-m-d 00:00:00');
                $end = $dt->modify('+6 days')->format('Y-m-d 23:59:59');
                return [$start, $end];
            case 'last_7':
                $start = (clone $today)->modify('-6 days')->format('Y-m-d 00:00:00');
                $end = $today->format('Y-m-d 23:59:59');
                return [$start, $end];
            case 'last_month':
                $start = (new \DateTime('first day of last month'))->format('Y-m-01 00:00:00');
                $end = (new \DateTime('last day of last month'))->format('Y-m-t 23:59:59');
                return [$start, $end];
            case 'last_30':
                $start = (clone $today)->modify('-29 days')->format('Y-m-d 00:00:00');
                $end = $today->format('Y-m-d 23:59:59');
                return [$start, $end];
            default:
                return ['1970-01-01 00:00:00', '2999-12-31 23:59:59'];
        }
    }
}

