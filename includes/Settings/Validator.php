<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class Validator
{
    /**
     * Validate and sanitize complete settings payload.
     *
     * @param array<string, mixed> $settings
     * @return array{valid: bool, errors: list<string>, sanitized: array<string, mixed>}
     */
    public static function validate(array $settings): array
    {
        $errors    = [];
        $sanitized = Defaults::get();

        // 1. Status validation
        $status = $settings['status'] ?? Schema::STATUS_DRAFT;
        if (in_array($status, Schema::ALLOWED_STATUSES, true)) {
            $sanitized['status'] = $status;
        } else {
            $errors[] = sprintf(
                /* translators: %s: invalid status */
                esc_html__('Invalid operation status: %s', 'tuedion-cookie'),
                esc_html((string) $status)
            );
        }

        // 2. Revision validation
        $revision = (int) ($settings['revision'] ?? 1);
        $sanitized['revision'] = max(1, $revision);

        // 3. Storage / Cookie validation
        if (isset($settings['cookie']) && is_array($settings['cookie'])) {
            $rawCookie = $settings['cookie'];
            $cookieName = sanitize_key($rawCookie['name'] ?? 'cc_cookie');
            if (empty($cookieName) || !preg_match('/^[a-zA-Z0-9_\-]+$/', $cookieName)) {
                $cookieName = 'cc_cookie';
            }

            $expiresAfterDays = (int) ($rawCookie['expiresAfterDays'] ?? 182);
            $expiresAfterDays = max(1, min(730, $expiresAfterDays));

            $sameSite = (string) ($rawCookie['sameSite'] ?? 'Lax');
            if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
                $sameSite = 'Lax';
            }

            $sanitized['cookie'] = [
                'name'             => $cookieName,
                'expiresAfterDays' => $expiresAfterDays,
                'domain'           => sanitize_text_field($rawCookie['domain'] ?? ''),
                'path'             => sanitize_text_field($rawCookie['path'] ?? '/'),
                'sameSite'         => $sameSite,
                'secure'           => !empty($rawCookie['secure']) || is_ssl(),
                'useLocalStorage'  => !empty($rawCookie['useLocalStorage']),
            ];
        }

        // 4. GUI Options validation
        if (isset($settings['banner']) && is_array($settings['banner'])) {
            $allowedBannerLayouts = ['box', 'box wide', 'box inline', 'cloud', 'cloud inline', 'bar', 'bar inline'];
            $allowedBannerPositions = [
                'bottom-right', 'bottom-left', 'bottom-center',
                'top-right', 'top-left', 'top-center',
                'middle-right', 'middle-left', 'middle-center'
            ];

            $bannerLayout = (string) ($settings['banner']['layout'] ?? 'box');
            $bannerPos    = (string) ($settings['banner']['position'] ?? 'bottom-right');

            $sanitized['banner'] = [
                'layout'               => in_array($bannerLayout, $allowedBannerLayouts, true) ? $bannerLayout : 'box',
                'position'             => in_array($bannerPos, $allowedBannerPositions, true) ? $bannerPos : 'bottom-right',
                'equal_weight_buttons' => !empty($settings['banner']['equal_weight_buttons']),
                'show_reject_button'   => !empty($settings['banner']['show_reject_button']),
                'show_manage_button'   => !empty($settings['banner']['show_manage_button']),
            ];
        }

        // 4b. Preferences Modal Options validation
        if (isset($settings['preferences']) && is_array($settings['preferences'])) {
            $allowedPrefLayouts = ['box', 'bar', 'bar wide'];
            $allowedPrefPositions = ['right', 'left'];

            $prefLayout = (string) ($settings['preferences']['layout'] ?? 'box');
            $prefPos    = (string) ($settings['preferences']['position'] ?? 'right');

            $sanitized['preferences'] = [
                'layout'   => in_array($prefLayout, $allowedPrefLayouts, true) ? $prefLayout : 'box',
                'position' => in_array($prefPos, $allowedPrefPositions, true) ? $prefPos : 'right',
            ];
        }

        // 4c. Trigger Options validation
        if (isset($settings['trigger']) && is_array($settings['trigger'])) {
            $rawTrig = $settings['trigger'];
            $allowedModes = ['both', 'icon', 'text'];
            $allowedPositions = ['bottom-left', 'bottom-right', 'top-left', 'top-right'];
            $allowedVisibilities = ['after_choice', 'always'];

            $mode = (string) ($rawTrig['mode'] ?? 'both');
            $pos = (string) ($rawTrig['position'] ?? 'bottom-left');
            $mobilePos = (string) ($rawTrig['mobile_position'] ?? 'bottom-left');
            $vis = (string) ($rawTrig['visibility_policy'] ?? 'after_choice');

            $sanitized['trigger'] = [
                'enabled'           => !empty($rawTrig['enabled']),
                'mode'              => in_array($mode, $allowedModes, true) ? $mode : 'both',
                'position'          => in_array($pos, $allowedPositions, true) ? $pos : 'bottom-left',
                'offset_x'          => max(0, min(300, (int) ($rawTrig['offset_x'] ?? 20))),
                'offset_y'          => max(0, min(300, (int) ($rawTrig['offset_y'] ?? 20))),
                'mobile_position'   => in_array($mobilePos, $allowedPositions, true) ? $mobilePos : 'bottom-left',
                'mobile_offset_x'   => max(0, min(300, (int) ($rawTrig['mobile_offset_x'] ?? 16))),
                'mobile_offset_y'   => max(0, min(300, (int) ($rawTrig['mobile_offset_y'] ?? 16))),
                'visibility_policy' => in_array($vis, $allowedVisibilities, true) ? $vis : 'after_choice',
                'path_exclusions'   => sanitize_textarea_field((string) ($rawTrig['path_exclusions'] ?? '')),
                'aria_label'        => sanitize_text_field((string) ($rawTrig['aria_label'] ?? '')),
            ];
        }

        // 4c. Theme & Color Palette validation
        if (isset($settings['theme']) && is_array($settings['theme'])) {
            $sanitized['theme'] = ThemeManager::resolve($settings['theme']);
        }

        // 5. Categories validation (Strict: at least one readOnly category must exist)
        if (isset($settings['categories']) && is_array($settings['categories'])) {
            $validCategories = [];
            $hasReadOnly = false;
            $seenIds = [];

            foreach ($settings['categories'] as $cat) {
                if (!is_array($cat) || empty($cat['id'])) {
                    continue;
                }

                $catId = sanitize_key((string) $cat['id']);
                if (empty($catId) || isset($seenIds[$catId])) {
                    continue;
                }
                $seenIds[$catId] = true;

                $isReadOnly = !empty($cat['readOnly']);
                if ($isReadOnly) {
                    $hasReadOnly = true;
                }

                $autoClear = [];
                if (!empty($cat['autoClear']) && is_array($cat['autoClear'])) {
                    foreach ($cat['autoClear'] as $ac) {
                        if (is_array($ac) && !empty($ac['name'])) {
                            $autoClear[] = [
                                'name' => sanitize_text_field((string) $ac['name']),
                                'path' => sanitize_text_field((string) ($ac['path'] ?? '/')),
                            ];
                        }
                    }
                }

                $validCategories[] = [
                    'id'          => $catId,
                    'label'       => sanitize_text_field((string) ($cat['label'] ?? $catId)),
                    'description' => self::sanitizeHtml((string) ($cat['description'] ?? '')),
                    'readOnly'    => $isReadOnly,
                    'enabled'     => $isReadOnly ? true : !empty($cat['enabled']),
                    'autoClear'   => $autoClear,
                ];
            }

            if (!$hasReadOnly) {
                $errors[] = esc_html__('At least one strictly necessary category (readOnly) must be configured.', 'tuedion-cookie');
            } else {
                $sanitized['categories'] = $validCategories;
            }
        }

        // 6. Services validation
        if (isset($settings['services']) && is_array($settings['services'])) {
            $validServices = [];
            $categoryIds = array_column($sanitized['categories'], 'id');

            // Category slug alias dictionary to auto-normalize variations
            $categoryAliases = [
                'functional'         => 'functionality',
                'function'           => 'functionality',
                'essential'          => 'necessary',
                'strictly-necessary' => 'necessary',
                'strictly_necessary' => 'necessary',
                'statistics'         => 'analytics',
                'stats'              => 'analytics',
                'performance'        => 'analytics',
                'advertising'        => 'marketing',
                'advertisement'      => 'marketing',
                'advertisements'     => 'marketing',
                'ads'                => 'marketing',
                'ad'                 => 'marketing',
                'targeting'          => 'marketing',
            ];

            foreach ($settings['services'] as $svc) {
                if (!is_array($svc) || empty($svc['id'])) {
                    continue;
                }

                $svcId = sanitize_key((string) $svc['id']);
                $catId = sanitize_key((string) ($svc['category'] ?? ''));

                if (isset($categoryAliases[$catId])) {
                    $catId = $categoryAliases[$catId];
                }

                if (!in_array($catId, $categoryIds, true)) {
                    if (in_array('functionality', $categoryIds, true)) {
                        $catId = 'functionality';
                    } elseif (in_array('necessary', $categoryIds, true)) {
                        $catId = 'necessary';
                    } else {
                        $errors[] = sprintf(
                            /* translators: 1: service ID, 2: invalid category ID */
                            esc_html__('Service "%1$s" is assigned to an unconfigured category "%2$s".', 'tuedion-cookie'),
                            esc_html($svcId),
                            esc_html($catId)
                        );
                        continue;
                    }
                }

                $validServices[] = [
                    'id'          => $svcId,
                    'label'       => sanitize_text_field((string) ($svc['label'] ?? $svcId)),
                    'category'    => $catId,
                    'description' => self::sanitizeHtml((string) ($svc['description'] ?? '')),
                    'cookies'     => is_array($svc['cookies'] ?? null) ? array_map('sanitize_text_field', $svc['cookies']) : [],
                ];
            }

            $sanitized['services'] = $validServices;
        }

        // 7. Advanced options
        if (isset($settings['advanced']) && is_array($settings['advanced'])) {
            $sanitized['advanced'] = [
                'clean_on_uninstall' => !empty($settings['advanced']['clean_on_uninstall']),
                'debug_mode'         => !empty($settings['advanced']['debug_mode']),
                'script_blocking'    => isset($settings['advanced']['script_blocking']) ? (bool) $settings['advanced']['script_blocking'] : true,
                'iframe_blocking'    => isset($settings['advanced']['iframe_blocking']) ? (bool) $settings['advanced']['iframe_blocking'] : true,
                'reload_on_revoke'   => !empty($settings['advanced']['reload_on_revoke']),
            ];
        }

        // 8. Google Consent Mode v2 options
        if (isset($settings['gcm']) && is_array($settings['gcm'])) {
            $gcm = $settings['gcm'];
            $defaultGcm = Defaults::get()['gcm'];
            $validMapping = $defaultGcm['mapping'];

            if (isset($gcm['mapping']) && is_array($gcm['mapping'])) {
                $categoryIds = array_column($sanitized['categories'] ?? [], 'id');
                foreach ($gcm['mapping'] as $signal => $catId) {
                    $signalKey = sanitize_key((string) $signal);
                    $targetCat = sanitize_key((string) $catId);
                    if (in_array($targetCat, $categoryIds, true) || $targetCat === 'necessary') {
                        $validMapping[$signalKey] = $targetCat;
                    }
                }
            }

            $sanitized['gcm'] = [
                'enabled'            => !empty($gcm['enabled']),
                'wait_for_update'    => max(100, min(5000, (int) ($gcm['wait_for_update'] ?? 500))),
                'ads_data_redaction' => !empty($gcm['ads_data_redaction']),
                'url_passthrough'   => !empty($gcm['url_passthrough']),
                'mapping'            => $validMapping,
            ];
        }

        // 9. Consent logging options
        if (isset($settings['logging']) && is_array($settings['logging'])) {
            $logging = $settings['logging'];
            $sanitized['logging'] = [
                'enabled'        => !empty($logging['enabled']),
                'retention_days' => max(7, min(730, (int) ($logging['retention_days'] ?? 90))),
            ];
        }

        // 10. Theme & Colors validation
        if (isset($settings['theme']) && is_array($settings['theme'])) {
            $sanitized['theme'] = ThemeManager::resolve($settings['theme']);
        }

        // 11. Legal URLs & Titles validation
        if (isset($settings['legal']) && is_array($settings['legal'])) {
            $rawLegal = $settings['legal'];
            $cleanLegal = [
                'privacy_url'    => esc_url_raw((string) ($rawLegal['privacy_url'] ?? '')),
                'terms_url'      => esc_url_raw((string) ($rawLegal['terms_url'] ?? '')),
                'privacy_title'  => sanitize_text_field((string) ($rawLegal['privacy_title'] ?? '')),
                'terms_title'    => sanitize_text_field((string) ($rawLegal['terms_title'] ?? '')),
                'privacy_urls'   => [],
                'terms_urls'     => [],
                'privacy_titles' => [],
                'terms_titles'   => [],
            ];

            if (isset($rawLegal['privacy_urls']) && is_array($rawLegal['privacy_urls'])) {
                foreach ($rawLegal['privacy_urls'] as $l => $u) {
                    $cleanLegal['privacy_urls'][sanitize_key((string) $l)] = esc_url_raw((string) $u);
                }
            }

            if (isset($rawLegal['terms_urls']) && is_array($rawLegal['terms_urls'])) {
                foreach ($rawLegal['terms_urls'] as $l => $u) {
                    $cleanLegal['terms_urls'][sanitize_key((string) $l)] = esc_url_raw((string) $u);
                }
            }

            if (isset($rawLegal['privacy_titles']) && is_array($rawLegal['privacy_titles'])) {
                foreach ($rawLegal['privacy_titles'] as $l => $t) {
                    $cleanLegal['privacy_titles'][sanitize_key((string) $l)] = sanitize_text_field((string) $t);
                }
            }

            if (isset($rawLegal['terms_titles']) && is_array($rawLegal['terms_titles'])) {
                foreach ($rawLegal['terms_titles'] as $l => $t) {
                    $cleanLegal['terms_titles'][sanitize_key((string) $l)] = sanitize_text_field((string) $t);
                }
            }

            $sanitized['legal'] = $cleanLegal;
        }

        // 12. Languages validation
        if (isset($settings['languages']) && is_array($settings['languages'])) {
            $rawLang = $settings['languages'];
            $defaultLocale = sanitize_key((string) ($rawLang['default_locale'] ?? 'en'));
            $autoDetect = sanitize_key((string) ($rawLang['auto_detect'] ?? 'browser'));
            $supported = [];
            if (!empty($rawLang['supported_locales']) && is_array($rawLang['supported_locales'])) {
                foreach ($rawLang['supported_locales'] as $loc) {
                    $locKey = sanitize_key((string) $loc);
                    if ($locKey !== '') {
                        $supported[] = $locKey;
                    }
                }
            }
            if (empty($supported)) {
                $supported = ['en', 'tr'];
            }
            $sanitized['languages'] = [
                'default_locale'    => $defaultLocale !== '' ? $defaultLocale : 'en',
                'auto_detect'       => in_array($autoDetect, ['browser', 'document', 'off'], true) ? $autoDetect : 'browser',
                'supported_locales' => array_values(array_unique($supported)),
            ];
        }

        // 13. Scheduled Scanner Cron validation
        if (isset($settings['scanner']) && is_array($settings['scanner'])) {
            $rawScanner = $settings['scanner'];
            $schedule = (string) ($rawScanner['schedule'] ?? 'weekly');
            if (!in_array($schedule, ['daily', 'weekly', 'monthly'], true)) {
                $schedule = 'weekly';
            }
            $alertEmail = sanitize_email((string) ($rawScanner['alert_email'] ?? ''));

            $sanitized['scanner'] = [
                'cron_enabled' => !empty($rawScanner['cron_enabled']),
                'schedule'     => $schedule,
                'alert_email'  => $alertEmail,
            ];
        }

        return [
            'valid'     => empty($errors),
            'errors'    => $errors,
            'sanitized' => $sanitized,
        ];
    }

    /**
     * Sanitize rich HTML text using strict allowlist (no script/style/event handlers).
     */
    public static function sanitizeHtml(string $html): string
    {
        $allowedTags = [
            'a'      => [
                'href'   => true,
                'title'  => true,
                'target' => true,
                'rel'    => true,
            ],
            'strong' => [],
            'b'      => [],
            'em'     => [],
            'i'      => [],
            'p'      => [],
            'br'     => [],
            'span'   => [
                'class' => true,
            ],
        ];

        return wp_kses($html, $allowedTags);
    }
}
