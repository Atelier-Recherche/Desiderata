<?php

declare(strict_types=1);

namespace Desiderata\Standalone;

use PDO;

final class RateLimit
{
    public static function check(PDO $pdo, string $prefix, string $bucket, string $ip, int $max, int $window): bool
    {
        $key = substr(hash('sha256', $bucket . '|' . $ip), 0, 64);
        $now = time();
        $stmt = $pdo->prepare("SELECT count, window_start FROM {$prefix}rate_limits WHERE bucket_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        if (!$row || ($now - (int) $row['window_start']) >= $window) {
            $upsert = $pdo->prepare(
                "REPLACE INTO {$prefix}rate_limits (bucket_key, count, window_start) VALUES (?, 1, ?)"
            );
            $upsert->execute([$key, $now]);
            return true;
        }

        if ((int) $row['count'] >= $max) {
            return false;
        }

        $upd = $pdo->prepare(
            "UPDATE {$prefix}rate_limits SET count = count + 1 WHERE bucket_key = ?"
        );
        $upd->execute([$key]);
        return true;
    }

    public static function ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return is_string($ip) ? $ip : '0.0.0.0';
    }
}
