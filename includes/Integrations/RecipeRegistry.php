<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registry of well-known third-party scripts, media iframes, and their autoClear cookies.
 *
 * NOTE: This registry does NOT initiate any external HTTP requests, remote API calls, or load
 * third-party scripts. It acts strictly as an offline dictionary of known script handles,
 * domain signatures, and iframe embed patterns to detect and BLOCK them until explicit consent is granted.
 */
final class RecipeRegistry
{
    /**
     * @var array<string, array<string, mixed>>|null Cached recipes list.
     */
    private static ?array $recipes = null;

    /**
     * Retrieve all registered recipes.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getAll(): array
    {
        if (self::$recipes !== null) {
            return self::$recipes;
        }

        $defaultRecipes = [
            // --- ANALYTICS RECIPES ---
            'google-analytics' => [
                'name'             => 'Google Analytics 4 / gtag.js',
                'category'         => 'analytics',
                'type'             => 'script',
                'handles'          => [
                    'google-analytics',
                    'gtag',
                    'ga4',
                    'google-gtag',
                    'google_gtagjs',
                    'site-kit-analytics',
                    'site-kit-analytics-4',
                ],
                'domains'          => [
                    'googletagmanager.com/gtag/js',
                    'google-analytics.com/analytics.js',
                    'google-analytics.com/ga.js',
                ],
                'auto_clear'       => [
                    '/^_ga/',
                    '/^_gid/',
                    '/^_gat/',
                    '/^_gac_/',
                ],
                'description'      => 'Google Analytics visitor metrics and tracking.',
            ],
            'google-tag-manager' => [
                'name'             => 'Google Tag Manager',
                'category'         => 'analytics',
                'type'             => 'script',
                'handles'          => [
                    'google-tag-manager',
                    'gtm',
                    'site-kit-tagmanager',
                ],
                'domains'          => [
                    'googletagmanager.com/gtm.js',
                ],
                'auto_clear'       => [
                    '/^_gcl_/',
                ],
                'description'      => 'Google Tag Manager script container.',
            ],
            'microsoft-clarity' => [
                'name'             => 'Microsoft Clarity',
                'category'         => 'analytics',
                'type'             => 'script',
                'handles'          => [
                    'clarity',
                    'ms-clarity',
                    'microsoft-clarity',
                ],
                'domains'          => [
                    'clarity.ms/tag/',
                    'clarity.ms/s/',
                ],
                'auto_clear'       => [
                    '/^_clck/',
                    '/^_clsk/',
                    '/^CLARITY/',
                ],
                'description'      => 'Microsoft Clarity heatmaps and user recordings.',
            ],
            'hotjar' => [
                'name'             => 'Hotjar',
                'category'         => 'analytics',
                'type'             => 'script',
                'handles'          => [
                    'hotjar',
                    'hotjar-tracking',
                ],
                'domains'          => [
                    'static.hotjar.com',
                    'script.hotjar.com',
                ],
                'auto_clear'       => [
                    '/^_hj/',
                    '/^_hjSession/',
                    '/^_hjIncludedIn/',
                ],
                'description'      => 'Hotjar heatmaps and behavioral analytics.',
            ],
            'matomo' => [
                'name'             => 'Matomo Analytics',
                'category'         => 'analytics',
                'type'             => 'script',
                'handles'          => [
                    'matomo',
                    'piwik',
                ],
                'domains'          => [
                    'matomo.js',
                    'piwik.js',
                ],
                'auto_clear'       => [
                    '/^_pk_ref/',
                    '/^_pk_cvar/',
                    '/^_pk_id/',
                    '/^_pk_ses/',
                ],
                'description'      => 'Matomo open-source privacy-focused analytics.',
            ],

            // --- MARKETING / ADVERTISING RECIPES ---
            'meta-pixel' => [
                'name'             => 'Meta (Facebook) Pixel',
                'category'         => 'marketing',
                'type'             => 'script',
                'handles'          => [
                    'facebook-pixel',
                    'meta-pixel',
                    'fbevents',
                    'fb-pixel',
                ],
                'domains'          => [
                    'connect.facebook.net',
                ],
                'auto_clear'       => [
                    '/^_fbp/',
                    '/^_fbc/',
                ],
                'description'      => 'Meta Pixel conversion tracking and ad audience building.',
            ],
            'google-ads' => [
                'name'             => 'Google Ads / Conversion Tracking',
                'category'         => 'marketing',
                'type'             => 'script',
                'handles'          => [
                    'google-ads',
                    'google-conversions',
                ],
                'domains'          => [
                    'googleadservices.com/pagead/conversion',
                ],
                'auto_clear'       => [
                    '/^_gcl_aw/',
                    '/^_gcl_dc/',
                ],
                'description'      => 'Google Ads remarketing and conversion tracking.',
            ],
            'tiktok-pixel' => [
                'name'             => 'TikTok Pixel',
                'category'         => 'marketing',
                'type'             => 'script',
                'handles'          => [
                    'tiktok-pixel',
                    'ttq',
                ],
                'domains'          => [
                    'analytics.tiktok.com/i18n/pixel/',
                ],
                'auto_clear'       => [
                    '/^_ttp/',
                    '/^_tt_enable_cookie/',
                ],
                'description'      => 'TikTok Pixel advertising and conversion measurement.',
            ],
            'linkedin-insight' => [
                'name'             => 'LinkedIn Insight Tag',
                'category'         => 'marketing',
                'type'             => 'script',
                'handles'          => [
                    'linkedin-insight',
                    'snap-licdn',
                ],
                'domains'          => [
                    'snap.licdn.com/li.lms-analytics/insight.min.js',
                ],
                'auto_clear'       => [
                    '/^li_sugr/',
                    '/^bcookie/',
                    '/^lidc/',
                ],
                'description'      => 'LinkedIn Insight Tag for campaign reporting and website demographics.',
            ],

            'microsoft-uet' => [
                'name'             => 'Microsoft Advertising / Bing UET',
                'category'         => 'marketing',
                'type'             => 'script',
                'handles'          => [
                    'bing-uet',
                    'microsoft-uet',
                    'uet-tag',
                    'bing-ads',
                ],
                'domains'          => [
                    'bat.bing.com/bat.js',
                    'bat.bing.com',
                ],
                'auto_clear'       => [
                    '/^_uetmsclkid/',
                    '/^_uetsid/',
                    '/^_uetvid/',
                ],
                'description'      => 'Microsoft Advertising Universal Event Tracking (UET) tag.',
            ],
            'hubspot' => [
                'name'             => 'HubSpot CRM & Tracking',
                'category'         => 'marketing',
                'type'             => 'script',
                'handles'          => [
                    'hubspot',
                    'hs-script-loader',
                    'hs-analytics',
                ],
                'domains'          => [
                    'js.hs-scripts.com',
                    'js.hs-analytics.net',
                    'js.hsforms.net',
                ],
                'auto_clear'       => [
                    '/^__hstc/',
                    '/^hubspotutk/',
                    '/^__hssc/',
                    '/^__hssrc/',
                    '/^messagesUtk/',
                ],
                'description'      => 'HubSpot analytics, forms, and live chat tracking.',
            ],
            'pinterest-tag' => [
                'name'             => 'Pinterest Tag',
                'category'         => 'marketing',
                'type'             => 'script',
                'handles'          => [
                    'pinterest-tag',
                    'pintrk',
                ],
                'domains'          => [
                    's.pinimg.com/ct/core.js',
                ],
                'auto_clear'       => [
                    '/^_pin_unauth/',
                ],
                'description'      => 'Pinterest advertising conversion and event tracking.',
            ],
            'activecampaign' => [
                'name'             => 'ActiveCampaign Tracking',
                'category'         => 'marketing',
                'type'             => 'script',
                'handles'          => [
                    'activecampaign',
                    'ac-tracking',
                ],
                'domains'          => [
                    'trackcmp.net',
                ],
                'auto_clear'       => [
                    '/^prism_/',
                ],
                'description'      => 'ActiveCampaign website tracking and event monitoring.',
            ],
            'plausible' => [
                'name'             => 'Plausible Analytics',
                'category'         => 'analytics',
                'type'             => 'script',
                'handles'          => [
                    'plausible',
                    'plausible-analytics',
                ],
                'domains'          => [
                    'plausible.io/js/script.js',
                ],
                'auto_clear'       => [],
                'description'      => 'Plausible privacy-friendly analytics (cookie-less).',
            ],
            'google-fonts' => [
                'name'             => 'Google Fonts',
                'category'         => 'functionality',
                'type'             => 'style',
                'handles'          => [
                    'google-fonts',
                    'fonts-google',
                ],
                'domains'          => [
                    'fonts.googleapis.com',
                    'fonts.gstatic.com',
                ],
                'auto_clear'       => [],
                'description'      => 'Google web typography service and font delivery network.',
            ],

            // --- SECURITY / CAPTCHA RECIPES ---
            'google-recaptcha' => [
                'name'             => 'Google reCAPTCHA',
                'category'         => 'necessary',
                'type'             => 'script',
                'handles'          => [
                    'recaptcha',
                    'google-recaptcha',
                    'grecaptcha',
                    'wpcf7-recaptcha',
                ],
                'domains'          => [
                    'google.com/recaptcha/',
                    // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Tracker detection pattern, not enqueued assets.
                    'gstatic.com/recaptcha/',
                ],
                'auto_clear'       => [
                    '/^_GRECAPTCHA/',
                ],
                'description'      => 'Google reCAPTCHA bot prevention and form security.',
            ],
            'hcaptcha' => [
                'name'             => 'hCaptcha',
                'category'         => 'necessary',
                'type'             => 'script',
                'handles'          => [
                    'hcaptcha',
                    'hcaptcha-api',
                ],
                'domains'          => [
                    'hcaptcha.com/1/api.js',
                    'assets.hcaptcha.com',
                ],
                'auto_clear'       => [],
                'description'      => 'hCaptcha bot prevention and privacy-focused verification.',
            ],
            'cloudflare-turnstile' => [
                'name'             => 'Cloudflare Turnstile',
                'category'         => 'necessary',
                'type'             => 'script',
                'handles'          => [
                    'turnstile',
                    'cf-turnstile',
                    'cloudflare-turnstile',
                ],
                'domains'          => [
                    // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Tracker detection pattern, not enqueued assets.
                    'challenges.cloudflare.com/turnstile',
                ],
                'auto_clear'       => [],
                'description'      => 'Cloudflare Turnstile privacy-preserving security challenge.',
            ],
            'youtube-api' => [
                'name'             => 'YouTube Player API',
                'category'         => 'marketing',
                'type'             => 'script',
                'handles'          => [
                    'youtube-api',
                    'youtube-iframe-api',
                    'elementor-youtube-api',
                ],
                'domains'          => [
                    'youtube.com/iframe_api',
                    'youtube.com/s/player/',
                    '/s/_/ytembeds/',
                ],
                'auto_clear'       => [
                    '/^YSC/',
                    '/^VISITOR_INFO1_LIVE/',
                ],
                'description'      => 'YouTube player JavaScript API.',
            ],

            // --- MEDIA / EMBED RECIPES (IFRAME) ---
            'youtube' => [
                'name'             => 'YouTube Embed',
                'category'         => 'marketing',
                'type'             => 'iframe',
                'patterns'         => [
                    'youtube.com/embed/',
                    'youtube-nocookie.com/embed/',
                    'youtu.be/',
                ],
                'auto_clear'       => [
                    '/^YSC/',
                    '/^VISITOR_INFO1_LIVE/',
                ],
                'description'      => 'Embedded YouTube video player.',
            ],
            'vimeo' => [
                'name'             => 'Vimeo Player',
                'category'         => 'marketing',
                'type'             => 'iframe',
                'patterns'         => [
                    'player.vimeo.com/video/',
                ],
                'auto_clear'       => [
                    '/^vuid/',
                ],
                'description'      => 'Embedded Vimeo video player.',
            ],
            'google-maps' => [
                'name'             => 'Google Maps Embed',
                'category'         => 'marketing',
                'type'             => 'iframe',
                'patterns'         => [
                    'google.com/maps/embed',
                    'maps.google.com/maps',
                ],
                'auto_clear'       => [
                    '/^NID/',
                ],
                'description'      => 'Embedded Google Maps iframe.',
            ],
            'openstreetmap' => [
                'name'             => 'OpenStreetMap Embed',
                'category'         => 'marketing',
                'type'             => 'iframe',
                'patterns'         => [
                    'openstreetmap.org/export/embed',
                    'tile.openstreetmap.org',
                ],
                'auto_clear'       => [
                    '/^_osm_/',
                ],
                'description'      => 'Embedded OpenStreetMap interactive map iframe.',
            ],
        ];

        /**
         * Filter available service recipes.
         *
         * @param array<string, array<string, mixed>> $defaultRecipes
         */
        self::$recipes = (array) apply_filters('tuedion_cookie_recipes', $defaultRecipes);

