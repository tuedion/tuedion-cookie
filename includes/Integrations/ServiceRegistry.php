<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enterprise Service Registry cataloging global third-party services, domains, scripts, and handles.
 */
final class ServiceRegistry
{
    /**
     * @var array<string, ServiceDefinition>|null
     */
    private static ?array $services = null;

    /**
     * Retrieve all registered services indexed by ID.
     *
     * @return array<string, ServiceDefinition>
     */
    public static function getAll(): array
    {
        if (self::$services !== null) {
            return self::$services;
        }

        $list = [
            // ==========================================
            // ANALYTICS SERVICES
            // ==========================================
            new ServiceDefinition(
                id: 'google-analytics',
                name: 'Google Analytics 4 / gtag.js',
                category: 'analytics',
                provider: 'Google LLC',
                domains: [
                    'googletagmanager.com',
                    'google-analytics.com',
                    'analytics.google.com',
                ],
                scriptPatterns: [
                    '/gtag/js',
                    '/analytics.js',
                    '/ga.js',
                ],
                inlinePatterns: [
                    'gtag(',
                    'ga(',
                    'GoogleAnalyticsObject',
                ],
                wpHandles: [
                    'google-analytics',
                    'gtag',
                    'ga4',
                    'google-gtag',
                    'google_gtagjs',
                    'site-kit-analytics',
                    'site-kit-analytics-4',
                ],
                autoClear: [
                    '/^_ga/',
                    '/^_gid/',
                    '/^_gat/',
                    '/^_gac_/',
                ],
                description: 'Google Analytics visitor metrics and tracking.'
            ),
            new ServiceDefinition(
                id: 'google-tag-manager',
                name: 'Google Tag Manager',
                category: 'analytics',
                provider: 'Google LLC',
                domains: [
                    'googletagmanager.com',
                ],
                scriptPatterns: [
                    '/gtm.js',
                ],
                inlinePatterns: [
                    'dataLayer.push',
                    'googletagmanager.com/gtm.js',
                ],
                wpHandles: [
                    'google-tag-manager',
                    'gtm',
                    'site-kit-tagmanager',
                ],
                autoClear: [
                    '/^_gcl_/',
                ],
                description: 'Google Tag Manager script container.'
            ),
            new ServiceDefinition(
                id: 'microsoft-clarity',
                name: 'Microsoft Clarity',
                category: 'analytics',
                provider: 'Microsoft Corporation',
                domains: [
                    'clarity.ms',
                    'www.clarity.ms',
                ],
                scriptPatterns: [
                    '/tag/',
                    '/s/',
                ],
                inlinePatterns: [
                    'clarity("init"',
                    "clarity('init'",
                ],
                wpHandles: [
                    'clarity',
                    'ms-clarity',
                    'microsoft-clarity',
                ],
                autoClear: [
                    '/^_clck/',
                    '/^_clsk/',
                    '/^CLARITY/',
                ],
                description: 'Microsoft Clarity heatmaps and user recordings.'
            ),
            new ServiceDefinition(
                id: 'hotjar',
                name: 'Hotjar',
                category: 'analytics',
                provider: 'Hotjar Ltd',
                domains: [
                    'static.hotjar.com',
                    'script.hotjar.com',
                ],
                scriptPatterns: [
                    '/c/hotjar-',
                ],
                inlinePatterns: [
                    'hjid:',
                    'hjsv:',
                    '_hjSettings',
                ],
                wpHandles: [
                    'hotjar',
                ],
                autoClear: [
                    '/^_hj/',
                ],
                description: 'Hotjar user heatmaps, session recordings, and feedback surveys.'
            ),
            new ServiceDefinition(
                id: 'matomo',
                name: 'Matomo Analytics',
                category: 'analytics',
                provider: 'InnoCraft Ltd',
                domains: [
                    'matomo.cloud',
                ],
                scriptPatterns: [
                    '/matomo.js',
                    '/piwik.js',
                ],
                inlinePatterns: [
                    '_paq.push',
                ],
                wpHandles: [
                    'matomo',
                    'wp-matomo',
                ],
                autoClear: [
                    '/^_pk_/',
                ],
                description: 'Privacy-focused Matomo open analytics engine.'
            ),
            new ServiceDefinition(
                id: 'yandex-metrica',
                name: 'Yandex Metrica',
                category: 'analytics',
                provider: 'Yandex LLC',
                domains: [
                    'mc.yandex.ru',
                ],
                scriptPatterns: [
                    '/metrika/tag.js',
                    '/metrika/watch.js',
                ],
                inlinePatterns: [
                    'ym(',
                ],
                wpHandles: [
                    'yandex-metrica',
                ],
                autoClear: [
                    '/^_ym_/',
                ],
                description: 'Yandex Metrica traffic stats, click maps, and session recordings.'
            ),
            new ServiceDefinition(
                id: 'plausible',
                name: 'Plausible Analytics',
                category: 'analytics',
                provider: 'Plausible Insights OÜ',
                domains: [
                    'plausible.io',
                ],
                scriptPatterns: [
                    '/js/script.js',
                    '/js/plausible.js',
                ],
                inlinePatterns: [
                    'plausible(',
                ],
                wpHandles: [
                    'plausible-analytics',
                ],
                autoClear: [],
                description: 'Lightweight, privacy-first analytics with zero cookies.'
            ),

            // ==========================================
            // MARKETING & ADVERTISING SERVICES
            // ==========================================
            new ServiceDefinition(
                id: 'facebook-pixel',
                name: 'Meta Pixel (Facebook)',
                category: 'marketing',
                provider: 'Meta Platforms, Inc.',
                domains: [
                    'connect.facebook.net',
                ],
                scriptPatterns: [
                    '/fbevents.js',
                ],
                inlinePatterns: [
                    'fbq("init"',
                    "fbq('init'",
                ],
                wpHandles: [
                    'facebook-pixel',
                    'fbevents',
                    'pixelyoursite',
                    'official-facebook-pixel',
                ],
                autoClear: [
                    '/^_fbp/',
                    '/^_fbc/',
                    '/^fr$/',
                ],
                description: 'Meta conversion tracking and audience targeting.'
            ),
            new ServiceDefinition(
                id: 'google-ads',
                name: 'Google Ads / DoubleClick',
                category: 'marketing',
                provider: 'Google LLC',
                domains: [
                    'googleadservices.com',
                    'googlesyndication.com',
                    'doubleclick.net',
                ],
                scriptPatterns: [
                    '/pagead/conversion.js',
                    '/pagead/show_ads.js',
                ],
                inlinePatterns: [
                    'google_ad_client',
                ],
                wpHandles: [
                    'google-ads',
                    'google-conversion',
                ],
                autoClear: [
                    '/^_gcl_aw/',
                    '/^_gcl_dc/',
                    '/^IDE$/',
                ],
                description: 'Google Ads remarketing and conversion tracking.'
            ),
            new ServiceDefinition(
                id: 'tiktok-pixel',
                name: 'TikTok Pixel',
                category: 'marketing',
                provider: 'TikTok Inc.',
                domains: [
                    'analytics.tiktok.com',
                ],
                scriptPatterns: [
                    '/i18n/pixel/events.js',
                    '/i18n/pixel/sdk.js',
                ],
                inlinePatterns: [
                    'ttq.load',
                    'ttq.page',
                ],
                wpHandles: [
                    'tiktok-pixel',
                ],
                autoClear: [
                    '/^_ttp/',
                ],
                description: 'TikTok advertising performance tracking.'
            ),
            new ServiceDefinition(
                id: 'linkedin-insight',
                name: 'LinkedIn Insight Tag',
                category: 'marketing',
                provider: 'LinkedIn Corporation',
                domains: [
                    'snap.licdn.com',
                ],
                scriptPatterns: [
                    '/li.lms-analytics/insight.min.js',
                ],
                inlinePatterns: [
                    '_linkedin_partner_id',
                ],
                wpHandles: [
                    'linkedin-insight',
                ],
                autoClear: [
                    '/^bcookie$/',
                    '/^li_sugr$/',
                    '/^lidc$/',
                ],
                description: 'LinkedIn advertising and visitor demographics.'
            ),
            new ServiceDefinition(
                id: 'pinterest-tag',
                name: 'Pinterest Tag',
                category: 'marketing',
                provider: 'Pinterest, Inc.',
                domains: [
                    's.pinimg.com',
                ],
                scriptPatterns: [
                    '/ct/core.js',
                ],
                inlinePatterns: [
                    'pintrk(',
                ],
                wpHandles: [
                    'pinterest-tag',
                ],
                autoClear: [
                    '/^_pin_unauth/',
                ],
                description: 'Pinterest conversion optimization and campaign measurement.'
            ),
            new ServiceDefinition(
                id: 'twitter-pixel',
                name: 'X / Twitter Ads Pixel',
                category: 'marketing',
                provider: 'Twitter, Inc. (X Corp)',
                domains: [
                    'static.ads-twitter.com',
                ],
                scriptPatterns: [
                    '/uwt.js',
                ],
                inlinePatterns: [
                    'twq("init"',
                    "twq('init'",
                ],
                wpHandles: [
                    'twitter-pixel',
                ],
                autoClear: [
                    '/^personalization_id$/',
                    '/^muc_ads$/',
                ],
                description: 'Twitter/X conversion tracking pixel.'
            ),
            new ServiceDefinition(
                id: 'microsoft-ads',
                name: 'Microsoft Advertising (UET)',
                category: 'marketing',
                provider: 'Microsoft Corporation',
                domains: [
                    'bat.bing.com',
                ],
                scriptPatterns: [
                    '/bat.js',
                ],
                inlinePatterns: [
                    'uetq',
                ],
                wpHandles: [
                    'bing-uet',
                ],
                autoClear: [
                    '/^_uetsid/',
                    '/^_uetvid/',
                ],
                description: 'Microsoft Universal Event Tracking (UET) tag.'
            ),
            new ServiceDefinition(
                id: 'criteo',
                name: 'Criteo OneTag',
                category: 'marketing',
                provider: 'Criteo SA',
                domains: [
                    'static.criteo.net',
                ],
                scriptPatterns: [
                    '/js/ld/ld.js',
                ],
                inlinePatterns: [
                    'criteo_q.push',
                ],
                wpHandles: [
                    'criteo',
                ],
                autoClear: [
                    '/^cto_bundle$/',
                    '/^cto_bidid$/',
                ],
                description: 'Criteo dynamic retargeting and display ads.'
            ),

            // ==========================================
            // MEDIA EMBEDS & IFRAMES
            // ==========================================
            new ServiceDefinition(
                id: 'youtube',
                name: 'YouTube',
                category: 'marketing',
                provider: 'Google LLC',
                domains: [
                    'youtube.com',
                    'www.youtube.com',
                    'youtube-nocookie.com',
                    'www.youtube-nocookie.com',
                    'youtu.be',
                ],
                iframePatterns: [
                    'youtube.com/embed/',
                    'youtube-nocookie.com/embed/',
                    'youtu.be/',
                ],
                autoClear: [
                    '/^VISITOR_INFO1_LIVE$/',
                    '/^YSC$/',
                    '/^PREF$/',
                ],
                description: 'Embedded YouTube video player.',
                type: 'iframe'
            ),
            new ServiceDefinition(
                id: 'vimeo',
                name: 'Vimeo',
                category: 'analytics',
                provider: 'Vimeo, Inc.',
                domains: [
                    'player.vimeo.com',
                    'vimeo.com',
                ],
                iframePatterns: [
                    'player.vimeo.com/video/',
                ],
                autoClear: [
                    '/^vuid$/',
                    '/^player$/',
                ],
                description: 'Embedded Vimeo video player.',
                type: 'iframe'
            ),
            new ServiceDefinition(
                id: 'google-maps',
                name: 'Google Maps Embed',
                category: 'functionality',
                provider: 'Google LLC',
                domains: [
                    'google.com/maps',
                    'maps.google.com',
                ],
                iframePatterns: [
                    'google.com/maps/embed',
                    'maps.google.com/maps',
                ],
                autoClear: [
                    '/^NID$/',
                    '/^1P_JAR$/',
                ],
                description: 'Interactive Google Maps location embed.',
                type: 'iframe'
            ),
            new ServiceDefinition(
                id: 'openstreetmap',
                name: 'OpenStreetMap',
                category: 'functionality',
                provider: 'OpenStreetMap Foundation',
                domains: [
                    'openstreetmap.org',
                ],
                iframePatterns: [
                    'openstreetmap.org/export/embed.html',
                ],
                autoClear: [],
                description: 'OpenStreetMap interactive map embed.',
                type: 'iframe'
            ),
            new ServiceDefinition(
                id: 'spotify',
                name: 'Spotify Embed',
                category: 'marketing',
                provider: 'Spotify AB',
                domains: [
                    'open.spotify.com',
                ],
                iframePatterns: [
                    'open.spotify.com/embed/',
                ],
                autoClear: [
                    '/^sp_/',
                ],
                description: 'Embedded Spotify music and podcast player.',
                type: 'iframe'
            ),
            new ServiceDefinition(
                id: 'soundcloud',
                name: 'SoundCloud Player',
                category: 'marketing',
                provider: 'SoundCloud Global Ltd.',
                domains: [
                    'w.soundcloud.com',
                ],
                iframePatterns: [
                    'w.soundcloud.com/player/',
                ],
                autoClear: [
                    '/^sc_anonymous_id$/',
                ],
                description: 'Embedded SoundCloud audio player.',
                type: 'iframe'
            ),

            // ==========================================
            // LIVE CHAT & CRM SERVICES
            // ==========================================
            new ServiceDefinition(
                id: 'intercom',
                name: 'Intercom Messenger',
                category: 'functionality',
                provider: 'Intercom, Inc.',
                domains: [
                    'widget.intercom.io',
                ],
                scriptPatterns: [
                    '/widget/',
                ],
                inlinePatterns: [
                    'Intercom("boot"',
                    "Intercom('boot'",
                ],
                wpHandles: [
                    'intercom',
                ],
                autoClear: [
                    '/^intercom-id-/',
                    '/^intercom-session-/',
                ],
                description: 'Intercom customer support live chat and messaging widget.'
            ),
            new ServiceDefinition(
                id: 'crisp',
                name: 'Crisp Live Chat',
                category: 'functionality',
                provider: 'Crisp IM SARL',
                domains: [
                    'client.crisp.chat',
                ],
                scriptPatterns: [
                    '/l.js',
                ],
                inlinePatterns: [
                    '$crisp',
                ],
                wpHandles: [
                    'crisp',
                ],
                autoClear: [
                    '/^crisp-client/',
                ],
                description: 'Crisp customer support chat widget.'
            ),
            new ServiceDefinition(
                id: 'tawkto',
                name: 'Tawk.to Live Chat',
                category: 'functionality',
                provider: 'Tawk.to Inc.',
                domains: [
                    'embed.tawk.to',
                ],
                scriptPatterns: [
                    '/tawk.to',
                ],
                inlinePatterns: [
                    'Tawk_API',
                ],
                wpHandles: [
                    'tawk-to',
                    'tawkto',
                ],
                autoClear: [
                    '/^__tawkuuid$/',
                    '/^TawkConnectionTime$/',
                ],
                description: 'Tawk.to free live chat widget.'
            ),
            new ServiceDefinition(
                id: 'hubspot',
                name: 'HubSpot Tracking & Forms',
                category: 'marketing',
                provider: 'HubSpot, Inc.',
                domains: [
                    'js.hs-scripts.com',
                    'js.hsanalytics.net',
                ],
                scriptPatterns: [
                    '/analytics/',
                ],
                inlinePatterns: [
                    '_hsq.push',
                ],
                wpHandles: [
                    'hubspot',
                    'leadin-script-loader',
                ],
                autoClear: [
                    '/^hubspotutk$/',
                    '/^__hss/',
                    '/^__hstc$/',
                ],
                description: 'HubSpot inbound marketing, CRM forms, and lead tracking.'
            ),
            new ServiceDefinition(
                id: 'google-recaptcha',
                name: 'Google reCAPTCHA',
                category: 'necessary',
                provider: 'Google LLC',
                domains: [
                    'google.com',
                    'recaptcha.net',
                    // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Domain signature for Google reCAPTCHA tracker blocking, not offloaded asset.
                    'gstatic.com',
                ],
                scriptPatterns: [
                    '/recaptcha/api.js',
                    'recaptcha/releases',
                ],
                inlinePatterns: [
                    'grecaptcha.execute',
                    'grecaptcha.render',
                    'grecaptcha.ready',
                ],
                wpHandles: [
                    'google-recaptcha',
                    'recaptcha',
                    'wpcf7-recaptcha',
                ],
                autoClear: [
                    '/^_GRECAPTCHA$/',
                ],
                description: 'Google reCAPTCHA spam and bot prevention service.'
            ),
        ];

        self::$services = [];
        foreach ($list as $service) {
            self::$services[$service->id] = $service;
        }

        return self::$services;
    }

