<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\I18n;

use Tuedion\CookieConsent\Consent\LanguageResolver;
use Tuedion\CookieConsent\Settings\Compiler;
use Tuedion\CookieConsent\Settings\Repository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages runtime language detection, multi-language plugin integrations (WPML/Polylang/TranslatePress),
 * and JSON import/export of translation dictionaries.
 */
final class TranslationManager
{
    public const CUSTOM_TRANSLATIONS_OPTION = 'tuedion_cookie_custom_translations';

    /**
     * Detect all active languages from installed multilingual plugins or WordPress core.
     *
     * @return array<string, array{name: string, native: string, dir: string, source: string}>
     */
    public static function getActiveLanguages(): array
    {
        $languages = [];

        // 1. WPML Detection
        if (defined('ICL_SITEPRESS_VERSION')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML core integration hook.
            $wpmlLangs = apply_filters('wpml_active_languages', null, 'skip_missing=0');
            if (is_array($wpmlLangs)) {
                foreach ($wpmlLangs as $code => $info) {
                    $slug = strtolower((string) ($info['code'] ?? $code));
                    $languages[$slug] = [
                        'name'   => (string) ($info['translated_name'] ?? $slug),
                        'native' => (string) ($info['native_name'] ?? $slug),
                        'dir'    => !empty($info['is_rtl']) ? 'rtl' : 'ltr',
                        'source' => 'WPML',
                    ];
                }
            }
        }

        // 2. Polylang Detection
        if (function_exists('pll_languages_list') && empty($languages)) {
            $pllLangs = pll_languages_list(['fields' => null]);
            if (is_array($pllLangs)) {
                foreach ($pllLangs as $pll) {
                    if (is_object($pll) && isset($pll->slug)) {
                        $slug = strtolower((string) $pll->slug);
                        $languages[$slug] = [
                            'name'   => (string) ($pll->name ?? $slug),
                            'native' => (string) ($pll->name ?? $slug),
                            'dir'    => !empty($pll->is_rtl) ? 'rtl' : 'ltr',
                            'source' => 'Polylang',
                        ];
                    }
                }
            }
        }

        // 3. Fallback to built-in supported languages
        foreach (LanguagePacks::SUPPORTED_LANGUAGES as $slug => $info) {
            if (!isset($languages[$slug])) {
                $languages[$slug] = array_merge($info, ['source' => 'Built-in']);
            }
        }

        // 4. Include custom imported languages
        $custom = get_option(self::CUSTOM_TRANSLATIONS_OPTION, []);
        if (is_array($custom)) {
            foreach (array_keys($custom) as $customSlug) {
                $slug = strtolower((string) $customSlug);
                if (!isset($languages[$slug])) {
                    $languages[$slug] = [
                        'name'   => strtoupper($slug) . ' (Custom JSON)',
                        'native' => strtoupper($slug),
                        'dir'    => in_array($slug, ['ar', 'he', 'fa', 'ur'], true) ? 'rtl' : 'ltr',
                        'source' => 'Custom Imported',
                    ];
                }
            }
        }

        return $languages;
    }

    /**
     * Get active multilingual engine name.
     */
    public static function getDetectedPluginName(): string
    {
        if (defined('ICL_SITEPRESS_VERSION')) {
            return 'WPML (' . ICL_SITEPRESS_VERSION . ')';
        }
        if (defined('POLYLANG_VERSION')) {
            return 'Polylang (v' . POLYLANG_VERSION . ')';
        }
        if (defined('TRP_PLUGIN_VERSION')) {
            return 'TranslatePress (v' . TRP_PLUGIN_VERSION . ')';
        }

        return 'WordPress Native (' . get_locale() . ')';
    }

