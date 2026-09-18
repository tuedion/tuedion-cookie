<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Consent;

if (!defined('ABSPATH')) {
    exit;
}

final class LanguageResolver
{
    /**
     * Resolve the active language code for the current request.
     * Prioritizes WPML, Polylang, TranslatePress, then native WordPress locale.
     */
    public static function getCurrentLanguage(): string
    {
        $lang = null;

        // 1. Check WPML
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML core integration hook.
        if (has_filter('wpml_current_language')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML core integration hook.
            $lang = apply_filters('wpml_current_language', null);
        } elseif (defined('ICL_LANGUAGE_CODE')) {
            $lang = (string) ICL_LANGUAGE_CODE;
        }

        // 2. Check Polylang
        if (empty($lang) && function_exists('pll_current_language')) {
            $pllLang = pll_current_language('slug');
            if (!empty($pllLang)) {
                $lang = (string) $pllLang;
            }
        }

        // 3. Check TranslatePress
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- TranslatePress integration hook.
        if (empty($lang) && has_filter('trp_get_current_language')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- TranslatePress integration hook.
            $trpLang = apply_filters('trp_get_current_language', null);
            if (!empty($trpLang)) {
                $lang = substr((string) $trpLang, 0, 2);
            }
        }

        // 4. Native WordPress locale fallback
        if (empty($lang)) {
            $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
            $lang = strtolower(substr((string) $locale, 0, 2));
        }

        $lang = strtolower(trim((string) $lang));
        if (empty($lang)) {
            $lang = 'en';
        }

        /**
         * Filter the resolved current language code.
         *
         * @param string $lang Resolved 2-letter or custom language slug.
         */
        return (string) apply_filters('tuedion_cookie_current_language', $lang);
    }

    /**
     * Convenient alias for getCurrentLanguage().
     */
    public static function resolve(): string
    {
        return self::getCurrentLanguage();
    }

    /**
     * Get all active languages installed in the site (via WPML, Polylang, or core).
     *
     * @return list<string>
     */
    public static function getActiveSiteLanguages(): array
    {
        $languages = [];

        // WPML active languages
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML core integration hook.
        if (has_filter('wpml_active_languages')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML core integration hook.
            $wpmlLangs = apply_filters('wpml_active_languages', null, 'skip_missing=0');
            if (is_array($wpmlLangs)) {
                foreach ($wpmlLangs as $item) {
                    if (!empty($item['code'])) {
                        $languages[] = strtolower((string) $item['code']);
                    }
                }
            }
        }

        // Polylang active languages
        if (empty($languages) && function_exists('pll_languages_list')) {
            $pllLangs = pll_languages_list(['fields' => 'slug']);
            if (is_array($pllLangs)) {
                foreach ($pllLangs as $slug) {
                    $languages[] = strtolower((string) $slug);
                }
            }
        }

        // Core fallback: include all 9 built-in supported languages
        if (empty($languages)) {
            $languages = array_keys(\Tuedion\CookieConsent\I18n\LanguagePacks::SUPPORTED_LANGUAGES);
        }

        // Include any custom imported language packs
        $customTranslations = get_option(\Tuedion\CookieConsent\I18n\TranslationManager::CUSTOM_TRANSLATIONS_OPTION, []);
        if (is_array($customTranslations) && !empty($customTranslations)) {
            foreach (array_keys($customTranslations) as $customCode) {
                $languages[] = strtolower((string) $customCode);
            }
        }

        $languages = array_values(array_unique(array_filter($languages)));

        /**
         * Filter the list of active site languages.
         *
         * @param list<string> $languages
         */
        return (array) apply_filters('tuedion_cookie_active_languages', $languages);
    }

