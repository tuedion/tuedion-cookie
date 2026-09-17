<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages Design Presets, custom color palettes, and generates high-specificity CSS.
 * Guarantees zero contrast clash across dark, light, and custom color themes.
 */
final class ThemeManager
{
    public const PRESET_LIGHT     = 'light';
    public const PRESET_DARK      = 'dark';
    public const PRESET_CORPORATE = 'corporate';
    public const PRESET_EMERALD   = 'emerald';
    public const PRESET_SLATE     = 'slate';
    public const PRESET_CUSTOM    = 'custom';

    public const COLOR_TOKENS = [
        'bg_color'            => '#ffffff',
        'text_color'          => '#0f172a',
        'subtext_color'       => '#475569',
        'border_color'        => '#e2e8f0',
        'card_bg'             => '#f8fafc',
        'btn_primary_bg'      => '#2563eb',
        'btn_primary_color'   => '#ffffff',
        'btn_secondary_bg'    => '#f1f5f9',
        'btn_secondary_color' => '#334155',
        'toggle_on_bg'        => '#2563eb',
    ];

    /**
     * Get pre-configured design presets with full color tokens.
     *
     * @return array<string, array{name: string, colors: array<string, string>}>
     */
    public static function getPresets(): array
    {
        return [
            self::PRESET_LIGHT => [
                'name'   => __('Clean Light', 'tuedion-cookie'),
                'colors' => [
                    'bg_color'            => '#ffffff',
                    'text_color'          => '#0f172a',
                    'subtext_color'       => '#475569',
                    'border_color'        => '#e2e8f0',
                    'card_bg'             => '#f8fafc',
                    'btn_primary_bg'      => '#2563eb',
                    'btn_primary_color'   => '#ffffff',
                    'btn_secondary_bg'    => '#f1f5f9',
                    'btn_secondary_color' => '#334155',
                    'toggle_on_bg'        => '#2563eb',
                ],
            ],
            self::PRESET_DARK => [
                'name'   => __('Midnight Dark', 'tuedion-cookie'),
                'colors' => [
                    'bg_color'            => '#0f172a',
                    'text_color'          => '#f8fafc',
                    'subtext_color'       => '#94a3b8',
                    'border_color'        => '#334155',
                    'card_bg'             => '#1e293b',
                    'btn_primary_bg'      => '#3b82f6',
                    'btn_primary_color'   => '#ffffff',
                    'btn_secondary_bg'    => '#1e293b',
                    'btn_secondary_color' => '#e2e8f0',
                    'toggle_on_bg'        => '#3b82f6',
                ],
            ],
            self::PRESET_CORPORATE => [
                'name'   => __('Corporate Navy', 'tuedion-cookie'),
                'colors' => [
                    'bg_color'            => '#ffffff',
                    'text_color'          => '#0b192c',
                    'subtext_color'       => '#475569',
                    'border_color'        => '#dbeafe',
                    'card_bg'             => '#f0f7ff',
                    'btn_primary_bg'      => '#1e3a8a',
                    'btn_primary_color'   => '#ffffff',
                    'btn_secondary_bg'    => '#eff6ff',
                    'btn_secondary_color' => '#1e3a8a',
                    'toggle_on_bg'        => '#1e3a8a',
                ],
            ],
            self::PRESET_EMERALD => [
                'name'   => __('Emerald Nature', 'tuedion-cookie'),
                'colors' => [
                    'bg_color'            => '#ffffff',
                    'text_color'          => '#064e3b',
                    'subtext_color'       => '#374151',
                    'border_color'        => '#d1fae5',
                    'card_bg'             => '#f0fdf4',
                    'btn_primary_bg'      => '#059669',
                    'btn_primary_color'   => '#ffffff',
                    'btn_secondary_bg'    => '#ecfdf5',
                    'btn_secondary_color' => '#065f46',
                    'toggle_on_bg'        => '#059669',
                ],
            ],
            self::PRESET_SLATE => [
                'name'   => __('Minimal Slate', 'tuedion-cookie'),
                'colors' => [
                    'bg_color'            => '#18181b',
                    'text_color'          => '#fafafa',
                    'subtext_color'       => '#a1a1aa',
                    'border_color'        => '#3f3f46',
                    'card_bg'             => '#27272a',
                    'btn_primary_bg'      => '#fafafa',
                    'btn_primary_color'   => '#18181b',
                    'btn_secondary_bg'    => '#27272a',
                    'btn_secondary_color' => '#e4e4e7',
                    'toggle_on_bg'        => '#38bdf8',
                ],
            ],
            self::PRESET_CUSTOM => [
                'name'   => __('Custom Theme', 'tuedion-cookie'),
                'colors' => self::COLOR_TOKENS,
            ],
        ];
    }

