<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Consent;

use Tuedion\CookieConsent\Diagnostics\Profiler;
use Tuedion\CookieConsent\Integrations\RecipeRegistry;
use Tuedion\CookieConsent\Settings\Repository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enforces deny-before-consent on registered and enqueued third-party scripts.
 * Transforms matching <script> tags to type="text/plain" and data-category="...".
 */
final class ScriptEnforcer
{
    /**
     * @var array<string, array{handle: string, src: string, category: string, service: string|null}>
     */
    private static array $enforcedScripts = [];

    /**
     * Internal handles and critical WP infrastructure that must NEVER be blocked or delayed.
     */
    private const IMMUNE_HANDLES = [
        'cookieconsent-vendor',
        'tuedion-cookie-public',
        'tuedion-cookie-event-bridge',
        'tuedion-cookie-bootstrap',
        'tuedion-cookie-persistent-trigger',
        'tuedion-cookie-gcm',
        'admin-bar',
        'jquery',
        'jquery-core',
        'jquery-migrate',
        'wp-embed',
        'wp-emoji-release',
        'elementor-frontend',
        'elementor-pro-frontend',
        'elementor-webpack-runtime',
        'polylang',
        // WooCommerce checkout and cart core scripts
        'woocommerce',
        'wc-checkout',
        'wc-cart',
        'wc-cart-fragments',
        'wc-add-to-cart',
    ];

    public static function register(): void
    {
        add_filter('script_loader_tag', [self::class, 'enforceScriptTag'], 99, 3);
        add_action('template_redirect', [self::class, 'startGlobalBuffer'], 1);
    }

    /**
     * Get records of scripts transformed on the current request (for diagnostics).
     *
     * @return array<string, array{handle: string, src: string, category: string, service: string|null}>
     */
    public static function getEnforcedScripts(): array
    {
        return self::$enforcedScripts;
    }

    /**
     * Inspect and transform script tag if it belongs to a consent-managed category.
     *
     * @param string $tag The HTML script tag.
     * @param string $handle The script handle.
     * @param string $src The script source URL.
     * @return string
     */
    public static function enforceScriptTag(string $tag, string $handle, string $src): string
    {
        if (is_admin()) {
            return $tag;
        }

        if (Profiler::isScriptEnforcerDisabled()) {
            Profiler::recordScript($handle, $src, 'BYPASS_FLAG', null, null);
            return $tag;
        }

        if (!Frontend::shouldLoad()) {
            return $tag;
        }

        $settings = Repository::getSettings();
        $blockingEnabled = $settings['advanced']['script_blocking'] ?? true;
        if (!$blockingEnabled) {
            Profiler::recordScript($handle, $src, 'DISABLED_IN_SETTINGS', null, null);
            return $tag;
        }

        // Protect core plugin scripts from third-party minifiers/delayers (WP Rocket, Cloudflare, PageSpeed, NitroPack)
        if (in_array($handle, self::IMMUNE_HANDLES, true)) {
            // Apply immunity attributes to ALL script tags in this snippet (e.g. before-inline + main script)
            $tag = (string) preg_replace_callback('/<script(?=[\s>])([^>]*)>/i', static function (array $m): string {
                $attrs = $m[1];
                if (!str_contains($attrs, 'data-cfasync=')) {
                    $attrs .= ' data-cfasync="false"';
                }
                if (!str_contains($attrs, 'data-pagespeed-no-defer')) {
                    $attrs .= ' data-pagespeed-no-defer';
                }
                if (!str_contains($attrs, 'data-no-defer=')) {
                    $attrs .= ' data-no-defer="1"';
                }
                // WP Rocket: prevent Delay JS and Defer JS from touching immune scripts
                if (!str_contains($attrs, 'data-rocketignore')) {
                    $attrs .= ' data-rocketignore="true"';
                }
                return '<script' . $attrs . '>';
            }, $tag);

            Profiler::recordScript($handle, $src, 'IMMUNE', 'necessary', null);
            return $tag;
        }

        // Check if author already provided data-category manually
        if (str_contains($tag, 'data-category=')) {
            if (str_contains($tag, 'data-category="necessary"') || str_contains($tag, "data-category='necessary'")) {
                Profiler::recordScript($handle, $src, 'EXPLICIT_NECESSARY', 'necessary', null);
                return $tag;
            }
            if (!str_contains($tag, 'type="text/plain"') && !str_contains($tag, "type='text/plain'")) {
                $tag = self::transformTag($tag, '', null, true);
            }
            Profiler::recordScript($handle, $src, 'MANUAL_TAG', 'custom', null);
            return $tag;
        }

        // Allow filters to supply category override
        $category = apply_filters('tuedion_cookie_script_category', null, $handle, $src, $tag);
        $serviceId = null;

        if ($category === null) {
            $recipe = RecipeRegistry::findRecipeForScript($handle, $src);
            if ($recipe !== null) {
                $category = $recipe['category'] ?? 'analytics';
                $serviceId = $recipe['id'] ?? null;
            }
        }

        // Strictly necessary scripts (captchas, security challenges, session tokens) must never be blocked
        if ($category === null || !is_string($category) || $category === 'necessary') {
            Profiler::recordScript($handle, $src, 'ALLOWED', $category ?: 'none', $serviceId);
            return $tag;
        }

        // Transform script tag safely
        $transformedTag = self::transformTag($tag, $category, $serviceId);

        // Record for diagnostics & telemetry
        self::$enforcedScripts[$handle] = [
            'handle'   => $handle,
            'src'      => $src,
            'category' => $category,
            'service'  => $serviceId,
        ];

        Profiler::recordScript($handle, $src, 'BLOCKED', $category, $serviceId);

        return $transformedTag;
    }

