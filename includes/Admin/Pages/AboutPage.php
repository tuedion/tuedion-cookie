<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Admin\Pages;

if (!defined('ABSPATH')) {
    exit;
}

final class AboutPage
{
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'tuedion-cookie'));
        }
        ?>
        <div class="wrap tdcc-admin-wrap">
            <header class="tdcc-header">
                <h1><?php echo esc_html__('About & Licenses — Tuedion Cookie', 'tuedion-cookie'); ?></h1>
            </header>

            <div class="tdcc-card">
                <div style="margin-bottom: 1.5rem;">
                    <img src="<?php echo esc_url(TUEDION_COOKIE_URL . 'assets/admin/images/tuedion-logo.svg'); ?>" alt="Tuedion" style="height: 48px; width: auto;">
                </div>
                <h2><?php echo esc_html__('About Tuedion Cookie', 'tuedion-cookie'); ?></h2>
                <p>
                    <?php echo esc_html__('Tuedion Cookie is a professional WordPress integration of Orest Bida’s high-performance vanilla-cookieconsent library, developed by Tuedion to give publishers and site administrators full control over visitor consent, privacy triggers, script blocking, and multi-language support.', 'tuedion-cookie'); ?>
                </p>
                <p>
                    <a href="https://tuedion.com" target="_blank" rel="noopener noreferrer" class="button button-secondary">
                        <?php echo esc_html__('Visit Tuedion.com', 'tuedion-cookie'); ?>
                    </a>
                </p>
            </div>

            <div class="tdcc-card">
                <h2><?php echo esc_html__('Open-Source Attribution & Upstream Library', 'tuedion-cookie'); ?></h2>
                <p>
                    <?php echo esc_html__('This plugin integrates vanilla-cookieconsent v3.1.0 created by Orest Bida.', 'tuedion-cookie'); ?>
                </p>
                <table class="widefat striped" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Upstream Project', 'tuedion-cookie'); ?></th>
                            <td><a href="https://github.com/orestbida/cookieconsent" target="_blank" rel="noopener noreferrer">orestbida/cookieconsent</a></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Author', 'tuedion-cookie'); ?></th>
                            <td>Orest Bida</td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Bundled Version', 'tuedion-cookie'); ?></th>
                            <td><code>3.1.0</code></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Upstream License', 'tuedion-cookie'); ?></th>
                            <td>MIT License</td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Plugin License', 'tuedion-cookie'); ?></th>
                            <td>GPL-2.0-or-later</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="tdcc-card">
                <h2><?php echo esc_html__('Legal Boundaries & Disclaimer', 'tuedion-cookie'); ?></h2>
                <p class="description">
                    <?php echo esc_html__('Tuedion Cookie provides technical consent management tools. It does not provide legal advice or guarantee compliance with privacy laws (such as GDPR, CCPA, or ePrivacy). Proper compliance depends on your cookies, tracking practices, and accurate disclosure policies.', 'tuedion-cookie'); ?>
                </p>
            </div>
        </div>
        <?php
    }
}
