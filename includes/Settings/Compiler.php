<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Settings;

use Tuedion\CookieConsent\Consent\CookieTableBuilder;
use Tuedion\CookieConsent\Consent\LanguageResolver;
use Tuedion\CookieConsent\I18n\TranslationManager;
use Tuedion\CookieConsent\Integrations\RecipeRegistry;

if (!defined('ABSPATH')) {
    exit;
}

final class Compiler
{
    public const TRANSIENT_KEY = 'tdcc_runtime_cfg_v151';
    public const LEGACY_TRANSIENT_KEY = 'tuedion_cookie_runtime_config';

    /**
     * Invalidate all runtime configuration transients across all languages.
     */
    public static function clearCache(): void
    {
        global $wpdb;

        delete_transient(self::TRANSIENT_KEY);
        delete_transient(self::LEGACY_TRANSIENT_KEY);
        delete_transient('tdcc_runtime_cfg_v150');
        delete_transient('tdcc_runtime_cfg_v142');
        delete_transient('tdcc_runtime_cfg_v141');
        delete_transient('tdcc_runtime_cfg_v140');
        delete_transient('tdcc_runtime_cfg_v133');
        delete_transient('tdcc_runtime_cfg_v132');
        delete_transient('tdcc_runtime_cfg_v130');
        delete_transient('tdcc_runtime_cfg_v124');
        delete_transient('tdcc_runtime_cfg_v122');
        delete_transient('tdcc_runtime_cfg_v121');
        delete_transient('tdcc_runtime_cfg_v120');

        $supportedLanguages = array_keys(\Tuedion\CookieConsent\I18n\LanguagePacks::SUPPORTED_LANGUAGES);
        foreach ($supportedLanguages as $lang) {
            delete_transient(self::TRANSIENT_KEY . '_' . $lang);
            delete_transient('tdcc_runtime_cfg_v150_' . $lang);
            delete_transient('tdcc_runtime_cfg_v142_' . $lang);
            delete_transient('tdcc_runtime_cfg_v141_' . $lang);
            delete_transient('tdcc_runtime_cfg_v140_' . $lang);
            delete_transient('tdcc_runtime_cfg_v133_' . $lang);
            delete_transient('tdcc_runtime_cfg_v132_' . $lang);
            delete_transient('tdcc_runtime_cfg_v130_' . $lang);
            delete_transient('tdcc_runtime_cfg_v124_' . $lang);
            delete_transient('tdcc_runtime_cfg_v122_' . $lang);
            delete_transient('tdcc_runtime_cfg_v121_' . $lang);
            delete_transient('tdcc_runtime_cfg_v120_' . $lang);
        }

        if (isset($wpdb) && $wpdb instanceof \wpdb) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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

        // Merge custom imported translations if available (cleanse any static cookieTable first)
        $customTranslations = get_option(TranslationManager::CUSTOM_TRANSLATIONS_OPTION, []);
        if (is_array($customTranslations) && !empty($customTranslations)) {
            $cleanedCustom = false;
            foreach ($customTranslations as $code => &$dict) {
                if (is_array($dict)) {
                    if (isset($dict['preferencesModal']['sections']) && is_array($dict['preferencesModal']['sections'])) {
                        foreach ($dict['preferencesModal']['sections'] as &$sec) {
                            if (is_array($sec) && isset($sec['cookieTable'])) {
                                unset($sec['cookieTable']);
                                $cleanedCustom = true;
                            }
                        }
                        unset($sec);
                    }
                    $translations[$code] = array_replace_recursive($translations[$code] ?? [], $dict);
                }
            }
            unset($dict);

            // Auto-heal the database option if legacy static cookieTable entries were present
            if ($cleanedCustom) {
                update_option(TranslationManager::CUSTOM_TRANSLATIONS_OPTION, $customTranslations, false);
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

        $currentDomain = wp_parse_url(home_url(), PHP_URL_HOST);
        if (!is_string($currentDomain) || empty($currentDomain)) {
            $currentDomain = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : 'localhost';
        }

        foreach ($translations as $code => &$trans) {
            if (isset($trans['consentModal']) && is_array($trans['consentModal'])) {
                if (!$showReject) {
                    unset($trans['consentModal']['acceptNecessaryBtn']);
                }
                if (!$showManage) {
                    unset($trans['consentModal']['showPreferencesBtn']);
                }
            }

            // Strict Global GDPR & Zero-Ghost Sanitization on preferencesModal sections
            if (isset($trans['preferencesModal']['sections']) && is_array($trans['preferencesModal']['sections'])) {
                foreach ($trans['preferencesModal']['sections'] as $secIdx => &$sec) {
                    if (!is_array($sec) || !isset($sec['cookieTable']) || !is_array($sec['cookieTable'])) {
                        continue;
                    }

                    $rawBody = (array) ($sec['cookieTable']['body'] ?? []);
                    $cleanBody = [];

                    foreach ($rawBody as $row) {
                        if (!is_array($row)) {
                            continue;
                        }

                        $rowName = strtolower(trim((string) ($row['name'] ?? '')));

                        // Strict Architecture Rule #19: WordPress authentication & admin cookies must NEVER appear in public visitor tables
                        if ($rowName === '' || str_starts_with($rowName, 'wordpress_') || str_starts_with($rowName, 'wp-settings-')) {
                            continue;
                        }

                        // Ensure duration is NEVER undefined, null, or empty
                        $rawDur = trim((string) ($row['duration'] ?? ''));
                        if ($rawDur === '' || $rawDur === 'undefined') {
                            $dictEntry = CookieTableBuilder::COOKIE_DICTIONARY[$rowName]
                                ?? CookieTableBuilder::COOKIE_DICTIONARY[$row['name'] ?? '']
                                ?? null;
                            $durKey = $dictEntry['duration'] ?? 'session';
                            $row['duration'] = CookieTableBuilder::resolveDuration($durKey, $code);
                        }

                        // Ensure domain is NEVER empty or undefined
                        $rawDom = trim((string) ($row['domain'] ?? ''));
                        if ($rawDom === '' || $rawDom === 'undefined') {
                            $row['domain'] = $currentDomain;
                        }

                        // Ensure description is NEVER empty or undefined
                        $rawDesc = trim((string) ($row['desc'] ?? ''));
                        if ($rawDesc === '' || $rawDesc === 'undefined') {
                            $dictEntry = CookieTableBuilder::COOKIE_DICTIONARY[$rowName]
                                ?? CookieTableBuilder::COOKIE_DICTIONARY[$row['name'] ?? '']
                                ?? null;
                            $row['desc'] = $dictEntry['desc'][$code] ?? $dictEntry['desc']['en'] ?? __('Essential system cookie.', 'tuedion-cookie');
                        }

                        $cleanBody[] = $row;
                    }

                    // Zero-Ghost policy: If category has no valid cookies, remove cookieTable completely
                    if (empty($cleanBody)) {
                        unset($sec['cookieTable']);
                    } else {
                        $sec['cookieTable']['body'] = array_values($cleanBody);
                    }
                }
                unset($sec);
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

