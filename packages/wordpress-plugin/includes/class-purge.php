<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Desiderata_Purge
{
    public static function init(): void
    {
        add_action('desiderata_purge_cron', [self::class, 'run']);
    }

    public static function run(): void
    {
        global $wpdb;

        $signals = Desiderata_Database::table('rtc_signals');
        $rooms = Desiderata_Database::table('chat_rooms');
        $messages = Desiderata_Database::table('chat_messages');
        $participants = Desiderata_Database::table('chat_participants');

        // Signals older than 2 minutes.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$signals} WHERE created_at < %s",
                gmdate('Y-m-d H:i:s', time() - 120)
            )
        );

        // Closed rooms older than 24h: cascade delete.
        $old_rooms = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$rooms} WHERE status = 'closed' AND closed_at IS NOT NULL AND closed_at < %s",
                gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)
            )
        );

        foreach ($old_rooms as $room_id) {
            self::purge_room((int) $room_id, true);
        }

        // Stale open rooms with no activity for 24h among participants.
        $stale = $wpdb->get_col(
            "SELECT r.id FROM {$rooms} r
             LEFT JOIN {$participants} p ON p.room_id = r.id
             WHERE r.status IN ('open','closing')
             GROUP BY r.id
             HAVING MAX(p.last_seen_at) IS NULL OR MAX(p.last_seen_at) < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)"
        );

        foreach ($stale as $room_id) {
            self::close_and_purge((int) $room_id);
        }
    }

    public static function close_and_purge(int $room_id): void
    {
        global $wpdb;
        $rooms = Desiderata_Database::table('chat_rooms');
        $wpdb->update(
            $rooms,
            [
                'status' => 'closed',
                'closed_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $room_id],
            ['%s', '%s'],
            ['%d']
        );
        self::purge_room($room_id, false);
    }

    public static function purge_room(int $room_id, bool $delete_room): void
    {
        global $wpdb;
        $messages = Desiderata_Database::table('chat_messages');
        $participants = Desiderata_Database::table('chat_participants');
        $signals = Desiderata_Database::table('rtc_signals');
        $rooms = Desiderata_Database::table('chat_rooms');

        $wpdb->delete($messages, ['room_id' => $room_id], ['%d']);
        $wpdb->delete($signals, ['room_id' => $room_id], ['%d']);
        $wpdb->delete($participants, ['room_id' => $room_id], ['%d']);

        if ($delete_room) {
            $wpdb->delete($rooms, ['id' => $room_id], ['%d']);
        }
    }
}
