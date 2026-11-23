<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Desiderata_Rest_Api
{
    private const NS = 'desiderata/v1';

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_filter('rest_pre_serve_request', [self::class, 'maybe_cors'], 10, 4);
    }

    /**
     * @param mixed $served
     * @return mixed
     */
    public static function maybe_cors($served, $result, $request, $server)
    {
        if (!$request instanceof WP_REST_Request) {
            return $served;
        }
        $route = $request->get_route();
        if (!is_string($route) || strpos($route, '/' . self::NS) !== 0) {
            return $served;
        }

        $origin = get_http_origin();
        $allowed = [home_url()];
        if ($origin && (in_array($origin, $allowed, true) || self::is_extension_origin($origin))) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Authorization, Content-Type');
            header('Access-Control-Allow-Credentials: true');
        }

        if ($request->get_method() === 'OPTIONS') {
            status_header(204);
            exit;
        }

        return $served;
    }

    private static function is_extension_origin(string $origin): bool
    {
        return (bool) preg_match('#^(chrome|moz)-extension://#', $origin);
    }

    public static function register_routes(): void
    {
        register_rest_route(self::NS, '/auth/status', [
            'methods' => 'GET',
            'callback' => [self::class, 'auth_status'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NS, '/auth/register', [
            'methods' => 'POST',
            'callback' => [self::class, 'register_account'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NS, '/auth/login', [
            'methods' => 'POST',
            'callback' => [self::class, 'login_account'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NS, '/auth/token', [
            'methods' => 'POST',
            'callback' => [self::class, 'issue_token'],
            'permission_callback' => static function (): bool {
                return is_user_logged_in() && current_user_can('desiderata_manage');
            },
        ]);

        register_rest_route(self::NS, '/me/wishlists', [
            'methods' => 'GET',
            'callback' => [self::class, 'list_my_wishlists'],
            'permission_callback' => [self::class, 'require_bearer'],
        ]);

        register_rest_route(self::NS, '/wishlists', [
            'methods' => 'POST',
            'callback' => [self::class, 'create_wishlist'],
            'permission_callback' => [self::class, 'require_bearer'],
        ]);

        register_rest_route(self::NS, '/wishlists/(?P<id>\d+)/sync', [
            'methods' => 'PUT',
            'callback' => [self::class, 'sync_wishlist'],
            'permission_callback' => [self::class, 'require_bearer'],
            'args' => [
                'id' => ['type' => 'integer', 'required' => true],
            ],
        ]);

        register_rest_route(self::NS, '/wishlists/(?P<slug>[a-z0-9]+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_public_wishlist'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NS, '/wishlists/(?P<slug>[a-z0-9]+)/items/(?P<item>\d+)/claim', [
            [
                'methods' => 'POST',
                'callback' => [self::class, 'claim_item'],
                'permission_callback' => '__return_true',
            ],
            [
                'methods' => 'DELETE',
                'callback' => [self::class, 'release_item'],
                'permission_callback' => '__return_true',
            ],
        ]);

        register_rest_route(self::NS, '/wishlists/(?P<slug>[a-z0-9]+)/chat/join', [
            'methods' => 'POST',
            'callback' => [self::class, 'chat_join'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NS, '/wishlists/(?P<slug>[a-z0-9]+)/chat/messages', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'chat_list_messages'],
                'permission_callback' => '__return_true',
            ],
            [
                'methods' => 'POST',
                'callback' => [self::class, 'chat_post_message'],
                'permission_callback' => '__return_true',
            ],
        ]);

        register_rest_route(self::NS, '/wishlists/(?P<slug>[a-z0-9]+)/chat/close', [
            'methods' => 'POST',
            'callback' => [self::class, 'chat_close'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NS, '/wishlists/(?P<slug>[a-z0-9]+)/rtc/signals', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'rtc_list_signals'],
                'permission_callback' => '__return_true',
            ],
            [
                'methods' => 'POST',
                'callback' => [self::class, 'rtc_post_signal'],
                'permission_callback' => '__return_true',
            ],
        ]);

        register_rest_route(self::NS, '/meta/isbn/(?P<isbn>[0-9Xx\-]+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'lookup_isbn'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NS, '/wishlists/(?P<id>\d+)/items/(?P<item>\d+)', [
            'methods' => 'PATCH',
            'callback' => [self::class, 'patch_item'],
            'permission_callback' => [self::class, 'require_bearer'],
        ]);

        register_rest_route(self::NS, '/wishlists/(?P<id>\d+)/items/(?P<item>\d+)/enrich', [
            'methods' => 'POST',
            'callback' => [self::class, 'enrich_item'],
            'permission_callback' => [self::class, 'require_bearer'],
        ]);

        register_rest_route(self::NS, '/wishlists/(?P<id>\d+)/import/zotero', [
            'methods' => 'POST',
            'callback' => [self::class, 'import_zotero'],
            'permission_callback' => [self::class, 'require_bearer'],
        ]);

        register_rest_route(self::NS, '/wishlists/(?P<slug>[a-z0-9]+)/export/zotero', [
            'methods' => 'GET',
            'callback' => [self::class, 'export_zotero'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function require_bearer(WP_REST_Request $request): bool
    {
        return Desiderata_Auth::current_api_user($request) !== null;
    }

    public static function auth_status(): WP_REST_Response
    {
        return new WP_REST_Response([
            'registration_open' => Desiderata_Auth::registration_open(),
            'turnstile_required' => Desiderata_Auth::turnstile_enabled(),
            'turnstile_site_key' => Desiderata_Auth::turnstile_enabled()
                ? Desiderata_Auth::turnstile_site_key()
                : '',
            'turnstile_url' => Desiderata_Auth::turnstile_enabled()
                ? home_url('/desiderata/turnstile/')
                : '',
            'version' => DESIDERATA_VERSION,
        ]);
    }

    public static function register_account(WP_REST_Request $request): WP_REST_Response
    {
        if (!Desiderata_Auth::registration_open()) {
            return self::error('registration_closed', 'Création de compte désactivée sur ce serveur.', 403);
        }
        if (!Desiderata_Rate_Limit::check('auth_register', 30, HOUR_IN_SECONDS)) {
            return self::error('rate_limited', 'Trop de tentatives.', 429);
        }

        $json = $request->get_json_params();
        if (!is_array($json)) {
            $json = [];
        }

        $email_raw = (string) ($request->get_param('email') ?? ($json['email'] ?? ''));
        $password = (string) ($request->get_param('password') ?? ($json['password'] ?? ''));
        $display = sanitize_text_field((string) ($request->get_param('display_name') ?? ($json['display_name'] ?? '')));
        $turnstile = (string) ($request->get_param('turnstile_token') ?? ($json['turnstile_token'] ?? ''));
        $email = sanitize_email($email_raw);

        if (Desiderata_Auth::turnstile_enabled()) {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null;
            if (!Desiderata_Auth::verify_turnstile($turnstile, $ip)) {
                return self::error('turnstile_failed', 'Vérification anti-bot échouée. Réessayez.', 403);
            }
        }

        if ($email === '' || !is_email($email)) {
            return self::error('invalid', 'E-mail invalide.', 400);
        }
        if (strlen($password) < 8) {
            return self::error('invalid', 'Mot de passe trop court (8 caractères min.).', 400);
        }
        if (email_exists($email)) {
            return self::error('exists', 'Un compte existe déjà avec cet e-mail.', 409);
        }

        // Ne pas utiliser l’e-mail comme user_login (souvent rejeté / sanitizé par WP).
        $login = self::unique_username_from_email($email);
        if ($login === '') {
            return self::error('invalid', 'Impossible de générer un identifiant.', 400);
        }

        $display_name = $display !== '' ? $display : $email;
        $user_id = self::insert_user_bypassing_hooks($login, $email, $password, $display_name);
        if ($user_id instanceof WP_Error) {
            return self::error(
                'invalid',
                $user_id->get_error_message() !== ''
                    ? $user_id->get_error_message()
                    : 'Création de compte refusée.',
                400
            );
        }

        $token = Desiderata_Auth::issue_token($user_id);
        return new WP_REST_Response([
            'ok' => true,
            'user_id' => $user_id,
            'email' => $email,
            'token' => $token,
            'hint' => Desiderata_Auth::token_hint($user_id),
        ], 201);
    }

    /**
     * Insertion utilisateur minimale (évite SMTP / plugins branchés sur wp_insert_user).
     *
     * @return int|WP_Error
     */
    private static function insert_user_bypassing_hooks(
        string $login,
        string $email,
        string $password,
        string $display_name
    ) {
        global $wpdb;

        if (username_exists($login) || email_exists($email)) {
            return new WP_Error('exists', 'Compte déjà existant.');
        }

        $hash = wp_hash_password($password);
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->insert(
            $wpdb->users,
            [
                'user_login' => $login,
                'user_pass' => $hash,
                'user_nicename' => $login,
                'user_email' => $email,
                'user_url' => '',
                'user_registered' => $now,
                'user_activation_key' => '',
                'user_status' => 0,
                'display_name' => mb_substr($display_name, 0, 250),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s']
        );

        if (!$inserted) {
            return new WP_Error('db_error', 'Échec SQL lors de la création du compte.');
        }

        $user_id = (int) $wpdb->insert_id;
        if ($user_id < 1) {
            return new WP_Error('db_error', 'Identifiant utilisateur invalide.');
        }

        $cap_key = $wpdb->get_blog_prefix() . 'capabilities';
        $level_key = $wpdb->get_blog_prefix() . 'user_level';
        update_user_meta($user_id, $cap_key, ['subscriber' => true]);
        update_user_meta($user_id, $level_key, 0);
        update_user_meta($user_id, 'nickname', $display_name);
        update_user_meta($user_id, 'show_admin_bar_front', 'false');

        // Capacité Desiderata sans repasser par tous les hooks add_cap si possible.
        $caps = get_user_meta($user_id, $cap_key, true);
        if (!is_array($caps)) {
            $caps = [];
        }
        $caps['subscriber'] = true;
        // Les capabilities custom WP sont stockées via WP_User::add_cap dans usermeta "wp_capabilities"
        // pour les caps non-rôles… En pratique add_cap ajoute une entrée booléenne:
        $user = new WP_User($user_id);
        $user->add_cap('desiderata_manage');

        clean_user_cache($user_id);
        return $user_id;
    }

    private static function unique_username_from_email(string $email): string
    {
        $local = strstr($email, '@', true);
        $base = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $local) ?? '');
        if ($base === '') {
            $base = 'desiderata';
        }
        $base = substr($base, 0, 40);

        for ($i = 0; $i < 12; $i++) {
            $candidate = $i === 0 ? $base : ($base . wp_generate_password(4, false, false));
            $candidate = sanitize_user($candidate, true);
            if ($candidate !== '' && !username_exists($candidate)) {
                return $candidate;
            }
        }
        return 'd' . substr(md5($email . microtime(true)), 0, 12);
    }
    public static function login_account(WP_REST_Request $request): WP_REST_Response
    {
        if (!Desiderata_Rate_Limit::check('auth_login', 20, HOUR_IN_SECONDS)) {
            return self::error('rate_limited', 'Trop de tentatives.', 429);
        }

        $json = $request->get_json_params();
        if (!is_array($json)) {
            $json = [];
        }
        $login = sanitize_text_field((string) ($request->get_param('email') ?? ($json['email'] ?? '')));
        if ($login === '') {
            $login = sanitize_text_field((string) ($request->get_param('login') ?? ($json['login'] ?? '')));
        }
        $password = (string) ($request->get_param('password') ?? ($json['password'] ?? ''));

        // Connexion par e-mail → résoudre vers user_login.
        if (is_email($login)) {
            $by_email = get_user_by('email', $login);
            if ($by_email instanceof WP_User) {
                $login = $by_email->user_login;
            }
        }

        $user = wp_authenticate($login, $password);
        if (is_wp_error($user)) {
            return self::error('unauthorized', 'Identifiants invalides.', 401);
        }
        if (!user_can($user, 'desiderata_manage')) {
            $user->add_cap('desiderata_manage');
        }

        $token = Desiderata_Auth::issue_token((int) $user->ID);
        return new WP_REST_Response([
            'ok' => true,
            'user_id' => (int) $user->ID,
            'email' => $user->user_email,
            'token' => $token,
            'hint' => Desiderata_Auth::token_hint((int) $user->ID),
        ]);
    }

    public static function issue_token(WP_REST_Request $request): WP_REST_Response
    {
        if (!Desiderata_Rate_Limit::check('auth_token', 10, HOUR_IN_SECONDS)) {
            return self::error('rate_limited', 'Trop de tentatives.', 429);
        }
        $token = Desiderata_Auth::issue_token(get_current_user_id());
        return new WP_REST_Response([
            'token' => $token,
            'hint' => Desiderata_Auth::token_hint(get_current_user_id()),
        ], 201);
    }

    public static function list_my_wishlists(WP_REST_Request $request): WP_REST_Response
    {
        $user = Desiderata_Auth::current_api_user($request);
        if (!$user) {
            return self::error('unauthorized', 'Non autorisé.', 401);
        }
        global $wpdb;
        $table = Desiderata_Database::table('wishlists');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, slug, title, updated_at FROM {$table} WHERE user_id = %d ORDER BY id ASC",
            $user->ID
        ), ARRAY_A);

        $out = [];
        foreach ((array) $rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'slug' => $row['slug'],
                'title' => $row['title'],
                'updated_at' => $row['updated_at'],
                'public_url' => home_url('/wishlist/' . $row['slug'] . '/'),
            ];
        }
        return new WP_REST_Response(['wishlists' => $out]);
    }

    public static function create_wishlist(WP_REST_Request $request): WP_REST_Response
    {
        $user = Desiderata_Auth::current_api_user($request);
        if (!$user) {
            return self::error('unauthorized', 'Non autorisé.', 401);
        }

        global $wpdb;
        $table = Desiderata_Database::table('wishlists');
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d",
            $user->ID
        ));
        if ($count >= Desiderata_Database::MAX_WISHLISTS_PER_USER) {
            return self::error('limit', 'Limite de listes atteinte.', 403);
        }

        $title = sanitize_text_field((string) $request->get_param('title'));
        if ($title === '') {
            $title = 'Wishlist';
        }

        $slug = self::unique_slug();
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert(
            $table,
            [
                'user_id' => $user->ID,
                'slug' => $slug,
                'title' => $title,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );

        $id = (int) $wpdb->insert_id;
        return new WP_REST_Response([
            'id' => $id,
            'slug' => $slug,
            'title' => $title,
            'public_url' => home_url('/wishlist/' . $slug . '/'),
        ], 201);
    }

    public static function sync_wishlist(WP_REST_Request $request): WP_REST_Response
    {
        $user = Desiderata_Auth::current_api_user($request);
        if (!$user) {
            return self::error('unauthorized', 'Non autorisé.', 401);
        }

        $wishlist = self::get_wishlist_by_id((int) $request['id']);
        if (!$wishlist || (int) $wishlist['user_id'] !== (int) $user->ID) {
            return self::error('not_found', 'Liste introuvable.', 404);
        }

        $items = $request->get_param('items');
        if (!is_array($items)) {
            return self::error('invalid', 'items doit être un tableau.', 400);
        }
        if (count($items) > Desiderata_Database::MAX_ITEMS_PER_WISHLIST) {
            return self::error('limit', 'Trop d’items.', 400);
        }

        $title = $request->get_param('title');
        if (is_string($title) && $title !== '') {
            $wishlist['title'] = sanitize_text_field($title);
        }

        $normalized = [];
        $order = 0;
        foreach ($items as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $url = self::sanitize_http_url((string) ($raw['url'] ?? ''));
            if ($url === null) {
                continue;
            }
            $bookmark = sanitize_text_field((string) ($raw['bookmark_guid'] ?? ''));
            $item_title = sanitize_text_field((string) ($raw['title'] ?? ''));
            if ($item_title === '') {
                $item_title = $url;
            }
            $meta = null;
            if (isset($raw['meta'])) {
                $meta = Desiderata_Meta::encode(is_array($raw['meta']) ? $raw['meta'] : (array) $raw['meta']);
            } elseif (!empty($raw['isbn'])) {
                $meta = Desiderata_Meta::encode(['isbn' => (string) $raw['isbn']]);
            }
            $normalized[] = [
                'bookmark_guid' => $bookmark !== '' ? $bookmark : ('u' . md5($url)),
                'url' => $url,
                'title' => mb_substr($item_title, 0, 512),
                'meta' => $meta,
                'sort_order' => $order++,
            ];
        }

        global $wpdb;
        $items_table = Desiderata_Database::table('wishlist_items');
        $wishlists = Desiderata_Database::table('wishlists');
        $wishlist_id = (int) $wishlist['id'];

        $existing = $wpdb->get_results($wpdb->prepare(
            "SELECT id, bookmark_guid, claimed_by, claimed_at, claim_hash FROM {$items_table} WHERE wishlist_id = %d",
            $wishlist_id
        ), ARRAY_A);

        $by_guid = [];
        foreach ((array) $existing as $row) {
            $by_guid[$row['bookmark_guid']] = $row;
        }

        $seen = [];
        $now = gmdate('Y-m-d H:i:s');

        foreach ($normalized as $item) {
            $guid = $item['bookmark_guid'];
            $seen[$guid] = true;
            if (isset($by_guid[$guid])) {
                $wpdb->update(
                    $items_table,
                    [
                        'url' => $item['url'],
                        'title' => $item['title'],
                        'meta' => $item['meta'],
                        'sort_order' => $item['sort_order'],
                        'updated_at' => $now,
                    ],
                    ['id' => (int) $by_guid[$guid]['id']],
                    ['%s', '%s', '%s', '%d', '%s'],
                    ['%d']
                );
            } else {
                $wpdb->insert(
                    $items_table,
                    [
                        'wishlist_id' => $wishlist_id,
                        'bookmark_guid' => $guid,
                        'url' => $item['url'],
                        'title' => $item['title'],
                        'meta' => $item['meta'],
                        'sort_order' => $item['sort_order'],
                        'updated_at' => $now,
                    ],
                    ['%d', '%s', '%s', '%s', '%s', '%d', '%s']
                );
            }
        }

        foreach ((array) $existing as $row) {
            if (!isset($seen[$row['bookmark_guid']])) {
                $wpdb->delete($items_table, ['id' => (int) $row['id']], ['%d']);
            }
        }

        $wpdb->update(
            $wishlists,
            [
                'title' => $wishlist['title'],
                'updated_at' => $now,
            ],
            ['id' => $wishlist_id],
            ['%s', '%s'],
            ['%d']
        );

        return new WP_REST_Response([
            'ok' => true,
            'id' => $wishlist_id,
            'slug' => $wishlist['slug'],
            'item_count' => count($normalized),
            'updated_at' => $now,
            'public_url' => home_url('/wishlist/' . $wishlist['slug'] . '/'),
        ]);
    }

    public static function get_public_wishlist(WP_REST_Request $request): WP_REST_Response
    {
        $wishlist = self::get_wishlist_by_slug((string) $request['slug']);
        if (!$wishlist) {
            return self::error('not_found', 'Liste introuvable.', 404);
        }

        global $wpdb;
        $items_table = Desiderata_Database::table('wishlist_items');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, bookmark_guid, url, title, meta, sort_order, claimed_by, claimed_at, updated_at
             FROM {$items_table} WHERE wishlist_id = %d ORDER BY sort_order ASC, id ASC",
            (int) $wishlist['id']
        ), ARRAY_A);

        $items = [];
        foreach ((array) $rows as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'bookmark_guid' => $row['bookmark_guid'],
                'url' => $row['url'],
                'title' => $row['title'],
                'meta' => Desiderata_Meta::decode($row['meta'] ?? null),
                'sort_order' => (int) $row['sort_order'],
                'claimed_by' => $row['claimed_by'],
                'claimed_at' => $row['claimed_at'],
                'available' => $row['claimed_by'] === null || $row['claimed_by'] === '',
            ];
        }

        return new WP_REST_Response([
            'id' => (int) $wishlist['id'],
            'slug' => $wishlist['slug'],
            'title' => $wishlist['title'],
            'updated_at' => $wishlist['updated_at'],
            'items' => $items,
        ]);
    }

    public static function claim_item(WP_REST_Request $request): WP_REST_Response
    {
        $slug = (string) $request['slug'];
        if (!Desiderata_Rate_Limit::check('claim_' . $slug, 30, HOUR_IN_SECONDS)) {
            return self::error('rate_limited', 'Trop de réservations.', 429);
        }

        $wishlist = self::get_wishlist_by_slug($slug);
        if (!$wishlist) {
            return self::error('not_found', 'Liste introuvable.', 404);
        }

        $item = self::get_item((int) $request['item'], (int) $wishlist['id']);
        if (!$item) {
            return self::error('not_found', 'Item introuvable.', 404);
        }

        if (!empty($item['claimed_by'])) {
            return self::error('already_claimed', 'Déjà réservé.', 409);
        }

        $name = sanitize_text_field((string) $request->get_param('name'));
        if ($name === '') {
            $name = 'Anonyme';
        }
        $name = mb_substr($name, 0, 120);

        $secret = Desiderata_Database::generate_token(16);
        $hash = Desiderata_Auth::hash_claim_secret($secret);
        $now = gmdate('Y-m-d H:i:s');

        global $wpdb;
        $table = Desiderata_Database::table('wishlist_items');
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET claimed_by = %s, claimed_at = %s, claim_hash = %s, updated_at = %s
             WHERE id = %d AND wishlist_id = %d AND (claimed_by IS NULL OR claimed_by = '')",
            $name,
            $now,
            $hash,
            $now,
            (int) $item['id'],
            (int) $wishlist['id']
        ));

        if (!$updated) {
            return self::error('already_claimed', 'Déjà réservé.', 409);
        }

        return new WP_REST_Response([
            'ok' => true,
            'item_id' => (int) $item['id'],
            'claimed_by' => $name,
            'claimed_at' => $now,
            'claim_secret' => $secret,
        ], 201);
    }

    public static function release_item(WP_REST_Request $request): WP_REST_Response
    {
        $slug = (string) $request['slug'];
        if (!Desiderata_Rate_Limit::check('release_' . $slug, 60, HOUR_IN_SECONDS)) {
            return self::error('rate_limited', 'Trop de requêtes.', 429);
        }

        $wishlist = self::get_wishlist_by_slug($slug);
        if (!$wishlist) {
            return self::error('not_found', 'Liste introuvable.', 404);
        }

        $item = self::get_item((int) $request['item'], (int) $wishlist['id']);
        if (!$item) {
            return self::error('not_found', 'Item introuvable.', 404);
        }

        if (empty($item['claimed_by'])) {
            return new WP_REST_Response(['ok' => true, 'released' => false]);
        }

        $owner = Desiderata_Auth::current_api_user($request);
        $is_owner = $owner && (int) $owner->ID === (int) $wishlist['user_id'];

        $secret = (string) ($request->get_param('claim_secret') ?? '');
        $allowed = $is_owner || (
            $secret !== ''
            && !empty($item['claim_hash'])
            && Desiderata_Auth::verify_claim_secret($secret, (string) $item['claim_hash'])
        );

        if (!$allowed) {
            return self::error('forbidden', 'Secret invalide.', 403);
        }

        global $wpdb;
        $table = Desiderata_Database::table('wishlist_items');
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET claimed_by = NULL, claimed_at = NULL, claim_hash = NULL, updated_at = %s
             WHERE id = %d",
            $now,
            (int) $item['id']
        ));

        return new WP_REST_Response(['ok' => true, 'released' => true]);
    }

    public static function chat_join(WP_REST_Request $request): WP_REST_Response
    {
        $slug = (string) $request['slug'];
        if (!Desiderata_Rate_Limit::check('chat_join_' . $slug, 60, HOUR_IN_SECONDS)) {
            return self::error('rate_limited', 'Trop de requêtes.', 429);
        }

        $wishlist = self::get_wishlist_by_slug($slug);
        if (!$wishlist) {
            return self::error('not_found', 'Liste introuvable.', 404);
        }

        $participant_id = sanitize_text_field((string) $request->get_param('participant_id'));
        if ($participant_id === '' || strlen($participant_id) > 64) {
            $participant_id = wp_generate_uuid4();
        }
        $display_name = sanitize_text_field((string) $request->get_param('display_name'));
        if ($display_name === '') {
            $display_name = 'Anonyme';
        }
        $display_name = mb_substr($display_name, 0, 120);

        global $wpdb;
        $rooms = Desiderata_Database::table('chat_rooms');
        $participants = Desiderata_Database::table('chat_participants');

        $room = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$rooms} WHERE wishlist_id = %d AND status IN ('open','closing') ORDER BY id DESC LIMIT 1",
            (int) $wishlist['id']
        ), ARRAY_A);

        $now = gmdate('Y-m-d H:i:s');
        if (!$room) {
            $token = Desiderata_Database::generate_token(16);
            $wpdb->insert(
                $rooms,
                [
                    'wishlist_id' => (int) $wishlist['id'],
                    'room_token' => $token,
                    'status' => 'open',
                    'created_at' => $now,
                ],
                ['%d', '%s', '%s', '%s']
            );
            $room = [
                'id' => (int) $wpdb->insert_id,
                'room_token' => $token,
                'status' => 'open',
            ];
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$participants} WHERE room_id = %d AND participant_id = %s",
            (int) $room['id'],
            $participant_id
        ), ARRAY_A);

        if ($existing) {
            $wpdb->update(
                $participants,
                [
                    'display_name' => $display_name,
                    'last_seen_at' => $now,
                    'wants_close' => 0,
                ],
                ['id' => (int) $existing['id']],
                ['%s', '%s', '%d'],
                ['%d']
            );
        } else {
            $wpdb->insert(
                $participants,
                [
                    'room_id' => (int) $room['id'],
                    'participant_id' => $participant_id,
                    'display_name' => $display_name,
                    'last_seen_at' => $now,
                    'wants_close' => 0,
                ],
                ['%d', '%s', '%s', '%s', '%d']
            );
        }

        if (($room['status'] ?? '') === 'closing') {
            $wpdb->update($rooms, ['status' => 'open', 'closed_at' => null], ['id' => (int) $room['id']]);
            $room['status'] = 'open';
        }

        $peer_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$participants} WHERE room_id = %d",
            (int) $room['id']
        ));

        $history = self::fetch_messages((int) $room['id'], 0);

        return new WP_REST_Response([
            'room_id' => (int) $room['id'],
            'room_token' => $room['room_token'],
            'status' => $room['status'],
            'participant_id' => $participant_id,
            'display_name' => $display_name,
            'peer_count' => $peer_count,
            'messages' => $history,
        ]);
    }

    public static function chat_list_messages(WP_REST_Request $request): WP_REST_Response
    {
        $slug = (string) $request['slug'];
        $wishlist = self::get_wishlist_by_slug($slug);
        if (!$wishlist) {
            return self::error('not_found', 'Liste introuvable.', 404);
        }

        $room = self::open_room_for_wishlist((int) $wishlist['id']);
        if (!$room) {
            return new WP_REST_Response(['messages' => [], 'status' => 'none', 'peer_count' => 0]);
        }

        $participant_id = sanitize_text_field((string) $request->get_param('participant_id'));
        if ($participant_id !== '') {
            self::touch_participant((int) $room['id'], $participant_id);
        }

        $since = (int) $request->get_param('since');
        $messages = self::fetch_messages((int) $room['id'], $since);

        global $wpdb;
        $participants = Desiderata_Database::table('chat_participants');
        $peer_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$participants} WHERE room_id = %d AND last_seen_at > %s",
            (int) $room['id'],
            gmdate('Y-m-d H:i:s', time() - 120)
        ));

        return new WP_REST_Response([
            'room_id' => (int) $room['id'],
            'status' => $room['status'],
            'peer_count' => $peer_count,
            'messages' => $messages,
        ]);
    }

    public static function chat_post_message(WP_REST_Request $request): WP_REST_Response
    {
        $slug = (string) $request['slug'];
        if (!Desiderata_Rate_Limit::check('chat_msg_' . $slug, 120, HOUR_IN_SECONDS)) {
            return self::error('rate_limited', 'Trop de messages.', 429);
        }

        $wishlist = self::get_wishlist_by_slug($slug);
        if (!$wishlist) {
            return self::error('not_found', 'Liste introuvable.', 404);
        }

        $room_token = sanitize_text_field((string) $request->get_param('room_token'));
        $participant_id = sanitize_text_field((string) $request->get_param('participant_id'));
        $body = sanitize_text_field((string) $request->get_param('body'));
        $body = mb_substr($body, 0, Desiderata_Database::MAX_MESSAGE_LENGTH);

        if ($room_token === '' || $participant_id === '' || $body === '') {
            return self::error('invalid', 'Paramètres manquants.', 400);
        }

        global $wpdb;
        $rooms = Desiderata_Database::table('chat_rooms');
        $room = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$rooms} WHERE wishlist_id = %d AND room_token = %s AND status IN ('open','closing')",
            (int) $wishlist['id'],
            $room_token
        ), ARRAY_A);

        if (!$room) {
            return self::error('not_found', 'Salon introuvable.', 404);
        }

        $participants = Desiderata_Database::table('chat_participants');
        $part = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$participants} WHERE room_id = %d AND participant_id = %s",
            (int) $room['id'],
            $participant_id
        ), ARRAY_A);
        if (!$part) {
            return self::error('forbidden', 'Participant inconnu.', 403);
        }

        self::touch_participant((int) $room['id'], $participant_id);

        $messages = Desiderata_Database::table('chat_messages');
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$messages} WHERE room_id = %d",
            (int) $room['id']
        ));

        if ($count >= Desiderata_Database::MAX_CHAT_MESSAGES) {
            $oldest = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$messages} WHERE room_id = %d ORDER BY id ASC LIMIT 1",
                (int) $room['id']
            ));
            if ($oldest) {
                $wpdb->delete($messages, ['id' => $oldest], ['%d']);
            }
        }

        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert(
            $messages,
            [
                'room_id' => (int) $room['id'],
                'participant_id' => $participant_id,
                'body' => $body,
                'created_at' => $now,
            ],
            ['%d', '%s', '%s', '%s']
        );

        $id = (int) $wpdb->insert_id;
        return new WP_REST_Response([
            'id' => $id,
            'room_id' => (int) $room['id'],
            'participant_id' => $participant_id,
            'display_name' => $part['display_name'],
            'body' => $body,
            'created_at' => $now,
        ], 201);
    }

    public static function chat_close(WP_REST_Request $request): WP_REST_Response
    {
        $slug = (string) $request['slug'];
        $wishlist = self::get_wishlist_by_slug($slug);
        if (!$wishlist) {
            return self::error('not_found', 'Liste introuvable.', 404);
        }

        $room_token = sanitize_text_field((string) $request->get_param('room_token'));
        $participant_id = sanitize_text_field((string) $request->get_param('participant_id'));
        if ($room_token === '' || $participant_id === '') {
            return self::error('invalid', 'Paramètres manquants.', 400);
        }

        global $wpdb;
        $rooms = Desiderata_Database::table('chat_rooms');
        $room = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$rooms} WHERE wishlist_id = %d AND room_token = %s AND status IN ('open','closing')",
            (int) $wishlist['id'],
            $room_token
        ), ARRAY_A);
        if (!$room) {
            return self::error('not_found', 'Salon introuvable.', 404);
        }

        $participants = Desiderata_Database::table('chat_participants');
        $wpdb->update(
            $participants,
            ['wants_close' => 1, 'last_seen_at' => gmdate('Y-m-d H:i:s')],
            [
                'room_id' => (int) $room['id'],
                'participant_id' => $participant_id,
            ],
            ['%d', '%s'],
            ['%d', '%s']
        );

        $active = $wpdb->get_results($wpdb->prepare(
            "SELECT participant_id, wants_close FROM {$participants}
             WHERE room_id = %d AND last_seen_at > %s",
            (int) $room['id'],
            gmdate('Y-m-d H:i:s', time() - 180)
        ), ARRAY_A);

        $all_want = !empty($active);
        foreach ((array) $active as $row) {
            if (!(int) $row['wants_close']) {
                $all_want = false;
                break;
            }
        }

        // Single active participant can close alone; otherwise all active must agree.
        if (count((array) $active) <= 1) {
            $all_want = true;
        }

        if ($all_want) {
            Desiderata_Purge::close_and_purge((int) $room['id']);
            return new WP_REST_Response(['ok' => true, 'closed' => true, 'status' => 'closed']);
        }

        $wpdb->update($rooms, ['status' => 'closing'], ['id' => (int) $room['id']], ['%s'], ['%d']);
        return new WP_REST_Response(['ok' => true, 'closed' => false, 'status' => 'closing']);
    }

    public static function rtc_post_signal(WP_REST_Request $request): WP_REST_Response
    {
        $slug = (string) $request['slug'];
        if (!Desiderata_Rate_Limit::check('rtc_' . $slug, 300, HOUR_IN_SECONDS)) {
            return self::error('rate_limited', 'Trop de signaux.', 429);
        }

        $wishlist = self::get_wishlist_by_slug($slug);
        if (!$wishlist) {
            return self::error('not_found', 'Liste introuvable.', 404);
        }

        $room_token = sanitize_text_field((string) $request->get_param('room_token'));
        $from_id = sanitize_text_field((string) $request->get_param('from_id'));
        $to_id = $request->get_param('to_id');
        $to_id = is_string($to_id) ? sanitize_text_field($to_id) : null;
        $type = sanitize_text_field((string) $request->get_param('type'));
        $payload = $request->get_param('payload');

        if (!in_array($type, ['offer', 'answer', 'ice'], true)) {
            return self::error('invalid', 'Type de signal invalide.', 400);
        }
        if ($room_token === '' || $from_id === '' || $payload === null) {
            return self::error('invalid', 'Paramètres manquants.', 400);
        }

        global $wpdb;
        $rooms = Desiderata_Database::table('chat_rooms');
        $room = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$rooms} WHERE wishlist_id = %d AND room_token = %s AND status IN ('open','closing')",
            (int) $wishlist['id'],
            $room_token
        ), ARRAY_A);
        if (!$room) {
            return self::error('not_found', 'Salon introuvable.', 404);
        }

        $json = is_string($payload) ? $payload : wp_json_encode($payload);
        if (!is_string($json) || strlen($json) > 65535) {
            return self::error('invalid', 'Payload trop volumineux.', 400);
        }

        $signals = Desiderata_Database::table('rtc_signals');
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert(
            $signals,
            [
                'room_id' => (int) $room['id'],
                'from_id' => $from_id,
                'to_id' => $to_id,
                'type' => $type,
                'payload' => $json,
                'created_at' => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s']
        );

        return new WP_REST_Response(['ok' => true, 'id' => (int) $wpdb->insert_id], 201);
    }

    public static function rtc_list_signals(WP_REST_Request $request): WP_REST_Response
    {
        $slug = (string) $request['slug'];
        $wishlist = self::get_wishlist_by_slug($slug);
        if (!$wishlist) {
            return self::error('not_found', 'Liste introuvable.', 404);
        }

        $room_token = sanitize_text_field((string) $request->get_param('room_token'));
        $participant_id = sanitize_text_field((string) $request->get_param('participant_id'));
        $since = (int) $request->get_param('since');

        if ($room_token === '' || $participant_id === '') {
            return self::error('invalid', 'Paramètres manquants.', 400);
        }

        global $wpdb;
        $rooms = Desiderata_Database::table('chat_rooms');
        $room = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$rooms} WHERE wishlist_id = %d AND room_token = %s AND status IN ('open','closing')",
            (int) $wishlist['id'],
            $room_token
        ), ARRAY_A);
        if (!$room) {
            return new WP_REST_Response(['signals' => []]);
        }

        $signals = Desiderata_Database::table('rtc_signals');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, from_id, to_id, type, payload, created_at FROM {$signals}
             WHERE room_id = %d AND id > %d AND from_id <> %s
               AND (to_id IS NULL OR to_id = '' OR to_id = %s)
             ORDER BY id ASC LIMIT 100",
            (int) $room['id'],
            $since,
            $participant_id,
            $participant_id
        ), ARRAY_A);

        $out = [];
        foreach ((array) $rows as $row) {
            $decoded = json_decode((string) $row['payload'], true);
            $out[] = [
                'id' => (int) $row['id'],
                'from_id' => $row['from_id'],
                'to_id' => $row['to_id'],
                'type' => $row['type'],
                'payload' => $decoded !== null ? $decoded : $row['payload'],
                'created_at' => $row['created_at'],
            ];
        }

        return new WP_REST_Response(['signals' => $out]);
    }

    public static function lookup_isbn(WP_REST_Request $request): WP_REST_Response
    {
        if (!Desiderata_Rate_Limit::check('isbn_lookup', 60, HOUR_IN_SECONDS)) {
            return self::error('rate_limited', 'Trop de requêtes.', 429);
        }
        $isbn = Desiderata_Meta::normalize_isbn((string) $request['isbn']);
        if ($isbn === null) {
            return self::error('invalid', 'ISBN invalide.', 400);
        }
        $found = Desiderata_OpenLibrary::lookup_isbn($isbn);
        if (!$found) {
            return self::error('not_found', 'Aucun résultat Open Library.', 404);
        }
        return new WP_REST_Response([
            'isbn' => $isbn,
            'meta' => $found['meta'],
            'title' => $found['title'],
            'suggested_url' => 'https://openlibrary.org/isbn/' . $isbn,
        ]);
    }

    public static function patch_item(WP_REST_Request $request): WP_REST_Response
    {
        $user = Desiderata_Auth::current_api_user($request);
        if (!$user) {
            return self::error('unauthorized', 'Non autorisé.', 401);
        }
        $wishlist = self::get_wishlist_by_id((int) $request['id']);
        if (!$wishlist || (int) $wishlist['user_id'] !== (int) $user->ID) {
            return self::error('not_found', 'Liste introuvable.', 404);
        }
        $item = self::get_item((int) $request['item'], (int) $wishlist['id']);
        if (!$item) {
            return self::error('not_found', 'Item introuvable.', 404);
        }

        $current = Desiderata_Meta::decode($item['meta'] ?? null) ?? [];
        $incoming = $request->get_param('meta');
        if (is_array($incoming)) {
            $current = array_merge($current, $incoming);
        }
        if ($request->get_param('isbn') !== null) {
            $current['isbn'] = (string) $request->get_param('isbn');
        }

        $title = $item['title'];
        if (is_string($request->get_param('title')) && $request->get_param('title') !== '') {
            $title = sanitize_text_field((string) $request->get_param('title'));
        }
        $url = $item['url'];
        if (is_string($request->get_param('url'))) {
            $candidate = self::sanitize_http_url((string) $request->get_param('url'));
            if ($candidate) {
                $url = $candidate;
            }
        }

        $encoded = Desiderata_Meta::encode($current);
        $now = gmdate('Y-m-d H:i:s');
        global $wpdb;
        $table = Desiderata_Database::table('wishlist_items');
        $wpdb->update(
            $table,
            [
                'title' => mb_substr($title, 0, 512),
                'url' => $url,
                'meta' => $encoded,
                'updated_at' => $now,
            ],
            ['id' => (int) $item['id']],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );

        return new WP_REST_Response([
            'id' => (int) $item['id'],
            'title' => $title,
            'url' => $url,
            'meta' => Desiderata_Meta::decode($encoded),
            'updated_at' => $now,
        ]);
    }

    public static function enrich_item(WP_REST_Request $request): WP_REST_Response
    {
        $user = Desiderata_Auth::current_api_user($request);
        if (!$user) {
            return self::error('unauthorized', 'Non autorisé.', 401);
        }
        if (!Desiderata_Rate_Limit::check('enrich', 40, HOUR_IN_SECONDS)) {
            return self::error('rate_limited', 'Trop de requêtes.', 429);
        }

        $wishlist = self::get_wishlist_by_id((int) $request['id']);
        if (!$wishlist || (int) $wishlist['user_id'] !== (int) $user->ID) {
            return self::error('not_found', 'Liste introuvable.', 404);
        }
        $item = self::get_item((int) $request['item'], (int) $wishlist['id']);
        if (!$item) {
            return self::error('not_found', 'Item introuvable.', 404);
        }

        $current = Desiderata_Meta::decode($item['meta'] ?? null) ?? [];
        $isbn = Desiderata_Meta::normalize_isbn((string) ($request->get_param('isbn') ?? ($current['isbn'] ?? '')));
        if ($isbn === null) {
            return self::error('invalid', 'ISBN requis pour l’enrichissement.', 400);
        }

        $found = Desiderata_OpenLibrary::lookup_isbn($isbn);
        if (!$found) {
            return self::error('not_found', 'Aucun résultat Open Library.', 404);
        }

        $merged = array_merge($current, $found['meta']);
        $title = $item['title'];
        if ($found['title'] !== '') {
            $title = $found['title'];
        }

        $encoded = Desiderata_Meta::encode($merged);
        $now = gmdate('Y-m-d H:i:s');
        global $wpdb;
        $table = Desiderata_Database::table('wishlist_items');
        $wpdb->update(
            $table,
            [
                'title' => mb_substr($title, 0, 512),
                'meta' => $encoded,
                'updated_at' => $now,
            ],
            ['id' => (int) $item['id']],
            ['%s', '%s', '%s'],
            ['%d']
        );

        return new WP_REST_Response([
            'id' => (int) $item['id'],
            'title' => $title,
            'meta' => Desiderata_Meta::decode($encoded),
        ]);
    }

    public static function import_zotero(WP_REST_Request $request): WP_REST_Response
    {
        $user = Desiderata_Auth::current_api_user($request);
        if (!$user) {
            return self::error('unauthorized', 'Non autorisé.', 401);
        }
        if (!Desiderata_Rate_Limit::check('zotero_import', 20, HOUR_IN_SECONDS)) {
            return self::error('rate_limited', 'Trop d’imports.', 429);
        }

        $wishlist = self::get_wishlist_by_id((int) $request['id']);
        if (!$wishlist || (int) $wishlist['user_id'] !== (int) $user->ID) {
            return self::error('not_found', 'Liste introuvable.', 404);
        }

        $payload = $request->get_param('items');
        if ($payload === null) {
            $payload = $request->get_json_params();
            if (is_array($payload) && array_key_exists('items', $payload)) {
                $payload = $payload['items'];
            }
        }

        $mapped = Desiderata_Zotero::import_items($payload);
        if (!$mapped) {
            return self::error('invalid', 'Aucune référence Zotero valide.', 400);
        }
        if (count($mapped) > Desiderata_Database::MAX_ITEMS_PER_WISHLIST) {
            return self::error('limit', 'Trop d’items.', 400);
        }

        $mode = sanitize_text_field((string) ($request->get_param('mode') ?? 'merge'));
        global $wpdb;
        $items_table = Desiderata_Database::table('wishlist_items');
        $wishlists = Desiderata_Database::table('wishlists');
        $wishlist_id = (int) $wishlist['id'];
        $now = gmdate('Y-m-d H:i:s');

        if ($mode === 'replace') {
            $wpdb->delete($items_table, ['wishlist_id' => $wishlist_id], ['%d']);
        }

        $existing = $wpdb->get_results($wpdb->prepare(
            "SELECT id, bookmark_guid FROM {$items_table} WHERE wishlist_id = %d",
            $wishlist_id
        ), ARRAY_A);
        $by_guid = [];
        foreach ((array) $existing as $row) {
            $by_guid[$row['bookmark_guid']] = (int) $row['id'];
        }

        $order = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(sort_order), -1) FROM {$items_table} WHERE wishlist_id = %d",
            $wishlist_id
        )) + 1;

        $imported = 0;
        $updated = 0;
        foreach ($mapped as $row) {
            $meta = Desiderata_Meta::encode($row['meta']);
            if (isset($by_guid[$row['bookmark_guid']])) {
                $wpdb->update(
                    $items_table,
                    [
                        'url' => $row['url'],
                        'title' => $row['title'],
                        'meta' => $meta,
                        'updated_at' => $now,
                    ],
                    ['id' => $by_guid[$row['bookmark_guid']]],
                    ['%s', '%s', '%s', '%s'],
                    ['%d']
                );
                $updated++;
            } else {
                if (count($by_guid) + $imported >= Desiderata_Database::MAX_ITEMS_PER_WISHLIST) {
                    break;
                }
                $wpdb->insert(
                    $items_table,
                    [
                        'wishlist_id' => $wishlist_id,
                        'bookmark_guid' => $row['bookmark_guid'],
                        'url' => $row['url'],
                        'title' => $row['title'],
                        'meta' => $meta,
                        'sort_order' => $order++,
                        'updated_at' => $now,
                    ],
                    ['%d', '%s', '%s', '%s', '%s', '%d', '%s']
                );
                $imported++;
            }
        }

        $wpdb->update($wishlists, ['updated_at' => $now], ['id' => $wishlist_id], ['%s'], ['%d']);

        return new WP_REST_Response([
            'ok' => true,
            'imported' => $imported,
            'updated' => $updated,
            'total_payload' => count($mapped),
        ], 201);
    }

    public static function export_zotero(WP_REST_Request $request): WP_REST_Response
    {
        $wishlist = self::get_wishlist_by_slug((string) $request['slug']);
        if (!$wishlist) {
            return self::error('not_found', 'Liste introuvable.', 404);
        }

        global $wpdb;
        $items_table = Desiderata_Database::table('wishlist_items');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, title, url, meta FROM {$items_table} WHERE wishlist_id = %d ORDER BY sort_order ASC, id ASC",
            (int) $wishlist['id']
        ), ARRAY_A);

        $csl = Desiderata_Zotero::export_csl(is_array($rows) ? $rows : []);
        $response = new WP_REST_Response($csl);
        $response->header('Content-Disposition', 'attachment; filename="desiderata-' . $wishlist['slug'] . '.json"');
        return $response;
    }

    /** @return array<string,mixed>|null */
    private static function get_wishlist_by_id(int $id): ?array
    {
        global $wpdb;
        $table = Desiderata_Database::table('wishlists');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private static function get_wishlist_by_slug(string $slug): ?array
    {
        global $wpdb;
        $table = Desiderata_Database::table('wishlists');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE slug = %s", $slug), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private static function get_item(int $item_id, int $wishlist_id): ?array
    {
        global $wpdb;
        $table = Desiderata_Database::table('wishlist_items');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND wishlist_id = %d",
            $item_id,
            $wishlist_id
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private static function open_room_for_wishlist(int $wishlist_id): ?array
    {
        global $wpdb;
        $rooms = Desiderata_Database::table('chat_rooms');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$rooms} WHERE wishlist_id = %d AND status IN ('open','closing') ORDER BY id DESC LIMIT 1",
            $wishlist_id
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private static function touch_participant(int $room_id, string $participant_id): void
    {
        global $wpdb;
        $participants = Desiderata_Database::table('chat_participants');
        $wpdb->update(
            $participants,
            ['last_seen_at' => gmdate('Y-m-d H:i:s')],
            ['room_id' => $room_id, 'participant_id' => $participant_id],
            ['%s'],
            ['%d', '%s']
        );
    }

    /** @return list<array<string,mixed>> */
    private static function fetch_messages(int $room_id, int $since): array
    {
        global $wpdb;
        $messages = Desiderata_Database::table('chat_messages');
        $participants = Desiderata_Database::table('chat_participants');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.id, m.participant_id, m.body, m.created_at, p.display_name
             FROM {$messages} m
             LEFT JOIN {$participants} p ON p.room_id = m.room_id AND p.participant_id = m.participant_id
             WHERE m.room_id = %d AND m.id > %d
             ORDER BY m.id ASC LIMIT 100",
            $room_id,
            $since
        ), ARRAY_A);

        $out = [];
        foreach ((array) $rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'participant_id' => $row['participant_id'],
                'display_name' => $row['display_name'] ?: 'Anonyme',
                'body' => $row['body'],
                'created_at' => $row['created_at'],
            ];
        }
        return $out;
    }

    private static function unique_slug(): string
    {
        global $wpdb;
        $table = Desiderata_Database::table('wishlists');
        for ($i = 0; $i < 8; $i++) {
            $slug = Desiderata_Database::generate_slug(10);
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE slug = %s",
                $slug
            ));
            if ($exists === 0) {
                return $slug;
            }
        }
        return Desiderata_Database::generate_slug(16);
    }

    private static function sanitize_http_url(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $url = esc_url_raw($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }
        return $url;
    }

    private static function error(string $code, string $message, int $status): WP_REST_Response
    {
        return new WP_REST_Response([
            'code' => $code,
            'message' => $message,
        ], $status);
    }
}
