<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Core;

if (!defined('ABSPATH')) {
    exit;
}

final class Autoloader
{
    private const PREFIX = 'Tuedion\\CookieConsent\\';

    public static function register(): void
    {
        spl_autoload_register([self::class, 'autoload']);
    }

    public static function autoload(string $class): void
    {
        if (!str_starts_with($class, self::PREFIX)) {
            return;
        }

        $relativeClass = substr($class, strlen(self::PREFIX));
        $file = TUEDION_COOKIE_PATH . 'includes/' . str_replace('\\', '/', $relativeClass) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
}
