<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Admin\Pages;

use Tuedion\CookieConsent\Settings\Repository;
use Tuedion\CookieConsent\Settings\Schema;
use Tuedion\CookieConsent\Settings\Compiler;

if (!defined('ABSPATH')) {
    exit;
}

final class WizardPage
{
    /**
     * Process wizard form submission before headers are sent.
     */
    public static function handleSubmission(): void
    {
        if (!isset($_POST['tuedion_complete_wizard'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        check_admin_referer('tuedion_wizard_action', 'tuedion_wizard_nonce');

        $settings = Repository::getSettings();

        if (isset($_POST['tuedion_reset_categories_wizard'])) {
            $defaults = \Tuedion\CookieConsent\Settings\Defaults::get();
            $settings['categories'] = $defaults['categories'];
            Repository::updateSettings($settings);
            Compiler::clearCache();
            // Refresh to same page to show reset
            if (!headers_sent()) {
                wp_safe_redirect(admin_url('admin.php?page=tuedion-cookie-wizard'));
                exit;
            }
            echo '<script>window.location.href = ' . wp_json_encode(admin_url('admin.php?page=tuedion-cookie-wizard')) . ';</script>';
            exit;
        }

        $layout = sanitize_text_field((string) ($_POST['wizard_layout'] ?? 'box'));
        $settings['banner']['layout'] = $layout;

        $action = sanitize_text_field((string) ($_POST['wizard_action'] ?? 'draft'));
        $settings['status'] = ($action === 'publish') ? Schema::STATUS_ENABLED : Schema::STATUS_DRAFT;

        Repository::updateSettings($settings);
        Compiler::clearCache();

        $redirectUrl = admin_url('admin.php?page=tuedion-cookie&wizard_completed=1');

        if (!headers_sent()) {
            wp_safe_redirect($redirectUrl);
            exit;
        }

        echo '<script>window.location.href = ' . wp_json_encode($redirectUrl) . ';</script>';
        exit;
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'tuedion-cookie'));
        }

        // Fallback check if submission reached render stage
        self::handleSubmission();
        ?>
        <div class="wrap tdcc-admin-wrap tdcc-wizard-wrap">
            <header class="tdcc-header">
                <h1><?php echo esc_html__('Tuedion Cookie — Quick Setup Wizard', 'tuedion-cookie'); ?></h1>
            </header>

            <div class="tdcc-card tdcc-wizard-container">
                <nav class="tdcc-wizard-steps">
                    <span class="tdcc-step is-active" data-step="1">1. Welcome</span>
                    <span class="tdcc-step" data-step="2">2. Profile</span>
                    <span class="tdcc-step" data-step="3">3. Categories</span>
                    <span class="tdcc-step" data-step="4">4. Layout</span>
                    <span class="tdcc-step" data-step="5">5. Finish</span>
                </nav>

                <form method="post" action="" id="tdcc-wizard-form">
                    <?php wp_nonce_field('tuedion_wizard_action', 'tuedion_wizard_nonce'); ?>
                    <input type="hidden" name="tuedion_complete_wizard" value="1">

                    <!-- Step 1: Welcome -->
                    <div class="tdcc-wizard-panel is-active" data-panel="1">
                        <h2><?php echo esc_html__('Welcome to Tuedion Cookie', 'tuedion-cookie'); ?></h2>
                        <p class="tdcc-wizard-intro">
                            <?php echo esc_html__('This wizard configures your site for professional consent management in 2 minutes. We will set up starter cookie categories, banner appearance, and compliance behavior.', 'tuedion-cookie'); ?>
                        </p>
                        <div class="tdcc-highlight-box">
                            <strong><?php echo esc_html__('Built on Orest Bida Vanilla CookieConsent (v3.1.0):', 'tuedion-cookie'); ?></strong>
                            <p><?php echo esc_html__('High performance, zero remote tracking dependencies, accessible and GDPR/ePrivacy ready.', 'tuedion-cookie'); ?></p>
                        </div>
                        <div class="tdcc-wizard-actions">
                            <button type="button" class="button button-primary tdcc-wizard-next"><?php echo esc_html__('Get Started &rarr;', 'tuedion-cookie'); ?></button>
                        </div>
                    </div>

                    <!-- Step 2: Behavior Profile -->
                    <div class="tdcc-wizard-panel" data-panel="2">
                        <h2><?php echo esc_html__('Select Consent Behavior Profile', 'tuedion-cookie'); ?></h2>
                        <div class="tdcc-profile-options">
                            <label class="tdcc-profile-card">
                                <input type="radio" name="wizard_profile" value="strict_opt_in" checked>
                                <strong><?php echo esc_html__('Strict Opt-in (EU / GDPR Standard)', 'tuedion-cookie'); ?></strong>
                                <p><?php echo esc_html__('No non-essential cookies or tracking scripts run until the visitor explicitly gives consent.', 'tuedion-cookie'); ?></p>
                            </label>
                            <label class="tdcc-profile-card">
                                <input type="radio" name="wizard_profile" value="granular">
                                <strong><?php echo esc_html__('Granular Category Choice', 'tuedion-cookie'); ?></strong>
                                <p><?php echo esc_html__('Visitors can toggle Analytics, Marketing, and Functionality cookies individually.', 'tuedion-cookie'); ?></p>
                            </label>
                        </div>
                        <div class="tdcc-wizard-actions">
                            <button type="button" class="button button-secondary tdcc-wizard-prev">&larr; <?php echo esc_html__('Back', 'tuedion-cookie'); ?></button>
                            <button type="button" class="button button-primary tdcc-wizard-next"><?php echo esc_html__('Next &rarr;', 'tuedion-cookie'); ?></button>
                        </div>
                    </div>

                    <!-- Step 3: Categories -->
                    <div class="tdcc-wizard-panel" data-panel="3">
                        <h2><?php echo esc_html__('Review Starter Categories', 'tuedion-cookie'); ?></h2>
                        <ul class="tdcc-starter-list">
                            <li><strong><?php echo esc_html__('Strictly Necessary:', 'tuedion-cookie'); ?></strong> <?php echo esc_html__('Always enabled, required for page functioning (read-only).', 'tuedion-cookie'); ?></li>
                            <li><strong><?php echo esc_html__('Analytics & Performance:', 'tuedion-cookie'); ?></strong> <?php echo esc_html__('Measures traffic and site usage (GA4, Matomo, etc.).', 'tuedion-cookie'); ?></li>
                            <li><strong><?php echo esc_html__('Marketing & Advertising:', 'tuedion-cookie'); ?></strong> <?php echo esc_html__('Personalized targeting (Meta Pixel, Google Ads, TikTok).', 'tuedion-cookie'); ?></li>
                            <li><strong><?php echo esc_html__('Functionality:', 'tuedion-cookie'); ?></strong> <?php echo esc_html__('User language, video embeds, and preferences.', 'tuedion-cookie'); ?></li>
                        </ul>
                        <div class="tdcc-wizard-actions">
                            <button type="submit" name="tuedion_reset_categories_wizard" value="1" class="button button-link-delete" style="float:left;" onclick="return confirm('<?php echo esc_attr__('Reset categories to defaults?', 'tuedion-cookie'); ?>');"><?php echo esc_html__('Reset Categories', 'tuedion-cookie'); ?></button>
                            <button type="button" class="button button-secondary tdcc-wizard-prev">&larr; <?php echo esc_html__('Back', 'tuedion-cookie'); ?></button>
                            <button type="button" class="button button-primary tdcc-wizard-next"><?php echo esc_html__('Next &rarr;', 'tuedion-cookie'); ?></button>
                        </div>
                    </div>

                    <!-- Step 4: Layout -->
                    <div class="tdcc-wizard-panel" data-panel="4">
                        <h2><?php echo esc_html__('Choose Banner Layout', 'tuedion-cookie'); ?></h2>
                        <div class="tdcc-layout-selector">
                            <label class="tdcc-layout-card">
                                <input type="radio" name="wizard_layout" value="box" checked>
                                <strong><?php echo esc_html__('Classic Box (Recommended)', 'tuedion-cookie'); ?></strong>
                                <p><?php echo esc_html__('Bottom corner dialog. Unobtrusive and clear.', 'tuedion-cookie'); ?></p>
                            </label>
                            <label class="tdcc-layout-card">
                                <input type="radio" name="wizard_layout" value="bar">
                                <strong><?php echo esc_html__('Full Bar', 'tuedion-cookie'); ?></strong>
                                <p><?php echo esc_html__('Spans across the bottom or top of the viewport.', 'tuedion-cookie'); ?></p>
                            </label>
                            <label class="tdcc-layout-card">
                                <input type="radio" name="wizard_layout" value="cloud">
                                <strong><?php echo esc_html__('Floating Cloud', 'tuedion-cookie'); ?></strong>
                                <p><?php echo esc_html__('Centered floating bubble with modern rounded accents.', 'tuedion-cookie'); ?></p>
                            </label>
                        </div>
                        <div class="tdcc-wizard-actions">
                            <button type="button" class="button button-secondary tdcc-wizard-prev">&larr; <?php echo esc_html__('Back', 'tuedion-cookie'); ?></button>
                            <button type="button" class="button button-primary tdcc-wizard-next"><?php echo esc_html__('Next &rarr;', 'tuedion-cookie'); ?></button>
                        </div>
                    </div>

                    <!-- Step 5: Finish -->
                    <div class="tdcc-wizard-panel" data-panel="5">
                        <h2><?php echo esc_html__('Ready to Launch!', 'tuedion-cookie'); ?></h2>
                        <p><?php echo esc_html__('Your initial consent management configuration is complete. You can either publish it to visitors right now, or save it as a Draft to inspect it first in the Live Preview editor.', 'tuedion-cookie'); ?></p>
                        <div class="tdcc-wizard-actions">
                            <button type="button" class="button button-secondary tdcc-wizard-prev">&larr; <?php echo esc_html__('Back', 'tuedion-cookie'); ?></button>
                            <button type="submit" name="wizard_action" value="draft" class="button button-secondary"><?php echo esc_html__('Save as Draft (Inspect First)', 'tuedion-cookie'); ?></button>
                            <button type="submit" name="wizard_action" value="publish" class="button button-primary"><?php echo esc_html__('Publish to Public Site &check;', 'tuedion-cookie'); ?></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
}