    /**
     * Rewrite <script> tag opening attributes for CookieConsent 3.x compliance.
     * Operates strictly on opening <script ...> tags, never corrupting inner script code.
     */
    private static function transformTag(string $tag, string $category, ?string $serviceId, bool $onlyType = false): string
    {
        return (string) preg_replace_callback('/<script(?=[\s>])([^>]*)>/i', static function (array $m) use ($category, $serviceId, $onlyType): string {
            $attrs = $m[1];

            // Extract original type to preserve in data-type for CookieConsent 3.x
            $origType = '';
            if (preg_match('/\btype\s*=\s*(["\'])(.*?)\1/i', $attrs, $typeMatch)) {
                $origType = trim($typeMatch[2]);
            }

            // 1. Set type="text/plain"
            if ($origType !== '') {
                $attrs = (string) preg_replace('/\btype\s*=\s*(["\']).*?\1/i', 'type="text/plain"', $attrs, 1);
            } else {
                $attrs .= ' type="text/plain"';
            }

            // Preserve original type (e.g. module) in data-type="..." for proper CookieConsent unlocking
            if ($origType !== '' && $origType !== 'text/javascript' && $origType !== 'application/javascript') {
                if (!preg_match('/\bdata-type\s*=/i', $attrs)) {
                    $attrs .= ' data-type="' . esc_attr($origType) . '"';
                }
            }

            if ($onlyType) {
                return '<script' . $attrs . '>';
            }

            // 2. Set data-category="..."
            $catVal = esc_attr($category);
            if (preg_match('/\bdata-category\s*=\s*(["\']).*?\1/i', $attrs)) {
                $attrs = (string) preg_replace('/\bdata-category\s*=\s*(["\']).*?\1/i', 'data-category="' . $catVal . '"', $attrs, 1);
            } else {
                $attrs .= ' data-category="' . $catVal . '"';
            }

            // 3. Set data-service="..." if provided
            if ($serviceId !== null && $serviceId !== '') {
                $svcVal = esc_attr($serviceId);
                if (preg_match('/\bdata-service\s*=\s*(["\']).*?\1/i', $attrs)) {
                    $attrs = (string) preg_replace('/\bdata-service\s*=\s*(["\']).*?\1/i', 'data-service="' . $svcVal . '"', $attrs, 1);
                } else {
                    $attrs .= ' data-service="' . $svcVal . '"';
                }
            }

            return '<script' . $attrs . '>';
        }, $tag);
    }

    /**
     * Developer Helper: Generate a compliant external script tag.
     *
     * @param string $category e.g. 'analytics', 'marketing'
     * @param string $src URL of script
     * @param string|null $service e.g. 'google-analytics'
     * @param array<string, string|bool> $attributes Extra HTML attributes (async, defer, id, etc.)
     * @return string
     */
    public static function scriptTag(string $category, string $src, ?string $service = null, array $attributes = []): string
    {
        $attrString = '';
        foreach ($attributes as $key => $val) {
            if ($val === true) {
                $attrString .= ' ' . esc_attr($key);
            } elseif ($val !== false && $val !== null) {
                $attrString .= sprintf(' %s="%s"', esc_attr($key), esc_attr((string) $val));
            }
        }

        $serviceAttr = $service !== null ? sprintf(' data-service="%s"', esc_attr($service)) : '';

        return sprintf(
            '<script type="text/plain" data-category="%s"%s src="%s"%s></script>',
            esc_attr($category),
            $serviceAttr,
            esc_url($src),
            $attrString
        );
    }

