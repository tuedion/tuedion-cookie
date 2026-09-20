<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Host-aware, URL-safe matcher for identifying services by script URL, iframe embed, or handle.
 */
final class ServiceMatcher
{
    /**
     * Match an enqueued script by handle and/or src URL.
     *
     * @param string $handle
     * @param string $src
     * @return array{service: ServiceDefinition, evidence_type: string, matched_value: string, weight: int}|null
     */
    public static function matchScript(string $handle, string $src = ''): ?array
    {
        $services = ServiceRegistry::getAll();

        // 1. Check SRC URL first (Highest confidence signal: +40)
        if ($src !== '') {
            $srcHost = (string) wp_parse_url($src, PHP_URL_HOST);
            $srcPath = (string) wp_parse_url($src, PHP_URL_PATH);
            $srcHostLower = strtolower($srcHost);

            if ($srcHostLower !== '') {
                foreach ($services as $service) {
                    foreach ($service->domains as $domain) {
                        $domainLower = strtolower($domain);
                        $hostMatches = ($srcHostLower === $domainLower || str_ends_with($srcHostLower, '.' . $domainLower));

                        if ($hostMatches) {
                            // If script has specific path patterns, check them
                            if (!empty($service->scriptPatterns)) {
                                foreach ($service->scriptPatterns as $pathPat) {
                                    if (str_contains($srcPath, $pathPat)) {
                                        return [
                                            'service'       => $service,
                                            'evidence_type' => 'script_url',
                                            'matched_value' => $srcHostLower . $pathPat,
                                            'weight'        => 40,
                                        ];
                                    }
                                }
                            } else {
                                return [
                                    'service'       => $service,
                                    'evidence_type' => 'script_domain',
                                    'matched_value' => $srcHostLower,
                                    'weight'        => 40,
                                ];
                            }
                        }
                    }
                }
            }
        }

        // 2. Check WP Handle (+15 weight)
        if ($handle !== '') {
            $handleLower = strtolower($handle);
            foreach ($services as $service) {
                foreach ($service->wpHandles as $h) {
                    if ($handleLower === strtolower($h)) {
                        return [
                            'service'       => $service,
                            'evidence_type' => 'wp_handle',
                            'matched_value' => $handle,
                            'weight'        => 15,
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Match an iframe embed by SRC URL.
     *
     * @param string $src
     * @return array{service: ServiceDefinition, evidence_type: string, matched_value: string, weight: int}|null
     */
    public static function matchIframe(string $src): ?array
    {
        if ($src === '') {
            return null;
        }

        $srcHost = strtolower((string) wp_parse_url($src, PHP_URL_HOST));
        $srcPath = (string) wp_parse_url($src, PHP_URL_PATH);
        if ($srcHost === '') {
            return null;
        }

        $services = ServiceRegistry::getAll();

        foreach ($services as $service) {
            if ($service->type !== 'iframe' && empty($service->iframePatterns)) {
                continue;
            }

            foreach ($service->domains as $domain) {
                $domainLower = strtolower($domain);
                $hostMatches = ($srcHost === $domainLower || str_ends_with($srcHost, '.' . $domainLower));

                if ($hostMatches) {
                    if (!empty($service->iframePatterns)) {
                        foreach ($service->iframePatterns as $pat) {
                            $patLower = strtolower($pat);
                            if (str_contains($patLower, '/')) {
                                [$expectedHost, $expectedPath] = explode('/', $patLower, 2);
                                if (($srcHost === $expectedHost || str_ends_with($srcHost, '.' . $expectedHost))
                                    && str_contains($srcPath, $expectedPath)) {
                                    return [
                                        'service'       => $service,
                                        'evidence_type' => 'iframe_embed',
                                        'matched_value' => $pat,
                                        'weight'        => 40,
                                    ];
                                }
                            } elseif ($srcHost === $patLower || str_ends_with($srcHost, '.' . $patLower)) {
                                return [
                                    'service'       => $service,
                                    'evidence_type' => 'iframe_domain',
                                    'matched_value' => $domain,
                                    'weight'        => 40,
                                ];
                            }
                        }
                    } else {
                        return [
                            'service'       => $service,
                            'evidence_type' => 'iframe_domain',
                            'matched_value' => $domain,
                            'weight'        => 40,
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Match inline code snippets against known tracking signatures.
     *
     * @param string $inlineCode
     * @return array{service: ServiceDefinition, evidence_type: string, matched_value: string, weight: int}|null
     */
    public static function matchInline(string $inlineCode): ?array
    {
        if ($inlineCode === '') {
            return null;
        }

        $services = ServiceRegistry::getAll();

        foreach ($services as $service) {
            foreach ($service->inlinePatterns as $pattern) {
                if (str_contains($inlineCode, $pattern)) {
                    return [
                        'service'       => $service,
                        'evidence_type' => 'inline_signature',
                        'matched_value' => $pattern,
                        'weight'        => 30,
                    ];
                }
            }
        }

        return null;
    }
}
