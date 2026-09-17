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

        wp_send_json_success([
            'message'        => sprintf(esc_html__('Service "%s" added to your cookie preferences.', 'tuedion-cookie'), $newService['label']),
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
        Compiler::clearCache();

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
            $catId = sanitize_key((string) ($_POST['cat_id'] ?? ''));
            $catLabel = sanitize_text_field((string) ($_POST['cat_label'] ?? ''));
            $catDesc = Validator::sanitizeHtml((string) ($_POST['cat_description'] ?? ''));
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
            $svcId = sanitize_key((string) ($_POST['svc_id'] ?? ''));
            $svcLabel = sanitize_text_field((string) ($_POST['svc_label'] ?? ''));
            $svcCategory = sanitize_key((string) ($_POST['svc_category'] ?? ''));
            $rawCookies = sanitize_text_field((string) ($_POST['svc_cookies'] ?? ''));
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
            Compiler::clearCache();
            $notice = esc_html__('Service deleted successfully.', 'tuedion-cookie');
        }

        // 5. Handle Run Site Scan
        if (isset($_POST['tuedion_run_scanner']) && check_admin_referer('tuedion_scanner_action', 'tuedion_scanner_nonce')) {
            $scanResult = CookieScanner::scan(true);
            $svcCount = (int) ($scanResult['stats']['detected_services_count'] ?? 0);
            $cookieCount = (int) ($scanResult['stats']['detected_cookies_count'] ?? 0);
            $notice = sprintf(
                esc_html__('Website scan completed! Detected %d active services and %d cookies on your site.', 'tuedion-cookie'),
                $svcCount,
                $cookieCount
            );
            $noticeType = 'success';
        }

        // 6. Handle Auto-Sync Detected Services to Configuration
        if (isset($_POST['tuedion_sync_detected_services']) && check_admin_referer('tuedion_sync_action', 'tuedion_sync_nonce')) {
            $syncedCount = CookieScanner::syncDetectedServicesToSettings();
            $notice = sprintf(
                esc_html__('%d detected services have been synchronized to your active cookie preferences!', 'tuedion-cookie'),
                $syncedCount
            );
            $noticeType = 'success';
        }

        // 7. Handle 1-Click Add Preset/Detected Service
        if (isset($_POST['tuedion_add_preset_service']) && check_admin_referer('tuedion_add_preset_action', 'tuedion_add_preset_nonce')) {
            $presetKey = sanitize_key((string) ($_POST['preset_key'] ?? ''));
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

                <!-- Scanner & Service Directory Section -->
                <?php
                $scanResults = CookieScanner::getResults();
                $detectedServices = (array) ($scanResults['detected_services'] ?? []);
                $detectedCookies = (array) ($scanResults['detected_cookies'] ?? []);
                $scanStats = (array) ($scanResults['stats'] ?? []);
                $lastScanDate = (string) ($scanResults['last_scan_date'] ?? '');
                $lastScanHuman = (string) ($scanResults['last_scan_human'] ?? '');
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
                ?>

                <div class="tdcc-scanner-hero">
                    <div class="tdcc-scanner-header">
                        <div class="tdcc-scanner-title">
                            <span class="dashicons dashicons-search"></span>
                            <div>
                                <h3>
                                    <?php echo esc_html__('Website Tracker & Service Scanner', 'tuedion-cookie'); ?>
                                </h3>
                                <p>
                                    <?php if (!empty($lastScanDate)): ?>
                                        <?php echo esc_html(sprintf(__('Last scan: %s (%s)', 'tuedion-cookie'), $lastScanDate, $lastScanHuman)); ?>
                                    <?php else: ?>
                                        <?php echo esc_html__('No scan executed yet. Run a site scan to detect active services and cookies.', 'tuedion-cookie'); ?>
                                    <?php endif; ?>
                                </p>
                                <div style="margin-top:6px; display:inline-flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                    <?php if ($cronEnabled): ?>
                                        <span class="tdcc-badge" style="display:inline-flex; align-items:center; gap:5px; font-weight:600; font-size:11px; padding:3px 8px; border-radius:4px; background:#dcfce7; color:#15803d; border:1px solid #86efac;">
                                            <span class="dashicons dashicons-backup" style="font-size:13px; width:13px; height:13px; line-height:13px;"></span>
                                            <?php echo esc_html(sprintf(__('WP-Cron Auto-Scan: Active (%s)', 'tuedion-cookie'), $cronScheduleLabel)); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="tdcc-badge" style="display:inline-flex; align-items:center; gap:5px; font-weight:600; font-size:11px; padding:3px 8px; border-radius:4px; background:#f1f5f9; color:#64748b; border:1px solid #cbd5e1;">
                                            <span class="dashicons dashicons-backup" style="font-size:13px; width:13px; height:13px; line-height:13px;"></span>
                                            <?php echo esc_html__('WP-Cron Auto-Scan: Off', 'tuedion-cookie'); ?>
                                        </span>
                                    <?php endif; ?>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=tuedion-cookie-settings#tdcc-scanner-settings')); ?>" style="font-size:12px; text-decoration:none; font-weight:600; color:#2563eb; display:inline-flex; align-items:center; gap:3px;">
                                        <span class="dashicons dashicons-admin-generic" style="font-size:13px; width:13px; height:13px; line-height:13px;"></span>
                                        <?php echo esc_html__('Configure Scheduled Scans & Email Alerts', 'tuedion-cookie'); ?> &rarr;
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div class="tdcc-scanner-actions">
                            <button type="button" class="button button-primary tdcc-client-scan-btn" data-nonce="<?php echo esc_attr(wp_create_nonce('tuedion_scanner_action')); ?>">
                                <span class="dashicons dashicons-update"></span>
                                <?php echo esc_html__('Scan Website Now', 'tuedion-cookie'); ?>
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

                    <!-- Metrics -->
                    <div class="tdcc-scanner-metrics">
                        <div class="tdcc-scanner-stat">
                            <div class="tdcc-scanner-stat-num is-active">
                                <?php echo esc_html((string) $detectedCount); ?>
                            </div>
                            <div class="tdcc-scanner-stat-label">
                                <?php echo esc_html__('Active Services on Site', 'tuedion-cookie'); ?>
                            </div>
                        </div>

                        <div class="tdcc-scanner-stat">
                            <div class="tdcc-scanner-stat-num is-discovered">
                                <?php echo esc_html((string) count($detectedCookies)); ?>
                            </div>
                            <div class="tdcc-scanner-stat-label">
                                <?php echo esc_html__('Discovered Cookies', 'tuedion-cookie'); ?>
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

                <!-- Navigation Tabs: Detected vs Catalogue vs Discovered Cookies -->
                <div class="tdcc-catalogue-nav">
                    <div class="tdcc-nav-pills">
                        <button type="button" class="tdcc-nav-pill is-active" data-target="pane-detected">
                            <span class="dashicons dashicons-yes"></span>
                            <?php echo esc_html__('Detected on This Site', 'tuedion-cookie'); ?>
                            <span class="count"><?php echo esc_html((string) $detectedCount); ?></span>
                        </button>

                        <button type="button" class="tdcc-nav-pill" data-target="pane-catalogue">
                            <span class="dashicons dashicons-category"></span>
                            <?php echo esc_html__('All Supported Presets', 'tuedion-cookie'); ?>
                            <span class="count"><?php echo esc_html((string) count($allRecipes)); ?></span>
                        </button>

                        <button type="button" class="tdcc-nav-pill" data-target="pane-cookies">
                            <span class="dashicons dashicons-database"></span>
                            <?php echo esc_html__('Discovered Cookies', 'tuedion-cookie'); ?>
                            <span class="count"><?php echo esc_html((string) count($detectedCookies)); ?></span>
                        </button>
                    </div>

                    <div class="tdcc-search-wrap">
                        <input type="search" id="tdcc-preset-search" placeholder="<?php echo esc_attr__('Search services or cookies...', 'tuedion-cookie'); ?>" class="regular-text tdcc-search-input">
                    </div>
                </div>

                <!-- Pane 1: Detected on This Site (DEFAULT) -->
                <div id="pane-detected" class="tdcc-catalogue-pane is-active">
                    <?php if (empty($detectedServices)): ?>
                        <div class="tdcc-scanner-empty">
                            <span class="dashicons dashicons-search"></span>
                            <h4>
                                <?php echo esc_html__('No Active Services Detected Yet', 'tuedion-cookie'); ?>
                            </h4>
                            <p>
                                <?php echo esc_html__('Run a real-time crawl to scan your pages, enqueued scripts, and embedded iframes (e.g. YouTube, Vimeo, Google Maps, GA4).', 'tuedion-cookie'); ?>
                            </p>
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
                                    <th scope="col"><?php echo esc_html__('Detection Origin / Source', 'tuedion-cookie'); ?></th>
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
                                        </td>
                                        <td>
                                            <code><?php echo esc_html($ds['category'] ?? 'marketing'); ?></code>
                                        </td>
                                        <td class="tdcc-source-cell">
                                            <?php
                                            $sources = (array) ($ds['sources'] ?? [$ds['source'] ?? '']);
                                            foreach ($sources as $srcLine):
                                            ?>
                                                <div class="tdcc-source-line">
                                                    <span class="dashicons dashicons-yes"></span>
                                                    <span><?php echo wp_kses_post($srcLine); ?></span>
                                                </div>
                                            <?php endforeach; ?>
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

                <!-- Pane 2: All Supported Presets (22) -->
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

                <!-- Pane 3: Discovered Cookies Inventory Table -->
                <div id="pane-cookies" class="tdcc-catalogue-pane">
                    <?php if (empty($detectedCookies)): ?>
                        <p class="tdcc-empty-notice">
                            <?php echo esc_html__('No cookies mapped yet. Run a site scan to discover active cookies.', 'tuedion-cookie'); ?>
                        </p>
                    <?php else: ?>
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
            </div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const scanBtns = document.querySelectorAll('.tdcc-client-scan-btn');
            if (!scanBtns.length) return;

            scanBtns.forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const _btn = this;
                    const originalText = _btn.innerHTML;
                    _btn.innerHTML = '<span class="dashicons dashicons-update tdcc-spin"></span> Scanning pages...';
                    _btn.disabled = true;

                    const nonce = _btn.getAttribute('data-nonce');

                    // Sequential scan URLs
                    const urlsToScan = [
                        '<?php echo esc_js(home_url('/?tdcc_audit=1')); ?>'
                    ];
                    
                    <?php if (class_exists('WooCommerce')): ?>
                        <?php $shop_id = get_option('woocommerce_shop_page_id'); ?>
                        <?php if ($shop_id): ?>
                            urlsToScan.push('<?php echo esc_js(get_permalink($shop_id) . (str_contains((string)get_permalink($shop_id), '?') ? '&' : '?') . 'tdcc_audit=1'); ?>');
                        <?php endif; ?>
                    <?php endif; ?>

                    let currentUrlIndex = 0;
                    let combinedData = { cookies: [], scripts: [], iframes: [] };
                    let iframe = null;
                    let timeout = null;

                    function cleanupAndFail() {
                        if (iframe) iframe.remove();
                        window.removeEventListener('message', onScanMessage);
                        _btn.innerHTML = originalText;
                        _btn.disabled = false;
                        alert('Scan timed out. Please try again.');
                    }

                    function scanNextUrl() {
                        if (currentUrlIndex >= urlsToScan.length) {
                            finishScan();
                            return;
                        }
                        
                        const url = urlsToScan[currentUrlIndex];
                        _btn.innerHTML = '<span class="dashicons dashicons-update tdcc-spin"></span> Scanning ' + (currentUrlIndex + 1) + '/' + urlsToScan.length + '...';
                        
                        iframe = document.createElement('iframe');
                        iframe.style.display = 'none';
                        iframe.src = url;
                        document.body.appendChild(iframe);

                        timeout = setTimeout(cleanupAndFail, 15000);
                    }

                    function finishScan() {
                        _btn.innerHTML = '<span class="dashicons dashicons-update tdcc-spin"></span> Analyzing results...';
                        
                        const fd = new URLSearchParams();
                        fd.append('action', 'tdcc_run_cookie_scan');
                        fd.append('nonce', nonce);
                        fd.append('payload', JSON.stringify(combinedData));

                        fetch(ajaxurl, {
                            method: 'POST',
                            body: fd
                        })
                        .then(res => res.json())
                        .then(res => {
                            if (res.success) {
                                window.location.reload();
                            } else {
                                alert(res.data.message || 'Error occurred during scan.');
                                _btn.innerHTML = originalText;
                                _btn.disabled = false;
                            }
                        })
                        .catch(err => {
                            alert('Network error during scan processing.');
                            _btn.innerHTML = originalText;
                            _btn.disabled = false;
                        });
                    }

                    function onScanMessage(event) {
                        if (event.data && event.data.type === 'tdcc_scan_result') {
                            clearTimeout(timeout);
                            if (iframe) iframe.remove();
                            
                            // Merge data
                            if (event.data.cookies) combinedData.cookies.push(event.data.cookies);
                            if (event.data.scripts) combinedData.scripts = combinedData.scripts.concat(event.data.scripts);
                            if (event.data.iframes) combinedData.iframes = combinedData.iframes.concat(event.data.iframes);

                            currentUrlIndex++;
                            scanNextUrl();
                        }
                    }

                    window.addEventListener('message', onScanMessage);
                    scanNextUrl();
                });
            });
        });
        </script>
        <style>
        .tdcc-spin {
            animation: tdcc-spin 2s infinite linear;
        }
        @keyframes tdcc-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(359deg); }
        }
        </style>
        <?php
    }
}
