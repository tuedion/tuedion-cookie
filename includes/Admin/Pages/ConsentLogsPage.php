<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Admin\Pages;

use Tuedion\CookieConsent\Logs\ConsentLogger;
use Tuedion\CookieConsent\Logs\LogCleaner;

if (!defined('ABSPATH')) {
    exit;
}

final class ConsentLogsPage
{
    /**
     * Handle admin-post CSV export request before any admin markup is rendered.
     */
    public static function handleExportPost(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'tuedion-cookie'));
        }

        $nonce = $_GET['_wpnonce'] ?? $_POST['_wpnonce'] ?? '';
        if (!wp_verify_nonce((string) $nonce, 'tdcc_export_logs_csv_nonce')) {
            wp_die(esc_html__('Security check failed or link expired. Please refresh the page and try again.', 'tuedion-cookie'), 403);
        }

        self::exportCsv();
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'tuedion-cookie'));
        }

        // Backward-compatible fallback if accessed directly
        if (isset($_GET['action']) && $_GET['action'] === 'tdcc_export_logs_csv') {
            $nonce = $_GET['_wpnonce'] ?? $_POST['_wpnonce'] ?? '';
            if (wp_verify_nonce((string) $nonce, 'tdcc_export_logs_csv_nonce')) {
                self::exportCsv();
                exit;
            }
        }

        $notice = null;
        $noticeType = 'success';

        // Handle Manual Purge
        if (isset($_POST['tdcc_purge_all_logs']) && check_admin_referer('tdcc_purge_logs_action', 'tdcc_purge_nonce')) {
            LogCleaner::purgeAllLogs();
            $notice = esc_html__('All consent log records have been permanently purged.', 'tuedion-cookie');
        }

        // Filtering & Pagination
        $page     = max(1, (int) ($_GET['paged'] ?? 1));
        $perPage  = 25;
        $search   = sanitize_text_field((string) ($_GET['s'] ?? ''));
        $action   = sanitize_key((string) ($_GET['action_filter'] ?? ''));
        $revision = isset($_GET['rev_filter']) && $_GET['rev_filter'] !== '' ? (int) $_GET['rev_filter'] : null;

        $queryResult = ConsentLogger::queryLogs([
            'page'     => $page,
            'per_page' => $perPage,
            'search'   => $search,
            'action'   => $action,
            'revision' => $revision,
        ]);

        $items = $queryResult['items'];
        $total = $queryResult['total'];
        $totalPages = (int) ceil($total / $perPage);

        $stats = LogCleaner::getLogStats();
        $isLoggingEnabled = ConsentLogger::isLoggingEnabled();
        $exportNonce = wp_create_nonce('tdcc_export_logs_csv_nonce');
        ?>
        <div class="wrap tdcc-admin-wrap">
            <header class="tdcc-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                <h1><?php echo esc_html__('Tuedion Cookie — Consent Audit Logs', 'tuedion-cookie'); ?></h1>
                <div class="tdcc-header-actions" style="display:flex; gap:10px;">
                    <a href="<?php echo esc_url(admin_url('admin-post.php?action=tdcc_export_logs_csv&_wpnonce=' . $exportNonce)); ?>" download="tuedion-consent-logs-<?php echo esc_attr(gmdate('Y-m-d')); ?>.csv" class="button button-secondary">
                        <span class="dashicons dashicons-download" style="vertical-align: text-bottom; margin-right: 4px;"></span>
                        <?php echo esc_html__('Export to CSV', 'tuedion-cookie'); ?>
                    </a>
                </div>
            </header>

            <?php if ($notice !== null): ?>
                <div class="notice notice-<?php echo esc_attr($noticeType); ?> is-dismissible tdcc-notice">
                    <p><?php echo esc_html($notice); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!$isLoggingEnabled): ?>
                <div class="notice notice-info tdcc-notice">
                    <p>
                        <strong><?php echo esc_html__('Consent Logging is Currently OFF:', 'tuedion-cookie'); ?></strong>
                        <?php echo esc_html__('No database writes are being performed. To log proof of consent under GDPR Art. 7(1), enable Consent Logging in Settings.', 'tuedion-cookie'); ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=tuedion-cookie-settings')); ?>"><?php echo esc_html__('Go to Settings', 'tuedion-cookie'); ?> &rarr;</a>
                    </p>
                </div>
            <?php endif; ?>

            <!-- Stats Bar -->
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:1rem; margin-bottom:1.5rem;">
                <div class="tdcc-card" style="margin:0; padding:1.25rem;">
                    <div style="font-size:12px; color:#64748b; font-weight:600; text-transform:uppercase;"><?php echo esc_html__('Logging Status', 'tuedion-cookie'); ?></div>
                    <div style="font-size:20px; font-weight:700; margin-top:4px;">
                        <?php if ($isLoggingEnabled): ?>
                            <span style="color:#16a34a;"><?php echo esc_html__('Active (Minimal/Cleanable)', 'tuedion-cookie'); ?></span>
                        <?php else: ?>
                            <span style="color:#dc2626;"><?php echo esc_html__('Disabled (Zero DB Writes)', 'tuedion-cookie'); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="tdcc-card" style="margin:0; padding:1.25rem;">
                    <div style="font-size:12px; color:#64748b; font-weight:600; text-transform:uppercase;"><?php echo esc_html__('Total Stored Records', 'tuedion-cookie'); ?></div>
                    <div style="font-size:20px; font-weight:700; margin-top:4px;"><?php echo esc_html(number_format_i18n($stats['total_count'])); ?></div>
                </div>
                <div class="tdcc-card" style="margin:0; padding:1.25rem;">
                    <div style="font-size:12px; color:#64748b; font-weight:600; text-transform:uppercase;"><?php echo esc_html__('Oldest Entry', 'tuedion-cookie'); ?></div>
                    <div style="font-size:14px; font-weight:600; margin-top:6px;"><?php echo esc_html($stats['oldest_date']); ?></div>
                </div>
                <div class="tdcc-card" style="margin:0; padding:1.25rem;">
                    <div style="font-size:12px; color:#64748b; font-weight:600; text-transform:uppercase;"><?php echo esc_html__('Newest Entry', 'tuedion-cookie'); ?></div>
                    <div style="font-size:14px; font-weight:600; margin-top:6px;"><?php echo esc_html($stats['newest_date']); ?></div>
                </div>
            </div>

            <!-- Filters Bar -->
            <div class="tdcc-card" style="margin-bottom: 1.5rem; padding: 1rem 1.25rem;">
                <form method="get" action="" style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                    <input type="hidden" name="page" value="tuedion-cookie-logs">

                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php echo esc_attr__('Search UUID or Masked IP...', 'tuedion-cookie'); ?>" style="min-width:240px;">

                    <select name="action_filter">
                        <option value=""><?php echo esc_html__('All Actions', 'tuedion-cookie'); ?></option>
                        <option value="accept_all" <?php selected($action, 'accept_all'); ?>><?php echo esc_html__('Accept All', 'tuedion-cookie'); ?></option>
                        <option value="accept_necessary" <?php selected($action, 'accept_necessary'); ?>><?php echo esc_html__('Reject Non-Essential', 'tuedion-cookie'); ?></option>
                        <option value="custom" <?php selected($action, 'custom'); ?>><?php echo esc_html__('Custom / Preferences', 'tuedion-cookie'); ?></option>
                        <option value="first_consent" <?php selected($action, 'first_consent'); ?>><?php echo esc_html__('First Consent', 'tuedion-cookie'); ?></option>
                        <option value="change" <?php selected($action, 'change'); ?>><?php echo esc_html__('Change / Revoke', 'tuedion-cookie'); ?></option>
                    </select>

                    <input type="submit" class="button" value="<?php echo esc_attr__('Filter', 'tuedion-cookie'); ?>">
                    <?php if ($search !== '' || $action !== '' || $revision !== null): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=tuedion-cookie-logs')); ?>" class="button button-link"><?php echo esc_html__('Reset', 'tuedion-cookie'); ?></a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Table of Logs -->
            <div class="tdcc-card" style="padding:0; overflow:hidden;">
                <table class="widefat striped" style="border:0; margin:0;">
                    <thead>
                        <tr>
                            <th scope="col" style="width:60px;"><?php echo esc_html__('ID', 'tuedion-cookie'); ?></th>
                            <th scope="col"><?php echo esc_html__('Consent UUID', 'tuedion-cookie'); ?></th>
                            <th scope="col"><?php echo esc_html__('Action', 'tuedion-cookie'); ?></th>
                            <th scope="col"><?php echo esc_html__('Accepted Categories', 'tuedion-cookie'); ?></th>
                            <th scope="col"><?php echo esc_html__('Policy Rev.', 'tuedion-cookie'); ?></th>
                            <th scope="col"><?php echo esc_html__('Masked IP', 'tuedion-cookie'); ?></th>
                            <th scope="col"><?php echo esc_html__('Date (UTC)', 'tuedion-cookie'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="7" style="text-align:center; padding: 2rem; color:#64748b;">
                                    <?php echo esc_html__('No consent audit logs recorded matching criteria.', 'tuedion-cookie'); ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $row): ?>
                                <?php
                                $categoriesList = json_decode((string) $row['categories'], true);
                                $categoriesStr = is_array($categoriesList) ? implode(', ', $categoriesList) : (string) $row['categories'];
                                ?>
                                <tr>
                                    <td><code>#<?php echo esc_html((string) $row['id']); ?></code></td>
                                    <td><code style="font-size:12px;"><?php echo esc_html((string) $row['consent_uuid']); ?></code></td>
                                    <td>
                                        <span class="tdcc-badge" style="text-transform:uppercase; font-size:11px;">
                                            <?php echo esc_html((string) $row['action']); ?>
                                        </span>
                                    </td>
                                    <td><strong><?php echo esc_html($categoriesStr); ?></strong></td>
                                    <td><code>v<?php echo esc_html((string) $row['revision']); ?></code></td>
                                    <td><code><?php echo esc_html((string) $row['anonymized_ip']); ?></code></td>
                                    <td><?php echo esc_html((string) $row['created_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="tablenav" style="margin-top:1rem;">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php printf(esc_html__('%s records', 'tuedion-cookie'), number_format_i18n($total)); ?></span>
                        <?php
                        echo paginate_links([
                            'base'      => add_query_arg('paged', '%#%'),
                            'format'    => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total'     => $totalPages,
                            'current'   => $page,
                        ]);
                        ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Purge Danger Zone -->
            <div class="tdcc-card" style="margin-top:2rem; border-left: 4px solid #dc2626;">
                <h3 style="color:#dc2626; margin-top:0;"><?php echo esc_html__('Data Retention & Purge Zone', 'tuedion-cookie'); ?></h3>
                <p class="description">
                    <?php echo esc_html__('Logs older than your configured retention threshold are automatically deleted by the daily cleanup cron. You can also manually purge all records immediately.', 'tuedion-cookie'); ?>
                </p>
                <form method="post" action="" class="tdcc-purge-form" data-confirm="<?php echo esc_attr__('Are you sure you want to permanently delete all consent records? This action cannot be undone.', 'tuedion-cookie'); ?>">
                    <?php wp_nonce_field('tdcc_purge_logs_action', 'tdcc_purge_nonce'); ?>
                    <input type="submit" name="tdcc_purge_all_logs" class="button button-link-delete" value="<?php echo esc_attr__('Truncate All Consent Logs', 'tuedion-cookie'); ?>">
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Escape cell values against CSV Formula Injection (Excel / LibreOffice DDE attack).
     *
     * @param mixed $value
     * @return string
     */
    private static function escapeCsvFormula(mixed $value): string
    {
        $str = (string) $value;
        if ($str !== '' && in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $str;
        }
        return $str;
    }

    /**
     * Stream CSV export of all consent records.
     */
    private static function exportCsv(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        global $wpdb;
        $tableName = \Tuedion\CookieConsent\Logs\ConsentLogTable::getTableName();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results("SELECT * FROM {$tableName} ORDER BY id DESC LIMIT 50000", ARRAY_A);
        $filename = 'tuedion-consent-logs-' . gmdate('Y-m-d') . '.csv';

        header('Content-Description: File Transfer');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0, no-cache');
        header('Pragma: public');

        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }

        // UTF-8 BOM for Microsoft Excel compatibility
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Header row
        fputcsv($out, ['ID', 'Consent UUID', 'Action', 'Categories', 'Services', 'Revision', 'Masked IP', 'User Agent Hash', 'Timestamp UTC']);

        foreach ($rows as $row) {
            fputcsv($out, [
                self::escapeCsvFormula($row['id']),
                self::escapeCsvFormula($row['consent_uuid']),
                self::escapeCsvFormula($row['action']),
                self::escapeCsvFormula($row['categories']),
                self::escapeCsvFormula($row['services']),
                self::escapeCsvFormula($row['revision']),
                self::escapeCsvFormula($row['anonymized_ip']),
                self::escapeCsvFormula($row['user_agent_hash']),
                self::escapeCsvFormula($row['created_at']),
            ]);
        }

        fclose($out);
        exit;
    }
}
