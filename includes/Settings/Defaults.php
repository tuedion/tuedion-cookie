<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class Defaults
{
    /**
     * Get complete default settings array.
     *
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        return [
            'status'      => Schema::STATUS_DRAFT,
            'revision'    => 1,
            'banner'      => [
                'layout'               => 'box',
                'position'             => 'bottom-right',
                'equal_weight_buttons' => true,
                'show_reject_button'   => true,
                'show_manage_button'   => true,
            ],
            'preferences' => [
                'layout'   => 'box',
                'position' => 'right',
            ],
            'cookie'      => [
                'name'             => 'cc_cookie',
                'expiresAfterDays' => 182,
                'domain'           => '',
                'path'             => '/',
                'sameSite'         => 'Lax',
                'useLocalStorage'  => false,
            ],
            'trigger'     => [
                'enabled'           => true,
                'mode'              => 'both', // 'icon', 'text', 'both'
                'position'          => 'bottom-left',
                'offset_x'          => 20,
                'offset_y'          => 20,
                'mobile_position'   => 'bottom-left',
                'mobile_offset_x'   => 16,
                'mobile_offset_y'   => 16,
                'visibility_policy' => 'after_choice', // 'after_choice', 'always'
                'path_exclusions'   => '',
                'aria_label'        => '',
            ],
            'categories'  => [
                [
                    'id'          => 'necessary',
                    'label'       => 'Strictly Necessary',
                    'description' => 'These cookies are essential for the proper functioning of the website and cannot be disabled.',
                    'readOnly'    => true,
                    'enabled'     => true,
                    'autoClear'   => [],
                ],
                [
                    'id'          => 'functionality',
                    'label'       => 'Functionality',
                    'description' => 'These cookies allow the website to remember choices you make (such as language or region).',
                    'readOnly'    => false,
                    'enabled'     => false,
                    'autoClear'   => [],
                ],
                [
                    'id'          => 'analytics',
                    'label'       => 'Analytics & Performance',
                    'description' => 'These cookies help us understand how visitors interact with our website to improve performance.',
                    'readOnly'    => false,
                    'enabled'     => false,
                    'autoClear'   => [],
                ],
                [
                    'id'          => 'marketing',
                    'label'       => 'Marketing & Advertising',
                    'description' => 'These cookies are used to deliver personalized advertisements relevant to your interests.',
                    'readOnly'    => false,
                    'enabled'     => false,
                    'autoClear'   => [],
                ],
            ],
            'services'    => [],
            'languages'   => [
                'default_locale'    => 'en',
                'auto_detect'       => 'document',
                'supported_locales' => ['en', 'tr'],
            ],
            'advanced'    => [
                'clean_on_uninstall' => false,
                'debug_mode'         => false,
                'script_blocking'    => true,
                'iframe_blocking'    => true,
                'reload_on_revoke'   => true,
            ],
            'gcm'         => [
                'enabled'            => true,
                'wait_for_update'    => 500,
                'ads_data_redaction' => true,
                'url_passthrough'   => false,
                'mapping'            => [
                    'ad_storage'              => 'marketing',
                    'ad_user_data'            => 'marketing',
                    'ad_personalization'      => 'marketing',
                    'analytics_storage'       => 'analytics',
                    'functionality_storage'   => 'functionality',
                    'personalization_storage' => 'functionality',
                    'security_storage'        => 'necessary',
                ],
            ],
            'logging'     => [
                'enabled'        => false, // Default OFF: zero writes
                'retention_days' => 90,
            ],
            'theme'       => [
                'preset'              => 'light',
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
                'accent_color'        => '#2563eb',
            ],
            'legal'       => [
                'privacy_url'    => '',
                'terms_url'      => '',
                'privacy_title'  => '',
                'terms_title'    => '',
                'privacy_urls'   => [],
                'terms_urls'     => [],
                'privacy_titles' => [],
                'terms_titles'   => [],
            ],
            'scanner'     => [
                'cron_enabled' => false,
                'schedule'     => 'weekly',
                'alert_email'  => '',
                'scan_targets' => ['home', 'page', 'post'],
            ],
        ];
    }
}
