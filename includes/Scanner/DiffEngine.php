<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Scanner;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Calculates deterministic diffs between two scanner runs.
 */
final class DiffEngine
{
    /**
     * Compute differences between current scan findings and previous baseline findings.
     *
     * @param array<string, mixed> $currentFindings
     * @param array<string, mixed>|null $previousFindings
     * @return array<string, mixed>
     */
    public static function compute(array $currentFindings, ?array $previousFindings = null): array
    {
        if ($previousFindings === null || empty($previousFindings)) {
            $currServices = array_keys((array) ($currentFindings['detected_services'] ?? []));
            $currCookies  = self::extractCookieNames((array) ($currentFindings['detected_cookies'] ?? []));
            $currUnknowns = self::extractUnknownIdentifiers((array) ($currentFindings['unknown_resources'] ?? []));

            return [
                'has_changes'      => !empty($currServices) || !empty($currCookies),
                'new_services'     => $currServices,
                'removed_services' => [],
                'new_cookies'      => $currCookies,
                'removed_cookies'  => [],
                'new_unknowns'     => $currUnknowns,
                'summary'          => sprintf(
                    /* translators: 1: service count, 2: cookie count */
                    __('Initial baseline: %1$d active services and %2$d cookies identified.', 'tuedion-cookie'),
                    count($currServices),
                    count($currCookies)
                ),
            ];
        }

        $currentServices  = array_keys((array) ($currentFindings['detected_services'] ?? []));
        $previousServices = array_keys((array) ($previousFindings['detected_services'] ?? []));

        $newServices     = array_values(array_diff($currentServices, $previousServices));
        $removedServices = array_values(array_diff($previousServices, $currentServices));

        $currentCookies  = self::extractCookieNames((array) ($currentFindings['detected_cookies'] ?? []));
        $previousCookies = self::extractCookieNames((array) ($previousFindings['detected_cookies'] ?? []));

        $newCookies     = array_values(array_diff($currentCookies, $previousCookies));
        $removedCookies = array_values(array_diff($previousCookies, $currentCookies));

        $currentUnknowns  = self::extractUnknownIdentifiers((array) ($currentFindings['unknown_resources'] ?? []));
        $previousUnknowns = self::extractUnknownIdentifiers((array) ($previousFindings['unknown_resources'] ?? []));

        $newUnknowns = array_values(array_diff($currentUnknowns, $previousUnknowns));

        $hasChanges = !empty($newServices) || !empty($removedServices) || !empty($newCookies) || !empty($removedCookies) || !empty($newUnknowns);

        $summaryParts = [];
        if (!empty($newServices)) {
            /* translators: %d: count of new services */
            $summaryParts[] = sprintf(_n('+%d new service', '+%d new services', count($newServices), 'tuedion-cookie'), count($newServices));
        }
        if (!empty($removedServices)) {
            /* translators: %d: count of removed services */
            $summaryParts[] = sprintf(_n('-%d removed service', '-%d removed services', count($removedServices), 'tuedion-cookie'), count($removedServices));
        }
        if (!empty($newCookies)) {
            /* translators: %d: count of new cookies */
            $summaryParts[] = sprintf(_n('+%d new cookie', '+%d new cookies', count($newCookies), 'tuedion-cookie'), count($newCookies));
        }
        if (!empty($removedCookies)) {
            /* translators: %d: count of removed cookies */
            $summaryParts[] = sprintf(_n('-%d removed cookie', '-%d removed cookies', count($removedCookies), 'tuedion-cookie'), count($removedCookies));
        }
        if (!empty($newUnknowns)) {
            /* translators: %d: count of new unknown resources */
            $summaryParts[] = sprintf(_n('+%d unknown resource', '+%d unknown resources', count($newUnknowns), 'tuedion-cookie'), count($newUnknowns));
        }

        $summaryText = $hasChanges
            ? implode(', ', $summaryParts)
            : __('No tracker or cookie changes detected since previous scan.', 'tuedion-cookie');

        return [
            'has_changes'      => $hasChanges,
            'new_services'     => $newServices,
            'removed_services' => $removedServices,
            'new_cookies'      => $newCookies,
            'removed_cookies'  => $removedCookies,
            'new_unknowns'     => $newUnknowns,
            'summary'          => $summaryText,
        ];
    }

    /**
     * @param list<array<string, mixed>> $cookies
     * @return list<string>
     */
    private static function extractCookieNames(array $cookies): array
    {
        $names = [];
        foreach ($cookies as $c) {
            $name = (string) ($c['name'] ?? '');
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * @param list<array<string, mixed>> $resources
     * @return list<string>
     */
    private static function extractUnknownIdentifiers(array $resources): array
    {
        $ids = [];
        foreach ($resources as $r) {
            $id = (string) ($r['identifier'] ?? $r['domain'] ?? '');
            if ($id !== '' && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }
}
