<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Consent;

use Tuedion\CookieConsent\Diagnostics\Profiler;
use Tuedion\CookieConsent\Settings\Repository;
use Tuedion\CookieConsent\Settings\Schema;
use Tuedion\CookieConsent\Settings\Compiler;
use Tuedion\CookieConsent\Settings\ThemeManager;
use Tuedion\CookieConsent\Settings\Defaults;

if (!defined('ABSPATH')) {
    exit;
}

final class Frontend
{
    public static function register(): void
    {
        Profiler::init();
        add_action('wp_enqueue_scripts', [self::class, 'enqueueAssets']);
        PersistentTrigger::register();
    }

    public static function shouldLoad(): bool
    {
        if (is_admin()) {
            return false;
        }

        if (Profiler::isBypassed()) {
            return false;
        }

        $status = Repository::getStatus();

        // If disabled, never load
        if ($status === Schema::STATUS_DISABLED) {
            $shouldLoad = false;
        } elseif ($status === Schema::STATUS_DRAFT) {
            // In draft mode, only load for authorized administrators
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preview parameter check.
            $shouldLoad = current_user_can('manage_options') || !empty($_GET['tuedion_cookie_preview']);
        } else {
            // Enabled mode
            $shouldLoad = true;
        }

        /**
         * Filter whether Tuedion Cookie frontend assets should load.
         *
         * @param bool $shouldLoad
         * @param string $status Current plugin status.
         */
        return (bool) apply_filters('tuedion_cookie_should_load', $shouldLoad, $status);
    }