        return self::$recipes;
    }

    /**
     * Retrieve a specific recipe by ID.
     *
     * @param string $id
     * @return array<string, mixed>|null
     */
    public static function get(string $id): ?array
    {
        $recipes = self::getAll();
        return $recipes[$id] ?? null;
    }

    /**
     * Check if a recipe ID exists in the registry.
     *
     * @param string $id
     * @return bool
     */
    public static function has(string $id): bool
    {
        $recipes = self::getAll();
        return isset($recipes[$id]);
    }

    /**
     * Find a recipe matching a script handle or src URL.
     *
     * @param string $handle
     * @param string $src
     * @return array<string, mixed>|null
     */
    public static function findRecipeForScript(string $handle, string $src): ?array
    {
        $recipes = self::getAll();
        $srcLower = strtolower($src);
        $handleLower = strtolower($handle);
        $srcHost = strtolower((string) wp_parse_url($src, PHP_URL_HOST));
        $srcPath = strtolower((string) wp_parse_url($src, PHP_URL_PATH));

        foreach ($recipes as $id => $recipe) {
            if (($recipe['type'] ?? '') !== 'script') {
                continue;
            }

            // Check handles
            if (!empty($recipe['handles']) && is_array($recipe['handles'])) {
                foreach ($recipe['handles'] as $h) {
                    if ($handleLower === strtolower($h)) {
                        $recipe['id'] = $id;
                        $recipe['category'] = self::resolveServiceCategory($id, (string) ($recipe['category'] ?? 'analytics'));
                        return $recipe;
                    }
                }
            }

            // Check URL domains/patterns with host-aware verification
            if (!empty($recipe['domains']) && is_array($recipe['domains'])) {
                foreach ($recipe['domains'] as $domain) {
                    $domainLower = strtolower($domain);

                    if ($srcHost !== '') {
                        if (str_contains($domainLower, '/')) {
                            [$expectedHost, $expectedPath] = explode('/', $domainLower, 2);
                            $hostMatches = ($srcHost === $expectedHost || str_ends_with($srcHost, '.' . $expectedHost));
                            if ($hostMatches && str_contains($srcPath, $expectedPath)) {
                                $recipe['id'] = $id;
                                $recipe['category'] = self::resolveServiceCategory($id, (string) ($recipe['category'] ?? 'analytics'));
                                return $recipe;
                            }
                        } elseif (str_contains($domainLower, '.')) {
                            // Standard domain pattern (e.g. connect.facebook.net)
                            if ($srcHost === $domainLower || str_ends_with($srcHost, '.' . $domainLower)) {
                                $recipe['id'] = $id;
                                $recipe['category'] = self::resolveServiceCategory($id, (string) ($recipe['category'] ?? 'analytics'));
                                return $recipe;
                            }
                        } else {
                            // Filename-only script pattern (e.g. matomo.js)
                            if (str_ends_with($srcPath, $domainLower)) {
                                $recipe['id'] = $id;
                                $recipe['category'] = self::resolveServiceCategory($id, (string) ($recipe['category'] ?? 'analytics'));
                                return $recipe;
                            }
                        }
                    } elseif (str_contains($srcLower, $domainLower)) {
                        $recipe['id'] = $id;
                        $recipe['category'] = self::resolveServiceCategory($id, (string) ($recipe['category'] ?? 'analytics'));
                        return $recipe;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Resolve actual service category honoring user overrides in Settings repository.
     * Ensures settings panel customizations are reflected in real blocking rules.
     *
     * @param string|null $serviceId
     * @param string $defaultCategory
     * @return string
     */
    public static function resolveServiceCategory(?string $serviceId, string $defaultCategory = 'marketing'): string
    {
        if ($serviceId === null || $serviceId === '') {
            return $defaultCategory;
        }

        $settings = \Tuedion\CookieConsent\Settings\Repository::getSettings();
        if (!empty($settings['services']) && is_array($settings['services'])) {
            foreach ($settings['services'] as $svc) {
                if (is_array($svc) && ($svc['id'] ?? '') === $serviceId && !empty($svc['category'])) {
                    return (string) $svc['category'];
                }
            }
        }

        return $defaultCategory;
    }

    /**
     * Find a recipe matching an iframe src URL with host-aware validation.
     *
     * @param string $src
     * @return array<string, mixed>|null
     */
    public static function findRecipeForIframe(string $src): ?array
    {
        $recipes = self::getAll();
        $srcLower = strtolower($src);
        $srcHost = strtolower((string) wp_parse_url($src, PHP_URL_HOST));
        $srcPath = strtolower((string) wp_parse_url($src, PHP_URL_PATH));

        foreach ($recipes as $id => $recipe) {
            if (($recipe['type'] ?? '') !== 'iframe') {
                continue;
            }

            if (!empty($recipe['patterns']) && is_array($recipe['patterns'])) {
                foreach ($recipe['patterns'] as $pattern) {
                    $patternLower = strtolower($pattern);

                    if ($srcHost !== '') {
                        if (str_contains($patternLower, '/')) {
                            [$expectedHost, $expectedPath] = explode('/', $patternLower, 2);
                            $hostMatches = ($srcHost === $expectedHost || str_ends_with($srcHost, '.' . $expectedHost));
                            if ($hostMatches && str_contains($srcPath, $expectedPath)) {
                                $recipe['id'] = $id;
                                $recipe['category'] = self::resolveServiceCategory($id, (string) ($recipe['category'] ?? 'marketing'));
                                return $recipe;
                            }
                        } else {
                            if ($srcHost === $patternLower || str_ends_with($srcHost, '.' . $patternLower)) {
                                $recipe['id'] = $id;
                                $recipe['category'] = self::resolveServiceCategory($id, (string) ($recipe['category'] ?? 'marketing'));
                                return $recipe;
                            }
                        }
                    } elseif (str_contains($srcLower, $patternLower)) {
                        $recipe['id'] = $id;
                        $recipe['category'] = self::resolveServiceCategory($id, (string) ($recipe['category'] ?? 'marketing'));
                        return $recipe;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Collect all autoClear cookie patterns registered for a given category.
     *
     * @param string $categoryId
     * @return list<array{name: string}>
     */
    public static function getAutoClearForCategory(string $categoryId): array
    {
        $recipes = self::getAll();
        $patterns = [];

        foreach ($recipes as $id => $recipe) {
            $effectiveCategory = self::resolveServiceCategory($id, (string) ($recipe['category'] ?? ''));
            if ($effectiveCategory !== $categoryId) {
                continue;
            }

            if (!empty($recipe['auto_clear']) && is_array($recipe['auto_clear'])) {
                foreach ($recipe['auto_clear'] as $cookiePattern) {
                    $patterns[] = [
                        'name' => (string) $cookiePattern,
                    ];
                }
            }
        }

        return $patterns;
    }
}
