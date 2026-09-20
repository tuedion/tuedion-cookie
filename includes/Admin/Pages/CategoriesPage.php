<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Admin\Pages;

use Tuedion\CookieConsent\Settings\Repository;
use Tuedion\CookieConsent\Settings\Compiler;
use Tuedion\CookieConsent\Settings\Validator;
use Tuedion\CookieConsent\Settings\Defaults;
use Tuedion\CookieConsent\Diagnostics\CookieScanner;
use Tuedion\CookieConsent\Integrations\RecipeRegistry;

if (!defined('ABSPATH')) {
    exit;
}

final class CategoriesPage
{
    public static function register(): void
    {
        add_action('wp_ajax_tdcc_add_preset_service', [self::class, 'handleAjaxAddPreset']);
        add_action('wp_ajax_tdcc_delete_service', [self::class, 'handleAjaxDeleteService']);
    }

    /**
     * AJAX endpoint to asynchronously add a service preset or detected tracker to configuration.
     */
    public static function handleAjaxAddPreset(): void
    {
        check_ajax_referer('tuedion_cookie_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('Unauthorized permission.', 'tuedion-cookie')], 403);
        }

        $presetKey = sanitize_key((string) ($_POST['preset_key'] ?? ''));
        if ($presetKey === '') {
            wp_send_json_error(['message' => esc_html__('Invalid service preset identifier.', 'tuedion-cookie')]);
        }

        $allRecipes = RecipeRegistry::getAll();
        $recipe = $allRecipes[$presetKey] ?? null;

        // If not in standard recipes, check detected services from CookieScanner
        if ($recipe === null) {
            $scanResults = CookieScanner::getResults();
            $detectedServices = (array) ($scanResults['detected_services'] ?? []);
            if (isset($detectedServices[$presetKey])) {
                $ds = $detectedServices[$presetKey];
                $recipe = [
                    'name'        => $ds['name'] ?? $presetKey,
                    'category'    => $ds['category'] ?? 'marketing',
                    'auto_clear'  => $ds['auto_clear'] ?? [],
                    'description' => $ds['description'] ?? '',
                ];
            }
        }

        if ($recipe === null) {
            wp_send_json_error(['message' => esc_html__('Preset not found in recipe catalogue.', 'tuedion-cookie')]);
        }

        $settings = Repository::getSettings();
        $existingServices = $settings['services'] ?? [];

        foreach ($existingServices as $es) {
            if (($es['id'] ?? '') === $presetKey) {
                wp_send_json_success([
                    'already_exists' => true,
                    /* translators: %s: Service name */
                    'message'        => sprintf(esc_html__('Service "%s" is already configured.', 'tuedion-cookie'), $recipe['name'] ?? $presetKey),
                    'service'        => $es,
                    'preset_key'     => $presetKey,
                ]);
                return;
            }
        }

        $cleanCookies = [];
        foreach ((array) ($recipe['auto_clear'] ?? []) as $pat) {
            $cleanPat = str_replace(['/^', '/', '\\'], '', (string) $pat);
            if ($cleanPat !== '') {
                $cleanCookies[] = $cleanPat;
            }
        }

        $newService = [
            'id'          => $presetKey,
            'label'       => $recipe['name'] ?? $presetKey,
            'category'    => $recipe['category'] ?? 'marketing',
            'cookies'     => $cleanCookies,
            'description' => $recipe['description'] ?? '',
        ];

        $existingServices[] = $newService;
        $settings['services'] = $existingServices;

        $val = Validator::validate($settings);
        if ($val['valid']) {
            Repository::updateSettings($val['sanitized']);
        } else {
            Repository::updateSettings($settings);
        }
        Compiler::clearCache();

        $deleteUrl = wp_nonce_url(
            add_query_arg(['action' => 'delete_svc', 'svc_id' => $presetKey], admin_url('admin.php?page=tuedion-cookie-categories')),
            'tuedion_delete_service'
        );

        /* translators: %s: Service name */
        $addedMsg = sprintf(esc_html__('Service "%s" added to your cookie preferences.', 'tuedion-cookie'), $newService['label']);

        wp_send_json_success([
            'message'        => $addedMsg,
            'service'        => $newService,
            'preset_key'     => $presetKey,
            'delete_url'     => $deleteUrl,
            'total_services' => count($existingServices),
        ]);
    }

