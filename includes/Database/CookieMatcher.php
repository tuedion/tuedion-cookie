<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Database;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * High-performance matcher for identifying cookies against exact and wildcard definitions.
 */
final class CookieMatcher
{
    /**
     * Match a cookie name against exact and wildcard catalogs.
     *
     * @param string $cookieName
     * @param array<string, array<string, mixed>> $exactMap
     * @param list<array<string, mixed>> $wildcardList
     * @return array<string, mixed>|null
     */
    public static function match(string $cookieName, array $exactMap, array $wildcardList): ?array
    {
        $nameTrimmed = trim($cookieName);
        if ($nameTrimmed === '') {
            return null;
        }

        $lowerName = strtolower($nameTrimmed);

        // 1. O(1) Exact Match Check
        if (isset($exactMap[$lowerName])) {
            $record = $exactMap[$lowerName];
            $record['matched_by'] = 'exact';
            return $record;
        }

        // 2. Wildcard Pattern Check
        foreach ($wildcardList as $item) {
            $regex = $item['pattern_regex'] ?? null;
            if (is_string($regex) && preg_match($regex, $nameTrimmed)) {
                $record = $item;
                $record['matched_by'] = 'wildcard';
                return $record;
            }
        }

        return null;
    }
}
