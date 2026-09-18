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

        // 2.5 Tuedion Public Integration CSS (depends on both vendors so custom overrides win)
        wp_enqueue_style(
            'tuedion-cookie-public',
            TUEDION_COOKIE_URL . 'assets/public/css/tuedion-cookie.css',
            ['cookieconsent-vendor', 'iframemanager-vendor'],
            TUEDION_COOKIE_VERSION
        );

        // Inject dynamic theme variables and full color tokens via ThemeManager
        $settings = Repository::getSettings();
        $theme = (array) ($settings['theme'] ?? Defaults::get()['theme']);
        $themeCss = ThemeManager::generateCss($theme);

        wp_add_inline_style('tuedion-cookie-public', $themeCss);

        // 3. Vendor CookieConsent UMD JS
        wp_enqueue_script(
            'cookieconsent-vendor',
            TUEDION_COOKIE_URL . 'assets/vendor/cookieconsent/3.1.0/cookieconsent.umd.js',
            [],
            '3.1.0',
            false // In head/early body for consent timing
        );

        // 3.5 IframeManager JS
        wp_enqueue_script(
            'iframemanager-vendor',
            TUEDION_COOKIE_URL . 'assets/vendor/iframemanager/1.2.5/iframemanager.js',
            [],
            '1.2.5',
            false
        );

        // 4. Tuedion Event Bridge JS
        wp_enqueue_script(
            'tuedion-cookie-event-bridge',
            TUEDION_COOKIE_URL . 'assets/public/js/event-bridge.js',
            [],
            TUEDION_COOKIE_VERSION,
            false
        );

        // 5. Tuedion Bootstrap JS
        wp_enqueue_script(
            'tuedion-cookie-bootstrap',
            TUEDION_COOKIE_URL . 'assets/public/js/bootstrap.js',
            ['cookieconsent-vendor', 'tuedion-cookie-event-bridge', 'iframemanager-vendor'],
            TUEDION_COOKIE_VERSION,
            false
        );

        // Exclude our scripts from WP Rocket Delay JS and Minification
        add_filter('script_loader_tag', [self::class, 'excludeScriptsFromOptimization'], 10, 3);

        // 6. Tuedion Persistent Privacy Trigger JS
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
                false
            );
        }

        // 8. Compile and inject deterministic runtime configuration
        $config = Compiler::compile();
        $settings = Repository::getSettings();
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
     * from delaying or breaking the core consent logic.
     */
    public static function excludeScriptsFromOptimization(string $tag, string $handle, string $src): string
    {
        $ourScripts = [
            'cookieconsent-vendor',
            'iframemanager-vendor',
            'tuedion-cookie-event-bridge',
            'tuedion-cookie-bootstrap',
        ];

        if (in_array($handle, $ourScripts, true)) {
            // data-no-optimize: Litespeed & WP Rocket
            // data-cfasync="false": Cloudflare & WP Rocket
            // data-no-minify="1": WP Rocket
            return str_replace('<script ', '<script data-no-optimize="1" data-no-minify="1" data-cfasync="false" ', $tag);
        }

        return $tag;
    }
}
