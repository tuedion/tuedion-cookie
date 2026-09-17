<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Admin\Pages;

use Tuedion\CookieConsent\I18n\LanguagePacks;
use Tuedion\CookieConsent\I18n\TranslationManager;
use Tuedion\CookieConsent\Integrations\Google\GcmMapping;
use Tuedion\CookieConsent\Integrations\Google\SiteKitDetector;
use Tuedion\CookieConsent\Settings\Defaults;
use Tuedion\CookieConsent\Settings\Repository;
use Tuedion\CookieConsent\Settings\Schema;

if (!defined('ABSPATH')) {
    exit;
}

final class SettingsPage
{
    /**
     * Handle admin-post translation export request before HTML output begins.
     */
    public static function handleExportPost(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'tuedion-cookie'));
        }

        $nonce = $_GET['_wpnonce'] ?? $_POST['_wpnonce'] ?? '';
        if (!wp_verify_nonce((string) $nonce, 'tdcc_export_translations_nonce')) {
            wp_die(esc_html__('Security check failed or link expired. Please refresh the page and try again.', 'tuedion-cookie'), 403);
        }

        self::exportTranslations();
    }

    /**
     * Export custom translations as downloadable JSON.
     */
    public static function exportTranslations(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $exportJson = TranslationManager::exportToJson();
        $filename = 'tuedion-cookie-translations-' . gmdate('Y-m-d') . '.json';

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0, no-cache');
        header('Pragma: public');
        header('Content-Length: ' . strlen($exportJson));

        echo $exportJson;
        exit;
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'tuedion-cookie'));
        }

        // Backward-compatible direct fallback
        if (isset($_GET['action']) && $_GET['action'] === 'tdcc_export_translations') {
            $nonce = $_GET['_wpnonce'] ?? $_POST['_wpnonce'] ?? '';
            if (wp_verify_nonce((string) $nonce, 'tdcc_export_translations_nonce')) {
                self::exportTranslations();
            }
        }

        $notice = null;
        $noticeType = 'success';

        // Handle JSON Import
        if (isset($_POST['tuedion_cookie_import_translations']) && check_admin_referer('tuedion_cookie_import_nonce', 'tuedion_cookie_nonce')) {
            $rawJson = wp_unslash($_POST['translation_json'] ?? '');
            $importResult = TranslationManager::importFromJson($rawJson);
            $notice = $importResult['message'];
            $noticeType = $importResult['success'] ? 'success' : 'error';
        }

        // Handle Settings Save
        if (isset($_POST['tuedion_cookie_save']) && check_admin_referer('tuedion_cookie_save_settings', 'tuedion_cookie_nonce')) {
            $settings = Repository::getSettings();

            $newStatus = sanitize_text_field((string) ($_POST['status'] ?? ''));
            if (in_array($newStatus, Schema::ALLOWED_STATUSES, true)) {
                $settings['status'] = $newStatus;
            }

            // Advanced options save
            $settings['advanced']['script_blocking']    = !empty($_POST['script_blocking']);
            $settings['advanced']['iframe_blocking']    = !empty($_POST['iframe_blocking']);
            $settings['advanced']['reload_on_revoke']   = !empty($_POST['reload_on_revoke']);
            $settings['advanced']['clean_on_uninstall'] = !empty($_POST['clean_on_uninstall']);
            $settings['advanced']['debug_mode']         = !empty($_POST['debug_mode']);

            // Legal URLs & Titles save
            $settings['legal']['privacy_title'] = sanitize_text_field(trim((string) ($_POST['legal_privacy_title'] ?? '')));
            $settings['legal']['terms_title']   = sanitize_text_field(trim((string) ($_POST['legal_terms_title'] ?? '')));
            $settings['legal']['privacy_url']   = esc_url_raw(trim((string) ($_POST['legal_privacy_url'] ?? '')));
            $settings['legal']['terms_url']     = esc_url_raw(trim((string) ($_POST['legal_terms_url'] ?? '')));

            $submittedPrivacyTitles = [];
            if (isset($_POST['legal_privacy_titles']) && is_array($_POST['legal_privacy_titles'])) {
                foreach ($_POST['legal_privacy_titles'] as $l => $t) {
                    $clean = sanitize_text_field(trim((string) $t));
                    if ($clean !== '') {
                        $submittedPrivacyTitles[sanitize_key((string) $l)] = $clean;
                    }
                }
            }
            $settings['legal']['privacy_titles'] = $submittedPrivacyTitles;

            $submittedPrivacyUrls = [];
            if (isset($_POST['legal_privacy_urls']) && is_array($_POST['legal_privacy_urls'])) {
                foreach ($_POST['legal_privacy_urls'] as $l => $u) {
                    $clean = esc_url_raw(trim((string) $u));
                    if ($clean !== '') {
                        $submittedPrivacyUrls[sanitize_key((string) $l)] = $clean;
                    }
                }
            }
            $settings['legal']['privacy_urls'] = $submittedPrivacyUrls;

            $submittedTermsTitles = [];
            if (isset($_POST['legal_terms_titles']) && is_array($_POST['legal_terms_titles'])) {
                foreach ($_POST['legal_terms_titles'] as $l => $t) {
                    $clean = sanitize_text_field(trim((string) $t));
                    if ($clean !== '') {
                        $submittedTermsTitles[sanitize_key((string) $l)] = $clean;
                    }
                }
            }
            $settings['legal']['terms_titles'] = $submittedTermsTitles;

            $submittedTermsUrls = [];
            if (isset($_POST['legal_terms_urls']) && is_array($_POST['legal_terms_urls'])) {
                foreach ($_POST['legal_terms_urls'] as $l => $u) {
                    $clean = esc_url_raw(trim((string) $u));
                    if ($clean !== '') {
                        $submittedTermsUrls[sanitize_key((string) $l)] = $clean;
                    }
                }
            }
            $settings['legal']['terms_urls'] = $submittedTermsUrls;

            // Google Consent Mode v2 save
            $settings['gcm']['enabled']            = !empty($_POST['gcm_enabled']);
            $settings['gcm']['wait_for_update']    = max(100, min(5000, (int) ($_POST['gcm_wait_for_update'] ?? 500)));
            $settings['gcm']['ads_data_redaction'] = !empty($_POST['gcm_ads_data_redaction']);
            $settings['gcm']['url_passthrough']   = !empty($_POST['gcm_url_passthrough']);

            if (isset($_POST['gcm_mapping']) && is_array($_POST['gcm_mapping'])) {
                $submittedMapping = [];
                foreach ($_POST['gcm_mapping'] as $sig => $cat) {
                    $submittedMapping[sanitize_key((string) $sig)] = sanitize_key((string) $cat);
                }
                $settings['gcm']['mapping'] = $submittedMapping;
            }

            // Consent Logging save
            $settings['logging']['enabled']        = !empty($_POST['logging_enabled']);
            $settings['logging']['retention_days'] = max(7, min(730, (int) ($_POST['logging_retention_days'] ?? 90)));

            // Scheduled Scanner save
            $settings['scanner']['cron_enabled'] = !empty($_POST['scanner_cron_enabled']);
            $scannerSchedule = (string) ($_POST['scanner_schedule'] ?? 'weekly');
            if (in_array($scannerSchedule, ['daily', 'weekly', 'monthly'], true)) {
                $settings['scanner']['schedule'] = $scannerSchedule;
            }
            $settings['scanner']['alert_email'] = sanitize_email((string) ($_POST['scanner_alert_email'] ?? ''));

            Repository::updateSettings($settings);
            \Tuedion\CookieConsent\Settings\Compiler::clearCache();
            $notice = esc_html__('Settings updated successfully.', 'tuedion-cookie');
        }

        $settings = Repository::getSettings();
        $status   = $settings['status'] ?? Schema::STATUS_DRAFT;

        $advanced = $settings['advanced'] ?? Defaults::get()['advanced'];
        $scriptBlocking   = !empty($advanced['script_blocking']);
        $iframeBlocking   = !empty($advanced['iframe_blocking']);
        $reloadOnRevoke   = !empty($advanced['reload_on_revoke']);
        $cleanOnUninstall = !empty($advanced['clean_on_uninstall']);
        $debugMode        = !empty($advanced['debug_mode']);

        $legal = $settings['legal'] ?? Defaults::get()['legal'];
        $privacyTitle  = $legal['privacy_title'] ?? '';
        $termsTitle    = $legal['terms_title'] ?? '';
        $privacyUrl    = $legal['privacy_url'] ?? '';
        $termsUrl      = $legal['terms_url'] ?? '';
        $privacyTitles = $legal['privacy_titles'] ?? [];
        $termsTitles   = $legal['terms_titles'] ?? [];
        $privacyUrls   = $legal['privacy_urls'] ?? [];
        $termsUrls     = $legal['terms_urls'] ?? [];
        $siteLanguages = \Tuedion\CookieConsent\Consent\LanguageResolver::getActiveSiteLanguages();

        $gcm = $settings['gcm'] ?? Defaults::get()['gcm'];
        $gcmEnabled = !empty($gcm['enabled']);
        $waitForUpdate = (int) ($gcm['wait_for_update'] ?? 500);
        $adsDataRedaction = !empty($gcm['ads_data_redaction']);
        $urlPassthrough = !empty($gcm['url_passthrough']);
        $activeMapping = GcmMapping::getActiveMapping($gcm);

        $logging = $settings['logging'] ?? Defaults::get()['logging'];
        $loggingEnabled = !empty($logging['enabled']);
        $retentionDays = (int) ($logging['retention_days'] ?? 90);

        $scannerConfig = $settings['scanner'] ?? Defaults::get()['scanner'];
        $scannerCronEnabled = !empty($scannerConfig['cron_enabled']);
        $scannerSchedule = (string) ($scannerConfig['schedule'] ?? 'weekly');
        $scannerAlertEmail = (string) ($scannerConfig['alert_email'] ?? '');

        $categories = $settings['categories'] ?? [];
        $siteKitDiagnostics = SiteKitDetector::getDiagnostics();

        $activeLangs = TranslationManager::getActiveLanguages();
        $detectedMultilingualEngine = TranslationManager::getDetectedPluginName();
        $exportNonce = wp_create_nonce('tdcc_export_translations_nonce');
        ?>
        <div class="wrap tdcc-admin-wrap">
            <header class="tdcc-header">
                <h1><?php echo esc_html__('Tuedion Cookie — Settings', 'tuedion-cookie'); ?></h1>
            </header>

            <?php if ($notice !== null): ?>
                <div class="notice notice-<?php echo esc_attr($noticeType); ?> is-dismissible tdcc-notice">
                    <p><?php echo esc_html($notice); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($siteKitDiagnostics['has_duplicate_risk'])): ?>
                <div class="notice notice-warning tdcc-notice">
                    <p><strong><?php echo esc_html__('Google Site Kit Warning:', 'tuedion-cookie'); ?></strong> <?php echo esc_html($siteKitDiagnostics['warning_message']); ?></p>
                </div>
            <?php endif; ?>

            <!-- Quick Navigation Jump Bar -->
            <nav class="tdcc-settings-nav" style="display:flex; flex-wrap:wrap; gap:8px; margin: 0 0 20px 0; padding: 12px 16px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.04);">
                <a href="#tdcc-general-card" class="button button-small"><?php echo esc_html__('General', 'tuedion-cookie'); ?></a>
                <a href="#tdcc-i18n-card" class="button button-small"><?php echo esc_html__('Languages & i18n', 'tuedion-cookie'); ?></a>
                <a href="#tdcc-legal-card" class="button button-small"><?php echo esc_html__('Legal Documents', 'tuedion-cookie'); ?></a>
                <a href="#tdcc-shortcodes-card" class="button button-small" style="background:#fef3c7; border-color:#fcd34d; color:#92400e; font-weight:600;">
                    <span class="dashicons dashicons-shortcode" style="font-size:14px; width:14px; height:14px; line-height:14px; vertical-align:text-top;"></span>
                    <?php echo esc_html__('Shortcodes', 'tuedion-cookie'); ?>
                </a>
                <a href="#tdcc-gcm-card" class="button button-small"><?php echo esc_html__('Google Consent Mode v2', 'tuedion-cookie'); ?></a>
                <a href="#tdcc-logging-card" class="button button-small"><?php echo esc_html__('Consent Records (Audit)', 'tuedion-cookie'); ?></a>
                <a href="#tdcc-scanner-card" class="button button-small" style="background:#eff6ff; border-color:#93c5fd; color:#1d4ed8; font-weight:600;">
                    <span class="dashicons dashicons-search" style="font-size:14px; width:14px; height:14px; line-height:14px; vertical-align:text-top;"></span>
                    <?php echo esc_html__('Scheduled Scanner', 'tuedion-cookie'); ?>
                </a>
                <a href="#tdcc-advanced-card" class="button button-small"><?php echo esc_html__('Advanced & Scripts', 'tuedion-cookie'); ?></a>
            </nav>

            <form method="post" action="" class="tdcc-form">
                <?php wp_nonce_field('tuedion_cookie_save_settings', 'tuedion_cookie_nonce'); ?>

                <!-- General Configuration -->
                <div class="tdcc-card" id="tdcc-general-card">
                    <h2><?php echo esc_html__('General Configuration', 'tuedion-cookie'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="tdcc-status"><?php echo esc_html__('Operation Mode', 'tuedion-cookie'); ?></label>
                                </th>
                                <td>
                                    <select name="status" id="tdcc-status" class="regular-text">
                                        <option value="<?php echo esc_attr(Schema::STATUS_DRAFT); ?>" <?php selected($status, Schema::STATUS_DRAFT); ?>>
                                            <?php echo esc_html__('Draft (Preview only in admin)', 'tuedion-cookie'); ?>
                                        </option>
                                        <option value="<?php echo esc_attr(Schema::STATUS_ENABLED); ?>" <?php selected($status, Schema::STATUS_ENABLED); ?>>
                                            <?php echo esc_html__('Enabled (Active on frontend)', 'tuedion-cookie'); ?>
                                        </option>
                                        <option value="<?php echo esc_attr(Schema::STATUS_DISABLED); ?>" <?php selected($status, Schema::STATUS_DISABLED); ?>>
                                            <?php echo esc_html__('Disabled (Completely halted)', 'tuedion-cookie'); ?>
                                        </option>
                                    </select>
                                    <p class="description">
                                        <?php echo esc_html__('In Draft mode, consent banners and scripts can be safely previewed in admin before publishing.', 'tuedion-cookie'); ?>
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Languages & Localization Status -->
                <div class="tdcc-card" id="tdcc-i18n-card">
                    <h2><?php echo esc_html__('Languages & Internationalization (i18n)', 'tuedion-cookie'); ?></h2>
                    <p class="description">
                        <?php echo esc_html__('Tuedion Cookie includes native support for 9 world languages, automated RTL layout mirroring for Arabic, and seamless WPML/Polylang/TranslatePress synchronization.', 'tuedion-cookie'); ?>
                    </p>

                    <table class="form-table" role="presentation" style="margin-top: 1rem;">
                        <tbody>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Detected Translation Engine', 'tuedion-cookie'); ?></th>
                                <td>
                                    <span class="tdcc-badge" style="background:#e0e7ff;color:#3730a3;font-size:12px;">
                                        <?php echo esc_html($detectedMultilingualEngine); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Active Language Packs', 'tuedion-cookie'); ?></th>
                                <td>
                                    <div style="display:flex; flex-wrap:wrap; gap:8px; margin-top:4px;">
                                        <?php foreach ($activeLangs as $code => $info): ?>
                                            <span class="tdcc-badge" style="display:inline-flex; align-items:center; gap:6px; padding:4px 10px; background:#f1f5f9; border:1px solid #cbd5e1; color:#0f172a;">
                                                <strong><?php echo esc_html(strtoupper($code)); ?></strong>
                                                <span><?php echo esc_html($info['native'] ?? $info['name']); ?></span>
                                                <?php if (($info['dir'] ?? 'ltr') === 'rtl'): ?>
                                                    <em style="color:#2563eb; font-style:normal; font-weight:700;">(RTL)</em>
                                                <?php endif; ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="description" style="margin-top:8px;">
                                        <?php echo esc_html__('Language is automatically resolved at runtime based on current page language, WPML/Polylang language switcher, or browser locale.', 'tuedion-cookie'); ?>
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <h3 style="margin-top: 1.5rem; margin-bottom: 0.5rem;"><?php echo esc_html__('Translation Dictionary Import & Export', 'tuedion-cookie'); ?></h3>
                    <p class="description">
                        <?php echo esc_html__('Export language strings to JSON for external localization bureaus, or import custom translation overrides.', 'tuedion-cookie'); ?>
                    </p>

                    <div style="margin-top: 1rem; display: flex; gap: 12px; align-items: center;">
                        <a href="<?php echo esc_url(admin_url('admin-post.php?action=tdcc_export_translations&_wpnonce=' . $exportNonce)); ?>" download="tuedion-cookie-translations-<?php echo esc_attr(gmdate('Y-m-d')); ?>.json" class="button button-secondary">
                            <span class="dashicons dashicons-download" style="vertical-align: text-bottom; margin-right: 4px;"></span>
                            <?php echo esc_html__('Export Translations (JSON)', 'tuedion-cookie'); ?>
                        </a>
                    </div>
                </div>

                <!-- Legal Compliance URLs & Titles -->
                <div class="tdcc-card" id="tdcc-legal-card">
                    <h2><?php echo esc_html__('Legal Compliance URLs & Titles (Privacy Policy & Terms)', 'tuedion-cookie'); ?></h2>
                    <p class="description">
                        <?php echo esc_html__('Configure links and display titles for your Privacy Policy and Terms & Conditions. These links and titles are automatically injected into the consent banner and preferences modal footer.', 'tuedion-cookie'); ?>
                    </p>

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="tdcc-legal-privacy-title"><?php echo esc_html__('Default Privacy Policy Title', 'tuedion-cookie'); ?></label>
                                </th>
                                <td>
                                    <input type="text" name="legal_privacy_title" id="tdcc-legal-privacy-title" value="<?php echo esc_attr($privacyTitle); ?>" class="regular-text" placeholder="<?php echo esc_attr__('Privacy Policy', 'tuedion-cookie'); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="tdcc-legal-privacy-url"><?php echo esc_html__('Default Privacy Policy URL', 'tuedion-cookie'); ?></label>
                                </th>
                                <td>
                                    <input type="url" name="legal_privacy_url" id="tdcc-legal-privacy-url" value="<?php echo esc_url($privacyUrl); ?>" class="regular-text code" placeholder="<?php echo esc_url(get_privacy_policy_url() ?: home_url('/privacy-policy/')); ?>">
                                    <p class="description">
                                        <?php echo esc_html__('Primary fallback URL. If left empty, WordPress\'s native privacy policy page is used automatically.', 'tuedion-cookie'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="tdcc-legal-terms-title"><?php echo esc_html__('Default Terms & Conditions Title', 'tuedion-cookie'); ?></label>
                                </th>
                                <td>
                                    <input type="text" name="legal_terms_title" id="tdcc-legal-terms-title" value="<?php echo esc_attr($termsTitle); ?>" class="regular-text" placeholder="<?php echo esc_attr__('Terms of Service', 'tuedion-cookie'); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="tdcc-legal-terms-url"><?php echo esc_html__('Default Terms & Conditions URL', 'tuedion-cookie'); ?></label>
                                </th>
                                <td>
                                    <input type="url" name="legal_terms_url" id="tdcc-legal-terms-url" value="<?php echo esc_url($termsUrl); ?>" class="regular-text code" placeholder="<?php echo esc_url(home_url('/terms/')); ?>">
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <?php if (!empty($siteLanguages)): ?>
                        <h3 style="margin-top: 1.5rem; margin-bottom: 0.5rem;"><?php echo esc_html__('Language-Specific Titles & URL Overrides', 'tuedion-cookie'); ?></h3>
                        <p class="description">
                            <?php echo esc_html__('Translate the link titles and specify dedicated policy URLs for each language. Note: If Polylang or WPML is active, translated policy pages are automatically detected when URLs are left blank.', 'tuedion-cookie'); ?>
                        </p>
                        <table class="widefat striped" style="margin-top: 0.75rem;">
                            <thead>
                                <tr>
                                    <th scope="col" style="width: 80px;"><?php echo esc_html__('Language', 'tuedion-cookie'); ?></th>
                                    <th scope="col" style="width: 22%;"><?php echo esc_html__('Privacy Policy Title', 'tuedion-cookie'); ?></th>
                                    <th scope="col"><?php echo esc_html__('Privacy Policy URL', 'tuedion-cookie'); ?></th>
                                    <th scope="col" style="width: 22%;"><?php echo esc_html__('Terms Title', 'tuedion-cookie'); ?></th>
                                    <th scope="col"><?php echo esc_html__('Terms of Service URL', 'tuedion-cookie'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($siteLanguages as $langCode): ?>
                                    <?php
                                    $pTitle = $privacyTitles[$langCode] ?? '';
                                    $pUrl = $privacyUrls[$langCode] ?? '';
                                    $tTitle = $termsTitles[$langCode] ?? '';
                                    $tUrl = $termsUrls[$langCode] ?? '';
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html(strtoupper($langCode)); ?></strong>
                                        </td>
                                        <td>
                                            <input type="text" name="legal_privacy_titles[<?php echo esc_attr($langCode); ?>]" value="<?php echo esc_attr($pTitle); ?>" class="regular-text" placeholder="<?php echo esc_attr(\Tuedion\CookieConsent\Consent\LanguageResolver::getPrivacyPolicyTitle($langCode)); ?>" style="width: 100%;">
                                        </td>
                                        <td>
                                            <input type="url" name="legal_privacy_urls[<?php echo esc_attr($langCode); ?>]" value="<?php echo esc_url($pUrl); ?>" class="regular-text code" placeholder="<?php echo esc_url(\Tuedion\CookieConsent\Consent\LanguageResolver::getPrivacyPolicyUrl($langCode)); ?>" style="width: 100%;">
                                        </td>
                                        <td>
                                            <input type="text" name="legal_terms_titles[<?php echo esc_attr($langCode); ?>]" value="<?php echo esc_attr($tTitle); ?>" class="regular-text" placeholder="<?php echo esc_attr(\Tuedion\CookieConsent\Consent\LanguageResolver::getTermsTitle($langCode)); ?>" style="width: 100%;">
                                        </td>
                                        <td>
                                            <input type="url" name="legal_terms_urls[<?php echo esc_attr($langCode); ?>]" value="<?php echo esc_url($tUrl); ?>" class="regular-text code" placeholder="<?php echo esc_url(\Tuedion\CookieConsent\Consent\LanguageResolver::getTermsUrl($langCode)); ?>" style="width: 100%;">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Compliance Shortcodes & Policy Page Integration -->
                <div class="tdcc-card" id="tdcc-shortcodes-card">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px;">
                        <div>
                            <h2><?php echo esc_html__('Compliance Shortcodes & Policy Page Integration', 'tuedion-cookie'); ?></h2>
                            <p class="description">
                                <?php echo esc_html__('Integrate dynamic, real-time cookie declaration tables, category lists, or a preferences modal trigger button directly into your Privacy Policy, Cookie Policy, or custom compliance pages.', 'tuedion-cookie'); ?>
                            </p>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:16px; margin-top:20px;">
                        <!-- Shortcode 1: Full Declaration -->
                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:18px; display:flex; flex-direction:column; justify-content:space-between;">
                            <div>
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                    <strong style="font-size:14px; color:#0f172a;"><?php echo esc_html__('Full Cookie Declaration Table', 'tuedion-cookie'); ?></strong>
                                    <span class="tdcc-badge" style="background:#dbeafe; color:#1e40af; font-size:11px; font-weight:700;"><?php echo esc_html__('Recommended', 'tuedion-cookie'); ?></span>
                                </div>
                                <p style="font-size:13px; color:#475569; margin:0 0 12px 0; line-height:1.4;">
                                    <?php echo esc_html__('Outputs an all-in-one compliance section: categorized tables (Name, Domain, Lifespan/Duration, Purpose) for all active cookies and a "Change your consent" modal opener button.', 'tuedion-cookie'); ?>
                                </p>
                                <div style="background:#ffffff; border:1px solid #cbd5e1; border-radius:6px; padding:8px 12px; margin-bottom:12px; display:flex; align-items:center; justify-content:space-between; gap:8px;">
                                    <code style="font-size:13px; color:#0f172a; background:none; padding:0; word-break:break-all;">[tuedion_cookie_declaration]</code>
                                    <button type="button" class="button button-small tdcc-copy-shortcode-btn" data-code="[tuedion_cookie_declaration]" data-copied-text="<?php echo esc_attr__('Copied!', 'tuedion-cookie'); ?>">
                                        <span class="dashicons dashicons-admin-page" style="font-size:14px; width:14px; height:14px; line-height:14px; vertical-align:text-top;"></span>
                                        <?php echo esc_html__('Copy', 'tuedion-cookie'); ?>
                                    </button>
                                </div>
                                <details style="font-size:12px; color:#64748b; margin-top:8px;">
                                    <summary style="cursor:pointer; font-weight:600; color:#2563eb;"><?php echo esc_html__('Available Parameters & Examples', 'tuedion-cookie'); ?></summary>
                                    <ul style="margin:8px 0 0 16px; list-style-type:disc;">
                                        <li><code>show_button="true|false"</code> — <?php echo esc_html__('Toggle "Change your consent" button (default: true).', 'tuedion-cookie'); ?></li>
                                        <li><code>show_intro="true|false"</code> — <?php echo esc_html__('Toggle introductory explanation text (default: true).', 'tuedion-cookie'); ?></li>
                                        <li><code>lang="tr|en|de|..."</code> — <?php echo esc_html__('Force a specific language (default: auto-detected).', 'tuedion-cookie'); ?></li>
                                    </ul>
                                    <p style="margin-top:6px;"><em><?php echo esc_html__('Example:', 'tuedion-cookie'); ?></em> <code>[tuedion_cookie_declaration show_intro="false"]</code></p>
                                </details>
                            </div>
                        </div>

                        <!-- Shortcode 2: Category Table -->
                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:18px; display:flex; flex-direction:column; justify-content:space-between;">
                            <div>
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                    <strong style="font-size:14px; color:#0f172a;"><?php echo esc_html__('Single Category Table', 'tuedion-cookie'); ?></strong>
                                    <span class="tdcc-badge" style="background:#f1f5f9; color:#475569; font-size:11px;"><?php echo esc_html__('Granular', 'tuedion-cookie'); ?></span>
                                </div>
                                <p style="font-size:13px; color:#475569; margin:0 0 12px 0; line-height:1.4;">
                                    <?php echo esc_html__('Outputs an isolated table showing cookies for a single specific category (e.g. Analytics, Marketing, or Necessary). Ideal for custom accordion layouts.', 'tuedion-cookie'); ?>
                                </p>
                                <div style="background:#ffffff; border:1px solid #cbd5e1; border-radius:6px; padding:8px 12px; margin-bottom:12px; display:flex; align-items:center; justify-content:space-between; gap:8px;">
                                    <code style="font-size:13px; color:#0f172a; background:none; padding:0; word-break:break-all;">[tuedion_cookie_table category="analytics"]</code>
                                    <button type="button" class="button button-small tdcc-copy-shortcode-btn" data-code='[tuedion_cookie_table category="analytics"]' data-copied-text="<?php echo esc_attr__('Copied!', 'tuedion-cookie'); ?>">
                                        <span class="dashicons dashicons-admin-page" style="font-size:14px; width:14px; height:14px; line-height:14px; vertical-align:text-top;"></span>
                                        <?php echo esc_html__('Copy', 'tuedion-cookie'); ?>
                                    </button>
                                </div>
                                <details style="font-size:12px; color:#64748b; margin-top:8px;">
                                    <summary style="cursor:pointer; font-weight:600; color:#2563eb;"><?php echo esc_html__('Available Parameters & Categories', 'tuedion-cookie'); ?></summary>
                                    <ul style="margin:8px 0 0 16px; list-style-type:disc;">
                                        <li><code>category="necessary"</code> (Zorunlu)</li>
                                        <li><code>category="analytics"</code> (Analitik)</li>
                                        <li><code>category="marketing"</code> (Pazarlama)</li>
                                        <li><code>category="functionality"</code> (İşlevsel)</li>
                                        <li><code>lang="tr|en|..."</code> — <?php echo esc_html__('Optional language code override.', 'tuedion-cookie'); ?></li>
                                    </ul>
                                </details>
                            </div>
                        </div>

                        <!-- Shortcode 3: Preferences Modal Button -->
                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:18px; display:flex; flex-direction:column; justify-content:space-between;">
                            <div>
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                    <strong style="font-size:14px; color:#0f172a;"><?php echo esc_html__('Preferences Modal Trigger', 'tuedion-cookie'); ?></strong>
                                    <span class="tdcc-badge" style="background:#ecfdf5; color:#047857; font-size:11px;"><?php echo esc_html__('Reversible Consent', 'tuedion-cookie'); ?></span>
                                </div>
                                <p style="font-size:13px; color:#475569; margin:0 0 12px 0; line-height:1.4;">
                                    <?php echo esc_html__('Generates an accessible button that opens the Cookie Preferences modal when clicked by a visitor. Meets GDPR Art. 7(3) right to withdraw consent.', 'tuedion-cookie'); ?>
                                </p>
                                <div style="background:#ffffff; border:1px solid #cbd5e1; border-radius:6px; padding:8px 12px; margin-bottom:12px; display:flex; align-items:center; justify-content:space-between; gap:8px;">
                                    <code style="font-size:13px; color:#0f172a; background:none; padding:0; word-break:break-all;">[tuedion_cookie_policy_link]</code>
                                    <button type="button" class="button button-small tdcc-copy-shortcode-btn" data-code="[tuedion_cookie_policy_link]" data-copied-text="<?php echo esc_attr__('Copied!', 'tuedion-cookie'); ?>">
                                        <span class="dashicons dashicons-admin-page" style="font-size:14px; width:14px; height:14px; line-height:14px; vertical-align:text-top;"></span>
                                        <?php echo esc_html__('Copy', 'tuedion-cookie'); ?>
                                    </button>
                                </div>
                                <div style="font-size:12px; color:#64748b; background:#ffffff; border:1px dashed #cbd5e1; border-radius:6px; padding:8px 10px; margin-top:8px;">
                                    <strong><?php echo esc_html__('HTML Attribute Alternative:', 'tuedion-cookie'); ?></strong><br>
                                    <?php echo esc_html__('Add this attribute to any link or button in your theme, footer or Elementor:', 'tuedion-cookie'); ?><br>
                                    <code style="color:#2563eb; font-weight:700;">data-cc="show-preferencesModal"</code>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Google Consent Mode v2 Configuration -->
                <div class="tdcc-card" id="tdcc-gcm-card">
                    <h2><?php echo esc_html__('Google Consent Mode v2 (GCM v2)', 'tuedion-cookie'); ?></h2>
                    <p class="description">
                        <?php echo esc_html__('Standardized Google consent signaling for Google Analytics 4, Google Ads, and Google Tag Manager.', 'tuedion-cookie'); ?>
                    </p>

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Enable GCM v2', 'tuedion-cookie'); ?></th>
                                <td>
                                    <label for="tdcc-gcm-enabled">
                                        <input type="checkbox" name="gcm_enabled" id="tdcc-gcm-enabled" value="1" <?php checked($gcmEnabled); ?>>
                                        <?php echo esc_html__('Inject early default consent state in <head> and dispatch dynamic updates on consent.', 'tuedion-cookie'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="tdcc-wait-for-update"><?php echo esc_html__('Wait for Update (ms)', 'tuedion-cookie'); ?></label>
                                </th>
                                <td>
                                    <input type="number" name="gcm_wait_for_update" id="tdcc-wait-for-update" value="<?php echo esc_attr((string) $waitForUpdate); ?>" min="100" max="5000" step="50" class="small-text">
                                    <span class="description"><?php echo esc_html__('Time Google tags wait for consent state update before firing default ping (recommended: 500ms).', 'tuedion-cookie'); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Ads Data Redaction', 'tuedion-cookie'); ?></th>
                                <td>
                                    <label for="tdcc-ads-redaction">
                                        <input type="checkbox" name="gcm_ads_data_redaction" id="tdcc-ads-redaction" value="1" <?php checked($adsDataRedaction); ?>>
                                        <?php echo esc_html__('Redact ad click identifiers (e.g. GCLID) when ad_storage is denied.', 'tuedion-cookie'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('URL Passthrough', 'tuedion-cookie'); ?></th>
                                <td>
                                    <label for="tdcc-url-passthrough">
                                        <input type="checkbox" name="gcm_url_passthrough" id="tdcc-url-passthrough" value="1" <?php checked($urlPassthrough); ?>>
                                        <?php echo esc_html__('Pass ad click identifiers through URL query parameters when cookies are denied.', 'tuedion-cookie'); ?>
                                    </label>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <h3 style="margin-top: 1.5rem; margin-bottom: 0.5rem;"><?php echo esc_html__('GCM v2 Signal to Category Mapping', 'tuedion-cookie'); ?></h3>
                    <p class="description">
                        <?php echo esc_html__('Map the 7 standardized Google signals to your plugin consent categories.', 'tuedion-cookie'); ?>
                    </p>

                    <table class="widefat striped" style="margin-top: 0.75rem; max-width: 650px;">
                        <thead>
                            <tr>
                                <th scope="col"><?php echo esc_html__('Google Signal', 'tuedion-cookie'); ?></th>
                                <th scope="col"><?php echo esc_html__('Assigned Category', 'tuedion-cookie'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (GcmMapping::ALL_SIGNALS as $signal): ?>
                                <?php
                                $assignedCat = $activeMapping[$signal] ?? 'marketing';
                                $isSecurity = ($signal === GcmMapping::SIGNAL_SECURITY_STORAGE);
                                ?>
                                <tr>
                                    <td>
                                        <code><?php echo esc_html($signal); ?></code>
                                        <?php if ($signal === GcmMapping::SIGNAL_AD_USER_DATA || $signal === GcmMapping::SIGNAL_AD_PERSONALIZATION): ?>
                                            <span class="tdcc-badge" style="font-size: 10px; margin-left: 6px;">V2 REQUIRED</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isSecurity): ?>
                                            <code>necessary</code> <span class="description">(<?php echo esc_html__('Always Granted', 'tuedion-cookie'); ?>)</span>
                                            <input type="hidden" name="gcm_mapping[<?php echo esc_attr($signal); ?>]" value="necessary">
                                        <?php else: ?>
                                            <select name="gcm_mapping[<?php echo esc_attr($signal); ?>]">
                                                <?php foreach ($categories as $cat): ?>
                                                    <?php $catId = $cat['id']; ?>
                                                    <option value="<?php echo esc_attr($catId); ?>" <?php selected($assignedCat, $catId); ?>>
                                                        <?php echo esc_html($cat['label'] ?? $catId); ?> (<?php echo esc_html($catId); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Consent Records & Privacy Audit -->
                <div class="tdcc-card" id="tdcc-logging-card">
                    <h2><?php echo esc_html__('Consent Records & Audit Logging (GDPR Art. 7(1))', 'tuedion-cookie'); ?></h2>
                    <p class="description">
                        <?php echo esc_html__('Under GDPR Art. 7(1), site operators may be required to demonstrate valid consent. By default, logging is OFF (zero database writes).', 'tuedion-cookie'); ?>
                    </p>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Enable Consent Logging', 'tuedion-cookie'); ?></th>
                                <td>
                                    <label for="tdcc-logging-enabled">
                                        <input type="checkbox" name="logging_enabled" id="tdcc-logging-enabled" value="1" <?php checked($loggingEnabled); ?>>
                                        <?php echo esc_html__('Log minimal, anonymized proof of consent (masked IP, revision, choices, timestamp).', 'tuedion-cookie'); ?>
                                    </label>
                                    <p class="description">
                                        <?php echo esc_html__('IP addresses are strictly anonymized using wp_privacy_anonymize_ip (raw IPs are never stored).', 'tuedion-cookie'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="tdcc-retention-days"><?php echo esc_html__('Data Retention (Days)', 'tuedion-cookie'); ?></label>
                                </th>
                                <td>
                                    <input type="number" name="logging_retention_days" id="tdcc-retention-days" value="<?php echo esc_attr((string) $retentionDays); ?>" min="7" max="730" step="1" class="small-text">
                                    <span class="description"><?php echo esc_html__('Days before logs are automatically pruned by daily cleanup cron (recommended: 90 days).', 'tuedion-cookie'); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Audit Viewer', 'tuedion-cookie'); ?></th>
                                <td>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=tuedion-cookie-logs')); ?>" class="button button-secondary">
                                        <?php echo esc_html__('Open Consent Logs & CSV Export', 'tuedion-cookie'); ?> &rarr;
                                    </a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Scheduled Automated Cookie Scanner (Enterprise Compliance) -->
                <div class="tdcc-card" id="tdcc-scanner-card">
                    <a id="tdcc-scanner-settings" style="position:relative; top:-20px;"></a>
                    <h2><?php echo esc_html__('Scheduled Cookie Scanner & Drift Detection', 'tuedion-cookie'); ?></h2>
                    <p class="description">
                        <?php echo esc_html__('Enterprise CMPs require continuous auditing to catch unauthorized cookie drift or new trackers installed by plugins or marketing tags.', 'tuedion-cookie'); ?>
                    </p>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Automated Scanning', 'tuedion-cookie'); ?></th>
                                <td>
                                    <label for="tdcc-scanner-cron-enabled">
                                        <input type="checkbox" name="scanner_cron_enabled" id="tdcc-scanner-cron-enabled" value="1" <?php checked($scannerCronEnabled); ?>>
                                        <?php echo esc_html__('Enable automated background site crawl via WP-Cron.', 'tuedion-cookie'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="tdcc-scanner-schedule"><?php echo esc_html__('Scan Frequency', 'tuedion-cookie'); ?></label>
                                </th>
                                <td>
                                    <select name="scanner_schedule" id="tdcc-scanner-schedule">
                                        <option value="daily" <?php selected($scannerSchedule, 'daily'); ?>><?php echo esc_html__('Daily', 'tuedion-cookie'); ?></option>
                                        <option value="weekly" <?php selected($scannerSchedule, 'weekly'); ?>><?php echo esc_html__('Weekly (Recommended)', 'tuedion-cookie'); ?></option>
                                        <option value="monthly" <?php selected($scannerSchedule, 'monthly'); ?>><?php echo esc_html__('Monthly', 'tuedion-cookie'); ?></option>
                                    </select>
                                    <p class="description"><?php echo esc_html__('How often the background worker analyzes live HTML and active scripts for unclassified cookies.', 'tuedion-cookie'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="tdcc-scanner-alert-email"><?php echo esc_html__('Security Alert Email', 'tuedion-cookie'); ?></label>
                                </th>
                                <td>
                                    <input type="email" name="scanner_alert_email" id="tdcc-scanner-alert-email" value="<?php echo esc_attr($scannerAlertEmail); ?>" class="regular-text" placeholder="<?php echo esc_attr((string) get_option('admin_email')); ?>">
                                    <p class="description"><?php echo esc_html__('Receive instant email notification if newly introduced or unclassified cookies are discovered. Leave blank to use site admin email.', 'tuedion-cookie'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Manual Scanner', 'tuedion-cookie'); ?></th>
                                <td>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=tuedion-cookie-categories')); ?>" class="button button-secondary">
                                        <?php echo esc_html__('Open Live Scanner Console', 'tuedion-cookie'); ?> &rarr;
                                    </a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Advanced & Data Governance -->
                <div class="tdcc-card" id="tdcc-advanced-card">
                    <h2><?php echo esc_html__('Advanced & Data Governance', 'tuedion-cookie'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Script Enforcement', 'tuedion-cookie'); ?></th>
                                <td>
                                    <label for="tdcc-script-blocking">
                                        <input type="checkbox" name="script_blocking" id="tdcc-script-blocking" value="1" <?php checked($scriptBlocking); ?>>
                                        <?php echo esc_html__('Enable Deny-Before-Consent script blocking (rewrites and pauses third-party scripts).', 'tuedion-cookie'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Iframe / Video Blocking', 'tuedion-cookie'); ?></th>
                                <td>
                                    <label for="tdcc-iframe-blocking">
                                        <input type="checkbox" name="iframe_blocking" id="tdcc-iframe-blocking" value="1" <?php checked($iframeBlocking); ?>>
                                        <?php echo esc_html__('Enable Iframe & Video embed blocking (replaces YouTube, Vimeo, Google Maps with consent placeholders).', 'tuedion-cookie'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Reload on Revoke', 'tuedion-cookie'); ?></th>
                                <td>
                                    <label for="tdcc-reload-on-revoke">
                                        <input type="checkbox" name="reload_on_revoke" id="tdcc-reload-on-revoke" value="1" <?php checked($reloadOnRevoke); ?>>
                                        <?php echo esc_html__('Reload page when visitor revokes consent (purges third-party tracking scripts from browser memory).', 'tuedion-cookie'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Uninstall Cleanup', 'tuedion-cookie'); ?></th>
                                <td>
                                    <label for="tdcc-clean-uninstall">
                                        <input type="checkbox" name="clean_on_uninstall" id="tdcc-clean-uninstall" value="1" <?php checked($cleanOnUninstall); ?>>
                                        <?php echo esc_html__('Completely remove all settings and database tables when plugin is uninstalled.', 'tuedion-cookie'); ?>
                                    </label>
                                    <p class="description">
                                        <?php echo esc_html__('If unchecked, your settings and configuration are preserved even if the plugin is deleted.', 'tuedion-cookie'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Diagnostics / Debug', 'tuedion-cookie'); ?></th>
                                <td>
                                    <label for="tdcc-debug-mode">
                                        <input type="checkbox" name="debug_mode" id="tdcc-debug-mode" value="1" <?php checked($debugMode); ?>>
                                        <?php echo esc_html__('Enable console debugging for consent lifecycle and GCM v2 events.', 'tuedion-cookie'); ?>
                                    </label>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="submit">
                    <input type="submit" name="tuedion_cookie_save" class="button button-primary" value="<?php echo esc_attr__('Save Settings', 'tuedion-cookie'); ?>">
                </p>
            </form>

            <!-- Translation Import Form -->
            <div class="tdcc-card">
                <h2><?php echo esc_html__('Import Custom Translations', 'tuedion-cookie'); ?></h2>
                <p class="description">
                    <?php echo esc_html__('Paste JSON translation dictionary to override or add custom translations.', 'tuedion-cookie'); ?>
                </p>
                <form method="post" action="">
                    <?php wp_nonce_field('tuedion_cookie_import_nonce', 'tuedion_cookie_nonce'); ?>
                    <p>
                        <textarea name="translation_json" rows="6" class="large-text code" placeholder='{"de": {"consentModal": {"title": "..."}}}'></textarea>
                    </p>
                    <p>
                        <input type="submit" name="tuedion_cookie_import_translations" class="button button-secondary" value="<?php echo esc_attr__('Import JSON Translations', 'tuedion-cookie'); ?>">
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
}