    /**
     * Get single service by ID or alias.
     *
     * @param string $id
     * @return ServiceDefinition|null
     */
    public static function get(string $id): ?ServiceDefinition
    {
        $all = self::getAll();
        if (isset($all[$id])) {
            return $all[$id];
        }

        $aliases = [
            'meta-pixel'       => 'facebook-pixel',
            'facebook'         => 'facebook-pixel',
            'gtm'              => 'google-tag-manager',
            'ga'               => 'google-analytics',
            'ga4'              => 'google-analytics',
            'gads'             => 'google-ads',
            'clarity'          => 'microsoft-clarity',
            'tiktok'           => 'tiktok-pixel',
            'pinterest'        => 'pinterest-tag',
            'linkedin'         => 'linkedin-insight',
            'twitter'          => 'twitter-pixel',
            'x-pixel'          => 'twitter-pixel',
            'osm'              => 'openstreetmap',
            'gmaps'            => 'google-maps',
            'recaptcha'        => 'google-recaptcha',
            'grecaptcha'       => 'google-recaptcha',
            'google_recaptcha' => 'google-recaptcha',
        ];

        $targetId = $aliases[$id] ?? null;
        if ($targetId !== null && isset($all[$targetId])) {
            return $all[$targetId];
        }

        return null;
    }
}
