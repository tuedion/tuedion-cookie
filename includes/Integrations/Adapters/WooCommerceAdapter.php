<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Integrations\Adapters;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce Ecosystem Adapter.
 * Guarantees zero checkout disruption, classifies session and cart cookies as strictly necessary,
 * and intercepts WooCommerce marketing extensions.
 */
final class WooCommerceAdapter
{
    /**
     * Strictly necessary WooCommerce first-party cookies.
     * These cookies MUST NOT be cleared or blocked even if the user rejects non-essential categories.
     *
     * @var list<array{name: string, description: string}>
     */
    private const ESSENTIAL_COOKIES = [
        [
            'name'        => 'woocommerce_cart_hash',
            'description' => 'Stores a hash of the shopping cart contents to detect changes across page loads.',
        ],
        [
            'name'        => 'woocommerce_items_in_cart',
            'description' => 'Keeps track of whether any items are currently in the cart.',
        ],
        [
            'name'        => 'wp_woocommerce_session_',
            'description' => 'Unique cryptographic session token identifying the current shopping basket.',
        ],
        [
            'name'        => 'woocommerce_recently_viewed',
            'description' => 'Stores recently viewed product IDs to display recommendations.',
        ],
    ];

    public static function register(): void
    {
        if (!self::isActive()) {
            return;
        }

        // Always add essential cookies to the necessary category manifest
        add_filter('tuedion_cookie_public_config', [self::class, 'injectEssentialCookies'], 10, 2);

        // Exclude WooCommerce AJAX endpoints from any script deferral/delays
        add_filter('tuedion_cookie_cache_exclusions', [self::class, 'addWooCommerceExclusions']);
    }

    /**
     * Check if WooCommerce is installed and active.
     */
    public static function isActive(): bool
    {
        return class_exists('WooCommerce') || defined('WC_VERSION');
    }

    /**
     * Get list of essential WooCommerce cookies.
     *
     * @return list<array{name: string, description: string}>
     */
    public static function getEssentialCookies(): array
    {
        return self::ESSENTIAL_COOKIES;
    }

    /**
     * Automatically register essential WooCommerce cookies under the 'necessary' category
     * in Orest Bida CookieConsent configuration.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function injectEssentialCookies(array $config, array $settings = []): array
    {
        if (!self::isActive() || !isset($config['categories']['necessary'])) {
            return $config;
        }

        if (!isset($config['categories']['necessary']['services']) || !is_array($config['categories']['necessary']['services'])) {
            $config['categories']['necessary']['services'] = [];
        }

        if (!isset($config['categories']['necessary']['services']['woocommerce'])) {
            $wcCookies = [];
            foreach (self::ESSENTIAL_COOKIES as $cookie) {
                $wcCookies[] = [
                    'name' => $cookie['name'],
                ];
            }
            $config['categories']['necessary']['services']['woocommerce'] = [
                'label'   => 'WooCommerce',
                'cookies' => $wcCookies,
            ];
        }

        return $config;
    }

    /**
     * Add WooCommerce AJAX fragments and checkout endpoints to cache exclusions.
     *
     * @param list<string> $exclusions
     * @return list<string>
     */
    public static function addWooCommerceExclusions(array $exclusions): array
    {
        $wcExclusions = [
            'wc-ajax=get_refreshed_fragments',
            'wc-ajax=update_order_review',
            'wc-ajax=checkout',
            'woocommerce_params',
            'wc_checkout_params',
            'wc_cart_fragments_params',
        ];

        foreach ($wcExclusions as $item) {
            if (!in_array($item, $exclusions, true)) {
                $exclusions[] = $item;
            }
        }

        return $exclusions;
    }
}
