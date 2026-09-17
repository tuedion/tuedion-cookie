<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Diagnostics;

use Tuedion\CookieConsent\Settings\Repository;
use Tuedion\CookieConsent\Integrations\Google\GcmMapping;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Consent State Inspector.
 * Inspects and evaluates the current visitor's consent cookie, revision freshness,
 * and active Google Consent Mode signal states.
 */
final class ConsentStateInspector
{
    public const COOKIE_NAME = 'cc_cookie';

    /**
     * Inspect current visitor consent state.
     *
     * @return array{
     *     has_cookie: bool,
     *     raw_cookie: string,
     *     consent_uuid: string,
     *     accepted_categories: list<string>,
     *     accepted_services: list<string>,
     *     revision: int,
     *     is_revision_current: bool,
     *     gcm_signals: array<string, string>
     * }
     */
    public static function inspect(): array
    {
        $settings = Repository::getSettings();
        $currentRevision = (int) ($settings['revision'] ?? 1);
        $cookieName = isset($settings['cookie']['name']) ? (string) $settings['cookie']['name'] : self::COOKIE_NAME;

        $rawCookie = isset($_COOKIE[$cookieName]) ? sanitize_text_field(wp_unslash($_COOKIE[$cookieName])) : '';

        $acceptedCategories = [];
        $acceptedServices = [];
        $consentUuid = '';
        $cookieRevision = 0;

        if ($rawCookie !== '') {
            $decoded = json_decode(stripslashes($rawCookie), true);
            if (is_array($decoded)) {
                $acceptedCategories = (array) ($decoded['categories'] ?? []);
                $consentUuid = (string) ($decoded['consentId'] ?? '');
                $cookieRevision = (int) ($decoded['revision'] ?? 0);

                // CookieConsent 3.x stores services as {"category": ["svc1", "svc2"]}
                $rawServices = $decoded['services'] ?? [];
                if (is_array($rawServices)) {
                    foreach ($rawServices as $catServices) {
                        if (is_array($catServices)) {
                            foreach ($catServices as $svc) {
                                if (is_string($svc)) {
                                    $acceptedServices[] = $svc;
                                }
                            }
                        } elseif (is_string($catServices)) {
                            $acceptedServices[] = $catServices;
                        }
                    }
                }
            }
        }

        // Calculate GCM signal states
        $gcmSettings = $settings['gcm'] ?? [];
        $mapping = GcmMapping::getActiveMapping($gcmSettings);
        $gcmSignals = [];

        foreach (GcmMapping::ALL_SIGNALS as $signal) {
            $cat = $mapping[$signal] ?? 'marketing';
            if ($cat === 'necessary') {
                $gcmSignals[$signal] = 'granted';
            } elseif (!empty($acceptedCategories) && in_array($cat, $acceptedCategories, true)) {
                $gcmSignals[$signal] = 'granted';
            } else {
                $gcmSignals[$signal] = 'denied';
            }
        }

        return [
            'has_cookie'          => $rawCookie !== '',
            'raw_cookie'          => $rawCookie,
            'consent_uuid'        => $consentUuid,
            'accepted_categories' => array_values(array_map('strval', $acceptedCategories)),
            'accepted_services'   => array_values(array_map('strval', $acceptedServices)),
            'revision'            => $cookieRevision,
            'is_revision_current' => $rawCookie !== '' && ($cookieRevision === $currentRevision),
            'gcm_signals'         => $gcmSignals,
        ];
    }
}
