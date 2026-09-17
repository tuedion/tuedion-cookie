<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Integrations\Google;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Detects Google Site Kit and analyzes compatibility / duplicate tag risks.
 */
final class SiteKitDetector
{
    /**
     * Check if Google Site Kit is installed and active.
     */
    public static function isSiteKitActive(): bool
    {
        if (defined('GOOGLESITEKIT_VERSION')) {
            return true;
        }

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active('google-site-kit/google-site-kit.php');
    }

    /**
     * Check if Site Kit's native Consent Mode feature is enabled.
     */
    public static function isSiteKitConsentModeEnabled(): bool
    {
        if (!self::isSiteKitActive()) {
            return false;
        }

        // Site Kit stores module settings in options
        $analyticsSettings = get_option('googlesitekit_analytics-4_settings');
        if (is_array($analyticsSettings) && !empty($analyticsSettings['consentModeEnabled'])) {
            return true;
        }

        $coreSettings = get_option('googlesitekit_consent_mode_settings');
        if (is_array($coreSettings) && !empty($coreSettings['enabled'])) {
            return true;
        }

        return false;
    }

    /**
     * Get diagnostic status and duplicate warnings.
     *
     * @return array{active: bool, version: string, consent_mode_enabled: bool, has_duplicate_risk: bool, warning_message: string}
     */
    public static function getDiagnostics(): array
    {
        $active = self::isSiteKitActive();
        $version = defined('GOOGLESITEKIT_VERSION') ? (string) GOOGLESITEKIT_VERSION : 'Not Active';
        $siteKitGcm = self::isSiteKitConsentModeEnabled();

        $hasDuplicateRisk = $active && $siteKitGcm;
        $warningMessage = '';

        if ($hasDuplicateRisk) {
            $warningMessage = __(
                'Both Tuedion Cookie and Google Site Kit have Google Consent Mode enabled. To avoid duplicate default states and timing conflicts, disable Consent Mode in Google Site Kit settings (Site Kit > Settings > Analytics > Consent Mode).',
                'tuedion-cookie'
            );
        }

        return [
            'active'               => $active,
            'version'              => $version,
            'consent_mode_enabled' => $siteKitGcm,
            'has_duplicate_risk'   => $hasDuplicateRisk,
            'warning_message'      => $warningMessage,
        ];
    }
}
