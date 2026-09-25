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
 * Enforces consent on embedded iframes (YouTube, Vimeo, Google Maps, etc.).
 * Intercepts iframes in content/widgets/embeds and replaces them with accessible placeholders.
 */
final class IframeEnforcer
{
    public static function register(): void
    {
        add_filter('the_content', [self::class, 'enforceContentIframes'], 20);
        add_filter('widget_text', [self::class, 'enforceContentIframes'], 20);
        add_filter('embed_oembed_html', [self::class, 'enforceContentIframes'], 20);
        add_filter('render_block', [self::class, 'enforceBlockIframes'], 20, 2);
        add_filter('elementor/frontend/the_content', [self::class, 'enforceContentIframes'], 20);

        // Global output buffer for frontend requests to catch Elementor popups, templates, and header/footer iframes
        add_action('template_redirect', [self::class, 'startGlobalBuffer'], 1);
    }

    /**
     * Common enforcement check: verify frontend should load, bypass flags, and Elementor editor/preview context.
     */
    public static function shouldEnforce(): bool
    {
        if (is_admin()) {
            return false;
        }

        if (!Frontend::shouldLoad()) {
            return false;
        }

        if (Profiler::isIframeEnforcerDisabled()) {
            return false;
        }

        // Exclude Elementor visual editor & preview
        if (class_exists('\Elementor\Plugin')) {
            try {
                if (\Elementor\Plugin::$instance->editor->is_edit_mode() || \Elementor\Plugin::$instance->preview->is_preview_mode()) {
                    return false;
                }
            } catch (\Throwable) {
                // Ignore if Elementor internal classes aren't available
            }
        }

        $settings = Repository::getSettings();
        return !empty($settings['advanced']['iframe_blocking']);
    }

