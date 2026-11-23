<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Desiderata_Rewrite
{
    public static function init(): void
    {
        add_action('init', [self::class, 'register_rewrites']);
        add_filter('query_vars', [self::class, 'query_vars']);
        add_action('template_redirect', [self::class, 'template_redirect']);
    }

    public static function register_rewrites(): void
    {
        add_rewrite_rule(
            '^wishlist/([a-z0-9]+)/?$',
            'index.php?desiderata_wishlist=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            '^desiderata/turnstile/?$',
            'index.php?desiderata_turnstile=1',
            'top'
        );
    }

    public static function query_vars(array $vars): array
    {
        $vars[] = 'desiderata_wishlist';
        $vars[] = 'desiderata_turnstile';
        return $vars;
    }

    public static function template_redirect(): void
    {
        if ((string) get_query_var('desiderata_turnstile') === '1') {
            $template = DESIDERATA_PATH . 'templates/turnstile.php';
            if (is_readable($template)) {
                status_header(200);
                nocache_headers();
                include $template;
                exit;
            }
            wp_die(esc_html__('Turnstile indisponible.', 'desiderata'), 503);
        }

        $slug = get_query_var('desiderata_wishlist');
        if (!$slug || !is_string($slug)) {
            return;
        }

        $template = DESIDERATA_PATH . 'templates/wishlist.php';
        if (is_readable($template)) {
            status_header(200);
            nocache_headers();
            include $template;
            exit;
        }

        wp_die(esc_html__('Wishlist introuvable.', 'desiderata'), 404);
    }
}
