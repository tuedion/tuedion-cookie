<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Core;

use Tuedion\CookieConsent\Settings\Schema;
use Tuedion\CookieConsent\Settings\Repository;

if (!defined('ABSPATH')) {
    exit;
}

final class MigrationRunner
{
    /**
     * Initial installation runner.
     */
    public static function install(): void
    {
        $currentVersion = get_option(Schema::SCHEMA_VERSION_OPTION);

        \Tuedion\CookieConsent\Logs\ConsentLogTable::install();

        if ($currentVersion === false) {
            Repository::initializeDefaults();
            add_option(Schema::SCHEMA_VERSION_OPTION, TUEDION_COOKIE_SCHEMA_VERSION, '', 'no');
            add_option('tuedion_cookie_activated_at', current_time('mysql'), '', 'no');
        } else {
            self::checkAndMigrate();
        }
    }

    /**
     * Check current installed version against code schema version and migrate if needed.
     */
    public static function checkAndMigrate(): void
    {
        $installedVersion = get_option(Schema::SCHEMA_VERSION_OPTION, '0.0.0');

        if (version_compare((string) $installedVersion, TUEDION_COOKIE_SCHEMA_VERSION, '<')) {
            self::runMigrations((string) $installedVersion, TUEDION_COOKIE_SCHEMA_VERSION);
            update_option(Schema::SCHEMA_VERSION_OPTION, TUEDION_COOKIE_SCHEMA_VERSION);
        }
    }

    /**
     * Sequential migration executor.
     */
    private static function runMigrations(string $fromVersion, string $toVersion): void
    {
        if (version_compare($fromVersion, '1.0.1', '<')) {
            $settings = Repository::getSettings();
            if (($settings['languages']['auto_detect'] ?? '') === 'browser') {
                $settings['languages']['auto_detect'] = 'document';
                Repository::updateSettings($settings);
            }
        }
    }
}
