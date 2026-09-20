<?php
/**
 * Plugin Name:       Tuedion Cookie
 * Plugin URI:        https://github.com/tuedion/tuedion-cookie
 * Description:       A professional WordPress integration of Orest Bida's CookieConsent library by Tuedion.
 * Version:           1.4.2
 * Requires at least: 6.2
 * Requires PHP:      8.2
 * Author:            Tuedion
 * Author URI:        https://tuedion.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tuedion-cookie
 * Domain Path:       /languages
 */

declare(strict_types=1);

namespace Tuedion\CookieConsent;

if (!defined('ABSPATH')) {
    exit;
}

// Core constants
define('TUEDION_COOKIE_VERSION', '1.4.2');
define('TUEDION_COOKIE_FILE', __FILE__);
define('TUEDION_COOKIE_PATH', plugin_dir_path(__FILE__));
define('TUEDION_COOKIE_URL', plugin_dir_url(__FILE__));
define('TUEDION_COOKIE_SCHEMA_VERSION', '1.0.1');
define('TUEDION_COOKIE_MIN_PHP', '8.2.0');
define('TUEDION_COOKIE_MIN_WP', '6.2');

// Autoloader
require_once TUEDION_COOKIE_PATH . 'includes/Core/Autoloader.php';
Core\Autoloader::register();

// Activation & Deactivation hooks
register_activation_hook(__FILE__, [Core\Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [Core\Deactivator::class, 'deactivate']);

// Bootstrap execution
add_action('plugins_loaded', static function (): void {
    if (Core\Requirements::check()) {
        Core\Plugin::instance()->boot();
    }
});
