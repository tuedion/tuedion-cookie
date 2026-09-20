<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Database;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Central repository managing the local Open Cookie Database and cached index.
 */
final class CookieDatabase
{
    public const TRANSIENT_KEY = 'tdcc_cookie_db_index_v140';

    /**
     * @var array{exact: array<string, array<string, mixed>>, wildcard: list<array<string, mixed>>, metadata: array<string, mixed>}|null
     */
    private static ?array $data = null;

    /**
     * Get the active database instance, loading from memory cache, transient, or disk.
     *
     * @return array{exact: array<string, array<string, mixed>>, wildcard: list<array<string, mixed>>, metadata: array<string, mixed>}
     */
    public static function getData(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $cached = get_transient(self::TRANSIENT_KEY);
        if (is_array($cached) && !empty($cached['exact'])) {
            self::$data = $cached;
            return self::$data;
        }

        $jsonPath = TUEDION_COOKIE_PATH . 'data/cookies/open-cookie-database.json';
        self::$data = CookieDatabaseLoader::load($jsonPath);

        // Cache in transient for 24 hours (or until manual update/cache clear)
        set_transient(self::TRANSIENT_KEY, self::$data, DAY_IN_SECONDS);

        return self::$data;
    }

    /**
     * Find cookie metadata by name.
     *
     * @param string $cookieName
     * @return array<string, mixed>|null
     */
    public static function find(string $cookieName): ?array
    {
        $db = self::getData();
        return CookieMatcher::match($cookieName, $db['exact'], $db['wildcard']);
    }

    /**
     * Total count of indexed cookie patterns.
     *
     * @return int
     */
    public static function count(): int
    {
        $db = self::getData();
        return count($db['exact']) + count($db['wildcard']);
    }

    /**
     * Get dataset metadata (version, records count, source).
     *
     * @return array<string, mixed>
     */
    public static function getMetadata(): array
    {
        $db = self::getData();
        return $db['metadata'] ?? [];
    }

    /**
     * Invalidate in-memory and transient caches.
     */
    public static function clearCache(): void
    {
        self::$data = null;
        delete_transient(self::TRANSIENT_KEY);
    }
}
