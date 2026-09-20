<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Diagnostics;

use Tuedion\CookieConsent\Consent\CookieTableBuilder;
use Tuedion\CookieConsent\Integrations\RecipeRegistry;
use Tuedion\CookieConsent\Settings\Compiler;
use Tuedion\CookieConsent\Settings\Repository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enterprise Cookie & Tracking Service Scanner.
 * Crawls live pages, inspects enqueued scripts/styles and active plugins,
 * and identifies real active trackers, embedded media iframes, and cookies in use.
 */
final class CookieScanner
{
    public const OPTION_KEY = 'tdcc_scanner_results';
    public const CRON_HOOK = 'tdcc_scheduled_cookie_scan';
    public const KNOWN_COOKIES_OPTION = 'tdcc_known_cookies';

    public static function register(): void
    {
        add_action('wp_ajax_tdcc_run_cookie_scan', [self::class, 'handleAjaxScan']);
        add_action('wp_ajax_tdcc_sync_scanned_services', [self::class, 'handleAjaxSync']);

        // Frontend Client-Side Scanner Asset Hook
        add_action('wp_enqueue_scripts', [self::class, 'enqueueClientSideScanner']);

        // WP-Cron Scheduled Scanning & Notifications
        add_filter('cron_schedules', [self::class, 'registerCronIntervals']);
        add_action(self::CRON_HOOK, [self::class, 'runScheduledScan']);
        add_action('tuedion_cookie_settings_saved', [self::class, 'syncCronFromSettings']);
    }

    /**
     * Enqueue the sandboxed Client-Side scanner script on frontend when ?tdcc_audit=1 is requested by an admin.
     */
    public static function enqueueClientSideScanner(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only audit query parameter check.
        if (!isset($_GET['tdcc_audit']) || !current_user_can('manage_options')) {
            return;
        }

        $scriptPath = TUEDION_COOKIE_PATH . 'assets/public/js/scanner-client.js';
        $ver = file_exists($scriptPath) ? (string) filemtime($scriptPath) : TUEDION_COOKIE_VERSION;

        wp_enqueue_script(
            'tdcc-scanner-client',
            TUEDION_COOKIE_URL . 'assets/public/js/scanner-client.js',
            [],
            $ver,
            true
        );
    }

    /**
     * Retrieve stored scan results or run a lightweight detection if none exists.
     *
     * @return array<string, mixed>
     */
    public static function getResults(): array
    {
        $cached = get_option(self::OPTION_KEY, null);
        if (is_array($cached) && !empty($cached['timestamp'])) {
            return $cached;
        }

        // Run an initial lightweight scan (enqueued + plugins) without heavy HTTP crawl
        return self::scan(false);
    }

    /**
     * Execute site scan and identify active tracking services and cookies.
     *
     * @param bool $deepHttp Whether to make a live HTTP request to crawl page HTML.
     * @return array<string, mixed>
     */
    public static function scan(bool $deepHttp = true): array
    {
        $detectedServices = [];
        $scannedUrls = [home_url('/')];

        // 1. Scan Enqueued Scripts & Styles
        $enqueuedDetections = self::scanEnqueuedAssets();
        foreach ($enqueuedDetections as $key => $data) {
            $detectedServices[$key] = $data;
        }

        // 2. Scan Active WordPress Plugins
        $pluginDetections = self::scanActivePlugins();
        foreach ($pluginDetections as $key => $data) {
            if (!isset($detectedServices[$key])) {
                $detectedServices[$key] = $data;
            } else {
                $detectedServices[$key]['sources'][] = $data['source'];
            }
        }

        // 3. Live HTTP Page Crawler (if enabled)
        $httpCookies = [];
        if ($deepHttp) {
            $crawlResult = self::crawlUrl(home_url('/?tdcc_safe=1'));
            foreach ($crawlResult['services'] as $key => $data) {
                if (!isset($detectedServices[$key])) {
                    $detectedServices[$key] = $data;
                } else {
                    $detectedServices[$key]['sources'][] = $data['source'];
                }
            }
            $httpCookies = $crawlResult['cookies'];
        }

        // 4. Enrich Detected Cookies Catalog
        $detectedCookies = self::buildCookiesCatalog(array_keys($detectedServices), $httpCookies);

        // 5. Build Stats & Summary
        $allRecipes = RecipeRegistry::getAll();
        $totalCatalogueCount = count($allRecipes);
        $detectedCount = count($detectedServices);

        $results = [
            'timestamp'         => time(),
            'last_scan_date'    => current_time('mysql'),
            'last_scan_human'   => human_time_diff(time(), time()) . ' ' . __('ago', 'tuedion-cookie'),
            'scanned_urls'      => $scannedUrls,
            'detected_services' => $detectedServices,
            'detected_cookies'  => $detectedCookies,
            'stats'             => [
                'detected_services_count' => $detectedCount,
                'detected_cookies_count'  => count($detectedCookies),
                'catalogue_total_count'   => $totalCatalogueCount,
                'coverage_percent'        => $totalCatalogueCount > 0 ? (int) round(($detectedCount / $totalCatalogueCount) * 100) : 0,
            ],
        ];

        update_option(self::OPTION_KEY, $results, false);
        Compiler::clearCache();

        return $results;
    }

