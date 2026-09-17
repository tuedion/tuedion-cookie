<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Admin;

use Tuedion\CookieConsent\Admin\Pages\DashboardPage;
use Tuedion\CookieConsent\Admin\Pages\ExperiencePage;
use Tuedion\CookieConsent\Admin\Pages\CategoriesPage;
use Tuedion\CookieConsent\Admin\Pages\SettingsPage;
use Tuedion\CookieConsent\Admin\Pages\DiagnosticsPage;
use Tuedion\CookieConsent\Admin\Pages\AboutPage;
use Tuedion\CookieConsent\Admin\Pages\ConsentLogsPage;
use Tuedion\CookieConsent\Admin\Pages\WizardPage;

if (!defined('ABSPATH')) {
    exit;
}

final class Menu
{
    public const MENU_SLUG = 'tuedion-cookie';
    public const CAPABILITY = 'manage_options';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addAdminMenu']);

        // Register clean admin-post export handlers
        add_action('admin_post_tdcc_export_logs_csv', [ConsentLogsPage::class, 'handleExportPost']);
        add_action('admin_post_tdcc_export_translations', [SettingsPage::class, 'handleExportPost']);
        add_action('admin_post_tdcc_export_support_report', [DiagnosticsPage::class, 'handleExportPost']);
    }

    public static function addAdminMenu(): void
    {
        // Top-level menu
        add_menu_page(
            __('Tuedion Cookie', 'tuedion-cookie'),
            __('Tuedion Cookie', 'tuedion-cookie'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [DashboardPage::class, 'render'],
            'dashicons-shield-alt',
            80
        );

        // Submenu: Dashboard
        add_submenu_page(
            self::MENU_SLUG,
            __('Dashboard — Tuedion Cookie', 'tuedion-cookie'),
            __('Dashboard', 'tuedion-cookie'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [DashboardPage::class, 'render']
        );

        // Submenu: Experience & Live Editor
        add_submenu_page(
            self::MENU_SLUG,
            __('Experience & Preview — Tuedion Cookie', 'tuedion-cookie'),
            __('Experience & Preview', 'tuedion-cookie'),
            self::CAPABILITY,
            'tuedion-cookie-experience',
            [ExperiencePage::class, 'render']
        );

        // Submenu: Categories & Services
        add_submenu_page(
            self::MENU_SLUG,
            __('Categories & Services — Tuedion Cookie', 'tuedion-cookie'),
            __('Categories & Services', 'tuedion-cookie'),
            self::CAPABILITY,
            'tuedion-cookie-categories',
            [CategoriesPage::class, 'render']
        );

        // Submenu: Settings
        add_submenu_page(
            self::MENU_SLUG,
            __('Settings — Tuedion Cookie', 'tuedion-cookie'),
            __('Settings', 'tuedion-cookie'),
            self::CAPABILITY,
            'tuedion-cookie-settings',
            [SettingsPage::class, 'render']
        );

        // Submenu: Consent Logs
        add_submenu_page(
            self::MENU_SLUG,
            __('Consent Logs — Tuedion Cookie', 'tuedion-cookie'),
            __('Consent Logs', 'tuedion-cookie'),
            self::CAPABILITY,
            'tuedion-cookie-logs',
            [ConsentLogsPage::class, 'render']
        );

        // Submenu: Diagnostics
        add_submenu_page(
            self::MENU_SLUG,
            __('Diagnostics — Tuedion Cookie', 'tuedion-cookie'),
            __('Diagnostics', 'tuedion-cookie'),
            self::CAPABILITY,
            'tuedion-cookie-diagnostics',
            [DiagnosticsPage::class, 'render']
        );

        // Submenu: About & Licenses
        add_submenu_page(
            self::MENU_SLUG,
            __('About & Licenses — Tuedion Cookie', 'tuedion-cookie'),
            __('About & Licenses', 'tuedion-cookie'),
            self::CAPABILITY,
            'tuedion-cookie-about',
            [AboutPage::class, 'render']
        );

        // Hidden Submenu: Quick Setup Wizard
        add_submenu_page(
            null,
            __('Setup Wizard — Tuedion Cookie', 'tuedion-cookie'),
            __('Setup Wizard', 'tuedion-cookie'),
            self::CAPABILITY,
            'tuedion-cookie-wizard',
            [WizardPage::class, 'render']
        );
    }
}
