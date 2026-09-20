<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Diagnostics;

use Tuedion\CookieConsent\Consent\CookieTableBuilder;
use Tuedion\CookieConsent\Database\CookieDatabase;
use Tuedion\CookieConsent\Database\CookieDatabaseUpdater;
use Tuedion\CookieConsent\Detection\ConfidenceScorer;
use Tuedion\CookieConsent\Detection\DetectionResult;
use Tuedion\CookieConsent\Detection\Evidence;
use Tuedion\CookieConsent\Integrations\RecipeRegistry;
use Tuedion\CookieConsent\Integrations\ServiceMatcher;
use Tuedion\CookieConsent\Integrations\ServiceRegistry;
use Tuedion\CookieConsent\Scanner\DiffEngine;
use Tuedion\CookieConsent\Scanner\ScanModel;
use Tuedion\CookieConsent\Scanner\ScanRepository;
use Tuedion\CookieConsent\Scanner\Quick\ContentSampler;
use Tuedion\CookieConsent\Scanner\Smart\UrlDiscovery;
use Tuedion\CookieConsent\Scanner\Smart\CrawlPlanner;
use Tuedion\CookieConsent\Scanner\Smart\ChangeWatcher;
use Tuedion\CookieConsent\Scanner\Detection\UnknownResourceDetector;
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
        add_action('wp_ajax_tdcc_plan_scan_urls', [self::class, 'handleAjaxPlanUrls']);
        add_action('wp_ajax_tdcc_manage_unknown_resource', [self::class, 'handleAjaxManageUnknownResource']);
        add_action('wp_ajax_tdcc_get_scan_history', [self::class, 'handleAjaxGetScanHistory']);
        add_action('wp_ajax_tdcc_reset_scan_results', [self::class, 'handleAjaxResetScan']);
        add_action('wp_ajax_tdcc_sync_scanned_services', [self::class, 'handleAjaxSync']);

        // Register site change listener for theme/plugin switch recommendations
        (new ChangeWatcher())->registerHooks();

        // Cookie Database Updater Hook
        CookieDatabaseUpdater::register();

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
     * Get all public and viewable post types with labels and published counts.
     *
     * @return array<string, array{key: string, label: string, count: int, required: bool}>
     */
    public static function getAvailablePostTypes(): array
    {
        $types = [
            'home' => [
                'key'      => 'home',
                'label'    => __('Home Page', 'tuedion-cookie'),
                'count'    => 1,
                'required' => true,
            ],
            'page' => [
                'key'      => 'page',
                'label'    => __('Pages', 'tuedion-cookie'),
                'count'    => (int) (wp_count_posts('page')->publish ?? 0),
                'required' => false,
            ],
            'post' => [
                'key'      => 'post',
                'label'    => __('Blog Posts', 'tuedion-cookie'),
                'count'    => (int) (wp_count_posts('post')->publish ?? 0),
                'required' => false,
            ],
        ];

        // Discover Custom Post Types
        $cpts = get_post_types(['public' => true, '_builtin' => false], 'objects');
        $ignoredCpts = ['elementor_library', 'e-landing-page', 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_global_styles'];

        foreach ($cpts as $slug => $cptObj) {
            if (in_array($slug, $ignoredCpts, true)) {
                continue;
            }
            if (empty($cptObj->publicly_queryable) && empty($cptObj->public)) {
                continue;
            }

            $countObj = wp_count_posts($slug);
            $pubCount = (int) ($countObj->publish ?? 0);
            $label = !empty($cptObj->labels->name) ? $cptObj->labels->name : $cptObj->label;

            $types[$slug] = [
                'key'      => $slug,
                'label'    => sprintf('%s (%s)', $label, $slug),
                'count'    => $pubCount,
                'required' => false,
            ];
        }

        return (array) apply_filters('tuedion_cookie_scanner_available_post_types', $types);
    }

    /**
     * Resolve representative target URLs for selected post types.
     *
     * @param list<string> $targetKeys
     * @param string $param Query param to append ('tdcc_audit=1' or 'tdcc_safe=1')
     * @return list<array{key: string, label: string, url: string}>
     */
    public static function resolveTargetUrls(array $targetKeys = [], string $param = 'tdcc_audit=1'): array
    {
        if (empty($targetKeys)) {
            $settings = Repository::getSettings();
            $targetKeys = (array) ($settings['scanner']['scan_targets'] ?? ['home', 'page', 'post']);
        }

        $results = [];
        $seenUrls = [];

        // 1. Home Page (Always first)
        $homeUrl = home_url('/?' . $param);
        $results[] = [
            'key'   => 'home',
            'label' => __('Home Page', 'tuedion-cookie'),
            'url'   => $homeUrl,
        ];
        $seenUrls[home_url('/')] = true;

        $frontPageId = (int) get_option('page_on_front');

        foreach ($targetKeys as $key) {
            if ($key === 'home') {
                continue;
            }

            // A. Standard Pages
            if ($key === 'page') {
                $pages = get_posts([
                    'post_type'     => 'page',
                    'post_status'   => 'publish',
                    'numberposts'   => $frontPageId > 0 ? 2 : 1,
                    'orderby'       => 'date',
                    'order'         => 'DESC',
                    'no_found_rows' => true,
                ]);
                $targetPage = null;
                foreach ($pages as $p) {
                    if ($frontPageId <= 0 || (int) $p->ID !== $frontPageId) {
                        $targetPage = $p;
                        break;
                    }
                }
                if ($targetPage !== null) {
                    $permalink = (string) get_permalink($targetPage->ID);
                    if ($permalink !== '' && !isset($seenUrls[$permalink])) {
                        $seenUrls[$permalink] = true;
                        $sep = str_contains($permalink, '?') ? '&' : '?';
                        $results[] = [
                            'key'   => 'page',
                            /* translators: %s: Page title */
                            'label' => sprintf(__('Page: %s', 'tuedion-cookie'), get_the_title($targetPage)),
                            'url'   => $permalink . $sep . $param,
                        ];
                    }
                }
                continue;
            }

            // B. Standard Posts
            if ($key === 'post') {
                $posts = get_posts([
                    'post_type'     => 'post',
                    'post_status'   => 'publish',
                    'numberposts'   => 1,
                    'orderby'       => 'date',
                    'order'         => 'DESC',
                    'no_found_rows' => true,
                ]);
                if (!empty($posts[0])) {
                    $permalink = (string) get_permalink($posts[0]->ID);
                    if ($permalink !== '' && !isset($seenUrls[$permalink])) {
                        $seenUrls[$permalink] = true;
                        $sep = str_contains($permalink, '?') ? '&' : '?';
                        $results[] = [
                            'key'   => 'post',
                            /* translators: %s: Post title */
                            'label' => sprintf(__('Blog: %s', 'tuedion-cookie'), get_the_title($posts[0])),
                            'url'   => $permalink . $sep . $param,
                        ];
                    }
                }
                continue;
            }

            // C. Special WooCommerce Product / Shop Handling
            if ($key === 'product') {
                if (class_exists('WooCommerce')) {
                    $shopId = (int) get_option('woocommerce_shop_page_id');
                    if ($shopId > 0) {
                        $shopUrl = (string) get_permalink($shopId);
                        if ($shopUrl !== '' && !isset($seenUrls[$shopUrl])) {
                            $seenUrls[$shopUrl] = true;
                            $sep = str_contains($shopUrl, '?') ? '&' : '?';
                            $results[] = [
                                'key'   => 'product_shop',
                                'label' => __('WooCommerce Shop', 'tuedion-cookie'),
                                'url'   => $shopUrl . $sep . $param,
                            ];
                        }
                    }
                }
            }

            // D. Generic Custom Post Types
            $cptPosts = get_posts([
                'post_type'   => $key,
                'post_status' => 'publish',
                'numberposts' => 1,
                'orderby'     => 'date',
                'order'       => 'DESC',
            ]);
            if (!empty($cptPosts[0])) {
                $permalink = (string) get_permalink($cptPosts[0]->ID);
                if ($permalink !== '' && !isset($seenUrls[$permalink])) {
                    $seenUrls[$permalink] = true;
                    $sep = str_contains($permalink, '?') ? '&' : '?';
                    $cptObj = get_post_type_object($key);
                    $cptLabel = $cptObj ? $cptObj->labels->singular_name : $key;
                    $results[] = [
                        'key'   => $key,
                        'label' => sprintf('%s: %s', $cptLabel, get_the_title($cptPosts[0])),
                        'url'   => $permalink . $sep . $param,
                    ];
                }
            }
        }

        return (array) apply_filters('tuedion_cookie_scanner_target_urls', $results, $targetKeys, $param);
    }

    /**
     * Execute site scan and identify active tracking services and cookies.
     *
     * @param bool $deepHttp Whether to make a live HTTP request to crawl page HTML.
     * @param list<string>|null $targetKeys Optional specific target keys to scan.
     * @return array<string, mixed>
     */
    public static function scan(bool $deepHttp = true, ?array $targetKeys = null): array
    {
        $detectedServices = [];
        $targetObjects = self::resolveTargetUrls($targetKeys ?? [], 'tdcc_safe=1');
        $scannedUrls = [];

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

        // 3. Live HTTP Page Crawler across resolved target URLs (if enabled)
        $allHttpCookies = [];
        if ($deepHttp) {
            foreach ($targetObjects as $tObj) {
                $targetUrl = $tObj['url'];
                $scannedUrls[] = $targetUrl;
                $crawlResult = self::crawlUrl($targetUrl);

                foreach ($crawlResult['services'] as $key => $data) {
                    if (!isset($detectedServices[$key])) {
                        $detectedServices[$key] = $data;
                    } else {
                        $detectedServices[$key]['sources'][] = $data['source'];
                    }
                }
                foreach ($crawlResult['cookies'] as $cName) {
                    if (!in_array($cName, $allHttpCookies, true)) {
                        $allHttpCookies[] = $cName;
                    }
                }
            }
        } else {
            $scannedUrls = array_column($targetObjects, 'url');
        }

        // 4. Enrich Detected Cookies Catalog
        $detectedCookies = self::buildCookiesCatalog(array_keys($detectedServices), $allHttpCookies);

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

                $match = ServiceMatcher::matchScript($handleStr, $src);
                if ($match !== null) {
                    $service = $match['service'];
                    $key = $service->id;
                    /* translators: %s: Enqueued script handle */
                    $scriptNotice = sprintf(__('Enqueued script: "%s"', 'tuedion-cookie'), $handleStr);
                    $res = new DetectionResult(
                        id: $key,
                        name: $service->name,
                        category: RecipeRegistry::resolveServiceCategory($key, $service->category),
                        provider: $service->provider,
                        type: $service->type,
                        autoClear: $service->autoClear,
                        isManaged: true
                    );
                    $res->addEvidence(new Evidence(
                        $match['evidence_type'],
                        $match['matched_value'],
                        $match['weight'],
                        $scriptNotice
                    ));
                    $detected[$key] = $res->toArray();
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
                'source'      => 'system',
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
                'source'      => 'system',
            ];
            $standardCookies[] = [
                'name'        => 'wp_woocommerce_session_*',
                'service'     => 'WooCommerce',
                'category'    => 'necessary',
                'domain'      => sanitize_text_field((string) wp_parse_url(home_url(), PHP_URL_HOST)),
                'duration'    => '2 days',
                'description' => __('Contains a unique code for each customer to find cart data in the database.', 'tuedion-cookie'),
                'source'      => 'system',
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
                        'source'      => 'service',
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

            // Query Open Cookie Database for rich metadata
            $dbCookie = CookieDatabase::find($rawClean);
            if ($dbCookie !== null) {
                $cookies[] = [
                    'name'        => $rawClean,
                    'service'     => $dbCookie['provider'] !== '' ? $dbCookie['provider'] : __('Detected Cookie', 'tuedion-cookie'),
                    'category'    => $dbCookie['category'],
                    'domain'      => $dbCookie['domain'] !== '' ? $dbCookie['domain'] : $siteHost,
                    'duration'    => $dbCookie['retention'] !== '' ? $dbCookie['retention'] : 'Session',
                    'description' => $dbCookie['description'] !== '' ? $dbCookie['description'] : __('Identified in Open Cookie Database.', 'tuedion-cookie'),
                    'source'      => 'database',
                ];
            } else {
                $cookies[] = [
                    'name'        => $rawClean,
                    'service'     => __('Detected Cookie', 'tuedion-cookie'),
                    'category'    => 'necessary',
                    'domain'      => $siteHost,
                    'duration'    => 'Session',
                    'description' => __('Detected active on site during scanner audit.', 'tuedion-cookie'),
                    'source'      => 'unclassified',
                ];
            }
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

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw JSON payload size-limited, JSON-decoded and strictly sanitized per-field in processClientScan().
        $payloadRaw = isset($_POST['payload']) ? wp_unslash((string) $_POST['payload']) : '';

        // Restrict maximum payload size to prevent memory exhaustion / DoS
        if (strlen($payloadRaw) > 200000) {
            wp_send_json_error(['message' => __('Payload size exceeds allowed limit.', 'tuedion-cookie')], 413);
        }

        $payload = json_decode($payloadRaw, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
            wp_send_json_error(['message' => __('Invalid JSON payload received from client scanner.', 'tuedion-cookie')], 400);
        }

        try {
            $results = self::processClientScan($payload);
            wp_send_json_success($results);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Process and sanitize the validated JSON payload sent from the client-side scanner.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function processClientScan(array $payload): array
    {
        $scannedUrls = !empty($payload['scanned_urls']) && is_array($payload['scanned_urls'])
            ? array_values(array_filter(array_map('esc_url_raw', $payload['scanned_urls'])))
            : [home_url('/')];
        $rawCookiesList = [];
        $rawScripts = [];
        $rawIframes = [];
        $unknownResources = [];

        /** @var array<string, DetectionResult> $accumulator */
        $accumulator = [];

        // 1. Scan Enqueued Scripts & Styles (Server Side fallback)
        $enqueuedDetections = self::scanEnqueuedAssets();
        foreach ($enqueuedDetections as $key => $data) {
            $service = ServiceRegistry::get($key);
            $res = new DetectionResult(
                id: $key,
                name: $data['name'] ?? $key,
                category: $data['category'] ?? 'marketing',
                provider: $service ? $service->provider : '',
                type: $data['type'] ?? 'script',
                autoClear: (array) ($data['auto_clear'] ?? []),
                isManaged: (bool) ($data['is_managed'] ?? true)
            );
            $res->addEvidence(new Evidence(
                'wp_handle',
                $key,
                25,
                (string) ($data['source'] ?? $key)
            ));
            $accumulator[$key] = $res;
        }

        // 2. Scan Active WordPress Plugins (Server Side)
        $pluginDetections = self::scanActivePlugins();
        foreach ($pluginDetections as $key => $data) {
            if (!isset($accumulator[$key])) {
                $service = ServiceRegistry::get($key);
                $accumulator[$key] = new DetectionResult(
                    id: $key,
                    name: $data['name'] ?? $key,
                    category: $data['category'] ?? 'marketing',
                    provider: $service ? $service->provider : '',
                    type: $data['type'] ?? 'plugin',
                    autoClear: (array) ($data['auto_clear'] ?? []),
                    isManaged: true
                );
            }
            $accumulator[$key]->addEvidence(new Evidence(
                'plugin',
                $key,
                50,
                (string) ($data['source'] ?? $key)
            ));
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

        // 6. Match Client Scripts against Service Registry
        $siteHost = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        foreach ($rawScripts as $scriptItem) {
            $scriptVal = (string) $scriptItem['value'];
            if ($scriptItem['type'] === 'src') {
                $cleanUrl = esc_url_raw($scriptVal);
                if ($cleanUrl === '') {
                    continue;
                }
                $match = ServiceMatcher::matchScript('', $cleanUrl);
                if ($match !== null) {
                    $svc = $match['service'];
                    $key = $svc->id;
                    if (!isset($accumulator[$key])) {
                        $accumulator[$key] = new DetectionResult(
                            id: $key,
                            name: $svc->name,
                            category: RecipeRegistry::resolveServiceCategory($key, $svc->category),
                            provider: $svc->provider,
                            type: 'script',
                            autoClear: $svc->autoClear,
                            isManaged: true
                        );
                    }
                    $accumulator[$key]->addEvidence(new Evidence(
                        $match['evidence_type'],
                        $match['matched_value'],
                        $match['weight'],
                        'Client Scan (JS): ' . substr($cleanUrl, 0, 60)
                    ));
                } else {
                    $scriptHost = strtolower((string) wp_parse_url($cleanUrl, PHP_URL_HOST));
                    if ($scriptHost !== '' && $scriptHost !== $siteHost && !str_ends_with($scriptHost, '.' . $siteHost)) {
                        $unknownResources[] = [
                            'type'   => 'script',
                            'url'    => $cleanUrl,
                            'domain' => $scriptHost,
                        ];
                    }
                }
            } else {
                // Inline Script - strictly cap length and strip tags
                $safeSnippet = substr(wp_strip_all_tags($scriptVal), 0, 300);
                $match = ServiceMatcher::matchInline($safeSnippet);
                if ($match !== null) {
                    $svc = $match['service'];
                    $key = $svc->id;
                    if (!isset($accumulator[$key])) {
                        $accumulator[$key] = new DetectionResult(
                            id: $key,
                            name: $svc->name,
                            category: RecipeRegistry::resolveServiceCategory($key, $svc->category),
                            provider: $svc->provider,
                            type: 'script',
                            autoClear: $svc->autoClear,
                            isManaged: true
                        );
                    }
                    $accumulator[$key]->addEvidence(new Evidence(
                        'inline_signature',
                        $match['matched_value'],
                        $match['weight'],
                        /* translators: %s: Matched tracking signature */
                        sprintf(__('Detected inline tracking snippet: %s', 'tuedion-cookie'), $match['matched_value'])
                    ));
                }
            }
        }

        // 7. Match Client Iframes against Service Registry
        foreach ($rawIframes as $iframeSrc) {
            $match = ServiceMatcher::matchIframe($iframeSrc);
            if ($match !== null) {
                $svc = $match['service'];
                $key = $svc->id;
                if (!isset($accumulator[$key])) {
                    $accumulator[$key] = new DetectionResult(
                        id: $key,
                        name: $svc->name,
                        category: RecipeRegistry::resolveServiceCategory($key, $svc->category),
                        provider: $svc->provider,
                        type: 'iframe',
                        autoClear: $svc->autoClear,
                        isManaged: true
                    );
                }
                $accumulator[$key]->addEvidence(new Evidence(
                    $match['evidence_type'],
                    $match['matched_value'],
                    $match['weight'],
                    'Client Scan (Iframe): ' . substr($iframeSrc, 0, 60)
                ));
            } else {
                $iframeHost = strtolower((string) wp_parse_url($iframeSrc, PHP_URL_HOST));
                if ($iframeHost !== '' && $iframeHost !== $siteHost && !str_ends_with($iframeHost, '.' . $siteHost)) {
                    $unknownResources[] = [
                        'type'   => 'iframe',
                        'url'    => $iframeSrc,
                        'domain' => $iframeHost,
                    ];
                }
            }
        }

        // 8. Process Client-Side Pixels and Dynamic Network Requests
        $rawPixels = (array) ($payload['pixels'] ?? []);
        foreach ($rawPixels as $pxUrl) {
            if (is_string($pxUrl) && $pxUrl !== '') {
                $cleanPx = esc_url_raw($pxUrl);
                $pxHost = strtolower((string) wp_parse_url($cleanPx, PHP_URL_HOST));
                if ($pxHost !== '' && $pxHost !== $siteHost && !str_ends_with($pxHost, '.' . $siteHost)) {
                    $unknownResources[] = [
                        'type'   => UnknownResourceDetector::TYPE_PIXEL,
                        'url'    => $cleanPx,
                        'domain' => $pxHost,
                    ];
                }
            }
        }

        $rawNetwork = (array) ($payload['network'] ?? []);
        foreach ($rawNetwork as $netUrl) {
            if (is_string($netUrl) && $netUrl !== '') {
                $cleanNet = esc_url_raw($netUrl);
                $netHost = strtolower((string) wp_parse_url($cleanNet, PHP_URL_HOST));
                if ($netHost !== '' && $netHost !== $siteHost && !str_ends_with($netHost, '.' . $siteHost)) {
                    $unknownResources[] = [
                        'type'   => UnknownResourceDetector::TYPE_NETWORK,
                        'url'    => $cleanNet,
                        'domain' => $netHost,
                    ];
                }
            }
        }

        // 9. Process Client-Side Storage Keys
        $clientStorage = (array) ($payload['storage'] ?? []);
        foreach ($clientStorage as $sKey) {
            if (!is_string($sKey) || $sKey === '') {
                continue;
            }
            $cleanKey = sanitize_text_field(substr($sKey, 0, 80));
            // Check matching service for storage key
            if (str_starts_with($cleanKey, '_ga') || str_starts_with($cleanKey, 'google_')) {
                if (isset($accumulator['google-analytics'])) {
                    $accumulator['google-analytics']->addEvidence(new Evidence('storage', $cleanKey, 20, "Storage key: $cleanKey"));
                }
            } elseif (str_starts_with($cleanKey, '_cl') || str_starts_with($cleanKey, 'clarity')) {
                if (isset($accumulator['microsoft-clarity'])) {
                    $accumulator['microsoft-clarity']->addEvidence(new Evidence('storage', $cleanKey, 20, "Storage key: $cleanKey"));
                }
            } elseif (str_starts_with($cleanKey, '_hj')) {
                if (isset($accumulator['hotjar'])) {
                    $accumulator['hotjar']->addEvidence(new Evidence('storage', $cleanKey, 20, "Storage key: $cleanKey"));
                }
            }
        }

        // 10. Link Detected Cookies as Evidence to Corresponding Services
        foreach ($rawCookiesList as $cookieName) {
            $dbCookie = CookieDatabase::find($cookieName);
            if ($dbCookie !== null) {
                $provider = $dbCookie['provider'];
                foreach ($accumulator as $svcId => $res) {
                    if (str_contains(strtolower($res->name), strtolower($provider))
                        || str_contains(strtolower($provider), strtolower($svcId))) {
                        $res->addEvidence(new Evidence('cookie', $cookieName, 50, "Associated cookie: $cookieName"));
                    }
                }
            }
        }

        // Convert Accumulator to array format
        $detectedServices = [];
        foreach ($accumulator as $key => $res) {
            $detectedServices[$key] = $res->toArray();
        }

        // 11. Record Unknown Resources into Detector
        $detector = new UnknownResourceDetector();
        foreach ($unknownResources as $uRes) {
            $detector->inspectAndRecord(
                (string) ($uRes['type'] ?? UnknownResourceDetector::TYPE_SCRIPT),
                (string) ($uRes['url'] ?? $uRes['identifier'] ?? ''),
                (string) ($scannedUrls[0] ?? home_url('/')),
                (string) ($uRes['domain'] ?? '')
            );
        }
        foreach ($rawCookiesList as $cookieName) {
            if (CookieDatabase::find($cookieName) === null) {
                $detector->inspectAndRecord(
                    UnknownResourceDetector::TYPE_COOKIE,
                    $cookieName,
                    (string) ($scannedUrls[0] ?? home_url('/'))
                );
            }
        }

        // 12. Apply Manual Administrator Overrides
        $allUnknowns = $detector->getAll();
        $overrideResult = ScanRepository::applyManualOverrides($detectedServices, $allUnknowns);
        $detectedServices = $overrideResult['services'];
        $allUnknowns = $overrideResult['unknowns'];

        // 13. Enrich Detected Cookies Catalog
        $detectedCookies = self::buildCookiesCatalog(array_keys($detectedServices), $rawCookiesList);

        // 14. Diff Engine Calculation against baseline
        $previousScan = ScanRepository::getLatestScan();
        $previousFindings = $previousScan ? $previousScan->toArray() : null;
        $currentFindings = [
            'detected_services' => $detectedServices,
            'detected_cookies'  => $detectedCookies,
            'unknown_resources' => $allUnknowns,
        ];
        $diff = DiffEngine::compute($currentFindings, $previousFindings);

        // 15. Build Stats & Summary
        $allServices = ServiceRegistry::getAll();
        $totalCatalogueCount = count($allServices);
        $detectedCount = count($detectedServices);

        $scanId = 'scan_' . wp_generate_password(12, false, false);
        $mode = sanitize_key((string) ($payload['mode'] ?? ScanModel::MODE_QUICK));
        $startedAt = sanitize_text_field((string) ($payload['started_at'] ?? current_time('mysql')));
        $duration = max(1, (int) ($payload['duration'] ?? 1));

        $results = [
            'scan_id'           => $scanId,
            'mode'              => $mode,
            'timestamp'         => time(),
            'last_scan_date'    => current_time('mysql'),
            'last_scan_human'   => human_time_diff(time(), time()) . ' ' . __('ago', 'tuedion-cookie'),
            'scanned_urls'      => $scannedUrls,
            'detected_services' => $detectedServices,
            'detected_cookies'  => $detectedCookies,
            'unknown_resources' => $allUnknowns,
            'diff'              => $diff,
            'database_meta'     => CookieDatabase::getMetadata(),
            'stats'             => [
                'detected_services_count' => $detectedCount,
                'detected_cookies_count'  => count($detectedCookies),
                'unknown_count'           => count($allUnknowns),
                'catalogue_total_count'   => $totalCatalogueCount,
                'coverage_percent'        => $totalCatalogueCount > 0 ? (int) round(($detectedCount / $totalCatalogueCount) * 100) : 0,
            ],
        ];

        // 16. Persist to ScanRepository and update backward-compatible option
        $scanModel = new ScanModel(
            id: $scanId,
            mode: $mode,
            status: ScanModel::STATUS_COMPLETED,
            startedAt: $startedAt,
            completedAt: current_time('mysql'),
            durationSeconds: $duration,
            discoveredUrls: (array) ($payload['discovered_urls'] ?? $scannedUrls),
            scannedUrls: $scannedUrls,
            detectedServices: $detectedServices,
            detectedCookies: $detectedCookies,
            unknownResources: $allUnknowns,
            diff: $diff,
            settings: (array) ($payload['settings'] ?? [])
        );
        ScanRepository::saveScan($scanModel);

        // Acknowledge structural changes since site was successfully audited
        (new ChangeWatcher())->markScanned();

        update_option(self::OPTION_KEY, $results, false);
        Compiler::clearCache();

        return $results;
    }

    /**
     * AJAX endpoint to plan and resolve candidate URLs based on selected scan mode & options.
     */
    public static function handleAjaxPlanUrls(): void
    {
        check_ajax_referer('tuedion_scanner_action', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized permission.', 'tuedion-cookie')], 403);
        }

        $mode = sanitize_key((string) ($_POST['mode'] ?? ScanModel::MODE_QUICK));
        $targets = [];

        try {
            if ($mode === ScanModel::MODE_SMART) {
                $discovery = new UrlDiscovery();
                $discovered = $discovery->discover([
                    'include_sitemap'  => !empty($_POST['include_sitemap']),
                    'include_menus'    => !empty($_POST['include_menus']),
                    'include_critical' => !empty($_POST['include_critical']),
                ]);

                $planner = new CrawlPlanner();
                $maxUrls = isset($_POST['max_urls']) ? absint(wp_unslash($_POST['max_urls'])) : 30;
                $maxUrls = max(3, min(100, $maxUrls));
                $rawExcludeRules = isset($_POST['exclude_rules']) ? sanitize_textarea_field(wp_unslash((string) $_POST['exclude_rules'])) : '';
                $excludeRules = $rawExcludeRules !== ''
                    ? array_filter(array_map('trim', explode("\n", $rawExcludeRules)))
                    : [];
                $rawIncludeRules = isset($_POST['include_rules']) ? sanitize_textarea_field(wp_unslash((string) $_POST['include_rules'])) : '';
                $includeRules = $rawIncludeRules !== ''
                    ? array_filter(array_map('trim', explode("\n", $rawIncludeRules)))
                    : [];

                $plannedUrls = $planner->planQueue($discovered['all'], [
                    'max_urls'         => $maxUrls,
                    'exclude_patterns' => $excludeRules,
                    'include_patterns' => $includeRules,
                ]);

                foreach ($plannedUrls as $idx => $u) {
                    $sep = str_contains($u, '?') ? '&' : '?';
                    $finalUrl = str_contains($u, 'tdcc_audit=1') ? $u : ($u . $sep . 'tdcc_audit=1');
                    $targets[] = [
                        'key'   => 'smart_' . $idx,
                        'label' => wp_parse_url($u, PHP_URL_PATH) ?: '/',
                        'url'   => $finalUrl,
                    ];
                }
            } elseif ($mode === ScanModel::MODE_FULL) {
                // Full Website Scan runtime discovery
                $discovery = new UrlDiscovery();
                $discovered = $discovery->discover();
                $planner = new CrawlPlanner();
                $plannedUrls = $planner->planQueue($discovered['all'], [
                    'max_urls' => 50,
                ]);

                foreach ($plannedUrls as $idx => $u) {
                    $sep = str_contains($u, '?') ? '&' : '?';
                    $finalUrl = str_contains($u, 'tdcc_audit=1') ? $u : ($u . $sep . 'tdcc_audit=1');
                    $targets[] = [
                        'key'   => 'full_' . $idx,
                        'label' => wp_parse_url($u, PHP_URL_PATH) ?: '/',
                        'url'   => $finalUrl,
                    ];
                }
            } else {
                // Quick Scan using ContentSampler
                $postTypes = isset($_POST['targets']) && is_array($_POST['targets'])
                    ? array_map('sanitize_key', (array) $_POST['targets'])
                    : ['home', 'page', 'post'];
                $strategy = sanitize_key((string) ($_POST['strategy'] ?? ContentSampler::STRATEGY_REPRESENTATIVE));
                $sampleSize = isset($_POST['sample_size']) ? absint(wp_unslash($_POST['sample_size'])) : 1;
                $sampleSize = max(1, min(5, $sampleSize));
                $limit = max(1, $sampleSize * max(1, count($postTypes)) + 1);

                $sampled = ContentSampler::sample(
                    $postTypes,
                    $strategy,
                    $limit,
                    'tdcc_audit=1'
                );

                foreach ($sampled as $sItem) {
                    $u = (string) ($sItem['url'] ?? '');
                    if ($u === '') {
                        continue;
                    }
                    $sep = str_contains($u, '?') ? '&' : '?';
                    $finalUrl = str_contains($u, 'tdcc_audit=1') ? $u : ($u . $sep . 'tdcc_audit=1');
                    $targets[] = [
                        'key'   => (string) ($sItem['key'] ?? 'page'),
                        'label' => (string) ($sItem['label'] ?? __('Page', 'tuedion-cookie')),
                        'url'   => $finalUrl,
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Planning error caught; fall back cleanly
        }

        // Fallback to front page if no target resolved
        if (empty($targets)) {
            $targets[] = [
                'key'   => 'home',
                'label' => __('Front Page', 'tuedion-cookie'),
                'url'   => home_url('/?tdcc_audit=1'),
            ];
        }

        wp_send_json_success([
            'mode'    => $mode,
            'targets' => $targets,
            'total'   => count($targets),
        ]);
    }

    /**
     * AJAX endpoint to ignore, categorize, or delete an unknown resource finding.
     */
    public static function handleAjaxManageUnknownResource(): void
    {
        check_ajax_referer('tuedion_scanner_action', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized permission.', 'tuedion-cookie')], 403);
        }

        $subAction = sanitize_key((string) ($_POST['sub_action'] ?? ''));
        $resourceId = sanitize_key((string) ($_POST['resource_id'] ?? ''));
        $detector = new UnknownResourceDetector();

        if ($subAction === 'ignore') {
            $detector->updateStatus($resourceId, UnknownResourceDetector::STATUS_IGNORED);
            ScanRepository::setManualOverride($resourceId, ['action' => 'ignore']);
            wp_send_json_success(['message' => __('Resource marked as ignored.', 'tuedion-cookie')]);
        }

        if ($subAction === 'unignore') {
            $detector->updateStatus($resourceId, UnknownResourceDetector::STATUS_PENDING);
            ScanRepository::removeManualOverride($resourceId);
            wp_send_json_success(['message' => __('Resource restored to pending.', 'tuedion-cookie')]);
        }

        if ($subAction === 'delete') {
            $detector->delete($resourceId);
            ScanRepository::removeManualOverride($resourceId);
            wp_send_json_success(['message' => __('Resource deleted.', 'tuedion-cookie')]);
        }

        if ($subAction === 'clear_all') {
            $detector->clearAll();
            wp_send_json_success(['message' => __('Unknown resources cleared.', 'tuedion-cookie')]);
        }

        if ($subAction === 'categorize') {
            $name = isset($_POST['service_name']) ? sanitize_text_field(wp_unslash((string) $_POST['service_name'])) : '';
            $cat = isset($_POST['service_category']) ? sanitize_key(wp_unslash((string) $_POST['service_category'])) : 'marketing';
            $domain = isset($_POST['service_domain']) ? sanitize_text_field(wp_unslash((string) $_POST['service_domain'])) : '';

            if ($name === '') {
                wp_send_json_error(['message' => __('Service name cannot be empty.', 'tuedion-cookie')]);
            }

            $settings = Repository::getSettings();
            $existingServices = $settings['services'] ?? [];
            $slug = sanitize_title($name);

            $existingServices[] = [
                'id'          => $slug,
                'label'       => $name,
                'category'    => $cat,
                'cookies'     => [],
                /* translators: %s: Service domain name */
                'description' => sprintf(__('Custom service for %s', 'tuedion-cookie'), $domain),
            ];

            $settings['services'] = $existingServices;
            Repository::updateSettings($settings);
            Compiler::clearCache();

            $detector->updateStatus($resourceId, UnknownResourceDetector::STATUS_CLASSIFIED, [
                'assigned_service' => $slug,
            ]);
            ScanRepository::setManualOverride($resourceId, [
                'action'   => 'categorized',
                'category' => $cat,
                'name'     => $name,
            ]);

            wp_send_json_success([
                /* translators: %s: Service name */
                'message' => sprintf(__('Service "%s" created and added to preferences.', 'tuedion-cookie'), $name),
                'service' => end($settings['services']),
            ]);
        }

        wp_send_json_error(['message' => __('Invalid action specified.', 'tuedion-cookie')]);
    }

    /**
     * AJAX endpoint to retrieve historical scan records and diff metrics.
     */
    public static function handleAjaxGetScanHistory(): void
    {
        check_ajax_referer('tuedion_scanner_action', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized permission.', 'tuedion-cookie')], 403);
        }

        $history = ScanRepository::getHistory(10);
        $latest = ScanRepository::getLatestScan();

        wp_send_json_success([
            'history' => $history,
            'latest'  => $latest ? $latest->toArray() : null,
        ]);
    }

    /**
     * AJAX endpoint to completely clear and reset all scanner findings, discovered cookies, and history.
     */
    public static function handleAjaxResetScan(): void
    {
        check_ajax_referer('tuedion_scanner_action', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized permission.', 'tuedion-cookie')], 403);
        }

        // 1. Clear core scanner results and database options
        delete_option(self::OPTION_KEY);
        delete_option(self::KNOWN_COOKIES_OPTION);
        ScanRepository::clearHistory();
        delete_option(ScanRepository::OPTION_OVERRIDES);
        (new UnknownResourceDetector())->clearAll();
        (new ChangeWatcher())->markScanned();

        // 2. Clean static cookieTable dumps from custom translations
        \Tuedion\CookieConsent\I18n\TranslationManager::purgeCustomCookieTables();

        // 3. Clean configured services and categories of any stray admin/auth cookies
        $settings = Repository::getSettings();
        $settingsModified = false;

        if (!empty($settings['services']) && is_array($settings['services'])) {
            $updatedServices = [];
            foreach ($settings['services'] as $svc) {
                if (!is_array($svc)) {
                    continue;
                }
                $sId = strtolower((string) ($svc['id'] ?? ''));
                // If service is itself an auth cookie or WP core service, omit it
                if (str_starts_with($sId, 'wordpress_') || str_starts_with($sId, 'wp-settings-') || $sId === 'wordpress') {
                    $settingsModified = true;
                    continue;
                }
                // Strip auth cookies from cookies list
                if (!empty($svc['cookies']) && is_array($svc['cookies'])) {
                    $origCookieCount = count($svc['cookies']);
                    $svc['cookies'] = array_values(array_filter($svc['cookies'], static function ($c): bool {
                        $cStr = strtolower((string) $c);
                        return !str_starts_with($cStr, 'wordpress_') && !str_starts_with($cStr, 'wp-settings-');
                    }));
                    if (count($svc['cookies']) !== $origCookieCount) {
                        $settingsModified = true;
                    }
                }
                $updatedServices[] = $svc;
            }
            $settings['services'] = $updatedServices;
        }

        if (!empty($settings['categories']) && is_array($settings['categories'])) {
            foreach ($settings['categories'] as &$cat) {
                if (!is_array($cat)) {
                    continue;
                }
                if (!empty($cat['autoClear']) && is_array($cat['autoClear'])) {
                    $origCount = count($cat['autoClear']);
                    $cat['autoClear'] = array_values(array_filter($cat['autoClear'], static function ($c): bool {
                        $name = strtolower(is_array($c) ? ($c['name'] ?? '') : (string) $c);
                        return !str_starts_with($name, 'wordpress_') && !str_starts_with($name, 'wp-settings-');
                    }));
                    if (count($cat['autoClear']) !== $origCount) {
                        $settingsModified = true;
                    }
                }
            }
            unset($cat);
        }

        if ($settingsModified) {
            Repository::updateSettings($settings);
        }

        // 4. Re-initialize empty clean baseline for scanner results
        $cleanResults = [
            'timestamp'         => time(),
            'last_scan_date'    => '',
            'last_scan_human'   => '',
            'scanned_urls'      => [],
            'detected_services' => [],
            'detected_cookies'  => [],
            'unknown_resources' => [],
            'diff'              => [
                'has_changes'      => false,
                'new_services'     => [],
                'removed_services' => [],
                'new_cookies'      => [],
                'removed_cookies'  => [],
                'new_unknowns'     => [],
                'summary'          => __('All scan findings cleared.', 'tuedion-cookie'),
            ],
            'database_meta'     => CookieDatabase::getMetadata(),
            'stats'             => [
                'detected_services_count' => 0,
                'detected_cookies_count'  => 0,
                'unknown_count'           => 0,
                'catalogue_total_count'   => count(ServiceRegistry::getAll()),
                'coverage_percent'        => 0,
            ],
        ];
        update_option(self::OPTION_KEY, $cleanResults, false);

        // 5. Invalidate all runtime compiler caches across languages and external caching plugins
        Compiler::clearCache();
        \Tuedion\CookieConsent\Integrations\CacheCompatibility::purgeAllCaches();

        wp_send_json_success([
            'message' => __('All scan results, discovered cookies, and history have been cleared successfully.', 'tuedion-cookie'),
        ]);
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

        $scanTargets = (array) ($scannerConfig['scan_targets'] ?? ['home', 'page', 'post']);

        // Run full site scan on configured targets
        $scanResults = self::scan(true, $scanTargets);
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