    /**
     * Scan enqueued scripts and styles registered in WordPress memory.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function scanEnqueuedAssets(): array
    {
        global $wp_scripts, $wp_styles;

        $detected = [];

        if ($wp_scripts instanceof \WP_Scripts && !empty($wp_scripts->registered)) {
            foreach ($wp_scripts->registered as $handle => $scriptObj) {
                $handleStr = (string) $handle;
                $src = is_object($scriptObj) && isset($scriptObj->src) ? (string) $scriptObj->src : '';

                $recipe = RecipeRegistry::findRecipeForScript($handleStr, $src);
                if ($recipe !== null) {
                    $key = $recipe['id'] ?? $handleStr;
                    /* translators: %s: Enqueued script handle */
                    $scriptNotice = sprintf(__('Enqueued script: "%s"', 'tuedion-cookie'), $handleStr);
                    $detected[$key] = [
                        'id'         => $key,
                        'name'       => $recipe['name'] ?? $key,
                        'category'   => $recipe['category'] ?? 'marketing',
                        'type'       => $recipe['type'] ?? 'script',
                        'source'     => $scriptNotice,
                        'sources'    => [$scriptNotice],
                        'auto_clear' => (array) ($recipe['auto_clear'] ?? []),
                        'is_managed' => true,
                    ];
                }
            }
        }

        // Check Google Fonts in styles
        if ($wp_styles instanceof \WP_Styles && !empty($wp_styles->registered)) {
            foreach ($wp_styles->registered as $handle => $styleObj) {
                $src = is_object($styleObj) && isset($styleObj->src) ? (string) $styleObj->src : '';
                if (str_contains($src, 'fonts.googleapis.com') || str_contains($src, 'fonts.gstatic.com')) {
                    /* translators: %s: Enqueued stylesheet handle */
                    $styleNotice = sprintf(__('Enqueued stylesheet: "%s"', 'tuedion-cookie'), (string) $handle);
                    $detected['google-fonts'] = [
                        'id'         => 'google-fonts',
                        'name'       => 'Google Fonts',
                        'category'   => 'functionality',
                        'type'       => 'style',
                        'source'     => $styleNotice,
                        'sources'    => [$styleNotice],
                        'auto_clear' => [],
                        'is_managed' => false,
                    ];
                }
            }
        }

        return $detected;
    }

    /**
     * Detect known tracking/cookie-setting plugins.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function scanActivePlugins(): array
    {
        $detected = [];
        $activePlugins = (array) get_option('active_plugins', []);
        if (function_exists('is_multisite') && is_multisite()) {
            $networkPlugins = array_keys((array) get_site_option('active_sitewide_plugins', []));
            $activePlugins = array_merge($activePlugins, $networkPlugins);
        }

        $pluginMap = [
            'google-site-kit/google-site-kit.php'                       => [
                'id'       => 'google-analytics',
                'name'     => 'Google Analytics 4 (via Site Kit)',
                'category' => 'analytics',
                'source'   => 'Active Plugin: Google Site Kit',
            ],
            'pixelyoursite/facebook-pixel.php'                          => [
                'id'       => 'facebook-pixel',
                'name'     => 'Meta Pixel (via PixelYourSite)',
                'category' => 'marketing',
                'source'   => 'Active Plugin: PixelYourSite',
            ],
            'duracelltomi-google-tag-manager/duracelltomi-google-tag-manager.php' => [
                'id'       => 'google-tag-manager',
                'name'     => 'Google Tag Manager (GTM4WP)',
                'category' => 'analytics',
                'source'   => 'Active Plugin: GTM4WP',
            ],
            'clarity/clarity.php'                                       => [
                'id'       => 'microsoft-clarity',
                'name'     => 'Microsoft Clarity',
                'category' => 'analytics',
                'source'   => 'Active Plugin: Microsoft Clarity',
            ],
            'hotjar/hotjar.php'                                         => [
                'id'       => 'hotjar',
                'name'     => 'Hotjar',
                'category' => 'analytics',
                'source'   => 'Active Plugin: Hotjar Official',
            ],
            'woocommerce/woocommerce.php'                              => [
                'id'       => 'woocommerce',
                'name'     => 'WooCommerce Core Commerce',
                'category' => 'necessary',
                'source'   => 'Active Plugin: WooCommerce',
            ],
            'elementor/elementor.php'                                   => [
                'id'       => 'elementor',
                'name'     => 'Elementor Page Builder Embeds',
                'category' => 'marketing',
                'source'   => 'Active Plugin: Elementor Core',
            ],
        ];

        foreach ($pluginMap as $pluginPath => $info) {
            if (in_array($pluginPath, $activePlugins, true)) {
                $id = $info['id'];
                $allRecipes = RecipeRegistry::getAll();
                $recipe = $allRecipes[$id] ?? [];

                $detected[$id] = [
                    'id'         => $id,
                    'name'       => $info['name'],
                    'category'   => $info['category'],
                    'type'       => $recipe['type'] ?? 'plugin',
                    'source'     => $info['source'],
                    'sources'    => [$info['source']],
                    'auto_clear' => (array) ($recipe['auto_clear'] ?? []),
                    'is_managed' => true,
                ];
            }
        }

        return $detected;
    }

    /**
     * Crawl a live page HTML and detect rendered scripts, iframes, and response cookies.
     *
     * @param string $url
     * @return array{services: array<string, array<string, mixed>>, cookies: list<string>}
     */
    private static function crawlUrl(string $url): array
    {
        $services = [];
        $cookies = [];

        $response = wp_remote_get($url, [
            'timeout'     => 8,
            'redirection' => 3,
            'sslverify'   => (bool) apply_filters('tuedion_cookie_scanner_sslverify', true),
            'headers'     => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 TuedionCookieScanner/1.0',
                'Accept'     => 'text/html,application/xhtml+xml',
            ],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return ['services' => $services, 'cookies' => $cookies];
        }

        // Extract Set-Cookie headers
        $headerCookies = wp_remote_retrieve_header($response, 'set-cookie');
        if (!empty($headerCookies)) {
            $rawList = is_array($headerCookies) ? $headerCookies : [$headerCookies];
            foreach ($rawList as $cHeader) {
                $parts = explode(';', (string) $cHeader);
                $cookiePair = trim($parts[0]);
                $cName = explode('=', $cookiePair)[0];
                if (!empty($cName) && !in_array($cName, $cookies, true)) {
                    $cookies[] = sanitize_text_field($cName);
                }
            }
        }

        $html = (string) wp_remote_retrieve_body($response);
        if (empty($html)) {
            return ['services' => $services, 'cookies' => $cookies];
        }

        // 1. Check Script Tags (Src & Inline)
        if (preg_match_all('/<script\b([^>]*?)>(.*?)<\/script>/is', $html, $scriptMatches, PREG_SET_ORDER)) {
            foreach ($scriptMatches as $m) {
                $attrStr = $m[1];
                $inlineCode = $m[2];

                $src = '';
                if (preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/i', $attrStr, $srcM)) {
                    $src = trim($srcM[2]);
                }

                // Check external src
                if ($src !== '') {
                    $recipe = RecipeRegistry::findRecipeForScript('', $src);
                    if ($recipe !== null) {
                        $key = $recipe['id'];
                        /* translators: %s: Truncated script source URL */
                        $scriptTagNotice = sprintf(__('Found &lt;script&gt; tag on page: "%s"', 'tuedion-cookie'), substr($src, 0, 60) . '...');
                        $services[$key] = [
                            'id'         => $key,
                            'name'       => $recipe['name'] ?? $key,
                            'category'   => $recipe['category'] ?? 'marketing',
                            'type'       => 'script',
                            'source'     => $scriptTagNotice,
                            'sources'    => [$scriptTagNotice],
                            'auto_clear' => (array) ($recipe['auto_clear'] ?? []),
                            'is_managed' => true,
                        ];
                    }
                }

                // Check inline code patterns
                if (!empty($inlineCode)) {
                    self::detectInlineTrackingSignatures($inlineCode, $services);
                }
            }
        }

        // 2. Check Iframe Tags
        if (preg_match_all('/<iframe\b([^>]*?)>/is', $html, $iframeMatches)) {
            foreach ($iframeMatches[1] as $attrStr) {
                if (preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/i', $attrStr, $srcM)) {
                    $src = trim($srcM[2]);
                    $recipe = RecipeRegistry::findRecipeForIframe($src);
                    if ($recipe !== null) {
                        $key = $recipe['id'];
                        /* translators: %s: Truncated iframe source URL */
                        $iframeEmbedNotice = sprintf(__('Found &lt;iframe&gt; embed on page: "%s"', 'tuedion-cookie'), substr($src, 0, 60) . '...');
                        $services[$key] = [
                            'id'         => $key,
                            'name'       => $recipe['name'] ?? $key,
                            'category'   => $recipe['category'] ?? 'marketing',
                            'type'       => 'iframe',
                            'source'     => $iframeEmbedNotice,
                            'sources'    => [$iframeEmbedNotice],
                            'auto_clear' => (array) ($recipe['auto_clear'] ?? []),
                            'is_managed' => true,
                        ];
                    }
                }
            }
        }

        return [
            'services' => $services,
            'cookies'  => $cookies,
        ];
    }

    /**
     * Detect inline script signatures for well-known trackers.
     *
     * @param string $inlineCode
     * @param array<string, array<string, mixed>> $services By-reference accumulator.
     */
    private static function detectInlineTrackingSignatures(string $inlineCode, array &$services): void
    {
        $signatures = [
            'google-analytics' => [
                'pattern'  => '/(?:gtag\s*\(\s*[\'"]config[\'"]|ga\s*\(\s*[\'"]create[\'"])/i',
                'name'     => 'Google Analytics (gtag/inline)',
                'category' => 'analytics',
                'note'     => 'Inline gtag config call',
            ],
            'google-tag-manager' => [
                'pattern'  => '/(?:googletagmanager\.com\/gtm\.js|dataLayer\.push)/i',
                'name'     => 'Google Tag Manager',
                'category' => 'analytics',
                'note'     => 'Inline GTM container / dataLayer',
            ],
            'facebook-pixel' => [
                'pattern'  => '/fbq\s*\(\s*[\'"]init[\'"]/i',
                'name'     => 'Meta Pixel',
                'category' => 'marketing',
                'note'     => 'Inline fbq init call',
            ],
            'microsoft-clarity' => [
                'pattern'  => '/clarity\s*\(\s*[\'"]init[\'"]/i',
                'name'     => 'Microsoft Clarity',
                'category' => 'analytics',
                'note'     => 'Inline Clarity snippet',
            ],
            'hotjar' => [
                'pattern'  => '/hjid\s*:\s*[0-9]+/i',
                'name'     => 'Hotjar',
                'category' => 'analytics',
                'note'     => 'Inline Hotjar snippet',
            ],
            'tiktok-pixel' => [
                'pattern'  => '/ttq\.load\s*\(/i',
                'name'     => 'TikTok Pixel',
                'category' => 'marketing',
                'note'     => 'Inline TikTok Pixel loader',
            ],
        ];

        foreach ($signatures as $key => $sig) {
            if (isset($services[$key])) {
                continue;
            }

            if (preg_match($sig['pattern'], $inlineCode)) {
                $allRecipes = RecipeRegistry::getAll();
                $recipe = $allRecipes[$key] ?? [];

                /* translators: %s: Inline tracking signature note */
                $sourceDesc = sprintf(__('Detected in inline page script: %s', 'tuedion-cookie'), $sig['note']);

                $services[$key] = [
                    'id'         => $key,
                    'name'       => $sig['name'],
                    'category'   => $sig['category'],
                    'type'       => 'script',
                    'source'     => $sourceDesc,
                    'sources'    => [$sourceDesc],
                    'auto_clear' => (array) ($recipe['auto_clear'] ?? []),
                    'is_managed' => true,
                ];
            }
        }
    }

    /**
     * Build detailed cookies list based on detected services, WordPress core, and HTTP headers.
     *
     * @param list<string> $detectedServiceIds
     * @param list<string> $rawHttpCookies
     * @return list<array<string, mixed>>
     */
    private static function buildCookiesCatalog(array $detectedServiceIds, array $rawHttpCookies): array
    {
        $cookies = [];
        $seen = [];

        // 1. Core Tuedion & WordPress Cookies (Always Present)
        $standardCookies = [
            [
                'name'        => 'cc_cookie',
                'service'     => 'Tuedion Cookie',
                'category'    => 'necessary',
                'domain'      => sanitize_text_field((string) wp_parse_url(home_url(), PHP_URL_HOST)),
                'duration'    => '182 days',
                'description' => __('Stores visitor consent preferences and revision status.', 'tuedion-cookie'),
            ],
        ];



        if (in_array('woocommerce', $detectedServiceIds, true) || class_exists('WooCommerce')) {
            $standardCookies[] = [
                'name'        => 'woocommerce_items_in_cart',
                'service'     => 'WooCommerce',
                'category'    => 'necessary',
                'domain'      => sanitize_text_field((string) wp_parse_url(home_url(), PHP_URL_HOST)),
                'duration'    => 'Session',
                'description' => __('Helps WooCommerce determine when cart contents and session change.', 'tuedion-cookie'),
            ];
            $standardCookies[] = [
                'name'        => 'wp_woocommerce_session_*',
                'service'     => 'WooCommerce',
                'category'    => 'necessary',
                'domain'      => sanitize_text_field((string) wp_parse_url(home_url(), PHP_URL_HOST)),
                'duration'    => '2 days',
                'description' => __('Contains a unique code for each customer to find cart data in the database.', 'tuedion-cookie'),
            ];
        }

        foreach ($standardCookies as $sc) {
            $cookies[] = $sc;
            $seen[$sc['name']] = true;
        }

        // 2. Cookies derived from Detected Services
        $serviceCookieMap = [
            'google-analytics' => [
                ['name' => '_ga', 'duration' => '2 years', 'desc' => 'Used by Google Analytics to distinguish unique users.'],
                ['name' => '_gid', 'duration' => '24 hours', 'desc' => 'Used by Google Analytics to distinguish users over a 24-hour window.'],
                ['name' => '_ga_*', 'duration' => '2 years', 'desc' => 'Maintains session state for Google Analytics 4.'],
                ['name' => '_gat', 'duration' => '1 minute', 'desc' => 'Used to throttle request rate to Google Analytics servers.'],
            ],
            'google-tag-manager' => [
                ['name' => '_gcl_au', 'duration' => '90 days', 'desc' => 'Used by Google AdSense and Tag Manager for experiment conversion rates.'],
            ],
            'facebook-pixel' => [
                ['name' => '_fbp', 'duration' => '90 days', 'desc' => 'Stores and tracks visits across websites for Meta advertising.'],
                ['name' => '_fbc', 'duration' => '90 days', 'desc' => 'Stores last click identifier for Meta conversion attribution.'],
            ],
            'microsoft-clarity' => [
                ['name' => '_clck', 'duration' => '1 year', 'desc' => 'Persists the Clarity user ID and settings unique to that website.'],
                ['name' => '_clsk', 'duration' => '24 hours', 'desc' => 'Connects multiple page views by a user into a single Clarity session.'],
            ],
            'hotjar' => [
                ['name' => '_hjSessionUser_*', 'duration' => '1 year', 'desc' => 'Hotjar cookie that persists user journey and unique ID.'],
                ['name' => '_hjSession_*', 'duration' => '30 minutes', 'desc' => 'Holds current session data for Hotjar heatmaps and recordings.'],
            ],
            'tiktok-pixel' => [
                ['name' => '_ttp', 'duration' => '13 months', 'desc' => 'Tracks performance of TikTok advertising campaigns and visitor events.'],
            ],
            'youtube' => [
                ['name' => 'VISITOR_INFO1_LIVE', 'duration' => '180 days', 'desc' => 'Estimates bandwidth on pages with embedded YouTube videos.'],
                ['name' => 'YSC', 'duration' => 'Session', 'desc' => 'Registers a unique ID to keep statistics of what videos from YouTube the user has seen.'],
            ],
        ];

        $siteHost = sanitize_text_field((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $allRecipes = RecipeRegistry::getAll();

        foreach ($detectedServiceIds as $svcId) {
            if (isset($serviceCookieMap[$svcId])) {
                $svcInfo = $allRecipes[$svcId] ?? [];
                $svcName = $svcInfo['name'] ?? $svcId;
                $cat     = $svcInfo['category'] ?? 'marketing';

                foreach ($serviceCookieMap[$svcId] as $item) {
                    if (isset($seen[$item['name']])) {
                        continue;
                    }
                    $seen[$item['name']] = true;
                    $cookies[] = [
                        'name'        => $item['name'],
                        'service'     => $svcName,
                        'category'    => $cat,
                        'domain'      => '.' . $siteHost,
                        'duration'    => $item['duration'],
                        'description' => $item['desc'],
                    ];
                }
            }
        }

        // 3. Any extra cookies caught directly in HTTP response headers or client scan
        $blockedCookiePrefixes = [
            'wordpress_',
            'wordpress_logged_in_',
            'wordpress_sec_',
            'wp-settings-',
            'wp-settings-time-',
            'wordpress_test_cookie',
        ];

        foreach ($rawHttpCookies as $rawName) {
            $rawClean = sanitize_text_field(trim((string) $rawName));
            if ($rawClean === '') {
                continue;
            }

            $isBlocked = false;
            foreach ($blockedCookiePrefixes as $prefix) {
                if (str_starts_with($rawClean, $prefix)) {
                    $isBlocked = true;
                    break;
                }
            }

            if ($isBlocked || isset($seen[$rawClean])) {
                continue;
            }

            $seen[$rawClean] = true;
            $cookies[] = [
                'name'        => $rawClean,
                'service'     => __('Detected Cookie', 'tuedion-cookie'),
                'category'    => 'necessary',
                'domain'      => $siteHost,
                'duration'    => 'Session',
                'description' => __('Detected active on site during scanner audit.', 'tuedion-cookie'),
            ];
        }

        return $cookies;
    }

    /**
     * Synchronize all detected services into the active plugin configuration ($settings['services']).
     *
     * @return int Number of newly synchronized services.
     */
    public static function syncDetectedServicesToSettings(): int
    {
        $results = self::getResults();
        $detected = $results['detected_services'] ?? [];
        if (empty($detected)) {
            return 0;
        }

        $settings = Repository::getSettings();
        $services = $settings['services'] ?? [];
        $existingIds = array_column($services, 'id');
        $addedCount = 0;

        foreach ($detected as $svcId => $svcData) {
            if (in_array($svcId, $existingIds, true)) {
                continue;
            }

            $cleanCookies = [];
            if (!empty($svcData['auto_clear'])) {
                foreach ((array) $svcData['auto_clear'] as $pat) {
                    $cleanPat = str_replace(['/^', '/', '\\'], '', (string) $pat);
                    if ($cleanPat !== '') {
                        $cleanCookies[] = $cleanPat;
                    }
                }
            }

            $services[] = [
                'id'       => $svcId,
                'label'    => $svcData['name'] ?? $svcId,
                'category' => $svcData['category'] ?? 'marketing',
                'cookies'  => $cleanCookies,
            ];

            $addedCount++;
        }

        if ($addedCount > 0) {
            $settings['services'] = $services;
            Repository::updateSettings($settings);
            Compiler::clearCache();
        }

        return $addedCount;
    }

    /**
     * AJAX endpoint to trigger real-time scan with strict validation.
     */
    public static function handleAjaxScan(): void
    {
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_send_json_error(['message' => __('Method not allowed.', 'tuedion-cookie')], 405);
        }

        check_ajax_referer('tuedion_scanner_action', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized permission.', 'tuedion-cookie')], 403);
        }

        $payloadRaw = isset($_POST['payload']) ? wp_unslash((string) $_POST['payload']) : '';

        // Restrict maximum payload size to prevent memory exhaustion / DoS
        if (strlen($payloadRaw) > 200000) {
            wp_send_json_error(['message' => __('Payload size exceeds allowed limit.', 'tuedion-cookie')], 413);
        }

        $payload = json_decode($payloadRaw, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
            wp_send_json_error(['message' => __('Invalid JSON payload received from client scanner.', 'tuedion-cookie')], 400);
        }

        $results = self::processClientScan($payload);
        wp_send_json_success($results);
    }

    /**
     * Process and sanitize the validated JSON payload sent from the client-side scanner.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function processClientScan(array $payload): array
    {
        $detectedServices = [];
        $scannedUrls = [home_url('/')];
        $rawCookiesList = [];
        $rawScripts = [];
        $rawIframes = [];

        // 1. Scan Enqueued Scripts & Styles (Server Side fallback)
        $enqueuedDetections = self::scanEnqueuedAssets();
        foreach ($enqueuedDetections as $key => $data) {
            $detectedServices[$key] = $data;
        }

        // 2. Scan Active WordPress Plugins (Server Side)
        $pluginDetections = self::scanActivePlugins();
        foreach ($pluginDetections as $key => $data) {
            if (!isset($detectedServices[$key])) {
                $detectedServices[$key] = $data;
            } else {
                $detectedServices[$key]['sources'][] = $data['source'];
            }
        }

        // Sensitive cookie prefixes to strictly filter out on server-side as well
        $blockedCookiePrefixes = [
            'wordpress_',
            'wordpress_logged_in_',
            'wordpress_sec_',
            'wp-settings-',
            'wp-settings-time-',
            'wordpress_test_cookie',
        ];

        // 3. Process Client-Side Cookies (Names only - strictly sanitize)
        $clientCookies = (array) ($payload['cookies'] ?? []);
        foreach ($clientCookies as $cItem) {
            $cName = '';
            if (is_string($cItem)) {
                $cName = trim(explode('=', $cItem)[0]);
            } elseif (is_array($cItem) && isset($cItem['name']) && is_string($cItem['name'])) {
                $cName = trim($cItem['name']);
            }

            if ($cName === '') {
                continue;
            }

            $cNameClean = sanitize_text_field($cName);
            if ($cNameClean === '') {
                continue;
            }

            $isBlocked = false;
            foreach ($blockedCookiePrefixes as $prefix) {
                if (str_starts_with($cNameClean, $prefix)) {
                    $isBlocked = true;
                    break;
                }
            }

            if (!$isBlocked && !in_array($cNameClean, $rawCookiesList, true)) {
                $rawCookiesList[] = $cNameClean;
            }
        }

        // 4. Process Client-Side Scripts (URLs or lightweight signature tokens)
        $clientScripts = (array) ($payload['scripts'] ?? []);
        foreach ($clientScripts as $sItem) {
            if (is_string($sItem) && !empty($sItem)) {
                $rawScripts[] = [
                    'type'  => str_starts_with($sItem, 'http') ? 'src' : 'inline',
                    'value' => $sItem,
                ];
            } elseif (is_array($sItem) && isset($sItem['value']) && is_string($sItem['value'])) {
                $rawScripts[] = [
                    'type'  => ($sItem['type'] ?? '') === 'src' ? 'src' : 'inline',
                    'value' => $sItem['value'],
                ];
            }
        }

        // 5. Process Client-Side Iframes (Sanitized URLs)
        $clientIframes = (array) ($payload['iframes'] ?? []);
        foreach ($clientIframes as $iString) {
            if (is_string($iString) && !empty($iString)) {
                $cleanIframeUrl = esc_url_raw($iString);
                if ($cleanIframeUrl !== '') {
                    $rawIframes[] = $cleanIframeUrl;
                }
            }
        }

        // 6. Match Client Scripts against Recipe Registry
        foreach ($rawScripts as $scriptItem) {
            $scriptVal = (string) $scriptItem['value'];
            if ($scriptItem['type'] === 'src') {
                $cleanUrl = esc_url_raw($scriptVal);
                if ($cleanUrl === '') {
                    continue;
                }
                $recipe = RecipeRegistry::findRecipeForScript('', $cleanUrl);
                if ($recipe !== null) {
                    $key = $recipe['id'];
                    $detectedServices[$key] = [
                        'id'         => $key,
                        'name'       => $recipe['name'] ?? $key,
                        'category'   => $recipe['category'] ?? 'marketing',
                        'type'       => 'script',
                        'source'     => 'Client Scan (JS): ' . substr($cleanUrl, 0, 60),
                        'sources'    => ['Client Scan (JS): ' . substr($cleanUrl, 0, 60)],
                        'auto_clear' => (array) ($recipe['auto_clear'] ?? []),
                        'is_managed' => true,
                    ];
                }
            } else {
                // Inline Script - strictly cap length and strip tags before regex
                $safeSnippet = substr(wp_strip_all_tags($scriptVal), 0, 300);
                self::detectInlineTrackingSignatures($safeSnippet, $detectedServices);
            }
        }

        // 7. Match Client Iframes against Recipe Registry
        foreach ($rawIframes as $iframeSrc) {
            $recipe = RecipeRegistry::findRecipeForIframe($iframeSrc);
            if ($recipe !== null) {
                $key = $recipe['id'];
                $detectedServices[$key] = [
                    'id'         => $key,
                    'name'       => $recipe['name'] ?? $key,
                    'category'   => $recipe['category'] ?? 'marketing',
                    'type'       => 'iframe',
                    'source'     => 'Client Scan (Iframe): ' . substr($iframeSrc, 0, 60),
                    'sources'    => ['Client Scan (Iframe): ' . substr($iframeSrc, 0, 60)],
                    'auto_clear' => (array) ($recipe['auto_clear'] ?? []),
                    'is_managed' => true,
                ];
            }
        }

        // 8. Enrich Detected Cookies Catalog
        $detectedCookies = self::buildCookiesCatalog(array_keys($detectedServices), $rawCookiesList);

        // 8. Build Stats & Summary
        $allRecipes = RecipeRegistry::getAll();
        $totalCatalogueCount = count($allRecipes);
        $detectedCount = count($detectedServices);

        $results = [
            'timestamp'         => time(),
            'last_scan_date'    => current_time('mysql'),
            'last_scan_human'   => human_time_diff(time(), time()) . ' ' . __('ago', 'tuedion-cookie'),
            'scanned_urls'      => $scannedUrls,
            'detected_services' => $detectedServices,
            'detected_cookies'  => $detectedCookies,
            'stats'             => [
                'detected_services_count' => $detectedCount,
                'detected_cookies_count'  => count($detectedCookies),
                'catalogue_total_count'   => $totalCatalogueCount,
                'coverage_percent'        => $totalCatalogueCount > 0 ? (int) round(($detectedCount / $totalCatalogueCount) * 100) : 0,
            ],
        ];

        update_option(self::OPTION_KEY, $results, false);
        Compiler::clearCache();

        return $results;
    }

    /**
     * AJAX endpoint to 1-click sync detected services to configuration.
     */
    public static function handleAjaxSync(): void
    {
        check_ajax_referer('tuedion_cookie_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized permission.', 'tuedion-cookie')], 403);
        }

        $syncedCount = self::syncDetectedServicesToSettings();
        /* translators: %d: Number of synchronized services */
        $syncMessage = sprintf(__('%d services synchronized to cookie preferences.', 'tuedion-cookie'), $syncedCount);
        wp_send_json_success([
            'synced'  => $syncedCount,
            'message' => $syncMessage,
        ]);
    }

    /**
     * Register weekly and monthly intervals for WP-Cron.
     *
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public static function registerCronIntervals(array $schedules): array
    {
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = [
                'interval' => 7 * DAY_IN_SECONDS,
                'display'  => __('Once Weekly', 'tuedion-cookie'),
            ];
        }
        if (!isset($schedules['monthly'])) {
            $schedules['monthly'] = [
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => __('Once Monthly', 'tuedion-cookie'),
            ];
        }
        return $schedules;
    }

    /**
     * Synchronize WP-Cron hook state with saved settings.
     *
     * @param array<string, mixed> $settings
     */
    public static function syncCronFromSettings(?array $settings = null): void
    {
        if ($settings === null) {
            $settings = Repository::getSettings();
        }
        $scanner = (array) ($settings['scanner'] ?? []);
        $enabled = !empty($scanner['cron_enabled']);
        $schedule = (string) ($scanner['schedule'] ?? 'weekly');

        if (!$enabled) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            return;
        }

        $next = wp_next_scheduled(self::CRON_HOOK);
        $currentSchedule = wp_get_schedule(self::CRON_HOOK);

        if (!$next || $currentSchedule !== $schedule) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            wp_schedule_event(time() + HOUR_IN_SECONDS, $schedule, self::CRON_HOOK);
        }
    }

    /**
     * Scheduled cron runner: execute site crawl and send email alert on newly detected cookies.
     */
    public static function runScheduledScan(): void
    {
        $settings = Repository::getSettings();
        $scannerConfig = (array) ($settings['scanner'] ?? []);
        if (empty($scannerConfig['cron_enabled'])) {
            return;
        }

        // Run full site scan
        $scanResults = self::scan(true);
        $detectedCookies = (array) ($scanResults['detected_cookies'] ?? []);

        // Load previously known cookies
        $knownCookies = (array) get_option(self::KNOWN_COOKIES_OPTION, []);
        $newCookies = [];

        foreach ($detectedCookies as $c) {
            if (!is_array($c)) {
                continue;
            }
            $cName = (string) ($c['name'] ?? '');
            if ($cName === '') {
                continue;
            }
            if (!in_array($cName, $knownCookies, true)) {
                $newCookies[] = $c;
                $knownCookies[] = $cName;
            }
        }

        // Persist updated known cookies
        update_option(self::KNOWN_COOKIES_OPTION, array_values(array_unique($knownCookies)), false);

        // If newly discovered cookies detected, dispatch email alert
        if (!empty($newCookies)) {
            self::sendNewCookieAlertEmail($newCookies, $settings);
        }
    }

    /**
     * Send email alert to admin when unclassified/new cookies are discovered by the scheduled scan.
     *
     * @param list<array<string, mixed>> $newCookies
     * @param array<string, mixed> $settings
     */
    private static function sendNewCookieAlertEmail(array $newCookies, array $settings): void
    {
        $to = !empty($settings['scanner']['alert_email'])
            ? (string) $settings['scanner']['alert_email']
            : (string) get_option('admin_email');

        if (!is_string($to) || !is_email($to)) {
            return;
        }

        $siteName = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        /* translators: %s: site name */
        $subject = sprintf(__('[%s] Alert: New Cookies Detected by Tuedion Cookie Scanner', 'tuedion-cookie'), $siteName);

        $cookieListStr = '';
        foreach ($newCookies as $c) {
            $name     = (string) ($c['name'] ?? 'unknown');
            $category = (string) ($c['category'] ?? 'unclassified');
            $domain   = (string) ($c['domain'] ?? 'current');
            $cookieListStr .= sprintf("- %s (Domain: %s, Category: %s)\n", $name, $domain, $category);
        }

        $reviewUrl = admin_url('admin.php?page=tuedion-cookie-scanner');

        $message = sprintf(
            /* translators: 1: site name, 2: count of cookies, 3: cookie list, 4: review url */
            __("Hello,\n\nThe automated Tuedion Cookie background crawler has completed a scheduled scan on %1\$s and detected %2\$d new cookie(s):\n\n%3\$s\nTo ensure your cookie consent banner and GDPR/ePrivacy compliance declarations remain accurate, please review and synchronize these cookies in your WordPress admin panel:\n%4\$s\n\nBest regards,\nTuedion Cookie Consent Management", 'tuedion-cookie'),
            $siteName,
            count($newCookies),
            $cookieListStr,
            $reviewUrl
        );

        wp_mail($to, $subject, $message, ['Content-Type: text/plain; charset=UTF-8']);
    }
}
