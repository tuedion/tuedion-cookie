<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Diagnostics;

use Tuedion\CookieConsent\Integrations\CacheCompatibility;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cache and Minification Compatibility Inspector.
 * Detects active WordPress performance plugins and verifies exclusion rules.
 */
final class CacheInspector
{
    /**
     * Inspect active caching solutions and return diagnostic status.
     *
     * @return array{
     *     active_caching_plugins: list<array<string, string>>,
     *     cloudflare_detected: bool,
     *     exclusions_summary: list<string>,
     *     hints: list<string>
     * }
     */
    public static function inspect(): array
    {
        $activePlugins = [];
        $hints = [];

        // 1. WP Rocket
        if (defined('WP_ROCKET_VERSION') || function_exists('rocket_clean_domain')) {
            $activePlugins[] = [
                'name'    => 'WP Rocket',
                'status'  => 'Active & Hooked',
                'version' => defined('WP_ROCKET_VERSION') ? WP_ROCKET_VERSION : 'detected',
            ];
            $hints[] = __('WP Rocket is active: Tuedion Cookie has hooked rocket_delay_js_exclusions and rocket_exclude_defer_js automatically.', 'tuedion-cookie');
        }

        // 2. LiteSpeed Cache
        if (defined('LSCWP_V')) {
            $activePlugins[] = [
                'name'    => 'LiteSpeed Cache',
                'status'  => 'Active & Hooked',
                'version' => (string) LSCWP_V,
            ];
            $hints[] = __('LiteSpeed Cache is active: Automatic JS deferral and delay exclusions are registered.', 'tuedion-cookie');
        }

        // 3. Autoptimize
        if (defined('AUTOPTIMIZE_PLUGIN_VERSION')) {
            $activePlugins[] = [
                'name'    => 'Autoptimize',
                'status'  => 'Active & Hooked',
                'version' => (string) AUTOPTIMIZE_PLUGIN_VERSION,
            ];
            $hints[] = __('Autoptimize is active: Script exclude filter autoptimize_filter_js_exclude is hooked.', 'tuedion-cookie');
        }

        // 4. SiteGround Optimizer
        if (defined('SiteGround_Optimizer\VERSION')) {
            $activePlugins[] = [
                'name'    => 'SiteGround Optimizer',
                'status'  => 'Active & Hooked',
                'version' => (string) \SiteGround_Optimizer\VERSION,
            ];
            $hints[] = __('SiteGround Optimizer is active: JS combine and minify exclusions are hooked.', 'tuedion-cookie');
        }

        // 5. Perfmatters
        if (defined('PERFMATTERS_VERSION')) {
            $activePlugins[] = [
                'name'    => 'Perfmatters',
                'status'  => 'Active & Hooked',
                'version' => (string) PERFMATTERS_VERSION,
            ];
            $hints[] = __('Perfmatters is active: Delay and Defer JS filters are hooked.', 'tuedion-cookie');
        }

        // 6. FlyingPress
        if (defined('FLYING_PRESS_VERSION')) {
            $activePlugins[] = [
                'name'    => 'FlyingPress',
                'status'  => 'Active & Hooked',
                'version' => (string) FLYING_PRESS_VERSION,
            ];
            $hints[] = __('FlyingPress is active: Delay/defer exclusions are registered.', 'tuedion-cookie');
        }

        // 7. Check Cloudflare
        $isCloudflare = isset($_SERVER['HTTP_CF_RAY']) || isset($_SERVER['HTTP_CF_CONNECTING_IP']);
        if ($isCloudflare) {
            $hints[] = __('Cloudflare CDN detected: Ensure Rocket Loader does not alter consent scripts. Tuedion Cookie applies data-cfasync="false" to all consent tags.', 'tuedion-cookie');
        }

        if (empty($activePlugins)) {
            $hints[] = __('No page caching plugins detected. Server-side caching or object caching may still be active.', 'tuedion-cookie');
        }

        return [
            'active_caching_plugins' => $activePlugins,
            'cloudflare_detected'    => $isCloudflare,
            'exclusions_summary'     => CacheCompatibility::EXCLUDED_PATTERNS,
            'hints'                  => $hints,
        ];
    }
}
