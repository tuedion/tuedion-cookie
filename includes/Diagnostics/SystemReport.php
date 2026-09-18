<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Diagnostics;

use Tuedion\CookieConsent\Settings\Schema;

if (!defined('ABSPATH')) {
    exit;
}

final class SystemReport
{
    /**
     * Gather system health and environment metrics.
     *
     * @return array<string, string|bool|int>
     */
    public static function compile(): array
    {
        $theme = wp_get_theme();
        $settings = \Tuedion\CookieConsent\Settings\Repository::getSettings();
        $advanced = $settings['advanced'] ?? [];
        $gcm = $settings['gcm'] ?? [];
        $cachePlugins = \Tuedion\CookieConsent\Integrations\CacheCompatibility::getActiveCachePlugins();
        $recipes = \Tuedion\CookieConsent\Integrations\RecipeRegistry::getAll();
        $siteKit = \Tuedion\CookieConsent\Integrations\Google\SiteKitDetector::getDiagnostics();

        return [
            'plugin_version'        => TUEDION_COOKIE_VERSION,
            'schema_version'        => (string) get_option(Schema::SCHEMA_VERSION_OPTION, 'not_installed'),
            'bundled_cookieconsent' => '3.1.0',
            'script_blocking'       => !empty($advanced['script_blocking']) ? 'Active (Deny-Before-Consent)' : 'Disabled',
            'iframe_blocking'       => !empty($advanced['iframe_blocking']) ? 'Active (Placeholder Mode)' : 'Disabled',
            'reload_on_revoke'      => !empty($advanced['reload_on_revoke']) ? 'Enabled' : 'Disabled',
            'gcm_v2_status'         => !empty($gcm['enabled']) ? 'Enabled (Early Default in <head>)' : 'Disabled',
            'gcm_wait_for_update'   => sprintf('%d ms', (int) ($gcm['wait_for_update'] ?? 500)),
            'google_site_kit'       => $siteKit['active'] ? sprintf('Active (%s)%s', $siteKit['version'], $siteKit['has_duplicate_risk'] ? ' - DUPLICATE RISK' : '') : 'Not Installed',
            'registered_recipes'    => (string) count($recipes),
            'detected_cache_layers' => !empty($cachePlugins) ? implode(', ', $cachePlugins) : 'None detected',
            'php_version'           => PHP_VERSION,
            'php_memory_limit'      => ini_get('memory_limit') ?: 'N/A',
            'wordpress_version'     => get_bloginfo('version'),
            'is_multisite'          => is_multisite() ? 'Yes' : 'No',
            'is_ssl'                => is_ssl() ? 'Yes' : 'No',
            'web_server'            => isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : 'Unknown',
            'active_theme'          => sprintf('%s (%s)', $theme->get('Name'), $theme->get('Version')),
            'site_locale'           => get_locale(),
        ];
    }
}
