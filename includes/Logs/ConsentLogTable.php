<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Logs;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages the custom database table schema for GDPR-compliant consent logs.
 */
final class ConsentLogTable
{
    public const TABLE_NAME = 'tuedion_consent_logs';

    /**
     * Get prefixed table name.
     */
    public static function getTableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Install or update the table schema using dbDelta.
     */
    public static function install(): void
    {
        global $wpdb;

        $tableName = self::getTableName();
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$tableName} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            consent_uuid varchar(64) NOT NULL DEFAULT '',
            action varchar(32) NOT NULL DEFAULT 'consent',
            categories text NOT NULL,
            services text NOT NULL,
            revision int(11) NOT NULL DEFAULT 1,
            anonymized_ip varchar(45) NOT NULL DEFAULT '',
            user_agent_hash varchar(64) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY consent_uuid (consent_uuid),
            KEY action (action),
            KEY revision (revision),
            KEY created_at (created_at)
        ) {$charsetCollate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Drop table upon uninstall if clean_on_uninstall is enabled.
     */
    public static function drop(): void
    {
        global $wpdb;
        $tableName = esc_sql(self::getTableName());
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name escaped and cannot be prepared in SQL.
        $wpdb->query("DROP TABLE IF EXISTS {$tableName}");
    }
}
