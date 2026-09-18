<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Admin\Pages;

use Tuedion\CookieConsent\Settings\Repository;
use Tuedion\CookieConsent\Settings\Schema;

if (!defined('ABSPATH')) {
    exit;
}

final class DashboardPage
{
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'tuedion-cookie'));
        }

        $settings   = Repository::getSettings();
        $status     = Repository::getStatus();
        $revision   = Repository::getRevision();
        $categories = $settings['categories'] ?? [];
        $services   = $settings['services'] ?? [];

        $statusLabels = [
            Schema::STATUS_ENABLED  => __('Active (Publish)', 'tuedion-cookie'),
            Schema::STATUS_DRAFT    => __('Draft (Preview Only)', 'tuedion-cookie'),
            Schema::STATUS_DISABLED => __('Disabled', 'tuedion-cookie'),
        ];
        $currentStatusLabel = $statusLabels[$status] ?? ucfirst($status);
        ?>
        <div class="wrap tdcc-admin-wrap">
            <header class="tdcc-header">
                <div class="tdcc-header-title">
                    <h1><?php echo esc_html__('Tuedion Cookie — Dashboard', 'tuedion-cookie'); ?></h1>
                    <span class="tdcc-badge tdcc-badge-<?php echo esc_attr($status); ?>">
                        <?php echo esc_html($currentStatusLabel); ?>
                    </span>
                </div>
                <div class="tdcc-header-actions">
                    <span class="tdcc-version-tag">
                        <?php
                        /* translators: %s: Plugin version */
                        echo esc_html(sprintf(__('v%s', 'tuedion-cookie'), TUEDION_COOKIE_VERSION));
                        ?>
                    </span>
                </div>
            </header>

            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice display trigger.
            if (isset($_GET['wizard_completed'])):
            ?>
                <div class="notice notice-success is-dismissible tdcc-notice">
                    <p><?php echo esc_html__('Setup wizard completed successfully! Your settings are saved. You can inspect the experience below.', 'tuedion-cookie'); ?></p>
                </div>
            <?php endif; ?>

            <!-- Quick Wizard / Launch Banner -->
            <div class="tdcc-card tdcc-banner-card">
                <div class="tdcc-banner-content">
                    <h2><?php echo esc_html__('Interactive Experience & Live Preview', 'tuedion-cookie'); ?></h2>
                    <p>
                        <?php echo esc_html__('Configure your consent banner, customize preferences center modal, and test responsive viewports (Desktop, Tablet, Mobile) in an isolated sandbox before publishing to visitors.', 'tuedion-cookie'); ?>
                    </p>
                    <div class="tdcc-banner-buttons">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=tuedion-cookie-experience')); ?>" class="button button-primary button-hero">
                            <?php echo esc_html__('Launch Experience & Live Editor &rarr;', 'tuedion-cookie'); ?>
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=tuedion-cookie-wizard')); ?>" class="button button-secondary">
                            <?php echo esc_html__('Re-run Setup Wizard', 'tuedion-cookie'); ?>
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=tuedion-cookie-categories')); ?>" class="button button-secondary">
                            <?php echo esc_html__('Manage Categories & Services', 'tuedion-cookie'); ?>
                        </a>
                    </div>
                </div>
            </div>

            <main class="tdcc-dashboard-grid">
                <div class="tdcc-card">
                    <h2><?php echo esc_html__('Consent Engine Status', 'tuedion-cookie'); ?></h2>
                    <p class="tdcc-card-value"><?php echo esc_html($currentStatusLabel); ?></p>
                    <p class="tdcc-card-desc">
                        <?php echo esc_html__('Controls whether the consent banner and script management are active on the public site.', 'tuedion-cookie'); ?>
                    </p>
                </div>

                <div class="tdcc-card">
                    <h2><?php echo esc_html__('Consent Revision', 'tuedion-cookie'); ?></h2>
                    <p class="tdcc-card-value"><?php echo esc_html((string) $revision); ?></p>
                    <p class="tdcc-card-desc">
                        <?php echo esc_html__('Incrementing revision automatically re-prompts visitors when privacy terms or categories change.', 'tuedion-cookie'); ?>
                    </p>
                </div>

                <div class="tdcc-card">
                    <h2><?php echo esc_html__('Categories', 'tuedion-cookie'); ?></h2>
                    <p class="tdcc-card-value"><?php echo esc_html((string) count($categories)); ?></p>
                    <p class="tdcc-card-desc">
                        <?php echo esc_html__('Configured consent categories (e.g. Necessary, Analytics, Marketing).', 'tuedion-cookie'); ?>
                    </p>
                </div>

                <div class="tdcc-card">
                    <h2><?php echo esc_html__('Managed Services', 'tuedion-cookie'); ?></h2>
                    <p class="tdcc-card-value"><?php echo esc_html((string) count($services)); ?></p>
                    <p class="tdcc-card-desc">
                        <?php echo esc_html__('Granular tracking services attached to categories.', 'tuedion-cookie'); ?>
                    </p>
                </div>
            </main>

            <!-- Compliance Shortcodes & Policy Integration Banner -->
            <section class="tdcc-card">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:12px;">
                    <div>
                        <h2 style="margin:0;"><?php echo esc_html__('Compliance Page Shortcodes', 'tuedion-cookie'); ?></h2>
                        <p class="description" style="margin:4px 0 0 0;">
                            <?php echo esc_html__('Paste these shortcodes into your Privacy Policy, Cookie Policy, or footer to display dynamic declarations or consent controls.', 'tuedion-cookie'); ?>
                        </p>
                    </div>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=tuedion-cookie-settings#tdcc-shortcodes-card')); ?>" class="button button-secondary">
                        <?php echo esc_html__('View Full Guide & Parameters', 'tuedion-cookie'); ?> &rarr;
                    </a>
                </div>

                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:12px;">
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:12px; display:flex; justify-content:space-between; align-items:center; gap:8px;">
                        <div>
                            <strong style="font-size:13px; color:#0f172a; display:block;"><?php echo esc_html__('Full Declaration Table', 'tuedion-cookie'); ?></strong>
                            <code style="font-size:12px; color:#2563eb;">[tuedion_cookie_declaration]</code>
                        </div>
                        <button type="button" class="button button-small tdcc-copy-shortcode-btn" data-code="[tuedion_cookie_declaration]" data-copied-text="<?php echo esc_attr__('Copied!', 'tuedion-cookie'); ?>">
                            <?php echo esc_html__('Copy', 'tuedion-cookie'); ?>
                        </button>
                    </div>

                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:12px; display:flex; justify-content:space-between; align-items:center; gap:8px;">
                        <div>
                            <strong style="font-size:13px; color:#0f172a; display:block;"><?php echo esc_html__('Category Table', 'tuedion-cookie'); ?></strong>
                            <code style="font-size:12px; color:#2563eb;">[tuedion_cookie_table category="analytics"]</code>
                        </div>
                        <button type="button" class="button button-small tdcc-copy-shortcode-btn" data-code='[tuedion_cookie_table category="analytics"]' data-copied-text="<?php echo esc_attr__('Copied!', 'tuedion-cookie'); ?>">
                            <?php echo esc_html__('Copy', 'tuedion-cookie'); ?>
                        </button>
                    </div>

                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:12px; display:flex; justify-content:space-between; align-items:center; gap:8px;">
                        <div>
                            <strong style="font-size:13px; color:#0f172a; display:block;"><?php echo esc_html__('Preferences Button', 'tuedion-cookie'); ?></strong>
                            <code style="font-size:12px; color:#2563eb;">[tuedion_cookie_policy_link]</code>
                        </div>
                        <button type="button" class="button button-small tdcc-copy-shortcode-btn" data-code="[tuedion_cookie_policy_link]" data-copied-text="<?php echo esc_attr__('Copied!', 'tuedion-cookie'); ?>">
                            <?php echo esc_html__('Copy', 'tuedion-cookie'); ?>
                        </button>
                    </div>
                </div>
            </section>

            <!-- Health & Readiness Checks -->
            <section class="tdcc-card">
                <h2><?php echo esc_html__('System Health & Compliance Readiness', 'tuedion-cookie'); ?></h2>
                <table class="widefat striped" role="presentation">
                    <tbody>
                        <tr>
                            <td><span class="dashicons dashicons-yes-alt tdcc-icon-success"></span> <strong><?php echo esc_html__('Bundled Engine', 'tuedion-cookie'); ?></strong></td>
                            <td>Orest Bida Vanilla CookieConsent 3.1.0 (Local package verified)</td>
                        </tr>
                        <tr>
                            <td><span class="dashicons dashicons-yes-alt tdcc-icon-success"></span> <strong><?php echo esc_html__('Strictly Necessary Category', 'tuedion-cookie'); ?></strong></td>
                            <td><?php echo esc_html__('Configured, locked to read-only, cannot be unselected by visitors.', 'tuedion-cookie'); ?></td>
                        </tr>
                        <tr>
                            <td><span class="dashicons dashicons-yes-alt tdcc-icon-success"></span> <strong><?php echo esc_html__('Event Bridge & Dual Dispatch', 'tuedion-cookie'); ?></strong></td>
                            <td><?php echo esc_html__('Active (Native CustomEvent + jQuery trigger for maximum compatibility)', 'tuedion-cookie'); ?></td>
                        </tr>
                        <tr>
                            <td><span class="dashicons dashicons-yes-alt tdcc-icon-success"></span> <strong><?php echo esc_html__('Language Resolver', 'tuedion-cookie'); ?></strong></td>
                            <td>
                                <?php
                                /* translators: %s: Current site language code */
                                echo esc_html(sprintf(__('Current locale: %s (WPML, Polylang and Core hooks active)', 'tuedion-cookie'), \Tuedion\CookieConsent\Consent\LanguageResolver::getCurrentLanguage()));
                                ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
        <?php
    }
}
