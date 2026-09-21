<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Integrations;

use Tuedion\CookieConsent\Settings\Compiler;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Universal Cache and Speed Optimizer Compatibility Layer.
 * Ensures Tuedion Cookie & CookieConsent scripts are excluded from JS delay/defer/combine
 * across all major WordPress performance plugins, CDNs (Cloudflare Rocket Loader),
 * and automatically purges page caches when consent configuration is published.
 */
final class CacheCompatibility
{
    /**
     * Script identifiers that must not be delayed, deferred, or merged by cache optimizers.
     */
    public const EXCLUDED_PATTERNS = [
        'tuedion',
        'tuedion-cookie',
        'tuedion-cookie.bundle.min.js',
        'tuedion-cookie.bundle.min.css',
        'tuedion-cookie-bundle',
        'cookieconsent',
        'cookieconsent.umd.js',
        'cookieconsent-vendor',
        'iframemanager',
        'iframemanager.js',
        'iframemanager.css',
        'iframemanager-vendor',
        'tdcc-managed',
        'tuedion-cookie-bootstrap',
        'tuedion-cookie-event-bridge',
        'tuedion-cookie-persistent-trigger',
        'tuedion-cookie-public',
        'tuedion-cookie-gcm',
        'google-consent-mode.js',
        'tuedionCookieConfig',
        'tuedionGcmConfig',
        'cc_cookie',
    ];

    public static function register(): void
    {
        // 1. WP Rocket — Exclude from Delay JS (interaction delay), but allow Defer JS
        add_filter('rocket_delay_js_exclusions', [self::class, 'filterArrayExclusions']);
        add_filter('rocket_exclude_js', [self::class, 'filterArrayExclusions']);
        add_filter('rocket_lazyload_iframe', [self::class, 'excludeIframeFromLazyload'], 10, 2);

        // 2. LiteSpeed Cache — Exclude from Delay JS, allow Defer JS
        add_filter('litespeed_optm_js_excludes', [self::class, 'filterArrayExclusions']);
        add_filter('litespeed_optm_delay_js_excludes', [self::class, 'filterArrayExclusions']);

        // 3. Autoptimize
        add_filter('autoptimize_filter_js_exclude', [self::class, 'filterCommaStringExclusions']);

        // 4. SiteGround Optimizer
        add_filter('sgo_javascript_combine_exclude', [self::class, 'filterArrayExclusions']);
        add_filter('sgo_js_minify_exclude', [self::class, 'filterArrayExclusions']);

        // 5. Perfmatters — Exclude from Delay JS, allow Defer JS
        add_filter('perfmatters_delay_js_exclusions', [self::class, 'filterArrayExclusions']);

        // 6. FlyingPress — Exclude from Delay JS, allow Defer JS
        add_filter('flying_press_delay_js_exclude', [self::class, 'filterArrayExclusions']);

        // 7. WP-Optimize
        add_filter('wp_optimize_minify_default_exclusions', [self::class, 'filterArrayExclusions']);

        // 8. Hummingbird (WPMU DEV)
        add_filter('wphb_combine_js_exclude', [self::class, 'filterArrayExclusions']);
        add_filter('wphb_minify_js_exclude', [self::class, 'filterArrayExclusions']);

        // 9. Breeze (Cloudways)
        add_filter('breeze_minify_js_exclude', [self::class, 'filterArrayExclusions']);

        // 10. Swift Performance
        add_filter('swift_performance_merge_scripts_exclude', [self::class, 'filterArrayExclusions']);

        // Cache Purge hooks on save/publish
        add_action('tuedion_cookie_settings_saved', [self::class, 'purgeAllCaches']);
        add_action('tuedion_cookie_purge_cache', [self::class, 'purgeAllCaches']);

        // Sync exclusions to WP Rocket DB option on activation and settings save
        add_action('tuedion_cookie_settings_saved', [self::class, 'syncWpRocketExclusions']);
        add_action('tuedion_cookie_activated', [self::class, 'syncWpRocketExclusions']);
    }

