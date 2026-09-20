<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Core;

if (!defined('ABSPATH')) {
    exit;
}

final class Activator
{
    public const REDIRECT_TRANSIENT = 'tuedion_cookie_activation_redirect';

    public static function activate(bool $network_wide = false): void
    {
        if (!Requirements::check()) {
            return;
        }

        if (is_multisite() && $network_wide) {
            $blogIds = get_sites(['fields' => 'ids']);
            $originalBlogId = get_current_blog_id();

            if (is_array($blogIds)) {
                foreach ($blogIds as $bId) {
                    switch_to_blog((int) $bId);
                    MigrationRunner::install();
                }
            }

            switch_to_blog($originalBlogId);
        } else {
            MigrationRunner::install();

            // Queue a transient for smooth onboarding redirect to setup wizard
            set_transient(self::REDIRECT_TRANSIENT, true, 60);
        }
    }

    /**
     * Handle site initialization on Multisite network when a new subsite is created.
     *
     * @param mixed $newSite
     * @param array<string, mixed> $args
     */
    public static function onNewSiteCreated(mixed $newSite, array $args = []): void
    {
        if (!is_object($newSite) || !isset($newSite->blog_id)) {
            return;
        }

        switch_to_blog((int) $newSite->blog_id);
        MigrationRunner::install();
        restore_current_blog();
    }

    /**
     * Redirect to setup wizard immediately after standard single-plugin activation.
     * Guarded against redirect loops, multisite network activation, bulk activation, AJAX, and CLI.
     */
    public static function handleActivationRedirect(): void
    {
        if (!get_transient(self::REDIRECT_TRANSIENT)) {
            return;
        }

        // Avoid loop if already on wizard page
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check for activation redirect.
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        if ($page === 'tuedion-cookie-wizard') {
            delete_transient(self::REDIRECT_TRANSIENT);
            return;
        }

        // Do not redirect on multisite network activation or bulk activation
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check for activation redirect.
        if (is_network_admin() || isset($_GET['activate-multi'])) {
            delete_transient(self::REDIRECT_TRANSIENT);
            return;
        }

        // Do not redirect during AJAX, REST, or WP-CLI/Cron requests
        if (wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        // Only redirect administrators
        if (!current_user_can('manage_options')) {
            delete_transient(self::REDIRECT_TRANSIENT);
            return;
        }

        delete_transient(self::REDIRECT_TRANSIENT);

        $wizardUrl = admin_url('admin.php?page=tuedion-cookie-wizard');

        if (!headers_sent()) {
            wp_safe_redirect($wizardUrl);
            exit;
        }
    }
}

