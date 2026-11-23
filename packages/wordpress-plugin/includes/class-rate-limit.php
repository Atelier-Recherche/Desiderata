<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Desiderata_Rate_Limit
{
    public static function check(string $bucket, int $max, int $window_seconds): bool
    {
        $ip = self::client_ip();
        $key = 'desiderata_rl_' . md5($bucket . '|' . $ip);
        $data = get_transient($key);

        if (!is_array($data) || !isset($data['count'], $data['start'])) {
            set_transient($key, ['count' => 1, 'start' => time()], $window_seconds);
            return true;
        }

        if ((time() - (int) $data['start']) >= $window_seconds) {
            set_transient($key, ['count' => 1, 'start' => time()], $window_seconds);
            return true;
        }

        if ((int) $data['count'] >= $max) {
            return false;
        }

        $data['count'] = (int) $data['count'] + 1;
        set_transient($key, $data, $window_seconds);
        return true;
    }

    public static function client_ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return is_string($ip) ? $ip : '0.0.0.0';
    }
}
