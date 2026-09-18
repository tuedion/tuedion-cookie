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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_tdcc_%' OR option_name LIKE '_transient_timeout_tdcc_%'");

        // Drop consent logs table
        $tableName = esc_sql($wpdb->prefix . 'tuedion_consent_logs');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name escaped and cannot be parameterized in SQL.
        $wpdb->query("DROP TABLE IF EXISTS {$tableName}");
    }
}

/**
 * Main uninstall routine.
 */
function tdcc_uninstall_plugin(): void
{
    $settings = get_option('tuedion_cookie_settings', []);
    $cleanOnUninstall = !empty($settings['advanced']['clean_on_uninstall']);

    if (!$cleanOnUninstall) {
        return;
    }

    if (is_multisite()) {
        $blogIds = get_sites(['fields' => 'ids']);
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

tdcc_uninstall_plugin();
