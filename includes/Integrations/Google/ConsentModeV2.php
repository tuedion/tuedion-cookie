<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Integrations\Google;

use Tuedion\CookieConsent\Consent\Frontend;
use Tuedion\CookieConsent\Settings\Defaults;
use Tuedion\CookieConsent\Settings\Repository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google Consent Mode v2 Engine.
 * Responsible for early default state injection in <head> at priority 0 (timing guarantee)
 * and providing client configuration for dynamic consent updates.
 */
final class ConsentModeV2
{
    public static function register(): void
    {
        // Inject early default state at the earliest possible hook
        add_action('wp_head', [self::class, 'renderEarlyDefaultState'], 0);
    }

    /**
     * Check if Google Consent Mode v2 is enabled.
     */
    public static function isEnabled(): bool
    {
        $settings = Repository::getSettings();
        return !empty($settings['gcm']['enabled']);
    }

    /**
     * Render the early default gtag('consent', 'default', ...) block.
     */
    public static function renderEarlyDefaultState(): void
    {
        if (is_admin() || !Frontend::shouldLoad()) {
            return;
        }

        if (\Tuedion\CookieConsent\Diagnostics\Profiler::isGcmDisabled()) {
            return;
        }

        if (!self::isEnabled()) {
            return;
        }

        $settings = Repository::getSettings();
        $gcmSettings = $settings['gcm'] ?? Defaults::get()['gcm'];
        $mapping = GcmMapping::getActiveMapping($gcmSettings);

        // Check if visitor already has a valid consent cookie from a previous session (Early Restore)
        $cookieName = isset($settings['cookie']['name']) ? (string) $settings['cookie']['name'] : 'cc_cookie';
        $currentRevision = (int) ($settings['revision'] ?? 1);

        $defaultStates = [];
        foreach ($mapping as $signal => $category) {
            if ($category === 'necessary' || $signal === GcmMapping::SIGNAL_SECURITY_STORAGE) {
                $defaultStates[$signal] = 'granted';
            } else {
                // Page-cache immunity: Non-essential signals default to denied in server HTML
                // Valid returning visitor consent is restored early in browser JS
                $defaultStates[$signal] = 'denied';
            }
        }

        // Wait for update timeout (default 500ms)
        $waitForUpdate = max(100, min(5000, (int) ($gcmSettings['wait_for_update'] ?? 500)));
        $defaultStates['wait_for_update'] = $waitForUpdate;

        $adsDataRedaction = !empty($gcmSettings['ads_data_redaction']);
        $urlPassthrough   = !empty($gcmSettings['url_passthrough']);
        $debugMode        = !empty($settings['advanced']['debug_mode']);

        $clientConfig = [
            'enabled'            => true,
            'mapping'            => $mapping,
            'debug'              => $debugMode,
            'adsDataRedaction'   => $adsDataRedaction,
            'urlPassthrough'     => $urlPassthrough,
            'cookieName'         => $cookieName,
            'revision'           => $currentRevision,
        ];

        // Format early inline JavaScript
        $jsonStates = (string) wp_json_encode($defaultStates, JSON_UNESCAPED_SLASHES);
        $jsonConfig = (string) wp_json_encode($clientConfig, JSON_UNESCAPED_SLASHES);

        $inlineJs = "window.dataLayer = window.dataLayer || [];\n";
        $inlineJs .= "function gtag(){dataLayer.push(arguments);}\n";
        $inlineJs .= "gtag('consent', 'default', {$jsonStates});\n";

        if ($adsDataRedaction) {
            $inlineJs .= "gtag('set', 'ads_data_redaction', true);\n";
        }

        if ($urlPassthrough) {
            $inlineJs .= "gtag('set', 'url_passthrough', true);\n";
        }

        $inlineJs .= "window.tuedionGcmConfig = {$jsonConfig};\n";

        if ($debugMode) {
            $inlineJs .= "console.log('[Tuedion Cookie] GCM v2 Early Default State Injected (Cache-Immune Denied Default):', {$jsonStates});\n";
        }

        // Output script tag with Cloudflare and PageSpeed immunity attributes
        printf(
            "<script data-cfasync=\"false\" data-pagespeed-no-defer data-rocketignore=\"true\" id=\"tuedion-cookie-gcm-default\">\n%s</script>\n",
            $inlineJs
        );
    }

    /**
     * Inspect existing cookie for returning visitor consent state with revision validation.
     *
     * @param string $cookieName
     * @param int $currentRevision
     * @return array<string, mixed>|null
     */
    public static function getExistingCookieConsent(string $cookieName, int $currentRevision = 1): ?array
    {
        if (empty($_COOKIE[$cookieName])) {
            return null;
        }

        $raw = sanitize_text_field(wp_unslash((string) $_COOKIE[$cookieName]));
        $decoded = json_decode($raw, true);

        if (is_array($decoded) && isset($decoded['categories']) && is_array($decoded['categories'])) {
            // Validate revision
            $cookieRevision = isset($decoded['revision']) ? (int) $decoded['revision'] : 1;
            if ($cookieRevision !== $currentRevision) {
                return null;
            }

            return $decoded;
        }

        return null;
    }
}