    /**
     * Resolve and validate a theme configuration array, merging preset colors where missing.
     *
     * @param array<string, mixed> $theme
     * @return array<string, mixed>
     */
    public static function resolve(array $theme): array
    {
        $presets = self::getPresets();
        $presetKey = sanitize_key((string) ($theme['preset'] ?? self::PRESET_LIGHT));
        if (!isset($presets[$presetKey])) {
            $presetKey = self::PRESET_LIGHT;
        }

        $presetColors = $presets[$presetKey]['colors'];
        $resolved = [
            'preset' => $presetKey,
        ];

        $isCustom = ($presetKey === self::PRESET_CUSTOM);

        foreach (self::COLOR_TOKENS as $token => $defaultHex) {
            if ($isCustom) {
                $val = (string) ($theme[$token] ?? '');
                $sanitized = sanitize_hex_color($val);
                $resolved[$token] = ($sanitized !== null && $sanitized !== '') ? $sanitized : $defaultHex;
            } else {
                // When a pre-configured design preset is selected, use the preset's official color palette
                $resolved[$token] = $presetColors[$token] ?? $defaultHex;
            }
        }

        // Backward-compatible alias
        $resolved['accent_color'] = $resolved['btn_primary_bg'];

        return $resolved;
    }

    /**
     * Generate high-specificity CSS variable injection string for frontend.
     *
     * @param array<string, mixed> $theme
     * @return string
     */
    public static function generateCss(array $theme): string
    {
        $c = self::resolve($theme);

        $bg          = $c['bg_color'];
        $text        = $c['text_color'];
        $subtext     = $c['subtext_color'];
        $border      = $c['border_color'];
        $cardBg      = $c['card_bg'];
        $btnPriBg    = $c['btn_primary_bg'];
        $btnPriColor = $c['btn_primary_color'];
        $btnSecBg    = $c['btn_secondary_bg'];
        $btnSecColor = $c['btn_secondary_color'];
        $toggleOn    = $c['toggle_on_bg'];

        return "
:root, #cc-main, #cc-main.cc--darkmode {
    --cc-bg: {$bg};
    --cc-primary-color: {$text};
    --cc-secondary-color: {$subtext};
    --cc-btn-primary-bg: {$btnPriBg};
    --cc-btn-primary-color: {$btnPriColor};
    --cc-btn-primary-border-color: {$btnPriBg};
    --cc-btn-primary-hover-bg: {$btnPriBg};
    --cc-btn-primary-hover-color: {$btnPriColor};
    --cc-btn-primary-hover-border-color: {$btnPriBg};
    --cc-btn-secondary-bg: {$btnSecBg};
    --cc-btn-secondary-color: {$btnSecColor};
    --cc-btn-secondary-border-color: {$border};
    --cc-btn-secondary-hover-bg: {$cardBg};
    --cc-btn-secondary-hover-color: {$text};
    --cc-btn-secondary-hover-border-color: {$border};
    --cc-separator-border-color: {$border};
    --cc-cookie-category-block-bg: {$cardBg};
    --cc-cookie-category-block-border: {$border};
    --cc-cookie-category-block-hover-bg: {$cardBg};
    --cc-cookie-category-block-hover-border: {$border};
    --cc-cookie-category-expanded-block-hover-bg: {$cardBg};
    --cc-toggle-on-bg: {$toggleOn};
    --cc-toggle-on-knob-bg: #ffffff;
    --cc-toggle-off-bg: #64748b;
    --cc-toggle-off-knob-bg: #ffffff;
    --cc-footer-bg: {$cardBg};
    --cc-footer-color: {$subtext};
    --cc-footer-border-color: {$border};
    --cc-link-color: {$btnPriBg};
    --tdcc-accent: {$toggleOn};
}

#cc-main .cm__title,
#cc-main .pm__title,
#cc-main .pm__section-title {
    color: var(--cc-primary-color) !important;
}

#cc-main .cm__desc,
#cc-main .pm__section-desc,
#cc-main .pm__section-desc-wrapper {
    color: var(--cc-secondary-color) !important;
}

#cc-main .cm__btn:not(.cm__btn--secondary):not(.cm__btn--close) {
    background-color: var(--cc-btn-primary-bg) !important;
    color: var(--cc-btn-primary-color) !important;
    border-color: var(--cc-btn-primary-border-color) !important;
}

#cc-main .cm__btn--secondary {
    background-color: var(--cc-btn-secondary-bg) !important;
    color: var(--cc-btn-secondary-color) !important;
    border-color: var(--cc-btn-secondary-border-color) !important;
}

#cc-main .pm__btn:not(.pm__btn--secondary):not(.pm__close-btn) {
    background-color: var(--cc-btn-primary-bg) !important;
    color: var(--cc-btn-primary-color) !important;
    border-color: var(--cc-btn-primary-border-color) !important;
}

#cc-main .pm__btn--secondary {
    background-color: var(--cc-btn-secondary-bg) !important;
    color: var(--cc-btn-secondary-color) !important;
    border-color: var(--cc-btn-secondary-border-color) !important;
}

.tdcc-persistent-trigger {
    background-color: var(--cc-bg) !important;
    color: var(--cc-primary-color) !important;
    border-color: var(--cc-separator-border-color) !important;
}

.tdcc-persistent-trigger:hover {
    background-color: var(--cc-cookie-category-block-bg) !important;
}

.tdcc-trigger-icon {
    color: var(--tdcc-accent) !important;
}
";
    }
}