    public static function enqueueAssets(): void
    {
        if (!self::shouldLoad()) {
            return;
        }

        $settings = Repository::getSettings();
        $isDebug  = !empty($settings['advanced']['debug_mode']) || (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG);

        // Theme dynamic CSS variables
        $theme    = (array) ($settings['theme'] ?? Defaults::get()['theme']);
        $themeCss = ThemeManager::generateCss($theme);

        // WordPress 6.3 - 7.1+ native script loading strategy configuration
        $supportsStrategy = version_compare(get_bloginfo('version'), '6.3', '>=');
        $deferStrategyArgs = $supportsStrategy ? [
            'strategy'  => 'defer',
            'in_footer' => false,
        ] : false;

        if (!$isDebug) {
            // =========================================================================
            // PRODUCTION MODE: Unified High-Performance Bundles (0ms Render-Blocking)
            // =========================================================================

            // 1. Single Consolidated CSS (CookieConsent + IframeManager + Public CSS)
            wp_enqueue_style(
                'tuedion-cookie-public',
                TUEDION_COOKIE_URL . 'assets/public/css/tuedion-cookie.bundle.min.css',
                [],
                TUEDION_COOKIE_VERSION
            );
            wp_add_inline_style('tuedion-cookie-public', $themeCss);

            // Register dummy styles for backwards compatibility with plugins checking vendor handles
            wp_register_style('cookieconsent-vendor', false, ['tuedion-cookie-public'], '3.1.0');
            wp_register_style('iframemanager-vendor', false, ['tuedion-cookie-public'], '1.2.5');

            // 2. Single Consolidated JS (CookieConsent + IframeManager + EventBridge + GCM + Bootstrap + Trigger)
            wp_enqueue_script(
                'tuedion-cookie-bootstrap',
                TUEDION_COOKIE_URL . 'assets/public/js/tuedion-cookie.bundle.min.js',
                [],
                TUEDION_COOKIE_VERSION,
                $deferStrategyArgs
            );

            if (function_exists('wp_script_add_data')) {
                wp_script_add_data('tuedion-cookie-bootstrap', 'strategy', 'defer');
            }

            // Register aliases/dummy scripts for vendor handles so Elementor and theme scripts don't break or double-enqueue
            wp_register_script('cookieconsent-vendor', false, ['tuedion-cookie-bootstrap'], '3.1.0', true);
            wp_register_script('iframemanager-vendor', false, ['tuedion-cookie-bootstrap'], '1.2.5', true);
            wp_register_script('tuedion-cookie-event-bridge', false, ['tuedion-cookie-bootstrap'], TUEDION_COOKIE_VERSION, true);
            wp_register_script('tuedion-cookie-persistent-trigger', false, ['tuedion-cookie-bootstrap'], TUEDION_COOKIE_VERSION, true);
            wp_register_script('tuedion-cookie-gcm', false, ['tuedion-cookie-bootstrap'], TUEDION_COOKIE_VERSION, true);

        } else {
            // =========================================================================
            // DEBUG / DEV MODE: Separate Unbundled Assets (still using defer)
            // =========================================================================

            // 1. Vendor CookieConsent CSS
            wp_enqueue_style(
                'cookieconsent-vendor',
                TUEDION_COOKIE_URL . 'assets/vendor/cookieconsent/3.1.0/cookieconsent.css',
                [],
                '3.1.0'
            );

            // 2. IframeManager Vendor CSS
            wp_enqueue_style(
                'iframemanager-vendor',
                TUEDION_COOKIE_URL . 'assets/vendor/iframemanager/1.2.5/iframemanager.css',
                [],
                '1.2.5'
            );

            // 2.5 Tuedion Public Integration CSS
            wp_enqueue_style(
                'tuedion-cookie-public',
                TUEDION_COOKIE_URL . 'assets/public/css/tuedion-cookie.css',
                ['cookieconsent-vendor', 'iframemanager-vendor'],
                TUEDION_COOKIE_VERSION
            );
            wp_add_inline_style('tuedion-cookie-public', $themeCss);

            // 3. Vendor CookieConsent UMD JS
            wp_enqueue_script(
                'cookieconsent-vendor',
                TUEDION_COOKIE_URL . 'assets/vendor/cookieconsent/3.1.0/cookieconsent.umd.js',
                [],
                '3.1.0',
                $deferStrategyArgs
            );
            if (function_exists('wp_script_add_data')) {
                wp_script_add_data('cookieconsent-vendor', 'strategy', 'defer');
            }

            // 3.5 IframeManager JS
            wp_enqueue_script(
                'iframemanager-vendor',
                TUEDION_COOKIE_URL . 'assets/vendor/iframemanager/1.2.5/iframemanager.js',
                [],
                '1.2.5',
                $deferStrategyArgs
            );
            if (function_exists('wp_script_add_data')) {
                wp_script_add_data('iframemanager-vendor', 'strategy', 'defer');
            }

            // 4. Tuedion Event Bridge JS
            wp_enqueue_script(
                'tuedion-cookie-event-bridge',
                TUEDION_COOKIE_URL . 'assets/public/js/event-bridge.js',
                [],
                TUEDION_COOKIE_VERSION,
                $deferStrategyArgs
            );
            if (function_exists('wp_script_add_data')) {
                wp_script_add_data('tuedion-cookie-event-bridge', 'strategy', 'defer');
            }

            // 5. Tuedion Bootstrap JS
            wp_enqueue_script(
                'tuedion-cookie-bootstrap',
                TUEDION_COOKIE_URL . 'assets/public/js/bootstrap.js',
                ['cookieconsent-vendor', 'tuedion-cookie-event-bridge', 'iframemanager-vendor'],
                TUEDION_COOKIE_VERSION,
                $deferStrategyArgs
            );
            if (function_exists('wp_script_add_data')) {
                wp_script_add_data('tuedion-cookie-bootstrap', 'strategy', 'defer');
            }

            // 6. Persistent Privacy Trigger JS
            wp_enqueue_script(
                'tuedion-cookie-persistent-trigger',
                TUEDION_COOKIE_URL . 'assets/public/js/persistent-trigger.js',
                ['cookieconsent-vendor', 'tuedion-cookie-event-bridge'],
                TUEDION_COOKIE_VERSION,
                true // in footer
            );

            // 7. Google Consent Mode v2 JS
            if (\Tuedion\CookieConsent\Integrations\Google\ConsentModeV2::isEnabled()) {
                wp_enqueue_script(
                    'tuedion-cookie-gcm',
                    TUEDION_COOKIE_URL . 'assets/public/js/google-consent-mode.js',
                    ['tuedion-cookie-event-bridge'],
                    TUEDION_COOKIE_VERSION,
                    $deferStrategyArgs
                );
                if (function_exists('wp_script_add_data')) {
                    wp_script_add_data('tuedion-cookie-gcm', 'strategy', 'defer');
                }
            }
        }

        // Apply script loader tag filter to guarantee defer and immunity attributes
        add_filter('script_loader_tag', [self::class, 'excludeScriptsFromOptimization'], 10, 3);

        // 8. Compile and inject deterministic runtime configuration
        $config = Compiler::compile();
        $isLoggingEnabled = !empty($settings['logging']['enabled']);
        $config['ajaxUrl'] = admin_url('admin-ajax.php');
        $config['logNonce'] = $isLoggingEnabled ? wp_create_nonce('tdcc_log_consent_nonce') : '';
        $config['loggingEnabled'] = $isLoggingEnabled;
        $config['disableModal'] = Profiler::isModalDisabled();
        $config['debug'] = Profiler::isDebugActive();

        $inlineScript = sprintf(
            'window.tuedionCookieConfig = %s;',
            wp_json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
        );

        wp_add_inline_script('tuedion-cookie-bootstrap', $inlineScript, 'before');
    }

    /**
     * Prevent caching/optimization plugins (like WP Rocket, Autoptimize, Litespeed) 
     * from delaying or breaking the core consent logic while guaranteeing defer.
     */
    public static function excludeScriptsFromOptimization(string $tag, string $handle, string $src): string
    {
        $ourScripts = [
            'cookieconsent-vendor',
            'iframemanager-vendor',
            'tuedion-cookie-event-bridge',
            'tuedion-cookie-bootstrap',
            'tuedion-cookie-persistent-trigger',
            'tuedion-cookie-gcm',
        ];

        if (in_array($handle, $ourScripts, true)) {
            // Ensure defer attribute is explicitly present so PageSpeed and all browsers treat it as non-blocking
            if (!str_contains($tag, ' defer')) {
                $tag = str_replace('<script ', '<script defer ', $tag);
            }

            // Exclude from Cloudflare Rocket Loader and WP Rocket delay, but allow browser defer
            return str_replace('<script ', '<script data-cfasync="false" data-pagespeed-no-defer="false" ', $tag);
        }

        return $tag;
    }
}
