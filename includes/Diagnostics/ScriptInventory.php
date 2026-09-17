<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Diagnostics;

use Tuedion\CookieConsent\Integrations\RecipeRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Script Inventory and Unmanaged Tracker Scanner.
 * Scans enqueued WordPress scripts and identifies managed vs unmanaged external tracking scripts.
 */
final class ScriptInventory
{
    /**
     * Core and functional script handles that are inherently exempt from consent blocking.
     */
    private const CORE_HANDLES = [
        'jquery',
        'jquery-core',
        'jquery-migrate',
        'wp-embed',
        'wp-polyfill',
        'wp-hooks',
        'wp-i18n',
        'wp-element',
        'react',
        'react-dom',
        'cookieconsent-vendor',
        'tuedion-cookie-event-bridge',
        'tuedion-cookie-bootstrap',
        'tuedion-cookie-persistent-trigger',
        'tuedion-cookie-gcm',
    ];

    /**
     * Known third-party tracking, advertising, and analytics domains that require consent.
     */
    private const KNOWN_TRACKER_DOMAINS = [
        'doubleclick.net',
        'googlesyndication.com',
        'adservice.google.',
        'criteo.com',
        'outbrain.com',
        'taboola.com',
        'adnxs.com',
        'adroll.com',
        'yandex.ru',
        'mc.yandex.',
        'crazyegg.com',
        'mouseflow.com',
        'smartlook.com',
        'luckyorange.com',
        'quantserve.com',
        'branch.io',
        'appsflyer.com',
        'mixpanel.com',
        'segment.com',
        'fullstory.com',
        'statcounter.com',
    ];

    /**
     * Scan and categorize all registered and enqueued scripts.
     *
     * @return array{
     *     total: int,
     *     managed: list<array<string, mixed>>,
     *     core: list<array<string, mixed>>,
     *     functional: list<array<string, mixed>>,
     *     unmanaged: list<array<string, mixed>>
     * }
     */
    public static function getInventory(): array
    {
        global $wp_scripts;

        $managed = [];
        $core = [];
        $functional = [];
        $unmanaged = [];

        $queue = [];
        if ($wp_scripts instanceof \WP_Scripts && !empty($wp_scripts->registered)) {
            $queue = $wp_scripts->registered;
        }

        foreach ($queue as $handle => $scriptObj) {
            $src = is_object($scriptObj) && isset($scriptObj->src) ? (string) $scriptObj->src : '';
            $handleStr = (string) $handle;

            // 1. Core check
            if (in_array($handleStr, self::CORE_HANDLES, true) || str_contains($src, '/wp-includes/js/')) {
                $core[] = [
                    'handle' => $handleStr,
                    'src'    => $src,
                    'type'   => 'core',
                ];
                continue;
            }

            // 2. Recipe match check
            $recipe = RecipeRegistry::findRecipeForScript($handleStr, $src);
            if ($recipe !== null) {
                $managed[] = [
                    'handle'   => $handleStr,
                    'src'      => $src,
                    'type'     => 'managed',
                    'recipe'   => $recipe['name'] ?? $recipe['id'],
                    'category' => $recipe['category'] ?? 'marketing',
                ];
                continue;
            }

            // 3. Check for unmanaged tracking patterns
            $isKnownTracker = false;
            $srcLower = strtolower($src);
            foreach (self::KNOWN_TRACKER_DOMAINS as $domain) {
                if (str_contains($srcLower, $domain)) {
                    $isKnownTracker = true;
                    break;
                }
            }

            if ($isKnownTracker) {
                $unmanaged[] = [
                    'handle'             => $handleStr,
                    'src'                => $src,
                    'type'               => 'unmanaged_tracker',
                    'risk'               => 'high',
                    'suggested_category' => 'marketing',
                ];
                continue;
            }

            // 4. Standard functional theme/plugin script
            $functional[] = [
                'handle' => $handleStr,
                'src'    => $src,
                'type'   => 'functional',
            ];
        }

        return [
            'total'      => count($managed) + count($core) + count($functional) + count($unmanaged),
            'managed'    => $managed,
            'core'       => $core,
            'functional' => $functional,
            'unmanaged'  => $unmanaged,
        ];
    }

    /**
     * Get list of unmanaged trackers requiring attention.
     *
     * @return list<array<string, mixed>>
     */
    public static function getUnmanagedTrackers(): array
    {
        return self::getInventory()['unmanaged'];
    }
}