    /**
     * Filter array-based exclusion lists (WP Rocket, LiteSpeed, Perfmatters, Breeze, etc.).
     *
     * @param mixed $exclusions
     * @return array<int, string>
     */
    public static function filterArrayExclusions(mixed $exclusions): array
    {
        $list = is_array($exclusions) ? $exclusions : [];
        return array_unique(array_merge($list, self::EXCLUDED_PATTERNS));
    }

    /**
     * Filter comma-separated string exclusion lists (Autoptimize).
     *
     * @param mixed $excludeStr
     * @return string
     */
    public static function filterCommaStringExclusions(mixed $excludeStr): string
    {
        $currentStr = is_string($excludeStr) ? $excludeStr : '';
        $current = array_filter(array_map('trim', explode(',', $currentStr)));
        $merged = array_unique(array_merge($current, self::EXCLUDED_PATTERNS));
        return implode(', ', $merged);
    }

    /**
     * Exclude Tuedion-managed embed containers from WP Rocket iframe lazyload.
     *
     * @param bool $load
     * @param string $html
     * @return bool
     */
    public static function excludeIframeFromLazyload(bool $load, string $html): bool
    {
        if (str_contains($html, 'data-service') || str_contains($html, 'tdcc-managed') || str_contains($html, 'data-no-lazy')) {
            return false;
        }

        return $load;
    }

    /**
     * Write exclusion patterns directly to WP Rocket's database option.
     * Ensures exclusions persist in page-cached HTML where PHP filters don't run.
     */
    public static function syncWpRocketExclusions(): void
    {
        if (!defined('WP_ROCKET_VERSION')) {
            return;
        }

        $rocketOptions = get_option('wp_rocket_settings', []);
        if (!is_array($rocketOptions)) {
            return;
        }

        $dirty = false;

        // Sync delay_js_exclusions
        $delayExclusions = is_array($rocketOptions['delay_js_exclusions'] ?? null) ? $rocketOptions['delay_js_exclusions'] : [];
        $originalCount = count($delayExclusions);
        $delayExclusions = array_unique(array_merge($delayExclusions, self::EXCLUDED_PATTERNS));
        if (count($delayExclusions) !== $originalCount) {
            $rocketOptions['delay_js_exclusions'] = array_values($delayExclusions);
            $dirty = true;
        }

        // Ensure our patterns are NOT in exclude_defer_js (so WP Rocket allows defer)
        $deferExclusions = is_array($rocketOptions['exclude_defer_js'] ?? null) ? $rocketOptions['exclude_defer_js'] : [];
        $cleanedDefer = array_values(array_diff($deferExclusions, self::EXCLUDED_PATTERNS));
        if (count($cleanedDefer) !== count($deferExclusions)) {
            $rocketOptions['exclude_defer_js'] = $cleanedDefer;
            $dirty = true;
        }

        // Sync exclude_js (minification)
        $minifyExclusions = is_array($rocketOptions['exclude_js'] ?? null) ? $rocketOptions['exclude_js'] : [];
        $originalMinifyCount = count($minifyExclusions);
        $minifyExclusions = array_unique(array_merge($minifyExclusions, self::EXCLUDED_PATTERNS));
        if (count($minifyExclusions) !== $originalMinifyCount) {
            $rocketOptions['exclude_js'] = array_values($minifyExclusions);
            $dirty = true;
        }

        if ($dirty) {
            update_option('wp_rocket_settings', $rocketOptions);

            // Clear WP Rocket cache so new exclusions take effect
            if (function_exists('rocket_clean_domain')) {
                rocket_clean_domain();
            }
        }
    }

