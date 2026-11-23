<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Desiderata_Activator
{
    public static function activate(): void
    {
        Desiderata_Database::create_tables();
        update_option('desiderata_db_version', DESIDERATA_DB_VERSION);

        // Capacité réservée aux administrateurs + comptes Desiderata individuels (pas au rôle subscriber).
        $subscriber = get_role('subscriber');
        if ($subscriber && $subscriber->has_cap('desiderata_manage')) {
            $subscriber->remove_cap('desiderata_manage');
        }
        $admin = get_role('administrator');
        if ($admin && !$admin->has_cap('desiderata_manage')) {
            $admin->add_cap('desiderata_manage');
        }

        Desiderata_Rewrite::register_rewrites();
        flush_rewrite_rules();

        if (!wp_next_scheduled('desiderata_purge_cron')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'desiderata_purge_cron');
        }
    }

    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled('desiderata_purge_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'desiderata_purge_cron');
        }
        flush_rewrite_rules();
    }
}
