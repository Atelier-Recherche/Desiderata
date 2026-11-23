<?php

declare(strict_types=1);

namespace Desiderata\Standalone;

use PDO;

final class Auth
{
    public static function bootstrapOwner(PDO $pdo, string $prefix, ?array $user): void
    {
        if (!$user || empty($user['email']) || empty($user['password'])) {
            return;
        }
        $stmt = $pdo->prepare("SELECT id FROM {$prefix}users WHERE email = ? LIMIT 1");
        $stmt->execute([(string) $user['email']]);
        if ($stmt->fetch()) {
            return;
        }
        $ins = $pdo->prepare(
            "INSERT INTO {$prefix}users (email, password_hash, display_name, created_at)
             VALUES (?, ?, ?, ?)"
        );
        $ins->execute([
            (string) $user['email'],
            password_hash((string) $user['password'], PASSWORD_DEFAULT),
            (string) ($user['display_name'] ?? 'Admin'),
            gmdate('Y-m-d H:i:s'),
        ]);
    }

    public static function issueToken(PDO $pdo, string $prefix, int $userId): string
    {
        $token = bin2hex(random_bytes(24));
        $prefixToken = substr($token, 0, 12);
        $stmt = $pdo->prepare(
            "UPDATE {$prefix}users
             SET api_token_hash = ?, api_token_prefix = ?, api_token_hint = ?
             WHERE id = ?"
        );
        $stmt->execute([
            password_hash($token, PASSWORD_DEFAULT),
            $prefixToken,
            $prefixToken . '…',
            $userId,
        ]);
        return $token;
    }

    public static function userFromBearer(PDO $pdo, string $prefix, ?string $authorization): ?array
    {
        if (!$authorization || !preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $authorization, $m)) {
            return null;
        }
        $token = $m[1];
        if (strlen($token) < 12) {
            return null;
        }
        $tokenPrefix = substr($token, 0, 12);
        $stmt = $pdo->prepare(
            "SELECT * FROM {$prefix}users WHERE api_token_prefix = ? LIMIT 5"
        );
        $stmt->execute([$tokenPrefix]);
        foreach ($stmt->fetchAll() as $row) {
            if (!empty($row['api_token_hash']) && password_verify($token, (string) $row['api_token_hash'])) {
                return $row;
            }
        }
        return null;
    }

    public static function login(PDO $pdo, string $prefix, string $email, string $password): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM {$prefix}users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, (string) $row['password_hash'])) {
            return null;
        }
        return $row;
    }

    public static function hashClaim(string $secret, string $salt): string
    {
        return hash_hmac('sha256', $secret, $salt);
    }

    public static function verifyClaim(string $secret, string $hash, string $salt): bool
    {
        return hash_equals($hash, self::hashClaim($secret, $salt));
    }
}
