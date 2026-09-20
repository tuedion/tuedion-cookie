<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Scanner\Smart;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Discovers URLs across Sitemaps, Navigation Menus, and WordPress Critical System Pages.
 */
final class UrlDiscovery
{
    /**
     * Maximum number of nested sitemap child indexes to follow.
     */
    private const MAX_SUB_SITEMAPS = 5;

    /**
     * Maximum number of URLs to extract from sitemaps to prevent memory spikes.
     */
    private const MAX_SITEMAP_URLS = 250;

    /**
     * Discover all candidate URLs grouped by origin source.
     *
     * @param array<string, mixed> $options
     * @return array{
     *     sitemap: list<string>,
     *     menus: list<string>,
     *     critical: list<string>,
     *     all: list<string>
     * }
     */
    public function discover(array $options = []): array
    {
        $includeSitemap  = (bool) ($options['include_sitemap'] ?? true);
        $includeMenus    = (bool) ($options['include_menus'] ?? true);
        $includeCritical = (bool) ($options['include_critical'] ?? true);

        $sitemapUrls  = $includeSitemap ? $this->discoverSitemapUrls() : [];
        $menuUrls     = $includeMenus ? $this->discoverMenuUrls() : [];
        $criticalUrls = $includeCritical ? $this->discoverCriticalUrls() : [];

        $allUrls = array_values(array_unique(array_merge($criticalUrls, $menuUrls, $sitemapUrls)));

        return [
            'sitemap'  => $sitemapUrls,
            'menus'    => $menuUrls,
            'critical' => $criticalUrls,
            'all'      => $allUrls,
        ];
    }

    /**
     * Discovers URLs from standard WordPress and SEO plugin sitemaps.
     *
     * @return list<string>
     */
    public function discoverSitemapUrls(): array
    {
        $candidateSitemaps = [
            home_url('/wp-sitemap.xml'),       // Core WP 5.5+
            home_url('/sitemap_index.xml'),    // Yoast / RankMath
            home_url('/sitemap.xml'),          // Standard fallback
        ];

        $urls = [];

        foreach ($candidateSitemaps as $sitemapUrl) {
            $response = wp_safe_remote_get($sitemapUrl, [
                'timeout'     => 8,
                'redirection' => 3,
                'sslverify'   => (bool) apply_filters('tuedion_cookie_crawler_ssl_verify', true),
                'user-agent'  => 'TuedionCookieScanner/3.0; ' . home_url(),
            ]);

            if (is_wp_error($response)) {
                continue;
            }

            $statusCode = (int) wp_remote_retrieve_response_code($response);
            if ($statusCode < 200 || $statusCode >= 300) {
                continue;
            }

            $body = wp_remote_retrieve_body($response);
            if (empty($body)) {
                continue;
            }

            $extracted = $this->parseXmlSitemap($body);
            if (!empty($extracted)) {
                $urls = array_merge($urls, $extracted);
                // Once we have discovered a working sitemap source with URLs, don't spam other variants
                if (count($urls) >= self::MAX_SITEMAP_URLS) {
                    break;
                }
            }
        }

        return array_values(array_unique(array_slice($urls, 0, self::MAX_SITEMAP_URLS)));
    }

