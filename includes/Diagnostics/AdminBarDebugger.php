<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Diagnostics;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WordPress Admin Bar Quick Debugger.
 * Provides immediate consent state inspection, preference trigger, and reset tool
 * directly in the top toolbar for administrators.
 */
final class AdminBarDebugger
{
    public static function register(): void
    {
        add_action('admin_bar_menu', [self::class, 'addAdminBarMenu'], 100);
        add_action('wp_enqueue_scripts', [self::class, 'enqueueResetScript']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueResetScript']);
    }

    /**
     * Add diagnostic nodes to admin bar.
     *
     * @param \WP_Admin_Bar $adminBar
     */
    public static function addAdminBarMenu(\WP_Admin_Bar $adminBar): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $consent = ConsentStateInspector::inspect();
        $statusText = $consent['has_cookie']
            ? sprintf(__('Consent: %s', 'tuedion-cookie'), implode(', ', $consent['accepted_categories']))
            : __('Consent: Not Set / Pending', 'tuedion-cookie');

        $rootId = 'tuedion-cookie-debug';

        // 1. Root Node
        $adminBar->add_node([
            'id'    => $rootId,
            'title' => '<span class="ab-icon dashicons dashicons-shield-alt" style="margin-top:2px;"></span> ' . esc_html($statusText),
            'href'  => admin_url('admin.php?page=tuedion-cookie-diagnostics'),
        ]);

        // 2. Subnode: UUID
        if ($consent['has_cookie'] && !empty($consent['consent_uuid'])) {
            $adminBar->add_node([
                'parent' => $rootId,
                'id'     => $rootId . '-uuid',
                'title'  => sprintf(__('UUID: %s', 'tuedion-cookie'), substr($consent['consent_uuid'], 0, 16) . '...'),
                'href'   => admin_url('admin.php?page=tuedion-cookie-logs&s=' . urlencode($consent['consent_uuid'])),
            ]);
        }

        // 3. Subnode: Revision
        if ($consent['has_cookie']) {
            $adminBar->add_node([
                'parent' => $rootId,
                'id'     => $rootId . '-revision',
                'title'  => sprintf(__('Policy Revision: v%d', 'tuedion-cookie'), $consent['revision']),
                'href'   => admin_url('admin.php?page=tuedion-cookie-diagnostics'),
            ]);
        }

        // 4. Subnode: Open Preferences
        $adminBar->add_node([
            'parent' => $rootId,
            'id'     => $rootId . '-open-pref',
            'title'  => __('Open Preferences Modal', 'tuedion-cookie'),
            'href'   => '#',
            'meta'   => [
                'onclick' => 'if(window.CookieConsent){window.CookieConsent.showPreferences();return false;}',
            ],
        ]);

        // 5. Subnode: Reset Cookie
        $adminBar->add_node([
            'parent' => $rootId,
            'id'     => $rootId . '-reset',
            'title'  => __('Clear Consent Cookie & Reload', 'tuedion-cookie'),
            'href'   => '#',
            'meta'   => [
                'onclick' => 'document.cookie="cc_cookie=; Max-Age=-99999999; path=/;"; window.location.reload(); return false;',
            ],
        ]);

        // 6. Subnode: Go to Diagnostics
        $adminBar->add_node([
            'parent' => $rootId,
            'id'     => $rootId . '-diagnostics',
            'title'  => __('Go to Full Diagnostics & Report &rarr;', 'tuedion-cookie'),
            'href'   => admin_url('admin.php?page=tuedion-cookie-diagnostics'),
        ]);
    }

    /**
     * Enqueue minimal helper script if needed.
     */
    public static function enqueueResetScript(): void
    {
        // Admin bar handles inline clicks safely when admin bar is showing
    }
}