    /**
     * Start output buffer on frontend requests.
     */
    public static function startGlobalBuffer(): void
    {
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('DOING_AJAX') && DOING_AJAX) || (defined('DOING_CRON') && DOING_CRON) || is_feed()) {
            return;
        }

        if (!self::shouldEnforce()) {
            return;
        }

        ob_start([self::class, 'processBufferedOutput']);
    }

    /**
     * Buffer callback processing all iframes in the final rendered HTML.
     */
    public static function processBufferedOutput(string $html): string
    {
        if (!str_contains($html, '<iframe')) {
            return $html;
        }

        return self::processIframes($html);
    }

    /**
     * Filter post/widget content for unapproved third-party iframes.
     *
     * @param mixed $content
     * @return string
     */
    public static function enforceContentIframes(mixed $content): string
    {
        if (!is_string($content) || empty($content) || !str_contains($content, '<iframe')) {
            return (string) $content;
        }

        if (!self::shouldEnforce()) {
            return $content;
        }

        return self::processIframes($content);
    }

    /**
     * Filter Gutenberg block output if it contains an iframe.
     *
     * @param string $blockContent
     * @param array<string, mixed> $block
     * @return string
     */
    public static function enforceBlockIframes(string $blockContent, array $block): string
    {
        if (empty($blockContent) || !str_contains($blockContent, '<iframe')) {
            return $blockContent;
        }

        if (!self::shouldEnforce()) {
            return $blockContent;
        }

        return self::processIframes($blockContent);
    }

    /**
     * Public gateway to enforce iframes in arbitrary HTML strings (used by Elementor, shortcodes, and adapters).
     *
     * @param string $html
     * @return string
     */
    public static function enforce(string $html): string
    {
        if (!self::shouldEnforce()) {
            return $html;
        }

        return self::processIframes($html);
    }

    /**
     * Parse embed source into service, id, and extra query params.
     *
     * @param string $src
     * @return array{service: string, id: string, params: string, category: string}|null
     */
    public static function parseEmbedSource(string $src): ?array
    {
        $src = trim($src);
        if ($src === '' || str_starts_with($src, 'about:') || str_starts_with($src, 'data:')) {
            return null;
        }

        // 1. YouTube
        if (preg_match('/(?:youtube(?:-nocookie)?\.com\/(?:embed\/|watch\?v=|v\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/i', $src, $m)) {
            $id = $m[1];
            $params = '';
            $query = wp_parse_url($src, PHP_URL_QUERY);
            if (!empty($query)) {
                $params = (string) $query;
            }
            return [
                'service'  => 'youtube',
                'id'       => $id,
                'params'   => $params,
                'category' => 'marketing',
            ];
        }

        // 2. Vimeo
        if (preg_match('/(?:player\.)?vimeo\.com\/(?:video\/)?([0-9]+)/i', $src, $m)) {
            $id = $m[1];
            $query = wp_parse_url($src, PHP_URL_QUERY) ?? '';
            return [
                'service'  => 'vimeo',
                'id'       => $id,
                'params'   => (string) $query,
                'category' => 'marketing',
            ];
        }

        // 3. Google Maps
        $srcLower = strtolower($src);
        if (str_contains($srcLower, 'google.com/maps') || str_contains($srcLower, 'maps.google.') || preg_match('/google\.[a-z.]+\/maps/i', $srcLower)) {
            return [
                'service'  => 'google-maps',
                'id'       => $src,
                'params'   => '',
                'category' => RecipeRegistry::resolveServiceCategory('google-maps', 'marketing'),
            ];
        }

        // 4. Fallback check with RecipeRegistry
        $recipe = RecipeRegistry::findRecipeForIframe($src);
        if ($recipe !== null) {
            return [
                'service'  => $recipe['id'] ?? 'external-media',
                'id'       => $src,
                'params'   => '',
                'category' => $recipe['category'] ?? 'marketing',
            ];
        }

        return null;
    }

    /**
     * Scan and replace matching iframes with placeholder wrappers.
     */
    private static function processIframes(string $html): string
    {
        if (!str_contains($html, '<iframe')) {
            return $html;
        }

        $pattern = '/<iframe\b([^>]*?)(?:>(.*?)<\/iframe>|\/>)/is';

        return (string) preg_replace_callback($pattern, static function (array $matches): string {
            $fullTag = $matches[0];
            $attributesStr = $matches[1];

            // 1. Check WP Rocket lazy-load attribute, data-src, then standard src
            $src = '';
            if (preg_match('/\bdata-lazy-src\s*=\s*(["\'])(.*?)\1/i', $attributesStr, $m)) {
                $src = html_entity_decode(trim($m[2]));
            }
            if ($src === '' || str_starts_with($src, 'data:') || str_starts_with($src, 'about:')) {
                if (preg_match('/\bdata-src\s*=\s*(["\'])(.*?)\1/i', $attributesStr, $m)) {
                    $src = html_entity_decode(trim($m[2]));
                }
            }
            if ($src === '' || str_starts_with($src, 'data:') || str_starts_with($src, 'about:')) {
                if (preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/i', $attributesStr, $m)) {
                    $src = html_entity_decode(trim($m[2]));
                }
            }

            if ($src === '' || str_starts_with($src, 'about:') || str_starts_with($src, 'data:')) {
                return $fullTag;
            }

            // Check if iframe is already managed
            if (str_contains($attributesStr, 'data-service=') || str_contains($attributesStr, 'tdcc-managed')) {
                return $fullTag;
            }

            $parsed = self::parseEmbedSource($src);
            if ($parsed === null) {
                return $fullTag;
            }

            $serviceId = $parsed['service'];
            $id = $parsed['id'];
            $params = $parsed['params'];
            $category = $parsed['category'];

            Profiler::recordIframe($src, $category, $serviceId);

            return self::renderPlaceholderWrapper($attributesStr, $serviceId, $id, $params, $src);
        }, $html);
    }

    /**
     * Render an Orest Bida iframemanager compliant placeholder DIV.
     */
    private static function renderPlaceholderWrapper(
        string $attributesStr,
        string $serviceId,
        string $id,
        string $params,
        string $originalSrc
    ): string {
        // Extract existing class attribute and sanitize lazyload classes
        $classes = ['tdcc-managed'];
        if (preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/i', $attributesStr, $classMatch)) {
            $existing = explode(' ', trim($classMatch[2]));
            foreach ($existing as $cls) {
                $cls = trim($cls);
                // Strip WP Rocket and other lazyloader flags from the container
                if ($cls !== '' && !in_array($cls, ['rocket-lazyload', 'lazyloaded', 'lazyloading', 'no-lazyload'], true)) {
                    $classes[] = $cls;
                }
            }
        }
        $classAttr = esc_attr(implode(' ', array_unique($classes)));

        // Extract style if present, or preserve explicit height from iframe attributes
        $styleAttr = '';
        if (preg_match('/\bstyle\s*=\s*(["\'])(.*?)\1/i', $attributesStr, $styleMatch)) {
            $styleAttr = sprintf(' style="%s"', esc_attr(trim($styleMatch[2])));
        } elseif (preg_match('/\bheight\s*=\s*(["\'])(.*?)\1/i', $attributesStr, $heightMatch)) {
            $hVal = trim($heightMatch[2]);
            if (is_numeric($hVal)) {
                $hVal .= 'px';
            }
            $styleAttr = sprintf(' style="min-height: %s;"', esc_attr($hVal));
        }

        // Determine thumbnail if available
        $thumbnailAttr = '';
        if ($serviceId === 'youtube' && !empty($id)) {
            $thumbnailAttr = sprintf(' data-thumbnail="https://i3.ytimg.com/vi/%s/hqdefault.jpg"', esc_attr($id));
        } elseif ($serviceId === 'vimeo' && !empty($id)) {
            $thumbnailAttr = sprintf(' data-thumbnail="https://vumbnail.com/%s.jpg"', esc_attr($id));
        }

        // Extract or default title if present
        $titleAttr = '';
        if (preg_match('/\btitle\s*=\s*(["\'])(.*?)\1/i', $attributesStr, $titleMatch)) {
            $titleAttr = sprintf(' data-title="%s"', esc_attr(trim($titleMatch[2])));
        } else {
            if ($serviceId === 'youtube') {
                $titleAttr = ' data-title="YouTube"';
            } elseif ($serviceId === 'vimeo') {
                $titleAttr = ' data-title="Vimeo"';
            } elseif ($serviceId === 'google-maps') {
                $titleAttr = ' data-title="Google Maps"';
            }
        }

        $paramsAttr = '';
        if ($params !== '') {
            $paramsAttr = sprintf(' data-params="%s"', esc_attr($params));
        }

        // Output standard Orest Bida iframemanager div element
        return sprintf(
            '<div class="%s" data-service="%s" data-id="%s"%s%s%s%s data-autoscale data-no-lazy="1" data-original-src="%s"></div>',
            $classAttr,
            esc_attr($serviceId),
            esc_attr($id),
            $thumbnailAttr,
            $paramsAttr,
            $titleAttr,
            $styleAttr,
            esc_url($originalSrc)
        );
    }
}