    /**
     * Flush all known caching engines upon settings update or manual purge.
     */
    public static function purgeAllCaches(): void
    {
        // 1. Purge internal transient
        Compiler::clearCache();

        // 2. WP Rocket
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        // 3. LiteSpeed Cache
        if (defined('LSCWP_V')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party cache plugin purge action.
            do_action('litespeed_purge_all');
        }

        // 4. Autoptimize
        if (class_exists('autoptimizeCache') && method_exists('autoptimizeCache', 'clearall')) {
            \autoptimizeCache::clearall();
        }

        // 5. WP Super Cache
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }

        // 6. W3 Total Cache
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }

        // 7. WP Fastest Cache
        if (class_exists('WpFastestCache') && isset($GLOBALS['wp_fastest_cache']) && method_exists($GLOBALS['wp_fastest_cache'], 'deleteCache')) {
            $GLOBALS['wp_fastest_cache']->deleteCache(true);
        }

        // 8. WP-Optimize
        if (function_exists('WP_Optimize')) {
            $wpOptimize = \WP_Optimize();
            if ($wpOptimize && method_exists($wpOptimize, 'get_page_cache')) {
                $pageCache = $wpOptimize->get_page_cache();
                if ($pageCache && method_exists($pageCache, 'purge')) {
                    $pageCache->purge();
                }
            }
        } elseif (class_exists('WP_Optimize') && method_exists('WP_Optimize', 'instance')) {
            $wpOptimize = \WP_Optimize::instance();
            if ($wpOptimize && method_exists($wpOptimize, 'get_page_cache')) {
                $pageCache = $wpOptimize->get_page_cache();
                if ($pageCache && method_exists($pageCache, 'purge')) {
                    $pageCache->purge();
                }
            }
        }

        // 9. Hummingbird
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party cache plugin purge action.
        do_action('wphb_clear_page_cache');

        // 10. Breeze (Cloudways)
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party cache plugin purge action.
        do_action('breeze_clear_all_cache');

        // 11. FlyingPress
        if (class_exists('FlyingPress\Purge') && method_exists('FlyingPress\Purge', 'purge_everything')) {
            \FlyingPress\Purge::purge_everything();
        }

        // 12. NitroPack
        if (function_exists('nitropack_purge_cache')) {
            nitropack_purge_cache();
        }
    }

    /**
     * Detect all active performance and caching layers in the current environment.
     *
     * @return list<string>
     */
    public static function getActiveCachePlugins(): array
    {
        $detected = [];

        if (function_exists('rocket_clean_domain') || defined('WP_ROCKET_VERSION')) {
            $detected[] = 'WP Rocket (' . (defined('WP_ROCKET_VERSION') ? WP_ROCKET_VERSION : 'Active') . ')';
        }

        if (defined('LSCWP_V')) {
            $detected[] = 'LiteSpeed Cache (v' . LSCWP_V . ')';
        }

        if (class_exists('autoptimizeCache')) {
            $detected[] = 'Autoptimize';
        }

        if (defined('W3TC')) {
            $detected[] = 'W3 Total Cache';
        }

        if (function_exists('wp_cache_clear_cache')) {
            $detected[] = 'WP Super Cache';
        }

        if (class_exists('WpFastestCache')) {
            $detected[] = 'WP Fastest Cache';
        }

        if (class_exists('WP_Optimize')) {
            $detected[] = 'WP-Optimize';
        }

        if (class_exists('SiteGround_Optimizer\Helper\Helper')) {
            $detected[] = 'SiteGround Optimizer';
        }

        if (defined('PERFMATTERS_VERSION')) {
            $detected[] = 'Perfmatters (v' . PERFMATTERS_VERSION . ')';
        }

        if (class_exists('FlyingPress\Plugin')) {
            $detected[] = 'FlyingPress';
        }

        if (defined('BREEZE_VERSION')) {
            $detected[] = 'Breeze (v' . BREEZE_VERSION . ')';
        }

        if (function_exists('wphb_clear_page_cache')) {
            $detected[] = 'Hummingbird';
        }

        if (function_exists('nitropack_purge_cache')) {
            $detected[] = 'NitroPack';
        }

        return $detected;
    }
}
