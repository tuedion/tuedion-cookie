<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Consent;

use Tuedion\CookieConsent\Settings\Repository;

if (!defined('ABSPATH')) {
    exit;
}

final class PersistentTrigger
{
    public static function register(): void
    {
        add_action('wp_footer', [self::class, 'renderTrigger']);
    }

    /**
     * Check if the current request path matches any user-configured path exclusions.
     */
    public static function isPathExcluded(string $exclusions): bool
    {
        if (empty(trim($exclusions))) {
            return false;
        }

        $currentUri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        $currentPath = wp_parse_url($currentUri, PHP_URL_PATH) ?: '/';

        $lines = explode("\n", str_replace("\r", "", $exclusions));
        foreach ($lines as $line) {
            $pattern = trim($line);
            if (empty($pattern)) {
                continue;
            }

            // Exact match or prefix match
            if (str_starts_with($currentPath, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public static function renderTrigger(): void
    {
        if (!Frontend::shouldLoad()) {
            return;
        }

        if (\Tuedion\CookieConsent\Diagnostics\Profiler::isTriggerDisabled()) {
            return;
        }

        $settings = Repository::getSettings();
        $trigger  = $settings['trigger'] ?? [];

        if (empty($trigger['enabled'])) {
            return;
        }

        // Check path exclusions
        $exclusions = (string) ($trigger['path_exclusions'] ?? '');
        if (self::isPathExcluded($exclusions)) {
            return;
        }

        $mode             = (string) ($trigger['mode'] ?? 'both');
        $pos              = (string) ($trigger['position'] ?? 'bottom-left');
        $mobilePos        = (string) ($trigger['mobile_position'] ?? 'bottom-left');
        $offsetX          = (int) ($trigger['offset_x'] ?? 20);
        $offsetY          = (int) ($trigger['offset_y'] ?? 20);
        $mobOffsetX       = (int) ($trigger['mobile_offset_x'] ?? 16);
        $mobOffsetY       = (int) ($trigger['mobile_offset_y'] ?? 16);
        $visibilityPolicy = (string) ($trigger['visibility_policy'] ?? 'after_choice');

        // Resolve label based on current locale across 9 languages
        $lang = LanguageResolver::getCurrentLanguage();
        $triggerLabels = [
            'en' => 'Cookie Preferences',
            'tr' => 'Çerez Tercihleri',
            'de' => 'Datenschutzeinstellungen',
            'fr' => 'Préférences cookies',
            'es' => 'Preferencias de cookies',
            'it' => 'Preferenze cookie',
            'nl' => 'Cookievoorkeuren',
            'ar' => 'تفضيلات الخصوصية',
            'ru' => 'Настройки cookie',
        ];
        $defaultText = $triggerLabels[$lang] ?? $triggerLabels['en'];
        $ariaLabel = !empty($trigger['aria_label']) ? $trigger['aria_label'] : $defaultText;
        $isRtl = ($lang === 'ar' || is_rtl());

        // Modern inline cookie SVG icon
        $svgIcon = '<svg class="tdcc-trigger-svg" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5"></path><path d="M8.5 8.5v.01"></path><path d="M7 14v.01"></path><path d="M11 17v.01"></path><path d="M15 13v.01"></path></svg>';

        ?>
        <div id="tdcc-persistent-trigger-wrapper"
             class="tdcc-trigger-wrapper tdcc-pos-<?php echo esc_attr($pos); ?> tdcc-mob-pos-<?php echo esc_attr($mobilePos); ?><?php echo $isRtl ? ' tdcc-rtl' : ''; ?>"
             data-policy="<?php echo esc_attr($visibilityPolicy); ?>"
             <?php echo $isRtl ? 'dir="rtl"' : ''; ?>
             style="--tdcc-trig-x: <?php echo esc_attr((string) $offsetX); ?>px; --tdcc-trig-y: <?php echo esc_attr((string) $offsetY); ?>px; --tdcc-trig-mob-x: <?php echo esc_attr((string) $mobOffsetX); ?>px; --tdcc-trig-mob-y: <?php echo esc_attr((string) $mobOffsetY); ?>px;">
            <button type="button"
                    id="tdcc-persistent-trigger-btn"
                    class="tdcc-persistent-trigger tdcc-mode-<?php echo esc_attr($mode); ?>"
                    aria-label="<?php echo esc_attr($ariaLabel); ?>">
                <?php if ($mode === 'icon' || $mode === 'both'): ?>
                    <span class="tdcc-trigger-icon" aria-hidden="true"><?php echo $svgIcon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <?php endif; ?>
                <?php if ($mode === 'text' || $mode === 'both'): ?>
                    <span class="tdcc-trigger-text"><?php echo esc_html($defaultText); ?></span>
                <?php endif; ?>
            </button>
        </div>
        <?php
    }
}
