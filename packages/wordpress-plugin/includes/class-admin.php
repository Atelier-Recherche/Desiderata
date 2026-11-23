<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Desiderata_Admin
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_desiderata_create_wishlist', [self::class, 'handle_create_wishlist']);
        add_action('admin_post_desiderata_delete_wishlist', [self::class, 'handle_delete_wishlist']);
        add_action('admin_post_desiderata_import_zotero', [self::class, 'handle_import_zotero']);
        add_action('admin_post_desiderata_save_settings', [self::class, 'handle_save_settings']);
        add_action('admin_post_desiderata_revoke_access', [self::class, 'handle_revoke_access']);
        add_action('admin_post_desiderata_grant_access', [self::class, 'handle_grant_access']);
    }

    public static function menu(): void
    {
        add_menu_page(
            'Desiderata',
            'Desiderata',
            'desiderata_manage',
            'desiderata',
            [self::class, 'render_page'],
            'dashicons-book-alt',
            58
        );
    }

    private static function is_site_admin(): bool
    {
        return current_user_can('manage_options');
    }

    public static function render_page(): void
    {
        if (!current_user_can('desiderata_manage')) {
            wp_die(esc_html__('Accès refusé.', 'desiderata'));
        }

        $user_id = get_current_user_id();
        $site_admin = self::is_site_admin();
        $lists = $site_admin ? self::all_wishlists() : self::user_wishlists($user_id);
        $desiderata_users = $site_admin ? Desiderata_Auth::list_desiderata_users() : [];
        $notice = isset($_GET['desiderata_notice']) ? sanitize_text_field(wp_unslash((string) $_GET['desiderata_notice'])) : '';

        ?>
        <div class="wrap">
            <h1>Desiderata</h1>
            <p><?php echo esc_html__('Wishlist collaborative — comptes via l’extension, listes publiques et modération.', 'desiderata'); ?></p>

            <?php if ($notice === 'wishlist_created') : ?>
                <div class="notice notice-success"><p><?php echo esc_html__('Liste créée.', 'desiderata'); ?></p></div>
            <?php endif; ?>
            <?php if ($notice === 'wishlist_deleted') : ?>
                <div class="notice notice-success"><p><?php echo esc_html__('Liste supprimée.', 'desiderata'); ?></p></div>
            <?php endif; ?>
            <?php if ($notice === 'settings_saved') : ?>
                <div class="notice notice-success"><p><?php echo esc_html__('Réglages enregistrés.', 'desiderata'); ?></p></div>
            <?php endif; ?>
            <?php if ($notice === 'access_revoked') : ?>
                <div class="notice notice-success"><p><?php echo esc_html__('Accès Desiderata révoqué (jeton invalidé).', 'desiderata'); ?></p></div>
            <?php endif; ?>
            <?php if ($notice === 'access_granted') : ?>
                <div class="notice notice-success"><p><?php echo esc_html__('Accès Desiderata rétabli.', 'desiderata'); ?></p></div>
            <?php endif; ?>
            <?php if ($notice === 'zotero_imported') : ?>
                <div class="notice notice-success"><p><?php echo esc_html__('Import Zotero terminé.', 'desiderata'); ?></p></div>
            <?php endif; ?>
            <?php if ($notice === 'zotero_error') : ?>
                <div class="notice notice-error"><p><?php echo esc_html__('Import Zotero impossible (JSON invalide ou liste introuvable).', 'desiderata'); ?></p></div>
            <?php endif; ?>

            <?php if ($site_admin) : ?>
            <h2><?php echo esc_html__('Inscription depuis l’extension', 'desiderata'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('desiderata_save_settings'); ?>
                <input type="hidden" name="action" value="desiderata_save_settings" />
                <p>
                    <label>
                        <input type="checkbox" name="registration_open" value="1" <?php checked(Desiderata_Auth::registration_open()); ?> />
                        <?php echo esc_html__('Autoriser la création de compte depuis l’extension (sans validation manuelle)', 'desiderata'); ?>
                    </label>
                </p>
                <h3><?php echo esc_html__('Cloudflare Turnstile', 'desiderata'); ?></h3>
                <p class="description">
                    <?php echo esc_html__('Si les deux clés sont renseignées, l’inscription exige un défi Turnstile (widget servi sur /desiderata/turnstile/). Ajoutez le domaine du site dans le dashboard Cloudflare.', 'desiderata'); ?>
                </p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="turnstile_site_key"><?php echo esc_html__('Site key', 'desiderata'); ?></label></th>
                        <td>
                            <input type="text" class="regular-text" id="turnstile_site_key" name="turnstile_site_key"
                                   value="<?php echo esc_attr(Desiderata_Auth::turnstile_site_key()); ?>"
                                   autocomplete="off" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="turnstile_secret_key"><?php echo esc_html__('Secret key', 'desiderata'); ?></label></th>
                        <td>
                            <input type="password" class="regular-text" id="turnstile_secret_key" name="turnstile_secret_key"
                                   value="" placeholder="<?php echo Desiderata_Auth::turnstile_secret_key() !== ''
                                       ? esc_attr__('(conservée — laisser vide pour ne pas changer)', 'desiderata')
                                       : ''; ?>"
                                   autocomplete="new-password" />
                        </td>
                    </tr>
                </table>
                <p>
                    <button class="button"><?php echo esc_html__('Enregistrer', 'desiderata'); ?></button>
                </p>
            </form>

            <h2 style="margin-top:2em;"><?php echo esc_html__('Utilisateurs Desiderata', 'desiderata'); ?></h2>
            <p class="description"><?php echo esc_html__('Comptes autorisés à utiliser l’API / l’extension. Révoquer coupe l’accès sans supprimer le compte WordPress.', 'desiderata'); ?></p>
            <table class="widefat striped" style="margin-top:1em;max-width:960px;">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th><?php echo esc_html__('Nom', 'desiderata'); ?></th>
                        <th>E-mail</th>
                        <th><?php echo esc_html__('Inscrit le', 'desiderata'); ?></th>
                        <th><?php echo esc_html__('Listes', 'desiderata'); ?></th>
                        <th><?php echo esc_html__('Session API', 'desiderata'); ?></th>
                        <th><?php echo esc_html__('Actions', 'desiderata'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$desiderata_users) : ?>
                    <tr><td colspan="7"><?php echo esc_html__('Aucun utilisateur Desiderata.', 'desiderata'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($desiderata_users as $u) : ?>
                        <?php
                        $uid = (int) $u->ID;
                        $list_count = self::count_user_wishlists($uid);
                        $has_token = Desiderata_Auth::token_hint($uid) !== '';
                        $is_self = $uid === $user_id;
                        ?>
                        <tr>
                            <td><?php echo $uid; ?></td>
                            <td><?php echo esc_html($u->display_name !== '' ? $u->display_name : $u->user_login); ?></td>
                            <td><?php echo esc_html($u->user_email); ?></td>
                            <td><?php echo esc_html(mysql2date('d/m/Y H:i', $u->user_registered)); ?></td>
                            <td><?php echo (int) $list_count; ?></td>
                            <td><?php echo $has_token ? esc_html__('Active', 'desiderata') : esc_html__('Aucune', 'desiderata'); ?></td>
                            <td>
                                <?php if ($is_self) : ?>
                                    <em><?php echo esc_html__('(vous)', 'desiderata'); ?></em>
                                <?php else : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js(__('Révoquer l’accès Desiderata de cet utilisateur ?', 'desiderata')); ?>');">
                                        <?php wp_nonce_field('desiderata_revoke_access_' . $uid); ?>
                                        <input type="hidden" name="action" value="desiderata_revoke_access" />
                                        <input type="hidden" name="user_id" value="<?php echo $uid; ?>" />
                                        <button type="submit" class="button button-link-delete"><?php echo esc_html__('Révoquer l’accès', 'desiderata'); ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <h3 style="margin-top:1.5em;"><?php echo esc_html__('Rétablir l’accès', 'desiderata'); ?></h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <?php wp_nonce_field('desiderata_grant_access'); ?>
                <input type="hidden" name="action" value="desiderata_grant_access" />
                <input type="email" name="user_email" placeholder="email@exemple.fr" required />
                <button type="submit" class="button"><?php echo esc_html__('Accorder desiderata_manage', 'desiderata'); ?></button>
            </form>
            <?php endif; ?>

            <h2 style="margin-top:2em;">
                <?php echo $site_admin
                    ? esc_html__('Toutes les listes', 'desiderata')
                    : esc_html__('Mes listes', 'desiderata'); ?>
            </h2>
            <?php if ($site_admin) : ?>
                <p class="description"><?php echo esc_html__('Vue administrateur : listes de tous les comptes Desiderata.', 'desiderata'); ?></p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('desiderata_create_wishlist'); ?>
                <input type="hidden" name="action" value="desiderata_create_wishlist" />
                <input type="text" name="title" placeholder="<?php echo esc_attr__('Titre de la liste', 'desiderata'); ?>" required />
                <button class="button button-secondary"><?php echo esc_html__('Créer une liste (pour mon compte)', 'desiderata'); ?></button>
            </form>

            <table class="widefat striped" style="margin-top:1em;max-width:960px;">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th><?php echo esc_html__('Titre', 'desiderata'); ?></th>
                        <?php if ($site_admin) : ?>
                            <th><?php echo esc_html__('Propriétaire', 'desiderata'); ?></th>
                        <?php endif; ?>
                        <th>Slug</th>
                        <th><?php echo esc_html__('Items', 'desiderata'); ?></th>
                        <th><?php echo esc_html__('Lien public', 'desiderata'); ?></th>
                        <th>Export</th>
                        <th><?php echo esc_html__('Actions', 'desiderata'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$lists) : ?>
                    <tr><td colspan="<?php echo $site_admin ? 8 : 7; ?>"><?php echo esc_html__('Aucune liste.', 'desiderata'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($lists as $list) : ?>
                        <?php
                        $url = home_url('/wishlist/' . $list->slug . '/');
                        $export = rest_url('desiderata/v1/wishlists/' . $list->slug . '/export/zotero');
                        $can_delete = $site_admin || (int) $list->user_id === $user_id;
                        $owner_label = self::owner_label((int) $list->user_id);
                        ?>
                        <tr>
                            <td><?php echo (int) $list->id; ?></td>
                            <td><?php echo esc_html($list->title); ?></td>
                            <?php if ($site_admin) : ?>
                                <td><?php echo esc_html($owner_label); ?></td>
                            <?php endif; ?>
                            <td><code><?php echo esc_html($list->slug); ?></code></td>
                            <td><?php echo (int) ($list->item_count ?? 0); ?></td>
                            <td><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php echo esc_html($url); ?></a></td>
                            <td><a href="<?php echo esc_url($export); ?>" target="_blank" rel="noopener">CSL-JSON</a></td>
                            <td>
                                <?php if ($can_delete) : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js(__('Supprimer cette liste et tous ses items ?', 'desiderata')); ?>');">
                                        <?php wp_nonce_field('desiderata_delete_wishlist_' . (int) $list->id); ?>
                                        <input type="hidden" name="action" value="desiderata_delete_wishlist" />
                                        <input type="hidden" name="wishlist_id" value="<?php echo (int) $list->id; ?>" />
                                        <button class="button button-link-delete" type="submit"><?php echo esc_html__('Supprimer', 'desiderata'); ?></button>
                                    </form>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <h2 style="margin-top:2em;"><?php echo esc_html__('Import Zotero', 'desiderata'); ?></h2>
            <p><?php echo esc_html__('Collez un export JSON Zotero (bibliothèque) ou CSL-JSON.', 'desiderata'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('desiderata_import_zotero'); ?>
                <input type="hidden" name="action" value="desiderata_import_zotero" />
                <p>
                    <label><?php echo esc_html__('Liste cible', 'desiderata'); ?>
                        <select name="wishlist_id" required>
                            <?php foreach ($lists as $list) : ?>
                                <?php
                                $label = $list->title . ' (' . $list->slug . ')';
                                if ($site_admin) {
                                    $label .= ' — ' . self::owner_label((int) $list->user_id);
                                }
                                ?>
                                <option value="<?php echo (int) $list->id; ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </p>
                <p>
                    <label><?php echo esc_html__('Mode', 'desiderata'); ?>
                        <select name="mode">
                            <option value="merge"><?php echo esc_html__('Fusionner', 'desiderata'); ?></option>
                            <option value="replace"><?php echo esc_html__('Remplacer toute la liste', 'desiderata'); ?></option>
                        </select>
                    </label>
                </p>
                <p>
                    <textarea name="zotero_json" rows="10" style="width:100%;max-width:800px;font-family:monospace;" required placeholder='[ { "key": "...", "data": { "title": "...", "url": "..." } } ]'></textarea>
                </p>
                <button class="button button-secondary"><?php echo esc_html__('Importer', 'desiderata'); ?></button>
            </form>

            <h2 style="margin-top:2em;"><?php echo esc_html__('Endpoint API', 'desiderata'); ?></h2>
            <p><code><?php echo esc_html(rest_url('desiderata/v1/')); ?></code></p>
        </div>
        <?php
    }

    public static function handle_save_settings(): void
    {
        if (!current_user_can('manage_options') || !check_admin_referer('desiderata_save_settings')) {
            wp_die(esc_html__('Accès refusé.', 'desiderata'));
        }
        $open = isset($_POST['registration_open']) && (string) $_POST['registration_open'] === '1';
        Desiderata_Auth::set_registration_open($open);
        $site_key = isset($_POST['turnstile_site_key'])
            ? sanitize_text_field(wp_unslash((string) $_POST['turnstile_site_key']))
            : '';
        $secret = isset($_POST['turnstile_secret_key'])
            ? sanitize_text_field(wp_unslash((string) $_POST['turnstile_secret_key']))
            : '';
        Desiderata_Auth::set_turnstile_keys($site_key, $secret);
        wp_safe_redirect(add_query_arg('desiderata_notice', 'settings_saved', admin_url('admin.php?page=desiderata')));
        exit;
    }

    public static function handle_revoke_access(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès refusé.', 'desiderata'));
        }
        $target_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        if ($target_id < 1 || !check_admin_referer('desiderata_revoke_access_' . $target_id)) {
            wp_die(esc_html__('Accès refusé.', 'desiderata'));
        }
        if ($target_id === get_current_user_id()) {
            wp_die(esc_html__('Vous ne pouvez pas révoquer votre propre accès ici.', 'desiderata'));
        }
        Desiderata_Auth::revoke_access($target_id);
        wp_safe_redirect(add_query_arg('desiderata_notice', 'access_revoked', admin_url('admin.php?page=desiderata')));
        exit;
    }

    public static function handle_grant_access(): void
    {
        if (!current_user_can('manage_options') || !check_admin_referer('desiderata_grant_access')) {
            wp_die(esc_html__('Accès refusé.', 'desiderata'));
        }
        $email = isset($_POST['user_email']) ? sanitize_email(wp_unslash((string) $_POST['user_email'])) : '';
        $user = $email !== '' ? get_user_by('email', $email) : false;
        if (!$user instanceof WP_User) {
            wp_die(esc_html__('Utilisateur introuvable pour cet e-mail.', 'desiderata'));
        }
        Desiderata_Auth::grant_access((int) $user->ID);
        wp_safe_redirect(add_query_arg('desiderata_notice', 'access_granted', admin_url('admin.php?page=desiderata')));
        exit;
    }

    public static function handle_create_wishlist(): void
    {
        if (!current_user_can('desiderata_manage') || !check_admin_referer('desiderata_create_wishlist')) {
            wp_die(esc_html__('Accès refusé.', 'desiderata'));
        }

        $user_id = get_current_user_id();
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash((string) $_POST['title'])) : '';
        if ($title === '') {
            $title = 'Wishlist';
        }

        global $wpdb;
        $table = Desiderata_Database::table('wishlists');
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d",
            $user_id
        ));

        if ($count >= Desiderata_Database::MAX_WISHLISTS_PER_USER) {
            wp_die(esc_html__('Limite de listes atteinte.', 'desiderata'));
        }

        $slug = self::unique_slug();
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert(
            $table,
            [
                'user_id' => $user_id,
                'slug' => $slug,
                'title' => $title,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );

        wp_safe_redirect(add_query_arg('desiderata_notice', 'wishlist_created', admin_url('admin.php?page=desiderata')));
        exit;
    }

    public static function handle_delete_wishlist(): void
    {
        if (!current_user_can('desiderata_manage')) {
            wp_die(esc_html__('Accès refusé.', 'desiderata'));
        }

        $wishlist_id = isset($_POST['wishlist_id']) ? (int) $_POST['wishlist_id'] : 0;
        if ($wishlist_id < 1 || !check_admin_referer('desiderata_delete_wishlist_' . $wishlist_id)) {
            wp_die(esc_html__('Accès refusé.', 'desiderata'));
        }

        global $wpdb;
        $table = Desiderata_Database::table('wishlists');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $wishlist_id), ARRAY_A);
        if (!$row) {
            wp_safe_redirect(admin_url('admin.php?page=desiderata'));
            exit;
        }

        $is_owner = (int) $row['user_id'] === get_current_user_id();
        if (!$is_owner && !self::is_site_admin()) {
            wp_die(esc_html__('Accès refusé.', 'desiderata'));
        }

        self::purge_wishlist($wishlist_id);
        wp_safe_redirect(add_query_arg('desiderata_notice', 'wishlist_deleted', admin_url('admin.php?page=desiderata')));
        exit;
    }

    public static function handle_import_zotero(): void
    {
        if (!current_user_can('desiderata_manage') || !check_admin_referer('desiderata_import_zotero')) {
            wp_die(esc_html__('Accès refusé.', 'desiderata'));
        }

        $user_id = get_current_user_id();
        $wishlist_id = isset($_POST['wishlist_id']) ? (int) $_POST['wishlist_id'] : 0;
        $mode = isset($_POST['mode']) ? sanitize_text_field(wp_unslash((string) $_POST['mode'])) : 'merge';
        $json = isset($_POST['zotero_json']) ? wp_unslash((string) $_POST['zotero_json']) : '';

        global $wpdb;
        $table = Desiderata_Database::table('wishlists');
        if (self::is_site_admin()) {
            $wishlist = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $wishlist_id
            ), ARRAY_A);
        } else {
            $wishlist = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d AND user_id = %d",
                $wishlist_id,
                $user_id
            ), ARRAY_A);
        }

        $mapped = Desiderata_Zotero::import_items($json);
        if (!$wishlist || !$mapped) {
            wp_safe_redirect(add_query_arg('desiderata_notice', 'zotero_error', admin_url('admin.php?page=desiderata')));
            exit;
        }

        $items_table = Desiderata_Database::table('wishlist_items');
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
            } else {
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
            }
        }

        $wpdb->update($table, ['updated_at' => $now], ['id' => $wishlist_id], ['%s'], ['%d']);
        wp_safe_redirect(add_query_arg('desiderata_notice', 'zotero_imported', admin_url('admin.php?page=desiderata')));
        exit;
    }

    private static function purge_wishlist(int $wishlist_id): void
    {
        global $wpdb;
        $items = Desiderata_Database::table('wishlist_items');
        $wishlists = Desiderata_Database::table('wishlists');
        $rooms = Desiderata_Database::table('chat_rooms');
        $participants = Desiderata_Database::table('chat_participants');
        $messages = Desiderata_Database::table('chat_messages');
        $signals = Desiderata_Database::table('rtc_signals');

        $room_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$rooms} WHERE wishlist_id = %d",
            $wishlist_id
        ));
        foreach ((array) $room_ids as $room_id) {
            $rid = (int) $room_id;
            $wpdb->delete($messages, ['room_id' => $rid], ['%d']);
            $wpdb->delete($signals, ['room_id' => $rid], ['%d']);
            $wpdb->delete($participants, ['room_id' => $rid], ['%d']);
            $wpdb->delete($rooms, ['id' => $rid], ['%d']);
        }

        $wpdb->delete($items, ['wishlist_id' => $wishlist_id], ['%d']);
        $wpdb->delete($wishlists, ['id' => $wishlist_id], ['%d']);
    }

    private static function owner_label(int $user_id): string
    {
        $user = get_userdata($user_id);
        if (!$user) {
            return sprintf('#%d (supprimé)', $user_id);
        }
        $name = $user->display_name !== '' ? $user->display_name : $user->user_login;
        return $name . ' <' . $user->user_email . '>';
    }

    private static function count_user_wishlists(int $user_id): int
    {
        global $wpdb;
        $table = Desiderata_Database::table('wishlists');
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d",
            $user_id
        ));
    }

    /** @return list<object> */
    private static function user_wishlists(int $user_id): array
    {
        global $wpdb;
        $table = Desiderata_Database::table('wishlists');
        $items = Desiderata_Database::table('wishlist_items');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT w.id, w.user_id, w.slug, w.title, w.updated_at,
                    (SELECT COUNT(*) FROM {$items} i WHERE i.wishlist_id = w.id) AS item_count
             FROM {$table} w
             WHERE w.user_id = %d
             ORDER BY w.id ASC",
            $user_id
        ));
        return is_array($rows) ? $rows : [];
    }

    /** @return list<object> */
    private static function all_wishlists(): array
    {
        global $wpdb;
        $table = Desiderata_Database::table('wishlists');
        $items = Desiderata_Database::table('wishlist_items');
        $rows = $wpdb->get_results(
            "SELECT w.id, w.user_id, w.slug, w.title, w.updated_at,
                    (SELECT COUNT(*) FROM {$items} i WHERE i.wishlist_id = w.id) AS item_count
             FROM {$table} w
             ORDER BY w.updated_at DESC, w.id DESC"
        );
        return is_array($rows) ? $rows : [];
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
}