    /**
     * Developer Helper: Generate a compliant inline script tag.
     *
     * @param string $category
     * @param string $code JavaScript code
     * @param string|null $service
     * @param array<string, string|bool> $attributes
     * @return string
     */
    public static function wrapInlineScript(string $category, string $code, ?string $service = null, array $attributes = []): string
    {
        $attrString = '';
        foreach ($attributes as $key => $val) {
            if ($val === true) {
                $attrString .= ' ' . esc_attr($key);
            } elseif ($val !== false && $val !== null) {
                $attrString .= sprintf(' %s="%s"', esc_attr($key), esc_attr((string) $val));
            }
        }

        $serviceAttr = $service !== null ? sprintf(' data-service="%s"', esc_attr($service)) : '';

        return sprintf(
            '<script type="text/plain" data-category="%s"%s%s>%s</script>',
            esc_attr($category),
            $serviceAttr,
            $attrString,
            $code
        );
    }

    /**
     * Start global HTML output buffer on the frontend to intercept hardcoded or un-enqueued inline scripts.
     */
    public static function startGlobalBuffer(): void
    {
        if (is_admin()) {
            return;
        }

        if (wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST) || is_feed()) {
            return;
        }

        if (Profiler::isScriptEnforcerDisabled()) {
            return;
        }

        if (!Frontend::shouldLoad()) {
            return;
        }

        $settings = Repository::getSettings();
        $blockingEnabled = $settings['advanced']['script_blocking'] ?? true;
        if (!$blockingEnabled) {
            return;
        }

        ob_start([self::class, 'filterBufferedHtml']);
    }

    /**
     * Filter complete HTML buffer output and rewrite unmanaged third-party inline/direct scripts.
     */
    public static function filterBufferedHtml(string $html): string
    {
        if (stripos($html, '<html') === false || stripos($html, '<body') === false) {
            return $html;
        }

        return (string) preg_replace_callback(
            '/<script\b([^>]*)>(.*?)<\/script>/is',
            static function (array $matches): string {
                $fullTag = $matches[0];
                $attrs   = $matches[1];
                $content = $matches[2];

                // 1. Skip if already classified with data-category or type="text/plain"
                if (stripos($attrs, 'data-category=') !== false || stripos($attrs, 'type="text/plain"') !== false || stripos($attrs, "type='text/plain'") !== false) {
                    return $fullTag;
                }

                // 2. Skip non-JS types (JSON-LD, templates, etc.)
                if (preg_match('/\btype\s*=\s*["\'](application\/ld\+json|application\/json|text\/template|text\/html)["\']/i', $attrs)) {
                    return $fullTag;
                }

                // 3. Skip immune core scripts
                if (stripos($attrs, 'cookieconsent') !== false || stripos($attrs, 'tuedion-cookie') !== false || stripos($attrs, 'jquery') !== false || stripos($attrs, 'data-rocketignore') !== false) {
                    return $fullTag;
                }

                // Extract src attribute if present
                $src = '';
                if (preg_match('/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $attrs, $srcMatch)) {
                    $src = $srcMatch[1];
                }

                // Determine if script matches a known tracker recipe
                $recipe = null;
                if ($src !== '') {
                    $recipe = RecipeRegistry::findRecipeForScript('', $src);
                } else {
                    $recipe = RecipeRegistry::findRecipeForScript('', $content);
                    if ($recipe === null) {
                        $recipe = self::detectInlineTrackerRecipe($content);
                    }
                }

                if ($recipe === null) {
                    return $fullTag;
                }

                $category = (string) ($recipe['category'] ?? 'analytics');
                $serviceId = (string) ($recipe['id'] ?? '');

                if ($category === 'necessary') {
                    return $fullTag;
                }

                // Transform the script opening tag to text/plain + data-category
                $transformedOpening = self::transformTag('<script' . $attrs . '>', $category, $serviceId);
                return $transformedOpening . $content . '</script>';
            },
            $html
        );
    }

    /**
     * Match common inline third-party tracking signatures.
     *
     * @param string $content
     * @return array<string, mixed>|null
     */
    private static function detectInlineTrackerRecipe(string $content): ?array
    {
        if (str_contains($content, 'gtag(') || str_contains($content, "ga('create") || str_contains($content, 'ga("create')) {
            return RecipeRegistry::get('google-analytics');
        }
        if (str_contains($content, 'fbq(')) {
            return RecipeRegistry::get('meta-pixel');
        }
        if (str_contains($content, 'ttq.load') || str_contains($content, 'ttq.page')) {
            return RecipeRegistry::get('tiktok-pixel');
        }
        if (str_contains($content, '_hjSettings')) {
            return RecipeRegistry::get('hotjar');
        }
        if (str_contains($content, 'clarity(')) {
            return RecipeRegistry::get('microsoft-clarity');
        }
        if (str_contains($content, 'pintrk(')) {
            return RecipeRegistry::get('pinterest-tag');
        }
        return null;
    }
}
