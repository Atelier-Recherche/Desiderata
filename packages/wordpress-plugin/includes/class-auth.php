<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Desiderata_Auth
{
    private const META_TOKEN_HASH = 'desiderata_api_token_hash';
    private const META_TOKEN_HINT = 'desiderata_api_token_hint';
    private const META_TOKEN_PREFIX = 'desiderata_api_token_prefix';

    public const OPTION_REGISTRATION = 'desiderata_registration_open';
    public const OPTION_TURNSTILE_SITE = 'desiderata_turnstile_site_key';
    public const OPTION_TURNSTILE_SECRET = 'desiderata_turnstile_secret_key';

    public static function init(): void
    {
        if (get_option(self::OPTION_REGISTRATION, null) === null) {
            add_option(self::OPTION_REGISTRATION, '1');
        }
        // Anciennes installs : retirer la cap du rôle subscriber (droits WP trop larges).
        $subscriber = get_role('subscriber');
        if ($subscriber && $subscriber->has_cap('desiderata_manage')) {
            $subscriber->remove_cap('desiderata_manage');
        }
    }

    public static function registration_open(): bool
    {
        return get_option(self::OPTION_REGISTRATION, '1') === '1';
    }

    public static function set_registration_open(bool $open): void
    {
        update_option(self::OPTION_REGISTRATION, $open ? '1' : '0');
    }

    public static function turnstile_site_key(): string
    {
        $v = get_option(self::OPTION_TURNSTILE_SITE, '');
        return is_string($v) ? trim($v) : '';
    }

    public static function turnstile_secret_key(): string
    {
        $v = get_option(self::OPTION_TURNSTILE_SECRET, '');
        return is_string($v) ? trim($v) : '';
    }

    public static function turnstile_enabled(): bool
    {
        return self::turnstile_site_key() !== '' && self::turnstile_secret_key() !== '';
    }

    public static function set_turnstile_keys(string $site_key, string $secret_key): void
    {
        update_option(self::OPTION_TURNSTILE_SITE, trim($site_key));
        // Secret vide = conserver l’existant (champ password non renvoyé).
        if ($secret_key !== '') {
            update_option(self::OPTION_TURNSTILE_SECRET, trim($secret_key));
        }
    }

    /**
     * Valide un jeton Turnstile auprès de Cloudflare.
     * Si Turnstile n’est pas configuré, accepte (pas de blocage).
     */
    public static function verify_turnstile(string $token, ?string $remote_ip = null): bool
    {
        if (!self::turnstile_enabled()) {
            return true;
        }
        if ($token === '' || strlen($token) > 2048) {
            return false;
        }

        $body = [
            'secret' => self::turnstile_secret_key(),
            'response' => $token,
        ];
        if ($remote_ip) {
            $body['remoteip'] = $remote_ip;
        }

        $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'timeout' => 12,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($body),
        ]);
        if (is_wp_error($response)) {
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);
        if ($code !== 200 || !is_array($data)) {
            return false;
        }
        return !empty($data['success']);
    }

    public static function issue_token(int $user_id): string
    {
        $token = Desiderata_Database::generate_token(24);
        $prefix = substr($token, 0, 12);
        update_user_meta($user_id, self::META_TOKEN_HASH, wp_hash_password($token));
        update_user_meta($user_id, self::META_TOKEN_HINT, $prefix . '…');
        update_user_meta($user_id, self::META_TOKEN_PREFIX, $prefix);
        return $token;
    }

    public static function revoke_token(int $user_id): void
    {
        delete_user_meta($user_id, self::META_TOKEN_HASH);
        delete_user_meta($user_id, self::META_TOKEN_HINT);
        delete_user_meta($user_id, self::META_TOKEN_PREFIX);
    }

    /** Révoque jeton API + capacité Desiderata (compte WP conservé). */
    public static function revoke_access(int $user_id): void
    {
        self::revoke_token($user_id);
        $user = new WP_User($user_id);
        if ($user->exists()) {
            $user->remove_cap('desiderata_manage');
        }
    }

    public static function grant_access(int $user_id): void
    {
        $user = new WP_User($user_id);
        if ($user->exists()) {
            $user->add_cap('desiderata_manage');
        }
    }

    /** @return list<WP_User> */
    public static function list_desiderata_users(): array
    {
        $users = get_users([
            'capability' => 'desiderata_manage',
            'orderby' => 'registered',
            'order' => 'DESC',
            'number' => 500,
        ]);
        return is_array($users) ? $users : [];
    }

    public static function token_hint(int $user_id): string
    {
        $hint = get_user_meta($user_id, self::META_TOKEN_HINT, true);
        return is_string($hint) ? $hint : '';
    }

    public static function validate_bearer(?string $authorization): ?WP_User
    {
        if (!$authorization || !preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $authorization, $m)) {
            return null;
        }
        $token = $m[1];
        return self::user_from_token($token);
    }

    public static function user_from_token(string $token): ?WP_User
    {
        if ($token === '' || strlen($token) < 12) {
            return null;
        }

        $prefix = substr($token, 0, 12);
        $users = get_users([
            'meta_key' => self::META_TOKEN_PREFIX,
            'meta_value' => $prefix,
            'number' => 5,
            'fields' => ['ID'],
        ]);

        foreach ($users as $user_row) {
            $user_id = (int) $user_row->ID;
            $hash = get_user_meta($user_id, self::META_TOKEN_HASH, true);
            if (!is_string($hash) || $hash === '') {
                continue;
            }
            if (wp_check_password($token, $hash, $user_id)) {
                $user = get_user_by('id', $user_id);
                if ($user instanceof WP_User && user_can($user, 'desiderata_manage')) {
                    return $user;
                }
            }
        }

        return null;
    }

    public static function current_api_user(WP_REST_Request $request): ?WP_User
    {
        $auth = $request->get_header('authorization');
        $user = self::validate_bearer($auth);
        if ($user) {
            return $user;
        }

        if (is_user_logged_in() && current_user_can('desiderata_manage')) {
            return wp_get_current_user();
        }

        return null;
    }

    public static function hash_claim_secret(string $secret): string
    {
        return hash_hmac('sha256', $secret, wp_salt('auth'));
    }

    public static function verify_claim_secret(string $secret, string $hash): bool
    {
        if ($secret === '' || $hash === '') {
            return false;
        }
        return hash_equals($hash, self::hash_claim_secret($secret));
    }
}
