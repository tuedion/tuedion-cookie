<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Core;

if (!defined('ABSPATH')) {
    exit;
}

final class Deactivator
{
    /**
     * Plugin deactivation handler.
     * Supports single-site and multisite network-wide deactivation.
     *
     * @param bool $network_wide
     */
    public static function deactivate(bool $network_wide = false): void
    {
        if (is_multisite() && $network_wide) {
            global $wpdb;
            $blogIds = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");
            $originalBlogId = get_current_blog_id();

            if (is_array($blogIds)) {
                foreach ($blogIds as $bId) {
                    switch_to_blog((int) $bId);
                    self::deactivateSingleSite();
                }
            }

            switch_to_blog($originalBlogId);
        } else {
            self::deactivateSingleSite();
        }
    }

    private static function deactivateSingleSite(): void
    {
        // Unschedule daily retention cleanup cron and automated scanner cron
        wp_clear_scheduled_hook(\Tuedion\CookieConsent\Logs\LogCleaner::CRON_HOOK);
        wp_clear_scheduled_hook(\Tuedion\CookieConsent\Diagnostics\CookieScanner::CRON_HOOK);

        // Flush volatile caches and temporary transients
        \Tuedion\CookieConsent\Settings\Compiler::clearCache();
        delete_transient('tuedion_cookie_system_report');
        delete_transient('tdcc_scanner_results');

        // User data and options are intentionally preserved
    }
}
