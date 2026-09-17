<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Integrations\Adapters;

use Tuedion\CookieConsent\Consent\Frontend;
use Tuedion\CookieConsent\Consent\IframeEnforcer;
use Tuedion\CookieConsent\Settings\Repository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Elementor Page Builder Adapter.
 * Intercepts Elementor Video, Google Maps, and Embed widgets to enforce consent placeholders
 * and binds dynamic popup events.
 */
final class ElementorAdapter
{
    /**
     * Target Elementor widget types that typically embed third-party iframes.
     */
    private const TARGET_WIDGETS = [
        'video',
        'google_maps',
        'html',
        'shortcode',
        'custom-html',
    ];

    public static function register(): void
    {
        // Hook into Elementor widget content rendering
        add_filter('elementor/widget/render_content', [self::class, 'filterWidgetContent'], 20, 2);

        // Inject Elementor dynamic popup/modal bridge script
        add_action('wp_enqueue_scripts', [self::class, 'enqueueElementorBridge'], 50);
    }

    /**
     * Check if Elementor is active.
     */
    public static function isActive(): bool
    {
        return did_action('elementor/loaded') || defined('ELEMENTOR_VERSION');
    }

    /**
     * Intercept and rewrite iframe embeds inside Elementor widgets.
     *
     * @param string $content
     * @param mixed $widget Elementor widget instance (or null in tests)
     * @return string
     */
    public static function filterWidgetContent(string $content, mixed $widget = null): string
    {
        // If content has no iframe tag, return fast
        if (!str_contains($content, '<iframe')) {
            return $content;
        }

        // If frontend should not load or in editor/preview mode, never block
        if (!Frontend::shouldLoad()) {
            return $content;
        }

        if (class_exists('\Elementor\Plugin')) {
            try {
                if (\Elementor\Plugin::$instance->editor->is_edit_mode() || \Elementor\Plugin::$instance->preview->is_preview_mode()) {
                    return $content;
                }
            } catch (\Throwable) {
                // Ignore if Elementor internal classes aren't ready
            }
        }

        $settings = Repository::getSettings();
        if (empty($settings['advanced']['iframe_blocking'])) {
            return $content;
        }

        // Process iframes through Tuedion IframeEnforcer
        return IframeEnforcer::enforce($content);
    }

    /**
     * Enqueue client-side script bridge for Elementor Popups.
     * Uses vanilla JS to avoid jQuery dependency issues with WP Rocket Delay JS.
     */
    public static function enqueueElementorBridge(): void
    {
        if (!self::isActive() || !Frontend::shouldLoad()) {
            return;
        }

        // Elementor popup/show and video click bridge — waits for jQuery safely
        // and intercepts video overlay clicks in capture phase before Elementor creates/plays YouTube.
        $bridgeScript = '
        (function() {
            function isCategoryAccepted(cat) {
                return window.CookieConsent && typeof window.CookieConsent.acceptedCategory === "function" && window.CookieConsent.acceptedCategory(cat);
            }

            function interceptElementorVideoClicks() {
                document.addEventListener("click", function(e) {
                    var target = e.target;
                    if (!target) return;

                    // Strictly match play button or overlay inside an Elementor video widget
                    var videoOverlay = target.closest(".elementor-widget-video .elementor-custom-embed-play, .elementor-widget-video .elementor-custom-embed-image-overlay");
                    if (videoOverlay && !isCategoryAccepted("marketing")) {
                        e.preventDefault();
                        e.stopPropagation();
                        if (window.CookieConsent && typeof window.CookieConsent.showPreferences === "function") {
                            window.CookieConsent.showPreferences();
                        } else if (window.TuedionCookie && typeof window.TuedionCookie.showPreferences === "function") {
                            window.TuedionCookie.showPreferences();
                        }
                    }
                }, true);
            }

            function bindElementorPopup() {
                if (window.jQuery) {
                    jQuery(document).on("elementor/popup/show", function () {
                        if (window.TuedionCookie && typeof window.TuedionCookie.activateIframes === "function") {
                            window.TuedionCookie.activateIframes();
                        }
                    });
                }
            }

            interceptElementorVideoClicks();

            if (window.jQuery) {
                bindElementorPopup();
            } else {
                document.addEventListener("DOMContentLoaded", bindElementorPopup);
                window.addEventListener("load", bindElementorPopup);
            }
        })();';

        wp_add_inline_script('tuedion-cookie-bootstrap', $bridgeScript);
    }
}