    /**
     * Parses an XML sitemap or sitemap index string and extracts loc tags.
     *
     * @param string $xmlContent
     * @return list<string>
     */
    private function parseXmlSitemap(string $xmlContent): array
    {
        $urls = [];

        // Disable entity loader to guard against XXE attacks
        $prevEntityLoader = libxml_disable_entity_loader(true);
        $prevErrors = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            if ($xml === false) {
                return [];
            }

            // Case 1: Sitemap Index (<sitemapindex><sitemap><loc>...</loc></sitemap></sitemapindex>)
            if (isset($xml->sitemap)) {
                $subSitemaps = [];
                foreach ($xml->sitemap as $sitemapNode) {
                    if (isset($sitemapNode->loc)) {
                        $subSitemaps[] = (string) $sitemapNode->loc;
                    }
                }

                $followed = 0;
                foreach ($subSitemaps as $subUrl) {
                    if ($followed >= self::MAX_SUB_SITEMAPS) {
                        break;
                    }

                    $subResponse = wp_safe_remote_get($subUrl, [
                        'timeout'   => 5,
                        'sslverify' => (bool) apply_filters('tuedion_cookie_crawler_ssl_verify', true),
                    ]);

                    if (!is_wp_error($subResponse) && wp_remote_retrieve_response_code($subResponse) === 200) {
                        $subBody = wp_remote_retrieve_body($subResponse);
                        $subXml = simplexml_load_string($subBody, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
                        if ($subXml !== false && isset($subXml->url)) {
                            foreach ($subXml->url as $urlNode) {
                                if (isset($urlNode->loc)) {
                                    $urls[] = (string) $urlNode->loc;
                                }
                            }
                        }
                    }
                    $followed++;
                }
            }

            // Case 2: Direct Urlset (<urlset><url><loc>...</loc></url></urlset>)
            if (isset($xml->url)) {
                foreach ($xml->url as $urlNode) {
                    if (isset($urlNode->loc)) {
                        $urls[] = (string) $urlNode->loc;
                    }
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prevErrors);
            if (PHP_VERSION_ID < 80000) {
                libxml_disable_entity_loader($prevEntityLoader);
            }
        }

        return $urls;
    }

    /**
     * Discovers URLs defined in registered WordPress navigation menus.
     *
     * @return list<string>
     */
    public function discoverMenuUrls(): array
    {
        $urls = [];
        $menus = wp_get_nav_menus();

        if (empty($menus) || !is_array($menus)) {
            return [];
        }

        $homeHost = (string) wp_parse_url(home_url(), PHP_URL_HOST);

        foreach ($menus as $menu) {
            $items = wp_get_nav_menu_items($menu->term_id);
            if (empty($items) || !is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (empty($item->url) || !is_string($item->url)) {
                    continue;
                }

                $url = trim($item->url);
                if ($url === '' || $url === '#' || str_starts_with($url, 'javascript:')) {
                    continue;
                }

                $urlHost = (string) wp_parse_url($url, PHP_URL_HOST);
                // Only retain menu URLs that belong to this WordPress site
                if ($urlHost === '' || strcasecmp($urlHost, $homeHost) === 0) {
                    $urls[] = $url;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Discovers critical system pages (Front page, blog, privacy policy, WooCommerce core pages).
     *
     * @return list<string>
     */
    public function discoverCriticalUrls(): array
    {
        $urls = [
            home_url('/'),
        ];

        // Blog / Posts page
        $pageForPosts = (int) get_option('page_for_posts');
        if ($pageForPosts > 0) {
            $permalink = get_permalink($pageForPosts);
            if ($permalink) {
                $urls[] = $permalink;
            }
        }

        // Privacy Policy page
        $privacyPage = (int) get_option('wp_page_for_privacy_policy');
        if ($privacyPage > 0) {
            $permalink = get_permalink($privacyPage);
            if ($permalink) {
                $urls[] = $permalink;
            }
        }

        // WooCommerce core pages if active
        if (function_exists('wc_get_page_id')) {
            $wcPages = ['shop', 'cart', 'checkout', 'myaccount'];
            foreach ($wcPages as $wcKey) {
                $pageId = (int) wc_get_page_id($wcKey);
                if ($pageId > 0) {
                    $permalink = get_permalink($pageId);
                    if ($permalink) {
                        $urls[] = $permalink;
                    }
                }
            }
        }

        // Contact page heuristics (often holds Google Maps, reCAPTCHA, form trackers)
        $contactPages = get_posts([
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 3,
            'name__in'       => ['contact', 'kontakt', 'iletisim', 'contact-us', 'contactus'],
        ]);

        foreach ($contactPages as $cPage) {
            $permalink = get_permalink($cPage->ID);
            if ($permalink) {
                $urls[] = $permalink;
            }
        }

        return array_values(array_unique($urls));
    }
}
