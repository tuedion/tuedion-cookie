<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Database;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Loads and compiles the Open Cookie Database snapshot into high-speed lookup indexes.
 */
final class CookieDatabaseLoader
{
    /**
     * Load raw JSON snapshot and parse into indexed exact and wildcard collections.
     *
     * @param string $jsonPath Path to the local JSON file.
     * @return array{exact: array<string, array<string, mixed>>, wildcard: list<array<string, mixed>>, metadata: array<string, mixed>}
     */
    public static function load(string $jsonPath): array
    {
        $result = [
            'exact'    => [],
            'wildcard' => [],
            'metadata' => [
                'source'        => 'Open Cookie Database',
                'records_count' => 0,
                'loaded_at'     => time(),
            ],
        ];

        if (!is_readable($jsonPath)) {
            return $result;
        }

        $rawJson = (string) file_get_contents($jsonPath);
        if ($rawJson === '') {
            return $result;
        }

        $records = json_decode($rawJson, true);
        if (!is_array($records)) {
            return $result;
        }

        $exact = [];
        $wildcard = [];

        // Support both flat array: [ { "name": ... } ] and nested vendor dictionary: { "Vendor": [ { "cookie": ... } ] }
        foreach ($records as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $itemsToProcess = [];
            if (isset($entry[0]) && is_array($entry[0])) {
                $vendor = sanitize_text_field((string) $key);
                foreach ($entry as $sub) {
                    if (is_array($sub)) {
                        $itemsToProcess[] = [
                            'name'        => (string) ($sub['cookie'] ?? $sub['name'] ?? ''),
                            'provider'    => (string) ($sub['dataController'] ?? $sub['provider'] ?? $vendor),
                            'category'    => (string) ($sub['category'] ?? 'analytics'),
                            'domain'      => (string) ($sub['domain'] ?? ''),
                            'wildcard'    => !empty($sub['wildcardMatch']) && (string) $sub['wildcardMatch'] === '1' || !empty($sub['wildcard']),
                            'description' => (string) ($sub['description'] ?? ''),
                            'retention'   => (string) ($sub['retentionPeriod'] ?? $sub['retention'] ?? 'Session'),
                            'controller'  => (string) ($sub['dataController'] ?? $sub['controller'] ?? $vendor),
                            'privacy_url' => (string) ($sub['privacyLink'] ?? $sub['privacy_url'] ?? ''),
                        ];
                    }
                }
            } else {
                $itemsToProcess[] = $entry;
            }

            foreach ($itemsToProcess as $item) {
                $rawName = (string) ($item['name'] ?? $item['cookie'] ?? '');
                $name = trim($rawName);
                if ($name === '') {
                    continue;
                }

                $isWildcard = !empty($item['wildcard'])
                    || (!empty($item['wildcardMatch']) && (string) $item['wildcardMatch'] === '1')
                    || str_contains($name, '*');

                $normalized = [
                    'name'        => $name,
                    'provider'    => sanitize_text_field((string) ($item['provider'] ?? $item['dataController'] ?? 'Unknown Provider')),
                    'category'    => self::normalizeCategory((string) ($item['category'] ?? 'analytics')),
                    'domain'      => sanitize_text_field((string) ($item['domain'] ?? '')),
                    'wildcard'    => $isWildcard,
                    'description' => sanitize_text_field((string) ($item['description'] ?? '')),
                    'retention'   => sanitize_text_field((string) ($item['retention'] ?? $item['retentionPeriod'] ?? 'Session')),
                    'controller'  => sanitize_text_field((string) ($item['controller'] ?? $item['dataController'] ?? '')),
                    'privacy_url' => esc_url_raw((string) ($item['privacy_url'] ?? $item['privacyLink'] ?? '')),
                ];

                if ($isWildcard) {
                    // Prepare regex pattern for fast matching
                    $escaped = preg_quote($name, '/');
                    $regex = '/^' . str_replace('\\*', '.*', $escaped) . '$/i';
                    $normalized['pattern_regex'] = $regex;
                    $wildcard[] = $normalized;
                } else {
                    $exact[strtolower($name)] = $normalized;
                }
            }
        }

        // Check for sibling metadata.json
        $metaPath = dirname($jsonPath) . '/metadata.json';
        $meta = [];
        if (is_readable($metaPath)) {
            $metaDecoded = json_decode((string) file_get_contents($metaPath), true);
            if (is_array($metaDecoded)) {
                $meta = $metaDecoded;
            }
        }

        $result['exact'] = $exact;
        $result['wildcard'] = $wildcard;
        $result['metadata'] = array_merge($result['metadata'], $meta, [
            'records_count' => count($exact) + count($wildcard),
        ]);

        return $result;
    }

    /**
     * Map vendor category terms to standard Orest Bida CookieConsent categories.
     *
     * @param string $cat
     * @return string
     */
    public static function normalizeCategory(string $cat): string
    {
        $c = strtolower(trim($cat));
        return match ($c) {
            'strictly necessary', 'essential', 'necessary', 'security' => 'necessary',
            'functional', 'functionality', 'preferences'               => 'functionality',
            'analytics', 'performance', 'statistics', 'measurement'   => 'analytics',
            'marketing', 'advertising', 'targeting', 'advertisement'   => 'marketing',
            default                                                    => 'analytics',
        };
    }
}