    /**
     * Resolve localized Privacy Policy URL for a specific language.
     */
    public static function getPrivacyPolicyUrl(string $langCode): string
    {
        $settings = \Tuedion\CookieConsent\Settings\Repository::getSettings();
        $lang = strtolower($langCode);

        // 1. Language-specific override in settings
        if (!empty($settings['legal']['privacy_urls'][$lang])) {
            return esc_url((string) $settings['legal']['privacy_urls'][$lang]);
        }

        // 2. Global custom URL in settings
        if (!empty($settings['legal']['privacy_url'])) {
            return esc_url((string) $settings['legal']['privacy_url']);
        }

        // 3. Polylang detection for WP Privacy Policy page
        $wpPrivacyPageId = (int) get_option('wp_page_for_privacy_policy');
        if ($wpPrivacyPageId > 0 && function_exists('pll_get_post')) {
            $translatedId = pll_get_post($wpPrivacyPageId, $lang);
            if ($translatedId && get_post_status($translatedId) === 'publish') {
                $url = get_permalink($translatedId);
                if (!empty($url)) {
                    return esc_url($url);
                }
            }
        }

        // 4. WPML detection for WP Privacy Policy page
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML core integration hook.
        if ($wpPrivacyPageId > 0 && has_filter('wpml_object_id')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML core integration hook.
            $translatedId = apply_filters('wpml_object_id', $wpPrivacyPageId, 'page', true, $lang);
            if ($translatedId && get_post_status($translatedId) === 'publish') {
                $url = get_permalink($translatedId);
                if (!empty($url)) {
                    return esc_url($url);
                }
            }
        }

        // 5. Fallback to WordPress native privacy policy URL
        $nativeUrl = get_privacy_policy_url();
        return !empty($nativeUrl) ? esc_url($nativeUrl) : '#';
    }

    /**
     * Resolve localized Terms & Conditions URL for a specific language.
     */
    public static function getTermsUrl(string $langCode): string
    {
        $settings = \Tuedion\CookieConsent\Settings\Repository::getSettings();
        $lang = strtolower($langCode);

        // 1. Language-specific override in settings
        if (!empty($settings['legal']['terms_urls'][$lang])) {
            return esc_url((string) $settings['legal']['terms_urls'][$lang]);
        }

        // 2. Global custom URL in settings
        if (!empty($settings['legal']['terms_url'])) {
            $termsVal = (string) $settings['legal']['terms_url'];
            if (is_numeric($termsVal)) {
                $pageId = (int) $termsVal;
                // Polylang
                if (function_exists('pll_get_post')) {
                    $trId = pll_get_post($pageId, $lang);
                    if ($trId) {
                        $pageId = $trId;
                    }
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML core integration hook.
                } elseif (has_filter('wpml_object_id')) {
                    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML core integration hook.
                    $trId = apply_filters('wpml_object_id', $pageId, 'page', true, $lang);
                    if ($trId) {
                        $pageId = $trId;
                    }
                }
                $url = get_permalink($pageId);
                if (!empty($url)) {
                    return esc_url($url);
                }
            } else {
                return esc_url($termsVal);
            }
        }

        return '';
    }

    /**
     * Resolve localized Privacy Policy link title/label for a specific language.
     */
    public static function getPrivacyPolicyTitle(string $langCode): string
    {
        $settings = \Tuedion\CookieConsent\Settings\Repository::getSettings();
        $lang = strtolower($langCode);

        // 1. Language-specific title override in settings
        if (!empty($settings['legal']['privacy_titles'][$lang])) {
            return (string) $settings['legal']['privacy_titles'][$lang];
        }

        // 2. Global custom title in settings
        if (!empty($settings['legal']['privacy_title'])) {
            return (string) $settings['legal']['privacy_title'];
        }

        // 3. Fallback to built-in translations
        $defaults = [
            'en' => 'Privacy Policy',
            'tr' => 'Gizlilik Politikası',
            'de' => 'Datenschutzerklärung',
            'fr' => 'Politique de confidentialité',
            'es' => 'Política de privacidad',
            'it' => 'Informativa sulla privacy',
            'nl' => 'Privacybeleid',
            'ar' => 'سياسة الخصوصية',
            'ru' => 'Политика конфиденциальности',
        ];

        return $defaults[$lang] ?? $defaults['en'];
    }

    /**
     * Resolve localized Terms & Conditions link title/label for a specific language.
     */
    public static function getTermsTitle(string $langCode): string
    {
        $settings = \Tuedion\CookieConsent\Settings\Repository::getSettings();
        $lang = strtolower($langCode);

        // 1. Language-specific title override in settings
        if (!empty($settings['legal']['terms_titles'][$lang])) {
            return (string) $settings['legal']['terms_titles'][$lang];
        }

        // 2. Global custom title in settings
        if (!empty($settings['legal']['terms_title'])) {
            return (string) $settings['legal']['terms_title'];
        }

        // 3. Fallback to built-in translations
        $defaults = [
            'en' => 'Terms of Service',
            'tr' => 'Kullanım Koşulları',
            'de' => 'Nutzungsbedingungen',
            'fr' => 'Conditions d\'utilisation',
            'es' => 'Términos y condiciones',
            'it' => 'Termini e condizioni',
            'nl' => 'Algemene voorwaarden',
            'ar' => 'الشروط والأحكام',
            'ru' => 'Условия использования',
        ];

        return $defaults[$lang] ?? $defaults['en'];
    }
}