    /**
     * AJAX endpoint to asynchronously delete a service from configuration.
     */
    public static function handleAjaxDeleteService(): void
    {
        check_ajax_referer('tuedion_cookie_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('Unauthorized permission.', 'tuedion-cookie')], 403);
        }

        $svcId = sanitize_key((string) ($_POST['service_id'] ?? ''));
        if ($svcId === '') {
            wp_send_json_error(['message' => esc_html__('Invalid service identifier.', 'tuedion-cookie')]);
        }

        $settings = Repository::getSettings();
        $services = $settings['services'] ?? [];
        $foundCat = null;
        $filteredServices = [];

        foreach ($services as $s) {
            if (($s['id'] ?? '') === $svcId) {
                $foundCat = $s['category'] ?? null;
            } else {
                $filteredServices[] = $s;
            }
        }

        if ($foundCat === null) {
            wp_send_json_error(['message' => esc_html__('Service not found in configuration.', 'tuedion-cookie')]);
        }

        $settings['services'] = array_values($filteredServices);
        Repository::updateSettings($settings);
        \Tuedion\CookieConsent\I18n\TranslationManager::purgeCustomCookieTables();
        Compiler::clearCache();
        \Tuedion\CookieConsent\Integrations\CacheCompatibility::purgeAllCaches();

        wp_send_json_success([
            'message'        => esc_html__('Service removed from cookie preferences.', 'tuedion-cookie'),
            'service_id'     => $svcId,
            'category'       => $foundCat,
            'total_services' => count($settings['services']),
        ]);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'tuedion-cookie'));
        }

        $notice = null;
        $noticeType = 'success';

        // 1. Handle Add / Edit Category
        if (isset($_POST['tuedion_save_category']) && check_admin_referer('tuedion_category_action', 'tuedion_category_nonce')) {
            $catId = sanitize_key(wp_unslash((string) ($_POST['cat_id'] ?? '')));
            $catLabel = sanitize_text_field(wp_unslash((string) ($_POST['cat_label'] ?? '')));
            $catDesc = wp_kses_post(wp_unslash((string) ($_POST['cat_description'] ?? '')));
            $isReadOnly = !empty($_POST['cat_read_only']) || $catId === 'necessary';
            $isEnabled = $isReadOnly || !empty($_POST['cat_enabled']);

            if (!empty($catId) && !empty($catLabel)) {
                $settings = Repository::getSettings();
                $categories = $settings['categories'] ?? [];
                $found = false;

                foreach ($categories as &$cat) {
                    if ($cat['id'] === $catId) {
                        $cat['label'] = $catLabel;
                        $cat['description'] = $catDesc;
                        $cat['readOnly'] = $isReadOnly;
                        $cat['enabled'] = $isEnabled;
                        $found = true;
                        break;
                    }
                }
                unset($cat);

                if (!$found) {
                    $categories[] = [
                        'id'          => $catId,
                        'label'       => $catLabel,
                        'description' => $catDesc,
                        'readOnly'    => $isReadOnly,
                        'enabled'     => $isEnabled,
                        'autoClear'   => [],
                    ];
                }

                $settings['categories'] = $categories;
                $val = Validator::validate($settings);
                if ($val['valid']) {
                    Repository::updateSettings($val['sanitized']);
                    Compiler::clearCache();
                    $notice = esc_html__('Category saved successfully.', 'tuedion-cookie');
                } else {
                    $notice = implode(' ', $val['errors']);
                    $noticeType = 'error';
                }
            }
        }

        // 2. Handle Delete Category
        if (isset($_GET['action'], $_GET['cat_id']) && $_GET['action'] === 'delete_cat') {
            check_admin_referer('tuedion_delete_category');
            $delId = sanitize_key($_GET['cat_id']);

            if ($delId === 'necessary') {
                $notice = esc_html__('The "necessary" category is strictly required by consent regulations and cannot be deleted.', 'tuedion-cookie');
                $noticeType = 'error';
            } else {
                $settings = Repository::getSettings();
                $settings['categories'] = array_values(array_filter($settings['categories'], fn($c) => $c['id'] !== $delId));
                // Remove orphaned services
                $settings['services'] = array_values(array_filter($settings['services'], fn($s) => ($s['category'] ?? '') !== $delId));

                $val = Validator::validate($settings);
                if ($val['valid']) {
                    Repository::updateSettings($val['sanitized']);
                    Compiler::clearCache();
                    $notice = esc_html__('Category and its attached services removed successfully.', 'tuedion-cookie');
                }
            }
        }

        // 2b. Handle Reset Categories
        if (isset($_POST['tuedion_reset_categories']) && check_admin_referer('tuedion_reset_action', 'tuedion_reset_nonce')) {
            $settings = Repository::getSettings();
            $defaults = \Tuedion\CookieConsent\Settings\Defaults::get();
            $settings['categories'] = $defaults['categories'];
            
            $val = Validator::validate($settings);
            if ($val['valid']) {
                Repository::updateSettings($val['sanitized']);
                Compiler::clearCache();
                $notice = esc_html__('Categories have been reset to their default values.', 'tuedion-cookie');
                $noticeType = 'success';
            }
        }

        // 3. Handle Add / Edit Service
        if (isset($_POST['tuedion_save_service']) && check_admin_referer('tuedion_service_action', 'tuedion_service_nonce')) {
            $svcId = sanitize_key(wp_unslash((string) ($_POST['svc_id'] ?? '')));
            $svcLabel = sanitize_text_field(wp_unslash((string) ($_POST['svc_label'] ?? '')));
            $svcCategory = sanitize_key(wp_unslash((string) ($_POST['svc_category'] ?? '')));
            $rawCookies = sanitize_text_field(wp_unslash((string) ($_POST['svc_cookies'] ?? '')));
            $cookiesList = array_values(array_filter(array_map('trim', explode(',', $rawCookies))));

            if (!empty($svcId) && !empty($svcLabel) && !empty($svcCategory)) {
                $settings = Repository::getSettings();
                $services = $settings['services'] ?? [];
                $found = false;

                foreach ($services as &$svc) {
                    if ($svc['id'] === $svcId) {
                        $svc['label'] = $svcLabel;
                        $svc['category'] = $svcCategory;
                        $svc['cookies'] = $cookiesList;
                        $found = true;
                        break;
                    }
                }
                unset($svc);

                if (!$found) {
                    $services[] = [
                        'id'       => $svcId,
                        'label'    => $svcLabel,
                        'category' => $svcCategory,
                        'cookies'  => $cookiesList,
                    ];
                }

                $settings['services'] = $services;
                $val = Validator::validate($settings);
                if ($val['valid']) {
                    Repository::updateSettings($val['sanitized']);
                    Compiler::clearCache();
                    $notice = esc_html__('Service saved successfully.', 'tuedion-cookie');
                } else {
                    $notice = implode(' ', $val['errors']);
                    $noticeType = 'error';
                }
            }
        }

        // 4. Handle Delete Service
        if (isset($_GET['action'], $_GET['svc_id']) && $_GET['action'] === 'delete_svc') {
            check_admin_referer('tuedion_delete_service');
            $delSvcId = sanitize_key($_GET['svc_id']);
            $settings = Repository::getSettings();
            $settings['services'] = array_values(array_filter($settings['services'], fn($s) => $s['id'] !== $delSvcId));
            Repository::updateSettings($settings);
            \Tuedion\CookieConsent\I18n\TranslationManager::purgeCustomCookieTables();
            Compiler::clearCache();
            \Tuedion\CookieConsent\Integrations\CacheCompatibility::purgeAllCaches();
            $notice = esc_html__('Service deleted successfully.', 'tuedion-cookie');
        }

        // 5. Handle Run Site Scan
        if (isset($_POST['tuedion_run_scanner']) && check_admin_referer('tuedion_scanner_action', 'tuedion_scanner_nonce')) {
            $scanResult = CookieScanner::scan(true);
            $svcCount = (int) ($scanResult['stats']['detected_services_count'] ?? 0);
            $cookieCount = (int) ($scanResult['stats']['detected_cookies_count'] ?? 0);
            $notice = sprintf(
                /* translators: 1: Number of services, 2: Number of cookies */
                esc_html__('Website scan completed! Detected %1$d active services and %2$d cookies on your site.', 'tuedion-cookie'),
                $svcCount,
                $cookieCount
            );
            $noticeType = 'success';
        }

        // 6. Handle Auto-Sync Detected Services to Configuration
        if (isset($_POST['tuedion_sync_detected_services']) && check_admin_referer('tuedion_sync_action', 'tuedion_sync_nonce')) {
            $syncedCount = CookieScanner::syncDetectedServicesToSettings();
            $notice = sprintf(
                /* translators: %d: Number of synchronized services */
                esc_html__('%d detected services have been synchronized to your active cookie preferences!', 'tuedion-cookie'),
                $syncedCount
            );
            $noticeType = 'success';
        }

        // 7. Handle 1-Click Add Preset/Detected Service
        if (isset($_POST['tuedion_add_preset_service']) && check_admin_referer('tuedion_add_preset_action', 'tuedion_add_preset_nonce')) {
            $presetKey = sanitize_key(wp_unslash((string) ($_POST['preset_key'] ?? '')));
            $allRecipes = RecipeRegistry::getAll();
            if (isset($allRecipes[$presetKey])) {
                $recipe = $allRecipes[$presetKey];
                $settings = Repository::getSettings();
                $existingServices = $settings['services'] ?? [];
                $alreadyExists = false;
                foreach ($existingServices as $es) {
                    if (($es['id'] ?? '') === $presetKey) {
                        $alreadyExists = true;
                        break;
                    }
                }
                if (!$alreadyExists) {
                    $cleanCookies = [];
                    foreach ((array) ($recipe['auto_clear'] ?? []) as $pat) {
                        $cleanPat = str_replace(['/^', '/', '\\'], '', (string) $pat);
                        if ($cleanPat !== '') {
                            $cleanCookies[] = $cleanPat;
                        }
                    }
                    $existingServices[] = [
                        'id'       => $presetKey,
                        'label'    => $recipe['name'] ?? $presetKey,
                        'category' => $recipe['category'] ?? 'marketing',
                        'cookies'  => $cleanCookies,
                    ];
                    $settings['services'] = $existingServices;
                    Repository::updateSettings($settings);
                    Compiler::clearCache();
                    /* translators: %s: Service name */
                    $notice = sprintf(esc_html__('Service "%s" added to your cookie preferences.', 'tuedion-cookie'), $recipe['name']);
                    $noticeType = 'success';
                }
            }
        }

        $settings   = Repository::getSettings();
        $categories = $settings['categories'] ?? [];
        $services   = $settings['services'] ?? [];
        ?>
        <div class="wrap tdcc-admin-wrap">
            <header class="tdcc-header">
                <h1><?php echo esc_html__('Categories & Tracking Services Manager', 'tuedion-cookie'); ?></h1>
            </header>

            <?php if ($notice !== null): ?>
                <div class="notice notice-<?php echo esc_attr($noticeType); ?> is-dismissible tdcc-notice">
                    <p><?php echo esc_html($notice); ?></p>
                </div>
            <?php endif; ?>

            <div class="tdcc-warning-box">
                <span class="dashicons dashicons-warning"></span>
                <p>
                    <?php echo esc_html__('Modifying categories or assigned services alters consent purposes. After making changes here, please visit the Experience editor to increment the revision and re-prompt existing visitors.', 'tuedion-cookie'); ?>
                </p>
            </div>

            <!-- Categories Section -->
            <div class="tdcc-card">
                <h2><?php echo esc_html__('Configured Categories', 'tuedion-cookie'); ?></h2>
                <table class="widefat striped" role="presentation">
                    <thead>
                        <tr>
                            <th scope="col"><?php echo esc_html__('ID', 'tuedion-cookie'); ?></th>
                            <th scope="col"><?php echo esc_html__('Label', 'tuedion-cookie'); ?></th>
                            <th scope="col"><?php echo esc_html__('Description', 'tuedion-cookie'); ?></th>
                            <th scope="col"><?php echo esc_html__('Type', 'tuedion-cookie'); ?></th>
                            <th scope="col"><?php echo esc_html__('Services', 'tuedion-cookie'); ?></th>
                            <th scope="col"><?php echo esc_html__('Actions', 'tuedion-cookie'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $cat):
                            $svcCount = count(array_filter($services, fn($s) => ($s['category'] ?? '') === $cat['id']));
                            $isReadOnly = !empty($cat['readOnly']);
                        ?>
                            <tr data-cat-id="<?php echo esc_attr($cat['id']); ?>">
                                <td><code><?php echo esc_html($cat['id']); ?></code></td>
                                <td><strong><?php echo esc_html($cat['label']); ?></strong></td>
                                <td><?php echo wp_kses_post($cat['description']); ?></td>
                                <td>
                                    <?php if ($isReadOnly): ?>
                                        <span class="tdcc-badge tdcc-badge-enabled"><?php echo esc_html__('Strictly Necessary', 'tuedion-cookie'); ?></span>
                                    <?php else: ?>
                                        <span class="tdcc-badge tdcc-badge-draft"><?php echo esc_html__('Optional', 'tuedion-cookie'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="tdcc-cat-service-count" data-cat-count="<?php echo esc_attr($cat['id']); ?>"><?php echo esc_html((string) $svcCount); ?></td>
                                <td>
                                    <?php if (!$isReadOnly): ?>
                                        <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'delete_cat', 'cat_id' => $cat['id']]), 'tuedion_delete_category')); ?>" class="button button-link-delete" onclick="return confirm('<?php echo esc_attr__('Are you sure you want to delete this category?', 'tuedion-cookie'); ?>');">
                                            <?php echo esc_html__('Delete', 'tuedion-cookie'); ?>
                                        </a>
                                    <?php else: ?>
                                        <em><?php echo esc_html__('Protected', 'tuedion-cookie'); ?></em>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div style="display: flex; justify-content: space-between; align-items: baseline; margin-top: 2rem;">
                    <h3 class="tdcc-subheading" style="margin: 0;"><?php echo esc_html__('Add or Update Category', 'tuedion-cookie'); ?></h3>
                    <form method="post" action="" style="display:inline-block; margin: 0;">
                        <?php wp_nonce_field('tuedion_reset_action', 'tuedion_reset_nonce'); ?>
                        <input type="hidden" name="tuedion_reset_categories" value="1">
                        <input type="submit" class="button button-link-delete" value="<?php echo esc_attr__('Restore Default Categories', 'tuedion-cookie'); ?>" onclick="return confirm('<?php echo esc_attr__('This will overwrite your categories with the 4 default categories. Are you sure?', 'tuedion-cookie'); ?>');">
                    </form>
                </div>
                <form method="post" action="" class="tdcc-inline-form">
                    <?php wp_nonce_field('tuedion_category_action', 'tuedion_category_nonce'); ?>
                    <input type="hidden" name="tuedion_save_category" value="1">

                    <div class="tdcc-form-row">
                        <label for="cat_id"><?php echo esc_html__('Category ID (slug):', 'tuedion-cookie'); ?></label>
                        <input type="text" name="cat_id" id="cat_id" placeholder="e.g. personalization" required class="regular-text">

                        <label for="cat_label"><?php echo esc_html__('Public Label:', 'tuedion-cookie'); ?></label>
                        <input type="text" name="cat_label" id="cat_label" placeholder="e.g. Personalization Cookies" required class="regular-text">
                    </div>

                    <div class="tdcc-form-row">
                        <label for="cat_description"><?php echo esc_html__('Description / Purpose:', 'tuedion-cookie'); ?></label>
                        <textarea name="cat_description" id="cat_description" rows="2" class="large-text" placeholder="<?php echo esc_attr__('Explain why these cookies are stored...', 'tuedion-cookie'); ?>"></textarea>
                    </div>

                    <p class="submit">
                        <input type="submit" class="button button-secondary" value="<?php echo esc_attr__('Save Category', 'tuedion-cookie'); ?>">
                    </p>
                </form>
            </div>

            <!-- Services Section -->
            <div class="tdcc-card">
                <h2><?php echo esc_html__('Tracking Services (Granular Control)', 'tuedion-cookie'); ?></h2>
                <table class="widefat striped" role="presentation">
                    <thead>
                        <tr>
                            <th scope="col"><?php echo esc_html__('Service Key', 'tuedion-cookie'); ?></th>
                            <th scope="col"><?php echo esc_html__('Label', 'tuedion-cookie'); ?></th>
                            <th scope="col"><?php echo esc_html__('Parent Category', 'tuedion-cookie'); ?></th>
                            <th scope="col"><?php echo esc_html__('Associated Cookies', 'tuedion-cookie'); ?></th>
                            <th scope="col"><?php echo esc_html__('Actions', 'tuedion-cookie'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="tdcc-configured-services-tbody">
                        <?php if (empty($services)): ?>
                            <tr class="tdcc-no-services-row">
                                <td colspan="5"><em><?php echo esc_html__('No granular services defined yet. Trackers will be governed at category level.', 'tuedion-cookie'); ?></em></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($services as $svc): ?>
                                <tr data-service-id="<?php echo esc_attr($svc['id']); ?>" data-category="<?php echo esc_attr($svc['category'] ?? ''); ?>">
                                    <td><code><?php echo esc_html($svc['id']); ?></code></td>
                                    <td><strong><?php echo esc_html($svc['label']); ?></strong></td>
                                    <td><code><?php echo esc_html($svc['category']); ?></code></td>
                                    <td><code><?php echo esc_html(implode(', ', (array) ($svc['cookies'] ?? []))); ?></code></td>
                                    <td>
                                        <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'delete_svc', 'svc_id' => $svc['id']]), 'tuedion_delete_service')); ?>" class="button button-link-delete tdcc-delete-svc-btn" data-service-id="<?php echo esc_attr($svc['id']); ?>" data-confirm="<?php echo esc_attr__('Delete this service?', 'tuedion-cookie'); ?>">
                                            <?php echo esc_html__('Delete', 'tuedion-cookie'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <h3 class="tdcc-subheading"><?php echo esc_html__('Add Tracking Service', 'tuedion-cookie'); ?></h3>
                <form method="post" action="" class="tdcc-inline-form">
                    <?php wp_nonce_field('tuedion_service_action', 'tuedion_service_nonce'); ?>
                    <input type="hidden" name="tuedion_save_service" value="1">

                    <div class="tdcc-form-row">
                        <label for="svc_id"><?php echo esc_html__('Service ID (slug):', 'tuedion-cookie'); ?></label>
                        <input type="text" name="svc_id" id="svc_id" placeholder="e.g. google-analytics" required class="regular-text">

                        <label for="svc_label"><?php echo esc_html__('Display Label:', 'tuedion-cookie'); ?></label>
                        <input type="text" name="svc_label" id="svc_label" placeholder="e.g. Google Analytics 4" required class="regular-text">

                        <label for="svc_category"><?php echo esc_html__('Category:', 'tuedion-cookie'); ?></label>
                        <select name="svc_category" id="svc_category" required>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo esc_attr($cat['id']); ?>">
                                    <?php echo esc_html($cat['label']); ?> (<?php echo esc_html($cat['id']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="tdcc-form-row">
                        <label for="svc_cookies"><?php echo esc_html__('Associated Cookie Names (comma-separated):', 'tuedion-cookie'); ?></label>
                        <input type="text" name="svc_cookies" id="svc_cookies" placeholder="_ga, _gid, _gat" class="large-text">
                    </div>

                    <p class="submit">
                        <input type="submit" class="button button-secondary" value="<?php echo esc_attr__('Add Service', 'tuedion-cookie'); ?>">
                    </p>
                </form>
            </div>

            <!-- Ecosystem Adapters & Service Directory Section -->
            <?php
            $wcActive = \Tuedion\CookieConsent\Integrations\Adapters\WooCommerceAdapter::isActive();
            $elemActive = \Tuedion\CookieConsent\Integrations\Adapters\ElementorAdapter::isActive();
            $formPlugins = \Tuedion\CookieConsent\Integrations\Adapters\FormsAdapter::getActiveFormPlugins();
            $allRecipes = \Tuedion\CookieConsent\Integrations\RecipeRegistry::getAll();
            ?>
            <div class="tdcc-card">
                <h2><?php echo esc_html__('Ecosystem Adapters & Integration Catalogue', 'tuedion-cookie'); ?></h2>
                <p class="description">
                    <?php echo esc_html__('Tuedion Cookie automatically interfaces with the active WordPress ecosystem to protect core site functions, avoid broken checkouts or forms, and govern third-party trackers declaratively.', 'tuedion-cookie'); ?>
                </p>

                <!-- Adapter Status Badges -->
                <div class="tdcc-adapters-grid">
                    <div class="tdcc-adapter-card">
                        <div class="tdcc-adapter-header">
                            <strong><?php echo esc_html__('WooCommerce', 'tuedion-cookie'); ?></strong>
                            <?php if ($wcActive): ?>
                                <span class="tdcc-badge tdcc-badge-enabled"><?php echo esc_html__('Active & Protected', 'tuedion-cookie'); ?></span>
                            <?php else: ?>
                                <span class="tdcc-badge tdcc-badge-neutral"><?php echo esc_html__('Not Installed', 'tuedion-cookie'); ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="tdcc-adapter-desc">
                            <?php echo esc_html__('Cart session & checkout cookies are strictly protected under necessary category with zero checkout disruption.', 'tuedion-cookie'); ?>
                        </p>
                    </div>

                    <div class="tdcc-adapter-card">
                        <div class="tdcc-adapter-header">
                            <strong><?php echo esc_html__('Elementor', 'tuedion-cookie'); ?></strong>
                            <?php if ($elemActive): ?>
                                <span class="tdcc-badge tdcc-badge-enabled"><?php echo esc_html__('Active & Protected', 'tuedion-cookie'); ?></span>
                            <?php else: ?>
                                <span class="tdcc-badge tdcc-badge-neutral"><?php echo esc_html__('Not Installed', 'tuedion-cookie'); ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="tdcc-adapter-desc">
                            <?php echo esc_html__('Video & map widgets automatically converted to consent placeholder cards with popup reactivation support.', 'tuedion-cookie'); ?>
                        </p>
                    </div>

                    <div class="tdcc-adapter-card">
                        <div class="tdcc-adapter-header">
                            <strong><?php echo esc_html__('Form Plugins (CF7 / WPForms / GF)', 'tuedion-cookie'); ?></strong>
                            <?php if (in_array(true, $formPlugins, true)): ?>
                                <span class="tdcc-badge tdcc-badge-enabled"><?php echo esc_html__('Active & Guarded', 'tuedion-cookie'); ?></span>
                            <?php else: ?>
                                <span class="tdcc-badge tdcc-badge-neutral"><?php echo esc_html__('Standard Core Forms', 'tuedion-cookie'); ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="tdcc-adapter-desc">
                            <?php echo esc_html__('Anti-spam verification and CAPTCHA challenges guarded with accessible unlock notices to prevent silent form failures.', 'tuedion-cookie'); ?>
                        </p>
                    </div>

                    <div class="tdcc-adapter-card">
                        <div class="tdcc-adapter-header">
                            <strong><?php echo esc_html__('Speed & Cache Engines', 'tuedion-cookie'); ?></strong>
                            <span class="tdcc-badge tdcc-badge-enabled"><?php echo esc_html__('12 Engines Supported', 'tuedion-cookie'); ?></span>
                        </div>
                        <p class="tdcc-adapter-desc">
                            <?php echo esc_html__('WP Rocket, LiteSpeed, Autoptimize, FlyingPress, Perfmatters & Cloudflare Rocket Loader exclusions active.', 'tuedion-cookie'); ?>
                        </p>
                    </div>
                </div>

                <!-- Scanner v3 Professional Console -->
                <?php
                $scanResults = CookieScanner::getResults();
                $detectedServices = (array) ($scanResults['detected_services'] ?? []);
                $detectedCookies = (array) ($scanResults['detected_cookies'] ?? []);
                $scanStats = (array) ($scanResults['stats'] ?? []);
                $lastScanDate = (string) ($scanResults['last_scan_date'] ?? '');
                $lastScanHuman = (string) ($scanResults['last_scan_human'] ?? '');
                $lastScanMode = (string) ($scanResults['mode'] ?? 'quick');
                $configuredServiceIds = array_column($services, 'id');
                $detectedCount = count($detectedServices);
                $scannerConfig = (array) ($settings['scanner'] ?? Defaults::get()['scanner']);
                $cronEnabled = !empty($scannerConfig['cron_enabled']);
                $cronSchedule = (string) ($scannerConfig['schedule'] ?? 'weekly');
                $scheduleLabels = [
                    'daily'   => __('Daily', 'tuedion-cookie'),
                    'weekly'  => __('Weekly', 'tuedion-cookie'),
                    'monthly' => __('Monthly', 'tuedion-cookie'),
                ];
                $cronScheduleLabel = $scheduleLabels[$cronSchedule] ?? __('Weekly', 'tuedion-cookie');
                $availablePostTypes = CookieScanner::getAvailablePostTypes();
                $configuredScanTargets = (array) ($scannerConfig['scan_targets'] ?? ['home', 'page', 'post']);

                // Scanner v3 Intelligence Layer Data
                $unknownDetector = new \Tuedion\CookieConsent\Scanner\Detection\UnknownResourceDetector();
                $unknownResources = $unknownDetector->getAll();
                $unknownCount = count($unknownResources);

                $scanHistory = \Tuedion\CookieConsent\Scanner\ScanRepository::getHistory(10);
                $latestScan = \Tuedion\CookieConsent\Scanner\ScanRepository::getLatestScan();
                $latestDiff = $latestScan ? $latestScan->diff : ($scanResults['diff'] ?? []);

                $changeWatcher = new \Tuedion\CookieConsent\Scanner\Smart\ChangeWatcher();
                $hasStructuralChanges = $changeWatcher->hasChanges();
                $pendingChange = $changeWatcher->getPendingChange();
                ?>

                <?php if ($hasStructuralChanges && $pendingChange): ?>
                    <div class="tdcc-notice-banner tdcc-notice-warning" id="tdcc-drift-banner">
                        <div class="tdcc-notice-content">
                            <span class="dashicons dashicons-warning tdcc-notice-icon"></span>
                            <div>
                                <strong><?php echo esc_html__('Structural Site Changes Detected:', 'tuedion-cookie'); ?></strong>
                                <?php
                                $cType = (string) ($pendingChange['type'] ?? '');
                                if ($cType === 'plugin_activated') {
                                    /* translators: %s: Plugin file */
                                    echo esc_html(sprintf(__('A WordPress plugin (%s) was recently activated.', 'tuedion-cookie'), $pendingChange['context']['plugin'] ?? ''));
                                } elseif ($cType === 'plugin_deactivated') {
                                    /* translators: %s: Plugin file */
                                    echo esc_html(sprintf(__('A WordPress plugin (%s) was recently deactivated.', 'tuedion-cookie'), $pendingChange['context']['plugin'] ?? ''));
                                } elseif ($cType === 'theme_switched') {
                                    /* translators: %s: Theme name */
                                    echo esc_html(sprintf(__('The active theme was switched to "%s".', 'tuedion-cookie'), $pendingChange['context']['theme'] ?? ''));
                                } else {
                                    echo esc_html__('Plugins or theme configurations have recently changed.', 'tuedion-cookie');
                                }
                                ?>
                                <span class="tdcc-notice-sub"><?php echo esc_html__('We recommend running a Smart Scan to detect any newly introduced cookies or third-party tracking scripts.', 'tuedion-cookie'); ?></span>
                            </div>
                        </div>
                        <button type="button" class="button button-small button-secondary tdcc-client-scan-btn" data-mode="smart" data-nonce="<?php echo esc_attr(wp_create_nonce('tuedion_scanner_action')); ?>">
                            <?php echo esc_html__('Run Smart Scan Now', 'tuedion-cookie'); ?>
                        </button>
                    </div>
                <?php endif; ?>

                <div class="tdcc-scanner-hero">
                    <div class="tdcc-scanner-header">
                        <div class="tdcc-scanner-title">
                            <span class="dashicons dashicons-search"></span>
                            <div>
                                <h3>
                                    <?php echo esc_html__('Website Tracker & Service Scanner v3', 'tuedion-cookie'); ?>
                                </h3>
                                <p>
                                    <?php if (!empty($lastScanDate)): ?>
                                        <?php
                                        /* translators: 1: Scan date, 2: Human readable relative time, 3: Scan mode */
                                        echo esc_html(sprintf(__('Last scan: %1$s (%2$s) via %3$s Scan', 'tuedion-cookie'), $lastScanDate, $lastScanHuman, ucfirst($lastScanMode)));
                                        ?>
                                    <?php else: ?>
                                        <?php echo esc_html__('No scan executed yet. Run a site scan to detect active services and cookies.', 'tuedion-cookie'); ?>
                                    <?php endif; ?>
                                </p>
                                <div class="tdcc-scanner-badges">
                                    <?php if ($cronEnabled): ?>
                                        <span class="tdcc-badge tdcc-badge-inline tdcc-badge-cron-on">
                                            <span class="dashicons dashicons-backup"></span>
                                            <?php
                                            /* translators: %s: Cron schedule frequency label */
                                            echo esc_html(sprintf(__('WP-Cron Auto-Scan: Active (%s)', 'tuedion-cookie'), $cronScheduleLabel));
                                            ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="tdcc-badge tdcc-badge-inline tdcc-badge-cron-off">
                                            <span class="dashicons dashicons-backup"></span>
                                            <?php echo esc_html__('WP-Cron Auto-Scan: Off', 'tuedion-cookie'); ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="tdcc-badge tdcc-badge-inline tdcc-badge-database">
                                        <span class="dashicons dashicons-database"></span>
                                        <?php
                                        $dbMeta = \Tuedion\CookieConsent\Database\CookieDatabase::getMetadata();
                                        /* translators: 1: Record count, 2: Version date */
                                        echo esc_html(sprintf(__('Open Cookie DB: %1$d patterns (%2$s)', 'tuedion-cookie'), $dbMeta['records_count'] ?? 0, $dbMeta['version'] ?? 'bundled'));
                                        ?>
                                    </span>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=tuedion-cookie-settings#tdcc-scanner-settings')); ?>" class="tdcc-scanner-badge-link">
                                        <span class="dashicons dashicons-admin-generic"></span>
                                        <?php echo esc_html__('Configure Scheduled Scans & Email Alerts', 'tuedion-cookie'); ?> &rarr;
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div class="tdcc-scanner-actions">
                            <button type="button" class="button button-primary tdcc-client-scan-btn" id="tdcc-primary-scan-btn" data-nonce="<?php echo esc_attr(wp_create_nonce('tuedion_scanner_action')); ?>">
                                <span class="dashicons dashicons-update"></span>
                                <?php echo esc_html__('Scan Website Now', 'tuedion-cookie'); ?>
                            </button>

                            <button type="button" class="button button-secondary" id="tdcc-update-cookie-db-btn" data-nonce="<?php echo esc_attr(wp_create_nonce('tuedion_scanner_action')); ?>" title="<?php echo esc_attr__('Fetch latest open cookie definitions from Open Cookie Database', 'tuedion-cookie'); ?>">
                                <span class="dashicons dashicons-cloud"></span>
                                <?php echo esc_html__('Update Cookie DB', 'tuedion-cookie'); ?>
                            </button>

                            <button type="button" class="button button-secondary tdcc-btn-danger" id="tdcc-reset-scan-btn" data-nonce="<?php echo esc_attr(wp_create_nonce('tuedion_scanner_action')); ?>" title="<?php echo esc_attr__('Reset all scanned services, discovered cookies, and history', 'tuedion-cookie'); ?>">
                                <span class="dashicons dashicons-trash"></span>
                                <?php echo esc_html__('Clear Scan Results', 'tuedion-cookie'); ?>
                            </button>

                            <?php if ($detectedCount > 0): ?>
                                <form method="post" action="" class="tdcc-sync-detected-form">
                                    <?php wp_nonce_field('tuedion_sync_action', 'tuedion_sync_nonce'); ?>
                                    <button type="submit" name="tuedion_sync_detected_services" class="button button-secondary" id="tdcc-sync-detected-btn" title="<?php echo esc_attr__('Automatically add all detected services to your visitor cookie preferences', 'tuedion-cookie'); ?>">
                                        <span class="dashicons dashicons-yes-alt"></span>
                                        <?php echo esc_html__('Sync Detected to Preferences', 'tuedion-cookie'); ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 3 Scan Modes Selector Cards -->
                    <div class="tdcc-scan-modes-wrap">
                        <div class="tdcc-modes-header">
                            <strong><?php echo esc_html__('Select Scan Mode:', 'tuedion-cookie'); ?></strong>
                        </div>
                        <div class="tdcc-scan-modes-grid" id="tdcc-scan-modes-container">
                            <div class="tdcc-mode-card is-active" data-mode="quick">
                                <div class="tdcc-mode-radio">
                                    <input type="radio" name="active_scan_mode" id="tdcc-mode-radio-quick" value="quick" checked>
                                </div>
                                <div class="tdcc-mode-content">
                                    <div class="tdcc-mode-title-row">
                                        <span class="dashicons dashicons-clock tdcc-mode-icon"></span>
                                        <span class="tdcc-mode-name"><?php echo esc_html__('Quick Scan', 'tuedion-cookie'); ?></span>
                                        <span class="tdcc-mode-pill"><?php echo esc_html__('Fast Sampling', 'tuedion-cookie'); ?></span>
                                    </div>
                                    <p class="tdcc-mode-desc">
                                        <?php echo esc_html__('Audits representative pages and selected custom post types via intelligent WordPress sampling.', 'tuedion-cookie'); ?>
                                    </p>
                                </div>
                            </div>

                            <div class="tdcc-mode-card" data-mode="smart">
                                <div class="tdcc-mode-radio">
                                    <input type="radio" name="active_scan_mode" id="tdcc-mode-radio-smart" value="smart">
                                </div>
                                <div class="tdcc-mode-content">
                                    <div class="tdcc-mode-title-row">
                                        <span class="dashicons dashicons-networking tdcc-mode-icon"></span>
                                        <span class="tdcc-mode-name"><?php echo esc_html__('Smart Scan', 'tuedion-cookie'); ?></span>
                                        <span class="tdcc-mode-pill tdcc-pill-smart"><?php echo esc_html__('Recommended', 'tuedion-cookie'); ?></span>
                                    </div>
                                    <p class="tdcc-mode-desc">
                                        <?php echo esc_html__('Discovers URLs from XML Sitemaps, Navigation Menus, and WooCommerce/WordPress critical pages.', 'tuedion-cookie'); ?>
                                    </p>
                                </div>
                            </div>

                            <div class="tdcc-mode-card" data-mode="full">
                                <div class="tdcc-mode-radio">
                                    <input type="radio" name="active_scan_mode" id="tdcc-mode-radio-full" value="full">
                                </div>
                                <div class="tdcc-mode-content">
                                    <div class="tdcc-mode-title-row">
                                        <span class="dashicons dashicons-admin-site-alt3 tdcc-mode-icon"></span>
                                        <span class="tdcc-mode-name"><?php echo esc_html__('Full Website Scan', 'tuedion-cookie'); ?></span>
                                        <span class="tdcc-mode-pill tdcc-pill-full"><?php echo esc_html__('Browser Runtime', 'tuedion-cookie'); ?></span>
                                    </div>
                                    <p class="tdcc-mode-desc">
                                        <?php echo esc_html__('Deep runtime audit with simulated scroll, delayed tracker detection, and network beacon interception.', 'tuedion-cookie'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Dynamic Configuration Drawer per Mode -->
                    <div class="tdcc-mode-settings-drawer">
                        <!-- Quick Scan Settings Panel -->
                        <div class="tdcc-mode-panel is-active" id="tdcc-panel-mode-quick">
                            <div class="tdcc-panel-options-row">
                                <div class="tdcc-option-group">
                                    <label for="tdcc-quick-strategy"><strong><?php echo esc_html__('Sampling Strategy:', 'tuedion-cookie'); ?></strong></label>
                                    <select id="tdcc-quick-strategy" name="quick_strategy" class="tdcc-custom-select">
                                        <option value="representative" selected><?php echo esc_html__('Representative Sample (Default)', 'tuedion-cookie'); ?></option>
                                        <option value="recently_modified"><?php echo esc_html__('Recently Modified Pages', 'tuedion-cookie'); ?></option>
                                        <option value="recently_published"><?php echo esc_html__('Recently Published Pages', 'tuedion-cookie'); ?></option>
                                        <option value="random"><?php echo esc_html__('Random Uniform Sample', 'tuedion-cookie'); ?></option>
                                    </select>
                                </div>
                                <div class="tdcc-option-group">
                                    <label for="tdcc-quick-sample-size"><strong><?php echo esc_html__('Sample Size per Type:', 'tuedion-cookie'); ?></strong></label>
                                    <select id="tdcc-quick-sample-size" name="quick_sample_size" class="tdcc-custom-select">
                                        <option value="1" selected>1 <?php echo esc_html__('page per type', 'tuedion-cookie'); ?></option>
                                        <option value="2">2 <?php echo esc_html__('pages per type', 'tuedion-cookie'); ?></option>
                                        <option value="3">3 <?php echo esc_html__('pages per type', 'tuedion-cookie'); ?></option>
                                        <option value="5">5 <?php echo esc_html__('pages per type', 'tuedion-cookie'); ?></option>
                                    </select>
                                </div>
                            </div>

                            <div class="tdcc-scanner-scope-bar">
                                <div class="tdcc-scope-title">
                                    <span class="dashicons dashicons-category"></span>
                                    <strong><?php echo esc_html__('Target Post Types:', 'tuedion-cookie'); ?></strong>
                                    <span class="tdcc-scope-hint"><?php echo esc_html__('Check post types to include in sampling', 'tuedion-cookie'); ?></span>
                                </div>
                                <div class="tdcc-scope-chips" id="tdcc-scanner-scope-items">
                                    <?php foreach ($availablePostTypes as $ptKey => $ptInfo):
                                        $isHome = ($ptKey === 'home');
                                        $isChecked = $isHome || in_array($ptKey, $configuredScanTargets, true);
                                    ?>
                                        <label class="tdcc-scope-chip <?php echo $isChecked ? 'is-checked' : ''; ?> <?php echo $isHome ? 'is-required' : ''; ?>">
                                            <input type="checkbox" name="scan_scope[]" value="<?php echo esc_attr($ptKey); ?>" <?php checked($isChecked); ?> <?php disabled($isHome); ?>>
                                            <span class="tdcc-chip-icon dashicons <?php echo esc_attr($ptInfo['icon'] ?? 'dashicons-admin-post'); ?>"></span>
                                            <span class="tdcc-chip-label"><?php echo esc_html($ptInfo['label']); ?></span>
                                            <?php if (!$isHome && isset($ptInfo['count'])): ?>
                                                <?php
                                                /* translators: %d: Number of published posts */
                                                $publishedCountTitle = sprintf(__('%d published', 'tuedion-cookie'), $ptInfo['count']);
                                                ?>
                                                <span class="tdcc-chip-count" title="<?php echo esc_attr($publishedCountTitle); ?>"><?php echo esc_html((string) $ptInfo['count']); ?></span>
                                            <?php endif; ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Smart Scan Settings Panel -->
                        <div class="tdcc-mode-panel" id="tdcc-panel-mode-smart">
                            <div class="tdcc-panel-options-row">
                                <div class="tdcc-option-group">
                                    <strong><?php echo esc_html__('URL Discovery Sources:', 'tuedion-cookie'); ?></strong>
                                    <div class="tdcc-checkbox-group">
                                        <label>
                                            <input type="checkbox" id="tdcc-smart-sitemap" name="smart_sitemap" value="1" checked>
                                            <?php echo esc_html__('XML Sitemaps (/wp-sitemap.xml)', 'tuedion-cookie'); ?>
                                        </label>
                                        <label>
                                            <input type="checkbox" id="tdcc-smart-menus" name="smart_menus" value="1" checked>
                                            <?php echo esc_html__('WordPress Navigation Menus', 'tuedion-cookie'); ?>
                                        </label>
                                        <label>
                                            <input type="checkbox" id="tdcc-smart-critical" name="smart_critical" value="1" checked>
                                            <?php echo esc_html__('Critical Pages (Shop, Privacy, Front Page)', 'tuedion-cookie'); ?>
                                        </label>
                                    </div>
                                </div>
                                <div class="tdcc-option-group">
                                    <label for="tdcc-smart-max-urls"><strong><?php echo esc_html__('Max Discovered URLs:', 'tuedion-cookie'); ?></strong></label>
                                    <select id="tdcc-smart-max-urls" name="smart_max_urls" class="tdcc-custom-select">
                                        <option value="15">15 <?php echo esc_html__('URLs', 'tuedion-cookie'); ?></option>
                                        <option value="30" selected>30 <?php echo esc_html__('URLs (Recommended)', 'tuedion-cookie'); ?></option>
                                        <option value="50">50 <?php echo esc_html__('URLs', 'tuedion-cookie'); ?></option>
                                        <option value="100">100 <?php echo esc_html__('URLs', 'tuedion-cookie'); ?></option>
                                    </select>
                                </div>
                            </div>

                            <div class="tdcc-panel-filters-row">
                                <div class="tdcc-filter-col">
                                    <label for="tdcc-smart-exclude"><strong><?php echo esc_html__('Exclude URL Patterns (One per line):', 'tuedion-cookie'); ?></strong></label>
                                    <textarea id="tdcc-smart-exclude" name="smart_exclude" rows="2" class="large-text code" placeholder="/private/*&#10;/members/*"></textarea>
                                </div>
                                <div class="tdcc-filter-col">
                                    <label for="tdcc-smart-include"><strong><?php echo esc_html__('Include Only Patterns (Optional):', 'tuedion-cookie'); ?></strong></label>
                                    <textarea id="tdcc-smart-include" name="smart_include" rows="2" class="large-text code" placeholder="/shop/*&#10;/blog/*"></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Full Website Scan Settings Panel -->
                        <div class="tdcc-mode-panel" id="tdcc-panel-mode-full">
                            <div class="tdcc-panel-options-row">
                                <div class="tdcc-option-group">
                                    <label for="tdcc-full-delay"><strong><?php echo esc_html__('Delayed Tracker Wait Time:', 'tuedion-cookie'); ?></strong></label>
                                    <select id="tdcc-full-delay" name="full_delay" class="tdcc-custom-select">
                                        <option value="1500">1.5 <?php echo esc_html__('seconds', 'tuedion-cookie'); ?></option>
                                        <option value="2500" selected>2.5 <?php echo esc_html__('seconds (GTM & Meta Beacons)', 'tuedion-cookie'); ?></option>
                                        <option value="4000">4.0 <?php echo esc_html__('seconds (Heavy scripts)', 'tuedion-cookie'); ?></option>
                                    </select>
                                </div>
                                <div class="tdcc-option-group">
                                    <strong><?php echo esc_html__('Runtime Interactions:', 'tuedion-cookie'); ?></strong>
                                    <div class="tdcc-checkbox-group">
                                        <label>
                                            <input type="checkbox" id="tdcc-full-scroll" name="full_scroll" value="1" checked>
                                            <?php echo esc_html__('Simulate Smooth Scroll to trigger lazy-loaded iframes and scripts', 'tuedion-cookie'); ?>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Live Scanner Multi-Step Progress Bar -->
                    <div class="tdcc-scanner-progress" id="tdcc-scanner-progress" aria-hidden="true">
                        <div class="tdcc-progress-header">
                            <div class="tdcc-progress-status">
                                <span class="dashicons dashicons-update tdcc-spin"></span>
                                <span class="tdcc-progress-label" id="tdcc-progress-label"><?php echo esc_html__('Initializing scan audit...', 'tuedion-cookie'); ?></span>
                            </div>
                            <div class="tdcc-progress-percent" id="tdcc-progress-percent">0%</div>
                        </div>
                        <div class="tdcc-progress-track">
                            <div class="tdcc-progress-bar" id="tdcc-progress-bar"></div>
                        </div>
                        <div class="tdcc-progress-meta">
                            <span class="tdcc-progress-step" id="tdcc-progress-step"><?php echo esc_html__('Planning crawl targets...', 'tuedion-cookie'); ?></span>
                            <span class="tdcc-progress-url" id="tdcc-progress-url"></span>
                        </div>
                    </div>

                    <!-- Metrics -->
                    <div class="tdcc-scanner-metrics">
                        <div class="tdcc-scanner-stat">
                            <div class="tdcc-scanner-stat-num is-active" id="tdcc-metric-services">
                                <?php echo esc_html((string) $detectedCount); ?>
                            </div>
                            <div class="tdcc-scanner-stat-label">
                                <?php echo esc_html__('Active Services on Site', 'tuedion-cookie'); ?>
                            </div>
                        </div>

                        <div class="tdcc-scanner-stat">
                            <div class="tdcc-scanner-stat-num is-discovered" id="tdcc-metric-cookies">
                                <?php echo esc_html((string) count($detectedCookies)); ?>
                            </div>
                            <div class="tdcc-scanner-stat-label">
                                <?php echo esc_html__('Discovered Cookies', 'tuedion-cookie'); ?>
                            </div>
                        </div>

                        <div class="tdcc-scanner-stat">
                            <div class="tdcc-scanner-stat-num <?php echo $unknownCount > 0 ? 'is-warning' : 'is-neutral'; ?>" id="tdcc-metric-unknowns">
                                <?php echo esc_html((string) $unknownCount); ?>
                            </div>
                            <div class="tdcc-scanner-stat-label">
                                <?php echo esc_html__('Unknown Resources', 'tuedion-cookie'); ?>
                            </div>
                        </div>

                        <div class="tdcc-scanner-stat">
                            <div class="tdcc-scanner-stat-num is-catalogue">
                                <?php echo esc_html((string) count($allRecipes)); ?>
                            </div>
                            <div class="tdcc-scanner-stat-label">
                                <?php echo esc_html__('Global Recipes Catalogue', 'tuedion-cookie'); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 5 Consolidated Navigation Tabs -->
                <div class="tdcc-catalogue-nav">
                    <div class="tdcc-nav-pills">
                        <button type="button" class="tdcc-nav-pill is-active" data-target="pane-detected">
                            <span class="dashicons dashicons-yes"></span>
                            <?php echo esc_html__('Detected Services', 'tuedion-cookie'); ?>
                            <span class="count"><?php echo esc_html((string) $detectedCount); ?></span>
                        </button>

                        <button type="button" class="tdcc-nav-pill" data-target="pane-cookies">
                            <span class="dashicons dashicons-database"></span>
                            <?php echo esc_html__('Discovered Cookies', 'tuedion-cookie'); ?>
                            <span class="count"><?php echo esc_html((string) count($detectedCookies)); ?></span>
                        </button>

                        <button type="button" class="tdcc-nav-pill <?php echo $unknownCount > 0 ? 'has-badge-alert' : ''; ?>" data-target="pane-unknowns">
                            <span class="dashicons dashicons-flag"></span>
                            <?php echo esc_html__('Unknown Resources', 'tuedion-cookie'); ?>
                            <span class="count"><?php echo esc_html((string) $unknownCount); ?></span>
                        </button>

                        <button type="button" class="tdcc-nav-pill" data-target="pane-history">
                            <span class="dashicons dashicons-backup"></span>
                            <?php echo esc_html__('Scan History & Diffs', 'tuedion-cookie'); ?>
                            <span class="count"><?php echo esc_html((string) count($scanHistory)); ?></span>
                        </button>

                        <button type="button" class="tdcc-nav-pill" data-target="pane-catalogue">
                            <span class="dashicons dashicons-category"></span>
                            <?php echo esc_html__('All Supported Presets', 'tuedion-cookie'); ?>
                            <span class="count"><?php echo esc_html((string) count($allRecipes)); ?></span>
                        </button>
                    </div>

                    <div class="tdcc-search-wrap">
                        <input type="search" id="tdcc-preset-search" placeholder="<?php echo esc_attr__('Search services, cookies, domains...', 'tuedion-cookie'); ?>" class="regular-text tdcc-search-input">
                    </div>
                </div>

                <!-- Pane 1: Detected Services on This Site -->
                <div id="pane-detected" class="tdcc-catalogue-pane is-active">
                    <?php if (empty($detectedServices)): ?>
                        <div class="tdcc-scanner-empty">
                            <span class="dashicons dashicons-search"></span>
                            <h4><?php echo esc_html__('No Active Services Detected Yet', 'tuedion-cookie'); ?></h4>
                            <p><?php echo esc_html__('Run a real-time crawl to scan your pages, enqueued scripts, and embedded iframes (e.g. YouTube, Vimeo, Google Maps, GA4).', 'tuedion-cookie'); ?></p>
                            <button type="button" class="button button-primary tdcc-client-scan-btn" data-nonce="<?php echo esc_attr(wp_create_nonce('tuedion_scanner_action')); ?>">
                                <?php echo esc_html__('Run First Website Scan', 'tuedion-cookie'); ?>
                            </button>
                        </div>
                    <?php else: ?>
                        <table class="widefat striped tdcc-searchable-table" role="presentation">
                            <thead>
                                <tr>
                                    <th scope="col"><?php echo esc_html__('Active Service', 'tuedion-cookie'); ?></th>
                                    <th scope="col"><?php echo esc_html__('Category', 'tuedion-cookie'); ?></th>
                                    <th scope="col"><?php echo esc_html__('Detection Origin / Evidence', 'tuedion-cookie'); ?></th>
                                    <th scope="col"><?php echo esc_html__('Cookie Patterns', 'tuedion-cookie'); ?></th>
                                    <th scope="col"><?php echo esc_html__('Preferences Status', 'tuedion-cookie'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($detectedServices as $dsId => $ds):
                                    $isConfigured = in_array($dsId, $configuredServiceIds, true);
                                ?>
                                    <tr data-preset-row="<?php echo esc_attr($dsId); ?>">
                                        <td>
                                            <strong><?php echo esc_html($ds['name'] ?? $dsId); ?></strong>
                                            <br><code class="tdcc-code-id"><?php echo esc_html($dsId); ?></code>
                                            <span class="tdcc-badge tdcc-badge-type">
                                                <?php echo esc_html(strtoupper($ds['type'] ?? 'SCRIPT')); ?>
                                            </span>
                                            <?php if (!empty($ds['confidence_label'])): ?>
                                                <?php
                                                /* translators: %d: Confidence score percentage */
                                                $confidenceTitle = sprintf(__('Confidence score: %d%%', 'tuedion-cookie'), $ds['confidence_score'] ?? 100);
                                                ?>
                                                <span class="tdcc-confidence-badge <?php echo esc_attr($ds['badge_class'] ?? 'tdcc-badge-confirmed'); ?>" title="<?php echo esc_attr($confidenceTitle); ?>">
                                                    <?php echo esc_html($ds['confidence_label']); ?> (<?php echo esc_html((string) ($ds['confidence_score'] ?? 100)); ?>%)
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <code><?php echo esc_html($ds['category'] ?? 'marketing'); ?></code>
                                        </td>
                                        <td class="tdcc-source-cell">
                                            <?php if (!empty($ds['evidence']) && is_array($ds['evidence'])): ?>
                                                <?php foreach ($ds['evidence'] as $ev): ?>
                                                    <div class="tdcc-source-line tdcc-evidence-line">
                                                        <span class="dashicons dashicons-yes-alt"></span>
                                                        <strong><?php echo esc_html(ucfirst(str_replace('_', ' ', (string) ($ev['type'] ?? 'signal')))); ?>:</strong>
                                                        <span><?php echo esc_html((string) ($ev['value'] ?? '')); ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <?php
                                                $sources = (array) ($ds['sources'] ?? [$ds['source'] ?? '']);
                                                foreach ($sources as $srcLine):
                                                ?>
                                                    <div class="tdcc-source-line">
                                                        <span class="dashicons dashicons-yes"></span>
                                                        <span><?php echo wp_kses_post($srcLine); ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $autoClear = (array) ($ds['auto_clear'] ?? []);
                                            if (empty($autoClear)):
                                            ?>
                                                <em><?php echo esc_html__('Third-party session', 'tuedion-cookie'); ?></em>
                                            <?php else: ?>
                                                <code><?php echo esc_html(implode(', ', $autoClear)); ?></code>
                                            <?php endif; ?>
                                        </td>
                                        <td class="tdcc-action-cell" data-preset-cell="<?php echo esc_attr($dsId); ?>">
                                            <?php if ($isConfigured): ?>
                                                <span class="tdcc-badge tdcc-badge-enabled tdcc-status-in-prefs">
                                                    <span class="dashicons dashicons-yes"></span>
                                                    <?php echo esc_html__('Configured in Preferences', 'tuedion-cookie'); ?>
                                                </span>
                                            <?php else: ?>
                                                <form method="post" action="" class="tdcc-add-preset-form" data-preset="<?php echo esc_attr($dsId); ?>">
                                                    <?php wp_nonce_field('tuedion_add_preset_action', 'tuedion_add_preset_nonce'); ?>
                                                    <input type="hidden" name="preset_key" value="<?php echo esc_attr($dsId); ?>">
                                                    <button type="submit" name="tuedion_add_preset_service" class="button button-small button-secondary tdcc-add-preset-btn" data-preset="<?php echo esc_attr($dsId); ?>" data-context="detected">
                                                        <?php echo esc_html__('+ Add to Preferences', 'tuedion-cookie'); ?>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Pane 2: Discovered Cookies Inventory Table -->
                <div id="pane-cookies" class="tdcc-catalogue-pane">
                    <?php if (empty($detectedCookies)): ?>
                        <p class="tdcc-empty-notice">
                            <?php echo esc_html__('No cookies mapped yet. Run a site scan to discover active cookies.', 'tuedion-cookie'); ?>
                        </p>
                    <?php else: ?>
                        <div class="tdcc-pane-actions-bar">
                            <span class="tdcc-pane-count-info">
                                <?php
                                /* translators: %d: Discovered cookie count */
                                echo esc_html(sprintf(__('Found %d cookies across scanned pages.', 'tuedion-cookie'), count($detectedCookies)));
                                ?>
                            </span>
                            <button type="button" class="button button-secondary button-small tdcc-btn-danger tdcc-trigger-reset-scan" data-nonce="<?php echo esc_attr(wp_create_nonce('tuedion_scanner_action')); ?>" title="<?php echo esc_attr__('Clear all discovered cookie records', 'tuedion-cookie'); ?>">
                                <span class="dashicons dashicons-trash"></span>
                                <?php echo esc_html__('Clear All Discovered Cookies', 'tuedion-cookie'); ?>
                            </button>
                        </div>
                        <table class="widefat striped tdcc-searchable-table" role="presentation">
                            <thead>
                                <tr>
                                    <th scope="col"><?php echo esc_html__('Cookie Identifier', 'tuedion-cookie'); ?></th>
                                    <th scope="col"><?php echo esc_html__('Provider / Service', 'tuedion-cookie'); ?></th>
                                    <th scope="col"><?php echo esc_html__('Category', 'tuedion-cookie'); ?></th>
                                    <th scope="col"><?php echo esc_html__('Domain', 'tuedion-cookie'); ?></th>
                                    <th scope="col"><?php echo esc_html__('Duration', 'tuedion-cookie'); ?></th>
                                    <th scope="col"><?php echo esc_html__('Description / Purpose', 'tuedion-cookie'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($detectedCookies as $c): ?>
                                    <tr>
                                        <td>
                                            <strong class="tdcc-cookie-name"><?php echo esc_html($c['name']); ?></strong>
                                            <?php if (!empty($c['source']) && $c['source'] === 'database'): ?>
                                                <span class="tdcc-badge-db" title="<?php echo esc_attr__('Verified via Open Cookie Database', 'tuedion-cookie'); ?>">
                                                    <?php echo esc_html__('Open Cookie DB', 'tuedion-cookie'); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html($c['service']); ?></td>
                                        <td><code><?php echo esc_html($c['category']); ?></code></td>
                                        <td><code><?php echo esc_html($c['domain']); ?></code></td>
                                        <td><?php echo esc_html($c['duration']); ?></td>
                                        <td class="tdcc-desc-cell">
                                            <?php echo esc_html($c['description']); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Pane 3: Unknown Resources Management -->
                <div id="pane-unknowns" class="tdcc-catalogue-pane">
                    <?php if (empty($unknownResources)): ?>
                        <div class="tdcc-scanner-empty">
                            <span class="dashicons dashicons-shield-alt"></span>
                            <h4><?php echo esc_html__('No Unknown Resources Detected', 'tuedion-cookie'); ?></h4>
                            <p><?php echo esc_html__('All external scripts, iframes, pixels, and cookies found on this site match recognized entries in the Service Registry and Open Cookie Database.', 'tuedion-cookie'); ?></p>
                        </div>
                    <?php else: ?>
                        <div class="tdcc-pane-top-actions">
                            <p class="tdcc-pane-intro">
                                <?php echo esc_html__('The following resources were detected during scans but do not match known global service recipes. You can convert them into custom services or mark them as ignored.', 'tuedion-cookie'); ?>
                            </p>
                            <button type="button" class="button button-small button-link-delete" id="tdcc-clear-unknowns-btn" data-nonce="<?php echo esc_attr(wp_create_nonce('tuedion_scanner_action')); ?>">
                                <?php echo esc_html__('Clear All Unknown Resources', 'tuedion-cookie'); ?>
                            </button>
                        </div>
                        <table class="widefat striped tdcc-searchable-table" role="presentation">
                            <thead>
                                <tr>
                                    <th scope="col"><?php echo esc_html__('Type', 'tuedion-cookie'); ?></th>
                                    <th scope="col"><?php echo esc_html__('Resource Identifier / Domain', 'tuedion-cookie'); ?></th>
                                    <th scope="col"><?php echo esc_html__('First Found On', 'tuedion-cookie'); ?></th>
                                    <th scope="col"><?php echo esc_html__('Status', 'tuedion-cookie'); ?></th>
                                    <th scope="col"><?php echo esc_html__('Actions', 'tuedion-cookie'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($unknownResources as $u):
                                    $uId = (string) $u['id'];
                                    $uStatus = (string) ($u['status'] ?? 'pending');
                                    $uType = (string) ($u['type'] ?? 'script');
                                ?>
                                    <tr data-unknown-row="<?php echo esc_attr($uId); ?>">
                                        <td>
                                            <span class="tdcc-badge tdcc-badge-type tdcc-badge-<?php echo esc_attr($uType); ?>">
                                                <?php echo esc_html(strtoupper($uType)); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong><?php echo esc_html($u['domain'] ?? ''); ?></strong>
                                            <br><code class="tdcc-code-url"><?php echo esc_html($u['identifier'] ?? ''); ?></code>
                                        </td>
                                        <td>
                                            <?php if (!empty($u['first_found_url'])): ?>
                                                <a href="<?php echo esc_url($u['first_found_url']); ?>" target="_blank" rel="noopener noreferrer" class="tdcc-url-link">
                                                    <?php echo esc_html(wp_parse_url($u['first_found_url'], PHP_URL_PATH) ?: '/'); ?>
                                                    <span class="dashicons dashicons-external"></span>
                                                </a>
                                            <?php else: ?>
                                                &mdash;
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($uStatus === 'ignored'): ?>
                                                <span class="tdcc-badge tdcc-badge-not-detected"><?php echo esc_html__('Ignored', 'tuedion-cookie'); ?></span>
                                            <?php elseif ($uStatus === 'classified'): ?>
                                                <span class="tdcc-badge tdcc-badge-enabled"><?php echo esc_html__('Classified', 'tuedion-cookie'); ?></span>
                                            <?php else: ?>
                                                <span class="tdcc-badge tdcc-badge-type"><?php echo esc_html__('Pending Review', 'tuedion-cookie'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="tdcc-action-cell">
                                            <div class="tdcc-action-btn-group">
                                                <button type="button" class="button button-small button-secondary tdcc-categorize-unknown-btn" data-id="<?php echo esc_attr($uId); ?>" data-domain="<?php echo esc_attr($u['domain'] ?? ''); ?>">
                                                    <?php echo esc_html__('+ Add Service', 'tuedion-cookie'); ?>
                                                </button>
                                                <?php if ($uStatus === 'ignored'): ?>
                                                    <button type="button" class="button button-small button-link tdcc-unignore-unknown-btn" data-id="<?php echo esc_attr($uId); ?>">
                                                        <?php echo esc_html__('Restore', 'tuedion-cookie'); ?>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="button button-small button-link tdcc-ignore-unknown-btn" data-id="<?php echo esc_attr($uId); ?>">
                                                        <?php echo esc_html__('Ignore', 'tuedion-cookie'); ?>
                                                    </button>
                                                <?php endif; ?>
                                                <button type="button" class="button button-small button-link-delete tdcc-delete-unknown-btn" data-id="<?php echo esc_attr($uId); ?>" title="<?php echo esc_attr__('Delete Record', 'tuedion-cookie'); ?>">
                                                    <span class="dashicons dashicons-trash"></span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Pane 4: Scan History & Diffs -->
                <div id="pane-history" class="tdcc-catalogue-pane">
                    <?php if (empty($scanHistory)): ?>
                        <div class="tdcc-scanner-empty">
                            <span class="dashicons dashicons-backup"></span>
                            <h4><?php echo esc_html__('No Scan History Recorded Yet', 'tuedion-cookie'); ?></h4>
                            <p><?php echo esc_html__('Run your first website scan to begin logging execution duration, drift summaries, and discovered trackers.', 'tuedion-cookie'); ?></p>
                        </div>
                    <?php else: ?>
                        <table class="widefat striped tdcc-searchable-table" role="presentation">
                            <thead>
                                <tr>
                                    <th scope="col"><?php echo esc_html__('Scan Run ID & Mode', 'tuedion-cookie'); ?></th>
                                    <th scope="col"><?php echo esc_html__('Timestamp', 'tuedion-cookie'); ?></th>
                                    <th scope="col"><?php echo esc_html__('Duration', 'tuedion-cookie'); ?></th>
                                    <th scope="col"><?php echo esc_html__('Pages Crawled', 'tuedion-cookie'); ?></th>
                                    <th scope="col"><?php echo esc_html__('Findings (Services / Cookies / Unknowns)', 'tuedion-cookie'); ?></th>
                                    <th scope="col"><?php echo esc_html__('Diff & Drift Summary', 'tuedion-cookie'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($scanHistory as $h): ?>
                                    <tr>
                                        <td>
                                            <code class="tdcc-code-id"><?php echo esc_html((string) ($h['id'] ?? 'scan_run')); ?></code>
                                            <span class="tdcc-badge tdcc-badge-mode-<?php echo esc_attr((string) ($h['mode'] ?? 'quick')); ?>">
                                                <?php echo esc_html(strtoupper((string) ($h['mode'] ?? 'quick'))); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong><?php echo esc_html((string) ($h['completed_at'] ?? $h['started_at'] ?? '')); ?></strong>
                                        </td>
                                        <td>
                                            <?php echo esc_html((string) ($h['duration_seconds'] ?? 0)); ?>s
                                        </td>
                                        <td>
                                            <?php echo esc_html((string) ($h['urls_scanned'] ?? 1)); ?> <?php echo esc_html__('pages', 'tuedion-cookie'); ?>
                                        </td>
                                        <td>
                                            <span class="tdcc-stat-chip is-active" title="<?php echo esc_attr__('Active Services', 'tuedion-cookie'); ?>">
                                                <?php echo esc_html((string) ($h['services_count'] ?? 0)); ?> <?php echo esc_html__('Services', 'tuedion-cookie'); ?>
                                            </span>
                                            <span class="tdcc-stat-chip is-discovered" title="<?php echo esc_attr__('Discovered Cookies', 'tuedion-cookie'); ?>">
                                                <?php echo esc_html((string) ($h['cookies_count'] ?? 0)); ?> <?php echo esc_html__('Cookies', 'tuedion-cookie'); ?>
                                            </span>
                                            <span class="tdcc-stat-chip is-neutral" title="<?php echo esc_attr__('Unknown Resources', 'tuedion-cookie'); ?>">
                                                <?php echo esc_html((string) ($h['unknown_count'] ?? 0)); ?> <?php echo esc_html__('Unknowns', 'tuedion-cookie'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($h['diff_summary'])): ?>
                                                <span class="tdcc-diff-summary <?php echo !empty($h['has_changes']) ? 'has-changes' : 'no-changes'; ?>">
                                                    <?php echo esc_html((string) $h['diff_summary']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="tdcc-diff-summary no-changes"><?php echo esc_html__('Baseline scan', 'tuedion-cookie'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Pane 5: All Supported Presets (22) -->
                <div id="pane-catalogue" class="tdcc-catalogue-pane">
                    <table class="widefat striped tdcc-searchable-table" role="presentation">
                        <thead>
                            <tr>
                                <th scope="col"><?php echo esc_html__('Service / Preset', 'tuedion-cookie'); ?></th>
                                <th scope="col"><?php echo esc_html__('Site Status', 'tuedion-cookie'); ?></th>
                                <th scope="col"><?php echo esc_html__('Type', 'tuedion-cookie'); ?></th>
                                <th scope="col"><?php echo esc_html__('Default Category', 'tuedion-cookie'); ?></th>
                                <th scope="col"><?php echo esc_html__('Auto-Cleared Cookie Patterns', 'tuedion-cookie'); ?></th>
                                <th scope="col"><?php echo esc_html__('Description', 'tuedion-cookie'); ?></th>
                                <th scope="col"><?php echo esc_html__('Action', 'tuedion-cookie'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allRecipes as $recKey => $rec):
                                $isDetectedOnSite = isset($detectedServices[$recKey]);
                                $isConfigured = in_array($recKey, $configuredServiceIds, true);
                            ?>
                                <tr data-preset-row="<?php echo esc_attr($recKey); ?>">
                                    <td>
                                        <strong><?php echo esc_html($rec['name'] ?? $recKey); ?></strong>
                                        <br><code class="tdcc-code-id"><?php echo esc_html($recKey); ?></code>
                                    </td>
                                    <td>
                                        <?php if ($isDetectedOnSite): ?>
                                            <span class="tdcc-badge tdcc-badge-enabled">
                                                <?php echo esc_html__('Active on Site', 'tuedion-cookie'); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="tdcc-badge tdcc-badge-not-detected">
                                                <?php echo esc_html__('Not Detected', 'tuedion-cookie'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="tdcc-badge tdcc-badge-type">
                                            <?php echo esc_html($rec['type'] ?? 'script'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <code><?php echo esc_html($rec['category'] ?? 'marketing'); ?></code>
                                    </td>
                                    <td>
                                        <?php
                                        $autoClear = (array) ($rec['auto_clear'] ?? []);
                                        if (empty($autoClear)):
                                        ?>
                                            <em><?php echo esc_html__('None / Third-party', 'tuedion-cookie'); ?></em>
                                        <?php else: ?>
                                            <code><?php echo esc_html(implode(', ', $autoClear)); ?></code>
                                        <?php endif; ?>
                                    </td>
                                    <td class="tdcc-desc-cell">
                                        <?php echo esc_html($rec['description'] ?? ''); ?>
                                    </td>
                                    <td class="tdcc-action-cell" data-preset-cell="<?php echo esc_attr($recKey); ?>">
                                        <?php if ($isConfigured): ?>
                                            <span class="tdcc-badge-in-prefs tdcc-status-in-prefs">
                                                <span class="dashicons dashicons-yes"></span>
                                                <?php echo esc_html__('In Preferences', 'tuedion-cookie'); ?>
                                            </span>
                                        <?php else: ?>
                                            <form method="post" action="" class="tdcc-add-preset-form" data-preset="<?php echo esc_attr($recKey); ?>">
                                                <?php wp_nonce_field('tuedion_add_preset_action', 'tuedion_add_preset_nonce'); ?>
                                                <input type="hidden" name="preset_key" value="<?php echo esc_attr($recKey); ?>">
                                                <button type="submit" name="tuedion_add_preset_service" class="button button-small button-secondary tdcc-add-preset-btn" data-preset="<?php echo esc_attr($recKey); ?>" data-context="catalogue">
                                                    <?php echo esc_html__('+ Add Service', 'tuedion-cookie'); ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }
}

