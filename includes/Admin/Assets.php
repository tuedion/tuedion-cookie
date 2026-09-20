<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Admin;

use Tuedion\CookieConsent\Settings\ThemeManager;

if (!defined('ABSPATH')) {
    exit;
}

final class Assets
{
    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAdminAssets']);
    }

    public static function enqueueAdminAssets(string $hookSuffix): void
    {
        // Strictly scoped enqueue: only load on Tuedion Cookie admin screens
        if (!str_contains($hookSuffix, 'tuedion-cookie')) {
            return;
        }

        $cssVer = file_exists(TUEDION_COOKIE_PATH . 'assets/admin/css/admin.css')
            ? (string) filemtime(TUEDION_COOKIE_PATH . 'assets/admin/css/admin.css')
            : TUEDION_COOKIE_VERSION;

        $jsVer = file_exists(TUEDION_COOKIE_PATH . 'assets/admin/js/admin.js')
            ? (string) filemtime(TUEDION_COOKIE_PATH . 'assets/admin/js/admin.js')
            : TUEDION_COOKIE_VERSION;

        wp_enqueue_style(
            'tuedion-cookie-admin',
            TUEDION_COOKIE_URL . 'assets/admin/css/admin.css',
            [],
            $cssVer
        );

        wp_enqueue_script(
            'tuedion-cookie-admin',
            TUEDION_COOKIE_URL . 'assets/admin/js/admin.js',
            ['jquery'],
            $jsVer,
            true
        );

        wp_localize_script('tuedion-cookie-admin', 'tuedionCookieAdminData', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('tuedion_cookie_admin_nonce'),
            'themePresets' => ThemeManager::getPresets(),
            'strings'      => [
                'saving'            => esc_html__('Saving...', 'tuedion-cookie'),
                'saved'             => esc_html__('Settings saved successfully.', 'tuedion-cookie'),
                'error'             => esc_html__('An error occurred. Please try again.', 'tuedion-cookie'),
                'adding'            => esc_html__('Adding...', 'tuedion-cookie'),
                'inPreferences'     => esc_html__('In Preferences', 'tuedion-cookie'),
                'configuredInPrefs' => esc_html__('Configured in Preferences', 'tuedion-cookie'),
                'deleting'          => esc_html__('Deleting...', 'tuedion-cookie'),
                'deleted'           => esc_html__('Service removed from cookie preferences.', 'tuedion-cookie'),
                'deleteConfirm'     => esc_html__('Delete this service?', 'tuedion-cookie'),
                'delete'            => esc_html__('Delete', 'tuedion-cookie'),
                'syncing'           => esc_html__('Syncing services...', 'tuedion-cookie'),
                'syncSuccess'       => esc_html__('Detected services synchronized to preferences.', 'tuedion-cookie'),
                'noServices'        => esc_html__('No granular services defined yet. Trackers will be governed at category level.', 'tuedion-cookie'),
                'addService'        => esc_html__('+ Add Service', 'tuedion-cookie'),
                'addToPreferences'  => esc_html__('+ Add to Preferences', 'tuedion-cookie'),
            ],
        ]);

        if (str_contains($hookSuffix, 'tuedion-cookie-categories')) {
            $scannerJsPath = TUEDION_COOKIE_PATH . 'assets/admin/js/cookie-scanner.js';
            $scannerVer = file_exists($scannerJsPath)
                ? (string) filemtime($scannerJsPath)
                : TUEDION_COOKIE_VERSION;

            wp_enqueue_script(
                'tuedion-cookie-scanner',
                TUEDION_COOKIE_URL . 'assets/admin/js/cookie-scanner.js',
                ['tuedion-cookie-admin'],
                $scannerVer,
                true
            );

            $urlsToScan = [
                home_url('/?tdcc_audit=1'),
            ];

            if (class_exists('WooCommerce')) {
                $shopId = get_option('woocommerce_shop_page_id');
                if ($shopId) {
                    $shopPermalink = (string) get_permalink($shopId);
                    $separator = str_contains($shopPermalink, '?') ? '&' : '?';
                    $urlsToScan[] = $shopPermalink . $separator . 'tdcc_audit=1';
                }
            }

            wp_localize_script('tuedion-cookie-scanner', 'tdccScannerConfig', [
                'ajaxUrl'    => admin_url('admin-ajax.php'),
                'nonce'      => wp_create_nonce('tuedion_scanner_action'),
                'urlsToScan' => $urlsToScan,
                'strings'    => [
                    'scanningPages'    => esc_html__('Scanning pages...', 'tuedion-cookie'),
                    'analyzingResults' => esc_html__('Analyzing results...', 'tuedion-cookie'),
                    'timeoutError'     => esc_html__('Scan timed out. Please try again.', 'tuedion-cookie'),
                    'networkError'     => esc_html__('Network error during scan processing.', 'tuedion-cookie'),
                    'genericError'     => esc_html__('Error occurred during scan.', 'tuedion-cookie'),
                ],
            ]);
        }
    }
}
