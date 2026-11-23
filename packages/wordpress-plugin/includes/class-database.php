<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Desiderata_Database
{
    public const MAX_WISHLISTS_PER_USER = 5;
    public const MAX_ITEMS_PER_WISHLIST = 500;
    public const MAX_CHAT_MESSAGES = 50;
    public const MAX_MESSAGE_LENGTH = 500;

    public static function table(string $suffix): string
    {
        global $wpdb;
        return $wpdb->prefix . 'desiderata_' . $suffix;
    }

    public static function maybe_upgrade(): void
    {
        $installed = get_option('desiderata_db_version');
        if ($installed === DESIDERATA_DB_VERSION) {
            return;
        }
        self::create_tables();
        update_option('desiderata_db_version', DESIDERATA_DB_VERSION);
    }

    public static function create_tables(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $wishlists = self::table('wishlists');
        $items = self::table('wishlist_items');
        $rooms = self::table('chat_rooms');
        $participants = self::table('chat_participants');
        $messages = self::table('chat_messages');
        $signals = self::table('rtc_signals');

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$wishlists} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            slug varchar(32) NOT NULL,
            title varchar(255) NOT NULL DEFAULT '',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY user_id (user_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$items} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            wishlist_id bigint(20) unsigned NOT NULL,
            bookmark_guid varchar(64) NOT NULL DEFAULT '',
            url text NOT NULL,
            title varchar(512) NOT NULL DEFAULT '',
            meta longtext NULL,
            sort_order int(11) NOT NULL DEFAULT 0,
            claimed_by varchar(120) NULL,
            claimed_at datetime NULL,
            claim_hash varchar(255) NULL,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY wishlist_id (wishlist_id),
            KEY bookmark_guid (bookmark_guid)
        ) {$charset};");

        dbDelta("CREATE TABLE {$rooms} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            wishlist_id bigint(20) unsigned NOT NULL,
            room_token varchar(64) NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'open',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            closed_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY room_token (room_token),
            KEY wishlist_id (wishlist_id),
            KEY status (status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$participants} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            room_id bigint(20) unsigned NOT NULL,
            participant_id varchar(64) NOT NULL,
            display_name varchar(120) NOT NULL DEFAULT '',
            last_seen_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            wants_close tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY room_participant (room_id, participant_id),
            KEY room_id (room_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$messages} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            room_id bigint(20) unsigned NOT NULL,
            participant_id varchar(64) NOT NULL,
            body varchar(500) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY room_id (room_id),
            KEY created_at (created_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$signals} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            room_id bigint(20) unsigned NOT NULL,
            from_id varchar(64) NOT NULL,
            to_id varchar(64) NULL,
            type varchar(16) NOT NULL,
            payload longtext NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY room_id (room_id),
            KEY created_at (created_at)
        ) {$charset};");
    }

    public static function generate_slug(int $length = 10): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyz23456789';
        $slug = '';
        for ($i = 0; $i < $length; $i++) {
            $slug .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $slug;
    }

    public static function generate_token(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
