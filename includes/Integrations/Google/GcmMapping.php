<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Integrations\Google;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google Consent Mode v2 Signal Mapping Registry.
 * Defines the 7 core GCM v2 signals and their corresponding consent categories.
 */
final class GcmMapping
{
    /**
     * Standard GCM v2 signals.
     */
    public const SIGNAL_AD_STORAGE             = 'ad_storage';
    public const SIGNAL_AD_USER_DATA           = 'ad_user_data';
    public const SIGNAL_AD_PERSONALIZATION     = 'ad_personalization';
    public const SIGNAL_ANALYTICS_STORAGE      = 'analytics_storage';
    public const SIGNAL_FUNCTIONALITY_STORAGE  = 'functionality_storage';
    public const SIGNAL_PERSONALIZATION_STORAGE= 'personalization_storage';
    public const SIGNAL_SECURITY_STORAGE       = 'security_storage';

    public const ALL_SIGNALS = [
        self::SIGNAL_AD_STORAGE,
        self::SIGNAL_AD_USER_DATA,
        self::SIGNAL_AD_PERSONALIZATION,
        self::SIGNAL_ANALYTICS_STORAGE,
        self::SIGNAL_FUNCTIONALITY_STORAGE,
        self::SIGNAL_PERSONALIZATION_STORAGE,
        self::SIGNAL_SECURITY_STORAGE,
    ];

    /**
     * Default Category-to-GCM signals mapping.
     *
     * @return array<string, string> Signal name => category ID
     */
    public static function getDefaultMapping(): array
    {
        return [
            self::SIGNAL_AD_STORAGE              => 'marketing',
            self::SIGNAL_AD_USER_DATA            => 'marketing',
            self::SIGNAL_AD_PERSONALIZATION      => 'marketing',
            self::SIGNAL_ANALYTICS_STORAGE       => 'analytics',
            self::SIGNAL_FUNCTIONALITY_STORAGE   => 'functionality',
            self::SIGNAL_PERSONALIZATION_STORAGE => 'functionality',
            self::SIGNAL_SECURITY_STORAGE        => 'necessary',
        ];
    }

    /**
     * Get active signal-to-category mapping from settings or defaults.
     *
     * @param array<string, mixed>|null $gcmSettings Custom GCM settings or null to fetch from repository.
     * @return array<string, string>
     */
    public static function getActiveMapping(?array $gcmSettings = null): array
    {
        $defaults = self::getDefaultMapping();

        if ($gcmSettings !== null && isset($gcmSettings['mapping']) && is_array($gcmSettings['mapping'])) {
            $mapping = array_merge($defaults, $gcmSettings['mapping']);
        } else {
            $settings = \Tuedion\CookieConsent\Settings\Repository::getSettings();
            $custom = $settings['gcm']['mapping'] ?? [];
            $mapping = is_array($custom) ? array_merge($defaults, $custom) : $defaults;
        }

        /**
         * Filter active GCM v2 signal mapping.
         *
         * @param array<string, string> $mapping
         */
        return (array) apply_filters('tuedion_cookie_gcm_mapping', $mapping);
    }
}
