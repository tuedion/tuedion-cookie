<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package TuedionCookie
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Clean up plugin data for a single site/blog.
 */
function tdcc_uninstall_single_site(): void
{
    // Delete options
    delete_option('tuedion_cookie_settings');
    delete_option('tuedion_cookie_schema_version');
    delete_option('tuedion_cookie_activated_at');
    delete_option('tuedion_cookie_custom_translations');

    // Delete transients
    delete_transient('tuedion_cookie_runtime_config');
    delete_transient('tdcc_runtime_cfg_v120');
    delete_transient('tdcc_runtime_cfg_v121');
    delete_transient('tdcc_runtime_cfg_v122');
    delete_transient('tdcc_runtime_cfg_v123');
    delete_transient('tuedion_cookie_system_report');
    delete_transient('tdcc_scanner_results');

    global $wpdb;
    if (isset($wpdb) && $wpdb instanceof \wpdb) {
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_tdcc_%' OR option_name LIKE '_transient_timeout_tdcc_%'");

        // Drop consent logs table
        $tableName = $wpdb->prefix . 'tuedion_consent_logs';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
        $wpdb->query("DROP TABLE IF EXISTS {$tableName}");
    }
}

$settings = get_option('tuedion_cookie_settings', []);
$cleanOnUninstall = !empty($settings['advanced']['clean_on_uninstall']);

if ($cleanOnUninstall) {
    global $wpdb;

    if (is_multisite() && isset($wpdb) && $wpdb instanceof \wpdb) {
        $blogIds = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");
        $originalBlogId = get_current_blog_id();

        if (is_array($blogIds)) {
            foreach ($blogIds as $bId) {
                switch_to_blog((int) $bId);
                tdcc_uninstall_single_site();
            }
        }

        switch_to_blog($originalBlogId);
    } else {
        tdcc_uninstall_single_site();
    }
}