    /**
     * Export translations to JSON string.
     *
     * @param string|null $specificLang Optional specific language code to export.
     * @return string
     */
    public static function exportToJson(?string $specificLang = null): string
    {
        $settings = Repository::getSettings();
        $categories = $settings['categories'] ?? [];
        $allPacks = LanguagePacks::getAll($categories);

        // Merge custom translations if present (cleanse static cookieTable first)
        $custom = get_option(self::CUSTOM_TRANSLATIONS_OPTION, []);
        if (is_array($custom)) {
            foreach ($custom as $code => $dict) {
                if (is_array($dict)) {
                    if (isset($dict['preferencesModal']['sections']) && is_array($dict['preferencesModal']['sections'])) {
                        foreach ($dict['preferencesModal']['sections'] as &$sec) {
                            if (is_array($sec) && isset($sec['cookieTable'])) {
                                unset($sec['cookieTable']);
                            }
                        }
                        unset($sec);
                    }
                    $allPacks[$code] = array_replace_recursive($allPacks[$code] ?? [], $dict);
                }
            }
        }

        // Strip static cookieTable from export payload to ensure pure UI translation strings
        foreach ($allPacks as $pCode => &$pack) {
            if (isset($pack['preferencesModal']['sections']) && is_array($pack['preferencesModal']['sections'])) {
                foreach ($pack['preferencesModal']['sections'] as &$sec) {
                    if (is_array($sec) && isset($sec['cookieTable'])) {
                        unset($sec['cookieTable']);
                    }
                }
                unset($sec);
            }
        }
        unset($pack);

        $payload = [
            'generator' => 'Tuedion Cookie v' . TUEDION_COOKIE_VERSION,
            'exported_at' => gmdate('c'),
            'translations' => ($specificLang !== null && isset($allPacks[$specificLang]))
                ? [$specificLang => $allPacks[$specificLang]]
                : $allPacks,
        ];

        return (string) wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Purge all static cookieTable snapshots stored in custom translations.
     * Prevents static ghost cookies from clobbering dynamic CMP tables.
     *
     * @return bool True if custom translations were modified and updated.
     */
    public static function purgeCustomCookieTables(): bool
    {
        $customTranslations = get_option(self::CUSTOM_TRANSLATIONS_OPTION, []);
        if (!is_array($customTranslations) || empty($customTranslations)) {
            return false;
        }

        $modified = false;
        foreach ($customTranslations as $code => &$pack) {
            if (!is_array($pack)) {
                continue;
            }
            if (isset($pack['preferencesModal']['sections']) && is_array($pack['preferencesModal']['sections'])) {
                foreach ($pack['preferencesModal']['sections'] as &$sec) {
                    if (is_array($sec) && isset($sec['cookieTable'])) {
                        unset($sec['cookieTable']);
                        $modified = true;
                    }
                }
                unset($sec);
            }
        }
        unset($pack);

        if ($modified) {
            update_option(self::CUSTOM_TRANSLATIONS_OPTION, $customTranslations, false);
            Compiler::clearCache();
        }

        return $modified;
    }

    /**
     * Import custom translations from JSON string.
     *
     * @param string $json
     * @return array{success: bool, message: string, imported_count: int}
     */
    public static function importFromJson(string $json): array
    {
        if (empty($json)) {
            return [
                'success' => false,
                'message' => __('Uploaded JSON content is empty.', 'tuedion-cookie'),
                'imported_count' => 0,
            ];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'message' => __('Invalid JSON format.', 'tuedion-cookie'),
                'imported_count' => 0,
            ];
        }

        $translations = $decoded['translations'] ?? $decoded;
        if (!is_array($translations)) {
            return [
                'success' => false,
                'message' => __('No valid translations dictionary found in payload.', 'tuedion-cookie'),
                'imported_count' => 0,
            ];
        }

        $sanitized = [];
        $count = 0;

        foreach ($translations as $langCode => $pack) {
            if (!is_string($langCode) || !is_array($pack)) {
                continue;
            }

            $code = sanitize_key($langCode);
            if (empty($code)) {
                continue;
            }

            // Sanitize modal fields preserving camelCase keys required by CookieConsent v3
            $allowedConsentModalKeys = [
                'title',
                'description',
                'acceptAllBtn',
                'acceptNecessaryBtn',
                'showPreferencesBtn',
                'closeIconLabel',
                'footer',
                'revisionMessage',
                'label',
            ];

            $allowedPreferencesModalKeys = [
                'title',
                'acceptAllBtn',
                'acceptNecessaryBtn',
                'savePreferencesBtn',
                'closeIconLabel',
                'serviceCounterLabel',
                'sections',
            ];

            $cleanPack = [];
            if (isset($pack['consentModal']) && is_array($pack['consentModal'])) {
                foreach ($pack['consentModal'] as $k => $v) {
                    $keyStr = (string) $k;
                    if (in_array($keyStr, $allowedConsentModalKeys, true)) {
                        $cleanPack['consentModal'][$keyStr] = wp_kses_post((string) $v);
                    }
                }
            }

            if (isset($pack['preferencesModal']) && is_array($pack['preferencesModal'])) {
                foreach ($pack['preferencesModal'] as $k => $v) {
                    $keyStr = (string) $k;
                    if (!in_array($keyStr, $allowedPreferencesModalKeys, true)) {
                        continue;
                    }

                    if ($keyStr === 'sections' && is_array($v)) {
                        $cleanSections = [];
                        foreach ($v as $sec) {
                            if (is_array($sec)) {
                                // Only import UI translation strings; never static cookieTable dumps
                                $cleanSec = [
                                    'title'          => sanitize_text_field((string) ($sec['title'] ?? '')),
                                    'description'    => wp_kses_post((string) ($sec['description'] ?? '')),
                                    'linkedCategory' => sanitize_key((string) ($sec['linkedCategory'] ?? '')),
                                ];
                                $cleanSections[] = $cleanSec;
                            }
                        }
                        $cleanPack['preferencesModal']['sections'] = $cleanSections;
                    } else {
                        $cleanPack['preferencesModal'][$keyStr] = wp_kses_post((string) $v);
                    }
                }
            }

            if (!empty($cleanPack)) {
                $sanitized[$code] = $cleanPack;
                $count++;
            }
        }

        if ($count === 0) {
            return [
                'success' => false,
                'message' => __('No valid language objects could be extracted.', 'tuedion-cookie'),
                'imported_count' => 0,
            ];
        }

        // Save custom translations
        $existing = get_option(self::CUSTOM_TRANSLATIONS_OPTION, []);
        $merged = is_array($existing) ? array_replace_recursive($existing, $sanitized) : $sanitized;
        update_option(self::CUSTOM_TRANSLATIONS_OPTION, $merged, false);

        // Invalidate compiler transient cache
        Compiler::clearCache();

        return [
            'success' => true,
            /* translators: %d: Number of imported language translations */
            'message' => sprintf(__('Successfully imported %d language translation(s).', 'tuedion-cookie'), $count),
            'imported_count' => $count,
        ];
    }
}
