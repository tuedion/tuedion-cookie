<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Logs;

use Tuedion\CookieConsent\Settings\Repository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles scheduled daily retention cleanup and manual purge of consent logs.
 */
final class LogCleaner
{
    public const CRON_HOOK = 'tuedion_cookie_daily_log_cleanup';

    public static function register(): void
    {
        add_action('init', [self::class, 'ensureCronScheduled']);
        add_action(self::CRON_HOOK, [self::class, 'cleanExpiredLogs']);
    }

    /**
     * Schedule daily cron event if not already scheduled.
     */
    public static function ensureCronScheduled(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    /**
     * Delete consent records exceeding the retention days threshold.
     *
     * @return int Number of deleted records.
     */
    public static function cleanExpiredLogs(): int
    {
        $settings = Repository::getSettings();
        $retentionDays = max(7, min(730, (int) ($settings['logging']['retention_days'] ?? 90)));

        $cutoffDate = gmdate('Y-m-d H:i:s', time() - ($retentionDays * DAY_IN_SECONDS));

        global $wpdb;
        $tableName = ConsentLogTable::getTableName();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->query(
            $wpdb->prepare("DELETE FROM {$tableName} WHERE created_at < %s", $cutoffDate)
        );

        return (int) $deleted;
    }

    /**
     * Manually purge all records from the consent log table.
     *
     * @return bool
     */
    public static function purgeAllLogs(): bool
    {
        global $wpdb;
        $tableName = ConsentLogTable::getTableName();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->query("TRUNCATE TABLE {$tableName}");

        return $result !== false;
    }

    /**
     * Get aggregate statistics on consent logs table.
     *
     * @return array{total_count: int, oldest_date: string, newest_date: string}
     */
    public static function getLogStats(): array
    {
        global $wpdb;
        $tableName = ConsentLogTable::getTableName();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            "SELECT COUNT(*) as total, MIN(created_at) as oldest, MAX(created_at) as newest FROM {$tableName}",
            ARRAY_A
        );

        return [
            'total_count' => (int) ($row['total'] ?? 0),
            'oldest_date' => (string) ($row['oldest'] ?? '—'),
            'newest_date' => (string) ($row['newest'] ?? '—'),
        ];
    }
}
