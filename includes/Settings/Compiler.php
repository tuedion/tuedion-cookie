<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Settings;

use Tuedion\CookieConsent\Consent\LanguageResolver;
use Tuedion\CookieConsent\Integrations\RecipeRegistry;

if (!defined('ABSPATH')) {
    exit;
}

final class Compiler
{
    public const TRANSIENT_KEY = 'tdcc_runtime_cfg_v131';
    public const LEGACY_TRANSIENT_KEY = 'tuedion_cookie_runtime_config';

    /**
     * Invalidate all runtime configuration transients across all languages.
     */
    public static function clearCache(): void
    {
        global $wpdb;

        delete_transient(self::TRANSIENT_KEY);
        delete_transient(self::LEGACY_TRANSIENT_KEY);
        delete_transient('tdcc_runtime_cfg_v130');
        delete_transient('tdcc_runtime_cfg_v124');
        delete_transient('tdcc_runtime_cfg_v122');
        delete_transient('tdcc_runtime_cfg_v121');
        delete_transient('tdcc_runtime_cfg_v120');

        $supportedLanguages = array_keys(\Tuedion\CookieConsent\I18n\LanguagePacks::SUPPORTED_LANGUAGES);
        foreach ($supportedLanguages as $lang) {
            delete_transient(self::TRANSIENT_KEY . '_' . $lang);
            delete_transient('tdcc_runtime_cfg_v130_' . $lang);
            delete_transient('tdcc_runtime_cfg_v124_' . $lang);
            delete_transient('tdcc_runtime_cfg_v122_' . $lang);
            delete_transient('tdcc_runtime_cfg_v121_' . $lang);
            delete_transient('tdcc_runtime_cfg_v120_' . $lang);
        }

        if (isset($wpdb) && $wpdb instanceof \wpdb) {
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_tdcc_runtime_cfg_%' OR option_name LIKE '_transient_timeout_tdcc_runtime_cfg_%'");
        }
    }

