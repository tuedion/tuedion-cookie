<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Core;

use Tuedion\CookieConsent\Admin\Menu;
use Tuedion\CookieConsent\Admin\Assets;
use Tuedion\CookieConsent\Consent\Frontend;
use Tuedion\CookieConsent\Consent\ScriptEnforcer;
use Tuedion\CookieConsent\Consent\IframeEnforcer;
use Tuedion\CookieConsent\Integrations\CacheCompatibility;

if (!defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private static ?self $instance = null;
    private bool $booted = false;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        $this->loadTextDomain();
        MigrationRunner::checkAndMigrate();

        // Register integrations and cache compatibility
        CacheCompatibility::register();

        // Register consent enforcers (script & iframe blocking)
        ScriptEnforcer::register();
        IframeEnforcer::register();

        // Register Google Consent Mode v2 early default injector
        \Tuedion\CookieConsent\Integrations\Google\ConsentModeV2::register();

        // Register consent audit logger and retention cleaner
        \Tuedion\CookieConsent\Logs\ConsentLogger::register();
        \Tuedion\CookieConsent\Logs\LogCleaner::register();
        \Tuedion\CookieConsent\Privacy\PrivacyTools::register();

        // Register ecosystem adapters (WooCommerce, Elementor, Form plugins)
        \Tuedion\CookieConsent\Integrations\Adapters\WooCommerceAdapter::register();
        \Tuedion\CookieConsent\Integrations\Adapters\ElementorAdapter::register();
        \Tuedion\CookieConsent\Integrations\Adapters\FormsAdapter::register();
        \Tuedion\CookieConsent\Integrations\CustomRecipeManager::register();

        // Register diagnostics admin bar debugger and cookie scanner
        \Tuedion\CookieConsent\Diagnostics\AdminBarDebugger::register();
        \Tuedion\CookieConsent\Diagnostics\CookieScanner::register();

        if (is_admin()) {
            Menu::register();
            Assets::register();
            \Tuedion\CookieConsent\Admin\Pages\CategoriesPage::register();

            // Early admin actions before output begins
            add_action('admin_init', [Activator::class, 'handleActivationRedirect']);
            add_action('admin_init', [\Tuedion\CookieConsent\Admin\Pages\WizardPage::class, 'handleSubmission']);
        }

        if (is_multisite()) {
            add_action('wp_initialize_site', [Activator::class, 'onNewSiteCreated'], 10, 2);
        }

        // Register frontend consent loader
        Frontend::register();
    }

    private function loadTextDomain(): void
    {
        add_action('init', static function (): void {
            load_plugin_textdomain(
                'tuedion-cookie',
                false,
                dirname(plugin_basename(TUEDION_COOKIE_FILE)) . '/languages/'
            );
        });
    }
}
