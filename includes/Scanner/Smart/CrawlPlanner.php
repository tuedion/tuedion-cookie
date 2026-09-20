<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Scanner\Smart;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plans, filters, normalizes and scopes the crawl queue for Smart and Full scan modes.
 */
final class CrawlPlanner
{
    /**
     * Dangerous URL patterns or keywords that should NEVER be crawled to avoid state modification.
     */
    private const FORBIDDEN_PATTERNS = [
        '/wp-admin',
        '/wp-login',
        '/wp-json',
        'xmlrpc.php',
        'wp-cron.php',
        'action=logout',
        'action=delete',
        'action=trash',
        'add-to-cart=',
        'remove_item=',
        'undo_item=',
        'empty_cart=',
        'cancel_order=',
        'order_again=',
        'checkout',
        'my-account/lost-password',
        '_wpnonce=',
        'tdcc_audit=',
        '/feed',
        '/feed/',
        '/trackback',
    ];

    /**
     * Non-HTML media extensions to skip during scanning.
     */
    private const STATIC_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico',
        'mp4', 'webm', 'ogg', 'mp3', 'wav', 'pdf', 'zip', 'gz', 'tar',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'css', 'js', 'json',
    ];

    /**
     * Query arguments that represent analytics or campaign tags and should be stripped during canonicalization.
     */
    private const STRIPPED_QUERY_ARGS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'fbclid', 'gclid', 'msclkid', 'mc_cid', 'mc_eid', 'ref',
    ];

    /**
     * @param string $homeUrl Site base URL for origin scoping
     */
    public function __construct(
        private readonly string $homeUrl = ''
    ) {
    }

    /**
     * Normalizes and filters a list of candidate URLs.
     *
     * @param list<string> $rawUrls
     * @param array<string, mixed> $rules Options: 'max_urls' (int), 'exclude_patterns'|'exclude' (list<string>), 'include_patterns'|'include' (list<string>)
     * @return list<string> Clean, scoped, deduplicated URLs
     */
    public function planQueue(array $rawUrls, array $rules = []): array
    {
        $maxUrls  = (int) ($rules['max_urls'] ?? 50);
        $excludes = (array) ($rules['exclude_patterns'] ?? $rules['exclude'] ?? []);
        $includes = (array) ($rules['include_patterns'] ?? $rules['include'] ?? []);

        $base = $this->homeUrl !== '' ? $this->homeUrl : home_url();
        $baseHost = (string) wp_parse_url($base, PHP_URL_HOST);

        $valid = [];

        foreach ($rawUrls as $url) {
            $normalized = $this->normalizeUrl($url);
            if ($normalized === null) {
                continue;
            }

            if (!$this->isSameHost($normalized, $baseHost)) {
                continue;
            }

            if ($this->isForbidden($normalized)) {
                continue;
            }

            if ($this->hasStaticExtension($normalized)) {
                continue;
            }

            if (!empty($excludes) && $this->matchesRuleSet($normalized, $excludes)) {
                continue;
            }

            if (!empty($includes) && !$this->matchesRuleSet($normalized, $includes)) {
                continue;
            }

            $valid[$normalized] = true;

            if (count($valid) >= $maxUrls) {
                break;
            }
        }

        return array_keys($valid);
    }

    /**
     * Extracts internal links from an HTML response body for depth-based discovery.
     *
     * @param string $htmlContent
     * @param string $parentUrl
     * @return list<string>
     */
    public function extractInternalLinks(string $htmlContent, string $parentUrl): array
    {
        if (empty($htmlContent)) {
            return [];
        }

        $base = $this->homeUrl !== '' ? $this->homeUrl : home_url();
        $baseHost = (string) wp_parse_url($base, PHP_URL_HOST);

        $found = [];

        // Match href="..."
        if (preg_match_all('/<a\s+[^>]*?href=["\']([^"\']+)["\']/i', $htmlContent, $matches)) {
            foreach ($matches[1] as $href) {
                $href = trim($href);
                if ($href === '' || $href === '#' || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
                    continue;
                }

                $resolved = $this->resolveRelativeUrl($href, $parentUrl);
                if ($resolved !== null && $this->isSameHost($resolved, $baseHost) && !$this->isForbidden($resolved) && !$this->hasStaticExtension($resolved)) {
                    $found[$this->normalizeUrl($resolved) ?? $resolved] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Normalizes a URL: parses components, removes query trackers, removes anchors, canonicalizes paths.
     */
    public function normalizeUrl(string $url): ?string
    {
        $parsed = wp_parse_url(trim($url));
        if ($parsed === false || empty($parsed['host']) || empty($parsed['scheme'])) {
            return null;
        }

        $scheme = strtolower($parsed['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = strtolower($parsed['host']);
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path = $parsed['path'] ?? '/';

        // Filter and strip tracking query parameters
        $queryStr = '';
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $queryArgs);
            foreach (self::STRIPPED_QUERY_ARGS as $stripKey) {
                unset($queryArgs[$stripKey]);
            }
            if (!empty($queryArgs)) {
                ksort($queryArgs);
                $queryStr = '?' . http_build_query($queryArgs);
            }
        }

        return $scheme . '://' . $host . $port . $path . $queryStr;
    }

    private function isSameHost(string $url, string $expectedHost): bool
    {
        $urlHost = (string) wp_parse_url($url, PHP_URL_HOST);
        return strcasecmp($urlHost, $expectedHost) === 0;
    }

    private function isForbidden(string $url): bool
    {
        $lower = strtolower($url);
        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function hasStaticExtension(string $url): bool
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, self::STATIC_EXTENSIONS, true);
    }

    /**
     * Tests a URL against a set of wildcard/prefix rules.
     *
     * @param string $url
     * @param list<string> $rules
     * @return bool
     */
    private function matchesRuleSet(string $url, array $rules): bool
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);

        foreach ($rules as $rawRule) {
            $rule = trim($rawRule);
            if ($rule === '') {
                continue;
            }

            // Wildcard matching: e.g. /shop/* or *contact*
            if (str_contains($rule, '*')) {
                $regex = '#^' . str_replace('\*', '.*', preg_quote($rule, '#')) . '$#i';
                if (preg_match($regex, $url) || preg_match($regex, $path)) {
                    return true;
                }
            } elseif (str_starts_with($rule, '/')) {
                // Path prefix match
                if (str_starts_with($path, $rule)) {
                    return true;
                }
            } else {
                // Keyword / Substring match
                if (str_contains($url, $rule)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Resolves a relative URL against a parent base URL.
     */
    private function resolveRelativeUrl(string $relative, string $parent): ?string
    {
        if (str_starts_with($relative, 'http://') || str_starts_with($relative, 'https://')) {
            return $relative;
        }

        $parsedParent = wp_parse_url($parent);
        if ($parsedParent === false || empty($parsedParent['host'])) {
            return null;
        }

        $scheme = $parsedParent['scheme'] ?? 'https';
        $host   = $parsedParent['host'];
        $port   = isset($parsedParent['port']) ? ':' . $parsedParent['port'] : '';
        $base   = $scheme . '://' . $host . $port;

        // Protocol-relative (//example.com/foo)
        if (str_starts_with($relative, '//')) {
            return $scheme . ':' . $relative;
        }

        // Root-relative (/foo/bar)
        if (str_starts_with($relative, '/')) {
            return $base . $relative;
        }

        // Path-relative (sub/page.html)
        $parentPath = $parsedParent['path'] ?? '/';
        $dir = dirname($parentPath);
        if ($dir === '\\' || $dir === '.') {
            $dir = '/';
        }
        $dir = rtrim($dir, '/') . '/';

        return $base . $dir . $relative;
    }
}
