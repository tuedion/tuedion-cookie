<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class Repository
{
    private static ?array $cachedSettings = null;

    /**
     * Get full stored settings array merged with defaults.
     *
     * @return array<string, mixed>
     */
    public static function getSettings(): array
    {
        if (self::$cachedSettings !== null) {
            return self::$cachedSettings;
        }

        $stored = get_option(Schema::OPTION_NAME, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        // Auto-heal legacy or misaligned category slugs in stored services
        if (!empty($stored['services']) && is_array($stored['services'])) {
            $healed = false;
            foreach ($stored['services'] as &$svc) {
                if (is_array($svc) && isset($svc['category'])) {
                    $cat = (string) $svc['category'];
                    if ($cat === 'functional') {
                        $svc['category'] = 'functionality';
                        $healed = true;
                    } elseif ($cat === 'essential' || $cat === 'strictly-necessary') {
                        $svc['category'] = 'necessary';
                        $healed = true;
                    } elseif ($cat === 'statistics' || $cat === 'stats') {
                        $svc['category'] = 'analytics';
                        $healed = true;
                    } elseif ($cat === 'advertising' || $cat === 'ads') {
                        $svc['category'] = 'marketing';
                        $healed = true;
                    }
                }
            }
            unset($svc);

            if ($healed) {
                update_option(Schema::OPTION_NAME, $stored);
            }
        }

        $defaults = Defaults::get();
        $storedCategories = isset($stored['categories']) && is_array($stored['categories']) ? array_values($stored['categories']) : null;
        $storedServices = isset($stored['services']) && is_array($stored['services']) ? array_values($stored['services']) : null;

        $merged = array_replace_recursive($defaults, $stored);

        // Numerically indexed lists must be replaced as a whole,
        // otherwise deleting a category leaves the trailing default element in place.
        if ($storedCategories !== null) {
            $merged['categories'] = $storedCategories;
        }

        // Self-heal: If categories array is somehow empty, force restore defaults
        if (empty($merged['categories']) || !is_array($merged['categories'])) {
            $merged['categories'] = $defaults['categories'];
        }
        if ($storedServices !== null) {
            $merged['services'] = $storedServices;
        }

        self::$cachedSettings = $merged;
        return self::$cachedSettings;
    }

    /**
     * Update settings and invalidate runtime caches.
     *
     * @param array<string, mixed> $settings
     */
    public static function updateSettings(array $settings): bool
    {
        // Auto-heal service category aliases before saving
        if (!empty($settings['services']) && is_array($settings['services'])) {
            foreach ($settings['services'] as &$svc) {
                if (is_array($svc) && isset($svc['category'])) {
                    $cat = (string) $svc['category'];
                    if ($cat === 'functional') {
                        $svc['category'] = 'functionality';
                    } elseif ($cat === 'essential' || $cat === 'strictly-necessary') {
                        $svc['category'] = 'necessary';
                    } elseif ($cat === 'statistics' || $cat === 'stats') {
                        $svc['category'] = 'analytics';
                    } elseif ($cat === 'advertising' || $cat === 'ads') {
                        $svc['category'] = 'marketing';
                    }
                }
            }
            unset($svc);
        }

        $updated = update_option(Schema::OPTION_NAME, $settings);
        self::$cachedSettings = null;
        Compiler::clearCache();

        // Trigger central action on successful settings save to flush page caches
        do_action('tuedion_cookie_settings_saved', $settings);

        return $updated !== false;
    }

    /**
     * Initialize defaults if settings option doesn't exist.
     */
    public static function initializeDefaults(): bool
    {
        if (get_option(Schema::OPTION_NAME) === false) {
            return add_option(Schema::OPTION_NAME, Defaults::get(), '', 'no');
        }

        return false;
    }

    /**
     * Get current plugin status ('enabled', 'draft', 'disabled').
     */
    public static function getStatus(): string
    {
        $settings = self::getSettings();
        $status = $settings['status'] ?? Schema::STATUS_DRAFT;

        return in_array($status, Schema::ALLOWED_STATUSES, true) ? $status : Schema::STATUS_DRAFT;
    }

    /**
     * Get current consent revision number.
     */
    public static function getRevision(): int
    {
        $settings = self::getSettings();
        return (int) ($settings['revision'] ?? 1);
    }
}
