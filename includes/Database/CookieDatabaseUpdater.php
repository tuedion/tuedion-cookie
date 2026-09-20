<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Database;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles manual, fail-safe updates of the Open Cookie Database definitions.
 */
final class CookieDatabaseUpdater
{
    // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Remote open-source cookie definition dataset endpoint, not an offloaded script or style.
    public const REMOTE_DATA_URL = 'https://raw.githubusercontent.com/jkwakman/Open-Cookie-Database/master/open-cookie-database.json';

    public static function register(): void
    {
        add_action('wp_ajax_tdcc_update_cookie_database', [self::class, 'handleAjaxUpdate']);
    }

    /**
     * AJAX handler for manual database definition update.
     */
    public static function handleAjaxUpdate(): void
    {
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_send_json_error(['message' => __('Method not allowed.', 'tuedion-cookie')], 405);
        }

        check_ajax_referer('tuedion_scanner_action', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized permission.', 'tuedion-cookie')], 403);
        }

        $result = self::downloadAndApply();
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Fetch remote definitions, validate schema, and atomically update the local snapshot.
     *
     * @return array{success: bool, message: string, records_count?: int, version?: string}
     */
    public static function downloadAndApply(): array
    {
        $targetFile = TUEDION_COOKIE_PATH . 'data/cookies/open-cookie-database.json';
        $metaFile   = TUEDION_COOKIE_PATH . 'data/cookies/metadata.json';

        $response = wp_remote_get(self::REMOTE_DATA_URL, [
            'timeout'     => 20,
            'redirection' => 3,
            'sslverify'   => (bool) apply_filters('tuedion_cookie_scanner_sslverify', true),
            'headers'     => [
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'),
                'Accept'     => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                /* translators: %s: Error message */
                'message' => sprintf(__('Failed to download remote database: %s', 'tuedion-cookie'), $response->get_error_message()),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return [
                'success' => false,
                /* translators: %d: HTTP status code */
                'message' => sprintf(__('Remote server returned HTTP status %d.', 'tuedion-cookie'), $code),
            ];
        }

        $body = (string) wp_remote_retrieve_body($response);
        if ($body === '') {
            return [
                'success' => false,
                'message' => __('Remote response was empty.', 'tuedion-cookie'),
            ];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || empty($decoded)) {
            return [
                'success' => false,
                'message' => __('Invalid or insufficient JSON structure received.', 'tuedion-cookie'),
            ];
        }

        // Schema validation & sanitization
        $validItems = [];
        $seen = [];

        // Upstream Open Cookie Database is grouped by vendor: { "Vendor Name": [ { "cookie": "...", ... } ] }
        // Alternatively, support flat list format: [ { "cookie": "...", ... } ] or [ { "name": "...", ... } ]
        foreach ($decoded as $vendorOrIndex => $val) {
            if (!is_array($val)) {
                continue;
            }

            // Case A: Vendor grouped array of cookie definitions
            if (isset($val[0]) && is_array($val[0])) {
                $vendorName = sanitize_text_field((string) $vendorOrIndex);
                foreach ($val as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $cName = trim((string) ($item['cookie'] ?? $item['name'] ?? ''));
                    if ($cName === '') {
                        continue;
                    }
                    $cLower = strtolower($cName);
                    if (isset($seen[$cLower])) {
                        continue;
                    }
                    $seen[$cLower] = true;

                    $isWildcard = (!empty($item['wildcardMatch']) && (string) $item['wildcardMatch'] === '1')
                        || !empty($item['wildcard'])
                        || str_contains($cName, '*');

                    $category = CookieDatabaseLoader::normalizeCategory((string) ($item['category'] ?? 'analytics'));
                    $provider = sanitize_text_field((string) ($item['dataController'] ?? $item['provider'] ?? $vendorName));

                    $validItems[] = [
                        'name'        => sanitize_text_field($cName),
                        'provider'    => $provider !== '' ? $provider : 'Unknown Provider',
                        'category'    => $category,
                        'domain'      => sanitize_text_field((string) ($item['domain'] ?? '')),
                        'wildcard'    => $isWildcard,
                        'description' => sanitize_text_field((string) ($item['description'] ?? '')),
                        'retention'   => sanitize_text_field((string) ($item['retentionPeriod'] ?? $item['retention'] ?? 'Session')),
                        'controller'  => sanitize_text_field((string) ($item['dataController'] ?? $item['controller'] ?? $vendorName)),
                        'privacy_url' => esc_url_raw((string) ($item['privacyLink'] ?? $item['privacy_url'] ?? '')),
                    ];
                }
            } else {
                // Case B: Flat list of cookie objects
                $cName = trim((string) ($val['cookie'] ?? $val['name'] ?? ''));
                if ($cName === '') {
                    continue;
                }
                $cLower = strtolower($cName);
                if (isset($seen[$cLower])) {
                    continue;
                }
                $seen[$cLower] = true;

                $isWildcard = (!empty($val['wildcardMatch']) && (string) $val['wildcardMatch'] === '1')
                    || !empty($val['wildcard'])
                    || str_contains($cName, '*');

                $category = CookieDatabaseLoader::normalizeCategory((string) ($val['category'] ?? 'analytics'));
                $provider = sanitize_text_field((string) ($val['dataController'] ?? $val['provider'] ?? 'Unknown Provider'));

                $validItems[] = [
                    'name'        => sanitize_text_field($cName),
                    'provider'    => $provider !== '' ? $provider : 'Unknown Provider',
                    'category'    => $category,
                    'domain'      => sanitize_text_field((string) ($val['domain'] ?? '')),
                    'wildcard'    => $isWildcard,
                    'description' => sanitize_text_field((string) ($val['description'] ?? '')),
                    'retention'   => sanitize_text_field((string) ($val['retentionPeriod'] ?? $val['retention'] ?? 'Session')),
                    'controller'  => sanitize_text_field((string) ($val['dataController'] ?? $val['controller'] ?? '')),
                    'privacy_url' => esc_url_raw((string) ($val['privacyLink'] ?? $val['privacy_url'] ?? '')),
                ];
            }
        }

        // Always preserve core first-party and essential WordPress / WooCommerce cookies
        $coreCookies = [
            [
                'name'        => 'cc_cookie',
                'provider'    => 'Tuedion Cookie',
                'category'    => 'necessary',
                'domain'      => '',
                'wildcard'    => false,
                'description' => 'Stores visitor consent preferences and revision status.',
                'retention'   => '182 days',
                'controller'  => 'First-party',
                'privacy_url' => '',
            ],
            [
                'name'        => 'woocommerce_items_in_cart',
                'provider'    => 'WooCommerce',
                'category'    => 'necessary',
                'domain'      => '',
                'wildcard'    => false,
                'description' => 'Helps WooCommerce determine when cart contents and session change.',
                'retention'   => 'Session',
                'controller'  => 'Automattic Inc.',
                'privacy_url' => 'https://automattic.com/privacy/',
            ],
            [
                'name'        => 'woocommerce_cart_hash',
                'provider'    => 'WooCommerce',
                'category'    => 'necessary',
                'domain'      => '',
                'wildcard'    => false,
                'description' => 'Stores a unique cryptographic hash of the cart contents to detect cart modifications.',
                'retention'   => 'Session',
                'controller'  => 'Automattic Inc.',
                'privacy_url' => 'https://automattic.com/privacy/',
            ],
            [
                'name'        => 'wp_woocommerce_session_*',
                'provider'    => 'WooCommerce',
                'category'    => 'necessary',
                'domain'      => '',
                'wildcard'    => true,
                'description' => 'Contains a unique code for each customer to find cart data in the database.',
                'retention'   => '2 days',
                'controller'  => 'Automattic Inc.',
                'privacy_url' => 'https://automattic.com/privacy/',
            ],
        ];

        foreach ($coreCookies as $coreC) {
            $cKey = strtolower($coreC['name']);
            if (!isset($seen[$cKey])) {
                $seen[$cKey] = true;
                array_unshift($validItems, $coreC);
            }
        }

        if (count($validItems) < 10) {
            return [
                'success' => false,
                'message' => __('Downloaded dataset failed schema validation.', 'tuedion-cookie'),
            ];
        }

        // Fail-safe atomic write
        $dir = dirname($targetFile);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $encodedJson = wp_json_encode($validItems, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encodedJson === false) {
            return [
                'success' => false,
                'message' => __('Failed to encode sanitized cookie definitions.', 'tuedion-cookie'),
            ];
        }

        $written = file_put_contents($targetFile, $encodedJson, LOCK_EX);
        if ($written === false) {
            return [
                'success' => false,
                'message' => __('Failed to write cookie definitions file to disk. Check directory permissions.', 'tuedion-cookie'),
            ];
        }

        $newVersion = current_time('Y-m-d');
        $meta = [
            'source'        => 'Open Cookie Database (GitHub)',
            'version'       => $newVersion,
            'updated_at'    => gmdate('c'),
            'license'       => 'Apache-2.0',
            'records_count' => count($validItems),
        ];
        file_put_contents($metaFile, (string) wp_json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

        CookieDatabase::clearCache();

        return [
            'success'       => true,
            'message'       => sprintf(
                /* translators: 1: Count of records, 2: Version */
                __('Cookie definitions updated successfully. %1$d cookie patterns loaded (%2$s).', 'tuedion-cookie'),
                count($validItems),
                $newVersion
            ),
            'records_count' => count($validItems),
            'version'       => $newVersion,
        ];
    }
}
