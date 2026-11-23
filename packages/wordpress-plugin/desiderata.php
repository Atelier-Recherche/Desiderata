<?php
/**
 * Plugin Name: Desiderata
 * Plugin URI:  https://github.com/Atelier-Recherche/Desiderata
 * Description: Wishlist collaborative de livres & documents — sync favoris, pages publiques, claims et chat éphémère.
 * Version:     0.2.6
 * Author:      L'atelier
 * Author URI:  https://github.com/Atelier-Recherche
 * Text Domain: desiderata
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('DESIDERATA_VERSION', '0.2.6');
define('DESIDERATA_FILE', __FILE__);
define('DESIDERATA_PATH', plugin_dir_path(__FILE__));
define('DESIDERATA_URL', plugin_dir_url(__FILE__));
define('DESIDERATA_DB_VERSION', '1');

require_once DESIDERATA_PATH . 'includes/shared/Meta.php';
require_once DESIDERATA_PATH . 'includes/shared/OpenLibrary.php';
require_once DESIDERATA_PATH . 'includes/shared/Zotero.php';
require_once DESIDERATA_PATH . 'includes/class-database.php';
require_once DESIDERATA_PATH . 'includes/class-auth.php';
require_once DESIDERATA_PATH . 'includes/class-rate-limit.php';
require_once DESIDERATA_PATH . 'includes/class-purge.php';
require_once DESIDERATA_PATH . 'includes/class-rest-api.php';
require_once DESIDERATA_PATH . 'includes/class-rewrite.php';
require_once DESIDERATA_PATH . 'includes/class-admin.php';
require_once DESIDERATA_PATH . 'includes/class-activator.php';

register_activation_hook(__FILE__, ['Desiderata_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['Desiderata_Activator', 'deactivate']);

add_action('plugins_loaded', static function (): void {
    Desiderata_Database::maybe_upgrade();
    Desiderata_Auth::init();
    Desiderata_Purge::init();
    Desiderata_Rest_Api::init();
    Desiderata_Rewrite::init();
    Desiderata_Admin::init();

    $installed_ver = get_option('desiderata_plugin_version');
    if ($installed_ver !== DESIDERATA_VERSION) {
        Desiderata_Rewrite::register_rewrites();
        flush_rewrite_rules(false);
        update_option('desiderata_plugin_version', DESIDERATA_VERSION);
    }
});