    /**
     * Compile settings into a deterministic Orest Bida CookieConsent v3.1.0 config object.
     *
     * @param array<string, mixed>|null $settings Custom settings array or null to fetch from Repository.
     * @param bool $forceRecompile If true, bypasses transient cache.
     * @return array<string, mixed>
     */
    public static function compile(?array $settings = null, bool $forceRecompile = false): array
    {
        $currentLang = LanguageResolver::getCurrentLanguage();
        $cacheKey = self::TRANSIENT_KEY . '_' . $currentLang;

        if ($settings === null && !$forceRecompile) {
            $cached = get_transient($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        if ($settings === null) {
            $settings = Repository::getSettings();
        }

        $validation = Validator::validate($settings);
        $s = $validation['sanitized'];

        // 1. Categories compilation
        $categoriesConfig = [];
        $categories = $s['categories'] ?? [];
        if (is_array($categories)) {
            foreach ($categories as $cat) {
                if (!is_array($cat) || empty($cat['id'])) {
                    continue;
                }

                $catId = (string) $cat['id'];
                $catConfig = [
                    'enabled'   => !empty($cat['enabled']),
                    'readOnly'  => !empty($cat['readOnly']),
                ];

                // Merge user autoClear cookies with recipe autoClear rules
                $autoClearCookies = [];
                if (!empty($cat['autoClear']) && is_array($cat['autoClear'])) {
                    $autoClearCookies = $cat['autoClear'];
                }
                $recipeAutoClear = RecipeRegistry::getAutoClearForCategory($catId);
                if (!empty($recipeAutoClear)) {
                    $autoClearCookies = array_merge($autoClearCookies, $recipeAutoClear);
                }

                if (!empty($autoClearCookies)) {
                    $catConfig['autoClear'] = [
                        'cookies' => $autoClearCookies,
                    ];
                }

                // Attach assigned services
                $servicesMap = [];
                $services = $s['services'] ?? [];
                if (is_array($services)) {
                    foreach ($services as $svc) {
                        if (is_array($svc) && ($svc['category'] ?? '') === $catId && !empty($svc['id'])) {
                            $servicesMap[$svc['id']] = [
                                'label'   => $svc['label'] ?? $svc['id'],
                                'cookies' => is_array($svc['cookies'] ?? null) ? $svc['cookies'] : [],
                            ];
                        }
                    }
                }

                if (!empty($servicesMap)) {
                    $catConfig['services'] = $servicesMap;
                }

                $categoriesConfig[$catId] = $catConfig;
            }
        }

        // 2. Cookie storage config
        $cookieSettings = $s['cookie'] ?? Defaults::get()['cookie'] ?? [
            'name'             => 'cc_cookie',
            'expiresAfterDays' => 182,
            'domain'           => '',
            'path'             => '/',
            'sameSite'         => 'Lax',
            'secure'           => is_ssl(),
            'useLocalStorage'  => false,
        ];

        // 3. GUI options
        $banner = $s['banner'] ?? [];
        $prefs  = $s['preferences'] ?? [];

        $bannerLayout = (string) ($banner['layout'] ?? 'box');
        $bannerPos    = str_replace('-', ' ', (string) ($banner['position'] ?? 'bottom right'));

        $prefLayout   = (string) ($prefs['layout'] ?? 'box');
        $prefPos      = (string) ($prefs['position'] ?? 'right');

        $guiOptions = [
            'consentModal'     => [
                'layout'             => $bannerLayout,
                'position'           => $bannerPos,
                'equalWeightButtons' => !empty($banner['equal_weight_buttons']),
                'flipButtons'        => false,
            ],
            'preferencesModal' => [
                'layout'             => $prefLayout,
                'position'           => $prefPos,
                'equalWeightButtons' => true,
                'flipButtons'        => false,
            ],
        ];

        // 4. Build language translations across world languages + custom imports
        $translations = \Tuedion\CookieConsent\I18n\LanguagePacks::getAll($s['categories']);

        // Merge custom imported translations if available
        $customTranslations = get_option(\Tuedion\CookieConsent\I18n\TranslationManager::CUSTOM_TRANSLATIONS_OPTION, []);
        if (is_array($customTranslations) && !empty($customTranslations)) {
            foreach ($customTranslations as $code => $dict) {
                if (is_array($dict)) {
                    $translations[$code] = array_replace_recursive($translations[$code] ?? [], $dict);
                }
            }
        }

        /**
         * Filter compiled translations dictionary.
         *
         * @param array<string, array<string, mixed>> $translations
         * @param array<string, mixed> $settings
         */
        $translations = (array) apply_filters('tuedion_cookie_translations', $translations, $settings);

        // Filter by supported locales if specified, ensuring current language is always preserved
        $langSettings = $s['languages'] ?? Defaults::get()['languages'];
        $defaultLocale = !empty($langSettings['default_locale']) ? (string) $langSettings['default_locale'] : 'en';
        $supportedLocales = !empty($langSettings['supported_locales']) && is_array($langSettings['supported_locales'])
            ? $langSettings['supported_locales']
            : array_keys($translations);

        $allowedLocales = array_unique(array_merge($supportedLocales, [$currentLang, $defaultLocale]));
        $translations = array_intersect_key($translations, array_flip($allowedLocales));

        // Honor consent modal button visibility options
        $showReject = !empty($banner['show_reject_button']);
        $showManage = !empty($banner['show_manage_button']);

        foreach ($translations as $code => &$trans) {
            if (isset($trans['consentModal']) && is_array($trans['consentModal'])) {
                if (!$showReject) {
                    unset($trans['consentModal']['acceptNecessaryBtn']);
                }
                if (!$showManage) {
                    unset($trans['consentModal']['showPreferencesBtn']);
                }
            }
        }
        unset($trans);

        // Language configuration for CookieConsent v3
        $autoDetectSetting = $langSettings['auto_detect'] ?? 'browser';
        $defaultLang = array_key_exists($currentLang, $translations)
            ? $currentLang
            : (array_key_exists($defaultLocale, $translations) ? $defaultLocale : 'en');

        $languageConfig = [
            'default'      => $defaultLang,
            'translations' => $translations,
        ];
        if (in_array($autoDetectSetting, ['browser', 'document'], true)) {
            $languageConfig['autoDetect'] = $autoDetectSetting;
        }

        // 5. Assemble final CookieConsent 3.1.0 runtime object
        $config = [
            'mode'                   => 'opt-in',
            'autoShow'               => true,
            'revision'               => (int) ($s['revision'] ?? 1),
            'manageScriptTags'       => !empty($s['advanced']['script_blocking']),
            'autoClearCookies'       => true,
            'reloadOnRevoke'         => !empty($s['advanced']['reload_on_revoke']),
            'disablePageInteraction' => false,
            'hideFromBots'           => true,
            'cookie'                 => $cookieSettings,
            'guiOptions'             => $guiOptions,
            'categories'             => $categoriesConfig,
            'language'               => $languageConfig,
        ];

        /**
         * Filter final compiled CookieConsent config before caching and delivery.
         *
         * @param array<string, mixed> $config
         * @param array<string, mixed> $settings
         */
        $finalConfig = (array) apply_filters('tuedion_cookie_public_config', $config, $settings);

        // Cache for 12 hours under locale-specific key
        set_transient($cacheKey, $finalConfig, 12 * HOUR_IN_SECONDS);

        return $finalConfig;
    }
}

