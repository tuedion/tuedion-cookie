<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Scanner\Detection;

use Tuedion\CookieConsent\Database\CookieDatabase;
use Tuedion\CookieConsent\Integrations\ServiceRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Detects, fingerprints, catalogs, and manages unknown external trackers, scripts, and cookies.
 */
final class UnknownResourceDetector
{
    public const OPTION_KEY = 'tdcc_scanner_unknown_resources';

    public const STATUS_PENDING    = 'pending';
    public const STATUS_IGNORED    = 'ignored';
    public const STATUS_CLASSIFIED = 'classified';

    public const TYPE_SCRIPT  = 'script';
    public const TYPE_IFRAME  = 'iframe';
    public const TYPE_PIXEL   = 'pixel';
    public const TYPE_COOKIE  = 'cookie';
    public const TYPE_NETWORK = 'network';

    /**
     * Inspect an external resource and register it as unknown if not present in registries.
     *
     * @param string $type One of self::TYPE_*
     * @param string $identifier URL, cookie name, or request endpoint
     * @param string $firstFoundUrl Page URL where detected
     * @param string $domain Hostname or domain
     * @return array<string, mixed>|null Returns resource record if registered as unknown, or null if known
     */
    public function inspectAndRecord(
        string $type,
        string $identifier,
        string $firstFoundUrl = '',
        string $domain = ''
    ): ?array {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $homeHost = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));

        if ($domain === '') {
            $parsedHost = (string) wp_parse_url($identifier, PHP_URL_HOST);
            $domain = $parsedHost !== '' ? strtolower($parsedHost) : $homeHost;
        } else {
            $domain = strtolower($domain);
        }

        // Ignore internal WordPress scripts/domains
        if ($domain === $homeHost || str_ends_with($domain, '.' . $homeHost)) {
            return null;
        }

        // Ignore core WP / standard CDNs
        $ignoredDomains = [
            // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Core WordPress emoji CDN domain ignored during audit discovery, not offloaded asset.
            's.w.org',
            'wordpress.org',
            'api.wordpress.org',
            'secure.gravatar.com',
            '0.gravatar.com',
            '1.gravatar.com',
            '2.gravatar.com',
        ];
        if (in_array($domain, $ignoredDomains, true)) {
            return null;
        }

        // Check if known in ServiceRegistry
        if ($this->isKnownServiceDomain($domain)) {
            return null;
        }

        // If it's a cookie, check CookieDatabase
        if ($type === self::TYPE_COOKIE) {
            if (CookieDatabase::find($identifier) !== null) {
                return null;
            }
        }

        $id = md5($type . ':' . $domain . ':' . $identifier);

        $resource = [
            'id'                => $id,
            'type'              => $type,
            'identifier'        => $identifier,
            'domain'            => $domain,
            'first_found_url'   => $firstFoundUrl !== '' ? $firstFoundUrl : home_url('/'),
            'first_detected_at' => time(),
            'last_seen_at'      => time(),
            'status'            => self::STATUS_PENDING,
            'assigned_service'  => null,
            'notes'             => '',
        ];

        $this->saveResource($resource);

        return $resource;
    }

    /**
     * Batch process candidate resources and persist unrecognized ones.
     *
     * @param list<array{type: string, url?: string, name?: string, domain?: string, source_url?: string}> $candidates
     * @return list<array<string, mixed>>
     */
    public function processBatch(array $candidates): array
    {
        $recorded = [];

        foreach ($candidates as $cand) {
            $type = (string) ($cand['type'] ?? self::TYPE_SCRIPT);
            $identifier = (string) ($cand['url'] ?? $cand['name'] ?? '');
            $domain = (string) ($cand['domain'] ?? '');
            $sourceUrl = (string) ($cand['source_url'] ?? '');

            $res = $this->inspectAndRecord($type, $identifier, $sourceUrl, $domain);
            if ($res !== null) {
                $recorded[] = $res;
            }
        }

        return $recorded;
    }

    /**
     * Retrieve all cataloged unknown resources.
     *
     * @param string|null $statusFilter Optional filter: 'pending', 'ignored', 'classified'
     * @return list<array<string, mixed>>
     */
    public function getAll(string $statusFilter = null): array
    {
        $all = get_option(self::OPTION_KEY, []);
        if (!is_array($all)) {
            return [];
        }

        if ($statusFilter === null) {
            return array_values($all);
        }

        return array_values(array_filter($all, static fn(array $item): bool => ($item['status'] ?? self::STATUS_PENDING) === $statusFilter));
    }

    /**
     * Update the status of a specific unknown resource.
     *
     * @param string $id
     * @param string $status
     * @param array<string, mixed> $extra
     * @return bool
     */
    public function updateStatus(string $id, string $status, array $extra = []): bool
    {
        $all = get_option(self::OPTION_KEY, []);
        if (!is_array($all) || !isset($all[$id])) {
            return false;
        }

        $all[$id]['status'] = $status;
        if (isset($extra['assigned_service'])) {
            $all[$id]['assigned_service'] = sanitize_text_field((string) $extra['assigned_service']);
        }
        if (isset($extra['notes'])) {
            $all[$id]['notes'] = sanitize_textarea_field((string) $extra['notes']);
        }

        return update_option(self::OPTION_KEY, $all, false);
    }

    /**
     * Delete an unknown resource record by ID.
     */
    public function delete(string $id): bool
    {
        $all = get_option(self::OPTION_KEY, []);
        if (!is_array($all) || !isset($all[$id])) {
            return false;
        }

        unset($all[$id]);
        return update_option(self::OPTION_KEY, $all, false);
    }

    /**
     * Clear all recorded unknown resources.
     */
    public function clearAll(): bool
    {
        return delete_option(self::OPTION_KEY);
    }

    /**
     * Save or merge a single unknown resource record.
     *
     * @param array<string, mixed> $resource
     */
    private function saveResource(array $resource): void
    {
        $all = get_option(self::OPTION_KEY, []);
        if (!is_array($all)) {
            $all = [];
        }

        $id = $resource['id'];

        if (isset($all[$id])) {
            // Preserve user-configured status and update last seen
            $all[$id]['last_seen_at'] = time();
            if (empty($all[$id]['first_found_url']) && !empty($resource['first_found_url'])) {
                $all[$id]['first_found_url'] = $resource['first_found_url'];
            }
        } else {
            $all[$id] = $resource;
        }

        update_option(self::OPTION_KEY, $all, false);
    }

    /**
     * Checks if a domain belongs to a registered service definition.
     */
    private function isKnownServiceDomain(string $domain): bool
    {
        $allServices = ServiceRegistry::getAll();
        $domainLower = strtolower($domain);

        foreach ($allServices as $svc) {
            foreach ($svc->domains as $svcDomain) {
                $svcDomainLower = strtolower($svcDomain);
                if ($domainLower === $svcDomainLower || str_ends_with($domainLower, '.' . $svcDomainLower)) {
                    return true;
                }
            }
        }

        return false;
    }
}
