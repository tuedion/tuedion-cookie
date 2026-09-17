<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Admin\Pages;

use Tuedion\CookieConsent\Settings\Repository;
use Tuedion\CookieConsent\Settings\Schema;
use Tuedion\CookieConsent\Settings\Compiler;
use Tuedion\CookieConsent\Settings\Validator;
use Tuedion\CookieConsent\Settings\ThemeManager;

if (!defined('ABSPATH')) {
    exit;
}

final class ExperiencePage
{
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'tuedion-cookie'));
        }

        $notice = null;
        $noticeType = 'success';

        $allowedTabs = ['tab-banner', 'tab-preferences', 'tab-theme', 'tab-trigger', 'tab-revision'];
        $activeTab = sanitize_key((string) ($_POST['active_tab'] ?? $_GET['tab'] ?? 'tab-banner'));
        if (!in_array($activeTab, $allowedTabs, true)) {
            $activeTab = 'tab-banner';
        }

        // Handle Form Submission (Save Draft or Publish)
        if (isset($_POST['tuedion_experience_save']) && check_admin_referer('tuedion_experience_action', 'tuedion_experience_nonce')) {
            $settings = Repository::getSettings();

            $actionType = sanitize_key((string) ($_POST['action_type_btn'] ?? $_POST['action_type'] ?? 'draft'));
            $isPublish  = ($actionType === 'publish');
            $settings['status'] = $isPublish ? Schema::STATUS_ENABLED : Schema::STATUS_DRAFT;

            // Revision bump
            if (!empty($_POST['bump_revision'])) {
                $settings['revision'] = ((int) ($settings['revision'] ?? 1)) + 1;
            }

            // Banner options
            $settings['banner']['layout']               = sanitize_text_field((string) ($_POST['banner_layout'] ?? 'box'));
            $settings['banner']['position']             = sanitize_text_field((string) ($_POST['banner_position'] ?? 'bottom-right'));
            $settings['banner']['equal_weight_buttons'] = !empty($_POST['equal_weight_buttons']);
            $settings['banner']['show_reject_button']   = !empty($_POST['show_reject_button']);
            $settings['banner']['show_manage_button']   = !empty($_POST['show_manage_button']);

            // Preferences options
            $settings['preferences']['layout']   = sanitize_text_field((string) ($_POST['pref_layout'] ?? 'box'));
            $settings['preferences']['position'] = sanitize_text_field((string) ($_POST['pref_position'] ?? 'right'));

            // Theme options (robust hex text vs color picker sync)
            $rawTheme = [
                'preset' => sanitize_text_field((string) ($_POST['theme_preset'] ?? 'light')),
            ];
            foreach (ThemeManager::COLOR_TOKENS as $token => $defaultHex) {
                $hexVal   = trim((string) ($_POST[$token . '_hex'] ?? ''));
                $colorVal = trim((string) ($_POST[$token] ?? ''));

                if ($hexVal !== '' && $hexVal[0] !== '#') {
                    $hexVal = '#' . $hexVal;
                }
                if ($colorVal !== '' && $colorVal[0] !== '#') {
                    $colorVal = '#' . $colorVal;
                }

                $validHex   = sanitize_hex_color($hexVal);
                $validColor = sanitize_hex_color($colorVal);

                if (!empty($validHex)) {
                    $chosen = $validHex;
                } elseif (!empty($validColor)) {
                    $chosen = $validColor;
                } else {
                    $chosen = $defaultHex;
                }
                $rawTheme[$token] = $chosen;
            }
            $settings['theme'] = ThemeManager::resolve($rawTheme);

            // Trigger options
            $settings['trigger']['enabled']           = !empty($_POST['trigger_enabled']);
            $settings['trigger']['mode']              = sanitize_text_field((string) ($_POST['trigger_mode'] ?? 'both'));
            $settings['trigger']['position']          = sanitize_text_field((string) ($_POST['trigger_position'] ?? 'bottom-left'));
            $settings['trigger']['offset_x']          = max(0, min(300, (int) ($_POST['trigger_offset_x'] ?? 20)));
            $settings['trigger']['offset_y']          = max(0, min(300, (int) ($_POST['trigger_offset_y'] ?? 20)));
            $settings['trigger']['mobile_position']   = sanitize_text_field((string) ($_POST['trigger_mobile_position'] ?? 'bottom-left'));
            $settings['trigger']['mobile_offset_x']   = max(0, min(300, (int) ($_POST['trigger_mobile_offset_x'] ?? 16)));
            $settings['trigger']['mobile_offset_y']   = max(0, min(300, (int) ($_POST['trigger_mobile_offset_y'] ?? 16)));
            $settings['trigger']['visibility_policy'] = sanitize_text_field((string) ($_POST['trigger_visibility_policy'] ?? 'after_choice'));
            $settings['trigger']['path_exclusions']   = sanitize_textarea_field((string) ($_POST['trigger_path_exclusions'] ?? ''));
            $settings['trigger']['aria_label']        = sanitize_text_field((string) ($_POST['trigger_aria_label'] ?? ''));

            // Legal Policy options (Tab 5)
            if (isset($_POST['legal_privacy_title'])) {
                $settings['legal']['privacy_title'] = sanitize_text_field(trim((string) $_POST['legal_privacy_title']));
            }
            if (isset($_POST['legal_privacy_url'])) {
                $settings['legal']['privacy_url'] = esc_url_raw(trim((string) $_POST['legal_privacy_url']));
            }
            if (isset($_POST['legal_terms_title'])) {
                $settings['legal']['terms_title'] = sanitize_text_field(trim((string) $_POST['legal_terms_title']));
            }
            if (isset($_POST['legal_terms_url'])) {
                $settings['legal']['terms_url'] = esc_url_raw(trim((string) $_POST['legal_terms_url']));
            }

            // Validate and Save
            $validation = Validator::validate($settings);
            if ($validation['valid']) {
                Repository::updateSettings($validation['sanitized']);
                Compiler::clearCache();

                $notice = $isPublish
                    ? esc_html__('Configuration published successfully and is now active on frontend.', 'tuedion-cookie')
                    : esc_html__('Draft configuration saved. You can preview changes below.', 'tuedion-cookie');
            } else {
                $notice = implode('<br>', $validation['errors']);
                $noticeType = 'error';
            }
        }

        $settings = Repository::getSettings();
        $status   = $settings['status'] ?? Schema::STATUS_DRAFT;
        $revision = (int) ($settings['revision'] ?? 1);
        $theme    = ThemeManager::resolve($settings['theme'] ?? []);
        $banner   = $settings['banner'] ?? [];
        $prefs    = $settings['preferences'] ?? [];
        $trigger  = $settings['trigger'] ?? [];
        $legal    = $settings['legal'] ?? [];
        ?>
        <div class="wrap tdcc-admin-wrap tdcc-experience-wrap">
            <header class="tdcc-header">
                <div class="tdcc-header-title">
                    <h1><?php echo esc_html__('Tuedion Cookie — Experience & Live Editor', 'tuedion-cookie'); ?></h1>
                    <span class="tdcc-badge tdcc-badge-<?php echo esc_attr($status); ?>">
                        <?php echo esc_html($status === Schema::STATUS_ENABLED ? __('Active (Published)', 'tuedion-cookie') : __('Draft Mode', 'tuedion-cookie')); ?>
                    </span>
                    <span class="tdcc-version-tag">
                        <?php echo esc_html(sprintf(__('Revision %d', 'tuedion-cookie'), $revision)); ?>
                    </span>
                </div>
            </header>

            <?php if ($notice !== null): ?>
                <div class="notice notice-<?php echo esc_attr($noticeType); ?> is-dismissible tdcc-notice">
                    <p><?php echo wp_kses_post($notice); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="" id="tdcc-experience-form" class="tdcc-split-layout">
                <?php wp_nonce_field('tuedion_experience_action', 'tuedion_experience_nonce'); ?>
                <input type="hidden" name="active_tab" id="tdcc-active-tab" value="<?php echo esc_attr($activeTab); ?>">
                <input type="hidden" name="action_type" id="tdcc-action-type" value="draft">
                <input type="hidden" name="tuedion_experience_save" value="1">

                <!-- Left Column: Form & Configuration Tabs (55%) -->
                <div class="tdcc-editor-column">
                    <nav class="tdcc-tabs" role="tablist">
                        <button type="button" class="tdcc-tab <?php echo ($activeTab === 'tab-banner') ? 'is-active' : ''; ?>" data-target="tab-banner" role="tab">
                            <?php echo esc_html__('Banner Editor', 'tuedion-cookie'); ?>
                        </button>
                        <button type="button" class="tdcc-tab <?php echo ($activeTab === 'tab-preferences') ? 'is-active' : ''; ?>" data-target="tab-preferences" role="tab">
                            <?php echo esc_html__('Preferences Modal', 'tuedion-cookie'); ?>
                        </button>
                        <button type="button" class="tdcc-tab <?php echo ($activeTab === 'tab-theme') ? 'is-active' : ''; ?>" data-target="tab-theme" role="tab">
                            <?php echo esc_html__('Theme & Styling', 'tuedion-cookie'); ?>
                        </button>
                        <button type="button" class="tdcc-tab <?php echo ($activeTab === 'tab-trigger') ? 'is-active' : ''; ?>" data-target="tab-trigger" role="tab">
                            <?php echo esc_html__('Privacy Trigger', 'tuedion-cookie'); ?>
                        </button>
                        <button type="button" class="tdcc-tab <?php echo ($activeTab === 'tab-revision') ? 'is-active' : ''; ?>" data-target="tab-revision" role="tab">
                            <?php echo esc_html__('Revision & Policy', 'tuedion-cookie'); ?>
                        </button>
                    </nav>

                    <!-- Tab 1: Banner Editor -->
                    <div id="tab-banner" class="tdcc-tab-pane <?php echo ($activeTab === 'tab-banner') ? 'is-active' : ''; ?> tdcc-card" role="tabpanel">
                        <h2><?php echo esc_html__('Consent Banner Appearance', 'tuedion-cookie'); ?></h2>
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><label for="banner_layout"><?php echo esc_html__('Layout Preset', 'tuedion-cookie'); ?></label></th>
                                    <td>
                                        <select name="banner_layout" id="banner_layout" class="regular-text tdcc-live-field">
                                            <option value="box" <?php selected($banner['layout'] ?? '', 'box'); ?>><?php echo esc_html__('Classic Box (Standard)', 'tuedion-cookie'); ?></option>
                                            <option value="box wide" <?php selected($banner['layout'] ?? '', 'box wide'); ?>><?php echo esc_html__('Box Wide', 'tuedion-cookie'); ?></option>
                                            <option value="box inline" <?php selected($banner['layout'] ?? '', 'box inline'); ?>><?php echo esc_html__('Box Inline', 'tuedion-cookie'); ?></option>
                                            <option value="bar" <?php selected($banner['layout'] ?? '', 'bar'); ?>><?php echo esc_html__('Full Bar (Edge to Edge)', 'tuedion-cookie'); ?></option>
                                            <option value="bar inline" <?php selected($banner['layout'] ?? '', 'bar inline'); ?>><?php echo esc_html__('Bar Inline', 'tuedion-cookie'); ?></option>
                                            <option value="cloud" <?php selected($banner['layout'] ?? '', 'cloud'); ?>><?php echo esc_html__('Clean Cloud (Floating)', 'tuedion-cookie'); ?></option>
                                            <option value="cloud inline" <?php selected($banner['layout'] ?? '', 'cloud inline'); ?>><?php echo esc_html__('Cloud Inline', 'tuedion-cookie'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="banner_position"><?php echo esc_html__('Position', 'tuedion-cookie'); ?></label></th>
                                    <td>
                                        <select name="banner_position" id="banner_position" class="regular-text tdcc-live-field">
                                            <option value="bottom-right" <?php selected($banner['position'] ?? '', 'bottom-right'); ?>><?php echo esc_html__('Bottom Right (Recommended)', 'tuedion-cookie'); ?></option>
                                            <option value="bottom-left" <?php selected($banner['position'] ?? '', 'bottom-left'); ?>><?php echo esc_html__('Bottom Left', 'tuedion-cookie'); ?></option>
                                            <option value="bottom-center" <?php selected($banner['position'] ?? '', 'bottom-center'); ?>><?php echo esc_html__('Bottom Center', 'tuedion-cookie'); ?></option>
                                            <option value="top-right" <?php selected($banner['position'] ?? '', 'top-right'); ?>><?php echo esc_html__('Top Right', 'tuedion-cookie'); ?></option>
                                            <option value="top-left" <?php selected($banner['position'] ?? '', 'top-left'); ?>><?php echo esc_html__('Top Left', 'tuedion-cookie'); ?></option>
                                            <option value="top-center" <?php selected($banner['position'] ?? '', 'top-center'); ?>><?php echo esc_html__('Top Center', 'tuedion-cookie'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php echo esc_html__('Buttons Configuration', 'tuedion-cookie'); ?></th>
                                    <td>
                                        <fieldset>
                                            <label for="equal_weight_buttons">
                                                <input type="checkbox" name="equal_weight_buttons" id="equal_weight_buttons" value="1" <?php checked(!empty($banner['equal_weight_buttons'])); ?> class="tdcc-live-field">
                                                <?php echo esc_html__('Equal Button Weight (Avoid deceptive dark patterns)', 'tuedion-cookie'); ?>
                                            </label><br>
                                            <label for="show_reject_button">
                                                <input type="checkbox" name="show_reject_button" id="show_reject_button" value="1" <?php checked(!empty($banner['show_reject_button'])); ?> class="tdcc-live-field">
                                                <?php echo esc_html__('Show "Reject Non-Essential" button on first banner', 'tuedion-cookie'); ?>
                                            </label><br>
                                            <label for="show_manage_button">
                                                <input type="checkbox" name="show_manage_button" id="show_manage_button" value="1" <?php checked(!empty($banner['show_manage_button'])); ?> class="tdcc-live-field">
                                                <?php echo esc_html__('Show "Manage Preferences" button', 'tuedion-cookie'); ?>
                                            </label>
                                        </fieldset>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Tab 2: Preferences Modal -->
                    <div id="tab-preferences" class="tdcc-tab-pane <?php echo ($activeTab === 'tab-preferences') ? 'is-active' : ''; ?> tdcc-card" role="tabpanel">
                        <h2><?php echo esc_html__('Preferences Modal Configuration', 'tuedion-cookie'); ?></h2>
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><label for="pref_layout"><?php echo esc_html__('Modal Layout', 'tuedion-cookie'); ?></label></th>
                                    <td>
                                        <select name="pref_layout" id="pref_layout" class="regular-text tdcc-live-field">
                                            <option value="box" <?php selected($prefs['layout'] ?? '', 'box'); ?>><?php echo esc_html__('Centered Dialog (Box)', 'tuedion-cookie'); ?></option>
                                            <option value="bar" <?php selected($prefs['layout'] ?? '', 'bar'); ?>><?php echo esc_html__('Side Drawer (Bar)', 'tuedion-cookie'); ?></option>
                                            <option value="bar wide" <?php selected($prefs['layout'] ?? '', 'bar wide'); ?>><?php echo esc_html__('Wide Side Drawer (Bar Wide)', 'tuedion-cookie'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="pref_position"><?php echo esc_html__('Drawer Position', 'tuedion-cookie'); ?></label></th>
                                    <td>
                                        <select name="pref_position" id="pref_position" class="regular-text tdcc-live-field">
                                            <option value="right" <?php selected($prefs['position'] ?? '', 'right'); ?>><?php echo esc_html__('Right Slide-In', 'tuedion-cookie'); ?></option>
                                            <option value="left" <?php selected($prefs['position'] ?? '', 'left'); ?>><?php echo esc_html__('Left Slide-In', 'tuedion-cookie'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Tab 3: Theme & Styling -->
                    <div id="tab-theme" class="tdcc-tab-pane <?php echo ($activeTab === 'tab-theme') ? 'is-active' : ''; ?> tdcc-card" role="tabpanel">
                        <h2><?php echo esc_html__('Design Presets & Complete Color Customization', 'tuedion-cookie'); ?></h2>
                        <p class="description">
                            <?php echo esc_html__('Select a curated design preset or customize each color surface individually. Choosing a preset immediately populates all color fields below.', 'tuedion-cookie'); ?>
                        </p>

                        <div class="tdcc-preset-bar" style="margin: 1.25rem 0; padding: 1rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                            <label for="theme_preset" style="font-weight: 700; display: block; margin-bottom: 6px;">
                                <?php echo esc_html__('Active Theme Preset:', 'tuedion-cookie'); ?>
                            </label>
                            <select name="theme_preset" id="theme_preset" class="regular-text tdcc-live-field" style="font-weight: 600;">
                                <?php foreach (ThemeManager::getPresets() as $presetKey => $presetData): ?>
                                    <option value="<?php echo esc_attr($presetKey); ?>" <?php selected($theme['preset'] ?? '', $presetKey); ?>>
                                        <?php echo esc_html($presetData['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="description" style="display: block; margin-top: 6px;">
                                <?php echo esc_html__('Modifying any individual color token below automatically sets the preset to Custom.', 'tuedion-cookie'); ?>
                            </span>
                        </div>

                        <h3 style="margin-top: 1.5rem; margin-bottom: 0.75rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px;">
                            <?php echo esc_html__('1. Base Surfaces & Typography', 'tuedion-cookie'); ?>
                        </h3>
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><label for="bg_color"><?php echo esc_html__('Modal Background', 'tuedion-cookie'); ?></label></th>
                                    <td>
                                        <div class="tdcc-color-group" style="display:flex; align-items:center; gap:8px;">
                                            <input type="color" name="bg_color" id="bg_color" value="<?php echo esc_attr($theme['bg_color']); ?>" class="tdcc-color-input tdcc-color-picker tdcc-live-field" data-hex="#bg_color_hex">
                                            <input type="text" name="bg_color_hex" id="bg_color_hex" value="<?php echo esc_attr($theme['bg_color']); ?>" class="tdcc-hex-input tdcc-live-field" data-color="#bg_color" size="7" maxlength="7" style="font-family: monospace;">
                                            <span class="description"><?php echo esc_html__('Consent banner and modal backdrop surface.', 'tuedion-cookie'); ?></span>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="text_color"><?php echo esc_html__('Primary Text & Headings', 'tuedion-cookie'); ?></label></th>
                                    <td>
                                        <div class="tdcc-color-group" style="display:flex; align-items:center; gap:8px;">
                                            <input type="color" name="text_color" id="text_color" value="<?php echo esc_attr($theme['text_color']); ?>" class="tdcc-color-input tdcc-color-picker tdcc-live-field" data-hex="#text_color_hex">
                                            <input type="text" name="text_color_hex" id="text_color_hex" value="<?php echo esc_attr($theme['text_color']); ?>" class="tdcc-hex-input tdcc-live-field" data-color="#text_color" size="7" maxlength="7" style="font-family: monospace;">
                                            <span class="description"><?php echo esc_html__('Titles, category names, and prominent labels.', 'tuedion-cookie'); ?></span>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="subtext_color"><?php echo esc_html__('Secondary / Description Text', 'tuedion-cookie'); ?></label></th>
                                    <td>
                                        <div class="tdcc-color-group" style="display:flex; align-items:center; gap:8px;">
                                            <input type="color" name="subtext_color" id="subtext_color" value="<?php echo esc_attr($theme['subtext_color']); ?>" class="tdcc-color-input tdcc-color-picker tdcc-live-field" data-hex="#subtext_color_hex">
                                            <input type="text" name="subtext_color_hex" id="subtext_color_hex" value="<?php echo esc_attr($theme['subtext_color']); ?>" class="tdcc-hex-input tdcc-live-field" data-color="#subtext_color" size="7" maxlength="7" style="font-family: monospace;">
                                            <span class="description"><?php echo esc_html__('Descriptions, cookie tables, and secondary notes.', 'tuedion-cookie'); ?></span>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="border_color"><?php echo esc_html__('Borders & Separators', 'tuedion-cookie'); ?></label></th>
                                    <td>
                                        <div class="tdcc-color-group" style="display:flex; align-items:center; gap:8px;">
                                            <input type="color" name="border_color" id="border_color" value="<?php echo esc_attr($theme['border_color']); ?>" class="tdcc-color-input tdcc-color-picker tdcc-live-field" data-hex="#border_color_hex">
                                            <input type="text" name="border_color_hex" id="border_color_hex" value="<?php echo esc_attr($theme['border_color']); ?>" class="tdcc-hex-input tdcc-live-field" data-color="#border_color" size="7" maxlength="7" style="font-family: monospace;">
                                            <span class="description"><?php echo esc_html__('Modal boundary borders and section divider lines.', 'tuedion-cookie'); ?></span>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="card_bg"><?php echo esc_html__('Category Card & Footer Surface', 'tuedion-cookie'); ?></label></th>
                                    <td>
                                        <div class="tdcc-color-group" style="display:flex; align-items:center; gap:8px;">
                                            <input type="color" name="card_bg" id="card_bg" value="<?php echo esc_attr($theme['card_bg']); ?>" class="tdcc-color-input tdcc-color-picker tdcc-live-field" data-hex="#card_bg_hex">
                                            <input type="text" name="card_bg_hex" id="card_bg_hex" value="<?php echo esc_attr($theme['card_bg']); ?>" class="tdcc-hex-input tdcc-live-field" data-color="#card_bg" size="7" maxlength="7" style="font-family: monospace;">
                                            <span class="description"><?php echo esc_html__('Inner cards in preferences modal and legal footer strip.', 'tuedion-cookie'); ?></span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <h3 style="margin-top: 1.5rem; margin-bottom: 0.75rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px;">
                            <?php echo esc_html__('2. Interactive Buttons & Controls', 'tuedion-cookie'); ?>
                        </h3>
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><label for="btn_primary_bg"><?php echo esc_html__('Primary Button Background', 'tuedion-cookie'); ?></label></th>
                                    <td>
                                        <div class="tdcc-color-group" style="display:flex; align-items:center; gap:8px;">
                                            <input type="color" name="btn_primary_bg" id="btn_primary_bg" value="<?php echo esc_attr($theme['btn_primary_bg']); ?>" class="tdcc-color-input tdcc-color-picker tdcc-live-field" data-hex="#btn_primary_bg_hex">
                                            <input type="text" name="btn_primary_bg_hex" id="btn_primary_bg_hex" value="<?php echo esc_attr($theme['btn_primary_bg']); ?>" class="tdcc-hex-input tdcc-live-field" data-color="#btn_primary_bg" size="7" maxlength="7" style="font-family: monospace;">
                                            <span class="description"><?php echo esc_html__('"Accept All" and "Save Preferences" primary CTA buttons.', 'tuedion-cookie'); ?></span>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="btn_primary_color"><?php echo esc_html__('Primary Button Text Color', 'tuedion-cookie'); ?></label></th>
                                    <td>
                                        <div class="tdcc-color-group" style="display:flex; align-items:center; gap:8px;">
                                            <input type="color" name="btn_primary_color" id="btn_primary_color" value="<?php echo esc_attr($theme['btn_primary_color']); ?>" class="tdcc-color-input tdcc-color-picker tdcc-live-field" data-hex="#btn_primary_color_hex">
                                            <input type="text" name="btn_primary_color_hex" id="btn_primary_color_hex" value="<?php echo esc_attr($theme['btn_primary_color']); ?>" class="tdcc-hex-input tdcc-live-field" data-color="#btn_primary_color" size="7" maxlength="7" style="font-family: monospace;">
                                            <span class="description"><?php echo esc_html__('Text color inside primary CTA buttons (ensures WCAG contrast).', 'tuedion-cookie'); ?></span>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="btn_secondary_bg"><?php echo esc_html__('Secondary Button Background', 'tuedion-cookie'); ?></label></th>
                                    <td>
                                        <div class="tdcc-color-group" style="display:flex; align-items:center; gap:8px;">
                                            <input type="color" name="btn_secondary_bg" id="btn_secondary_bg" value="<?php echo esc_attr($theme['btn_secondary_bg']); ?>" class="tdcc-color-input tdcc-color-picker tdcc-live-field" data-hex="#btn_secondary_bg_hex">
                                            <input type="text" name="btn_secondary_bg_hex" id="btn_secondary_bg_hex" value="<?php echo esc_attr($theme['btn_secondary_bg']); ?>" class="tdcc-hex-input tdcc-live-field" data-color="#btn_secondary_bg" size="7" maxlength="7" style="font-family: monospace;">
                                            <span class="description"><?php echo esc_html__('"Reject Non-Essential" and "Manage Preferences" buttons.', 'tuedion-cookie'); ?></span>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="btn_secondary_color"><?php echo esc_html__('Secondary Button Text Color', 'tuedion-cookie'); ?></label></th>
                                    <td>
                                        <div class="tdcc-color-group" style="display:flex; align-items:center; gap:8px;">
                                            <input type="color" name="btn_secondary_color" id="btn_secondary_color" value="<?php echo esc_attr($theme['btn_secondary_color']); ?>" class="tdcc-color-input tdcc-color-picker tdcc-live-field" data-hex="#btn_secondary_color_hex">
                                            <input type="text" name="btn_secondary_color_hex" id="btn_secondary_color_hex" value="<?php echo esc_attr($theme['btn_secondary_color']); ?>" class="tdcc-hex-input tdcc-live-field" data-color="#btn_secondary_color" size="7" maxlength="7" style="font-family: monospace;">
                                            <span class="description"><?php echo esc_html__('Text color inside secondary buttons.', 'tuedion-cookie'); ?></span>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="toggle_on_bg"><?php echo esc_html__('Toggle Active & Accent Highlight', 'tuedion-cookie'); ?></label></th>
                                    <td>
                                        <div class="tdcc-color-group" style="display:flex; align-items:center; gap:8px;">
                                            <input type="color" name="toggle_on_bg" id="toggle_on_bg" value="<?php echo esc_attr($theme['toggle_on_bg']); ?>" class="tdcc-color-input tdcc-color-picker tdcc-live-field" data-hex="#toggle_on_bg_hex">
                                            <input type="text" name="toggle_on_bg_hex" id="toggle_on_bg_hex" value="<?php echo esc_attr($theme['toggle_on_bg']); ?>" class="tdcc-hex-input tdcc-live-field" data-color="#toggle_on_bg" size="7" maxlength="7" style="font-family: monospace;">
                                            <span class="description"><?php echo esc_html__('Active category toggle switch and floating cookie icon color.', 'tuedion-cookie'); ?></span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Tab 4: Persistent Privacy Trigger -->
                    <div id="tab-trigger" class="tdcc-tab-pane <?php echo ($activeTab === 'tab-trigger') ? 'is-active' : ''; ?> tdcc-card" role="tabpanel">
                        <h2><?php echo esc_html__('Persistent Privacy Trigger Button', 'tuedion-cookie'); ?></h2>
                        <p class="description">
                            <?php echo esc_html__('Consent is reversible. This button stays accessible after visitors make a choice, allowing them to re-open preferences at any time.', 'tuedion-cookie'); ?>
                        </p>
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><?php echo esc_html__('Enable Trigger', 'tuedion-cookie'); ?></th>
                                    <td>
                                        <label for="trigger_enabled">
                                            <input type="checkbox" name="trigger_enabled" id="trigger_enabled" value="1" <?php checked(!empty($trigger['enabled'])); ?> class="tdcc-live-field">
                                            <?php echo esc_html__('Display floating Privacy Trigger on public pages after consent', 'tuedion-cookie'); ?>
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="trigger_mode"><?php echo esc_html__('Display Style', 'tuedion-cookie'); ?></label></th>
                                    <td>
                                        <select name="trigger_mode" id="trigger_mode" class="regular-text tdcc-live-field">
                                            <option value="both" <?php selected($trigger['mode'] ?? '', 'both'); ?>><?php echo esc_html__('Icon + Text (Recommended)', 'tuedion-cookie'); ?></option>
                                            <option value="icon" <?php selected($trigger['mode'] ?? '', 'icon'); ?>><?php echo esc_html__('Icon Only (Compact)', 'tuedion-cookie'); ?></option>
                                            <option value="text" <?php selected($trigger['mode'] ?? '', 'text'); ?>><?php echo esc_html__('Text Only', 'tuedion-cookie'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="trigger_position"><?php echo esc_html__('Screen Position', 'tuedion-cookie'); ?></label></th>
                                    <td>
                                        <select name="trigger_position" id="trigger_position" class="regular-text tdcc-live-field">
                                            <option value="bottom-left" <?php selected($trigger['position'] ?? '', 'bottom-left'); ?>><?php echo esc_html__('Bottom Left (Standard)', 'tuedion-cookie'); ?></option>
                                            <option value="bottom-right" <?php selected($trigger['position'] ?? '', 'bottom-right'); ?>><?php echo esc_html__('Bottom Right', 'tuedion-cookie'); ?></option>
                                            <option value="top-left" <?php selected($trigger['position'] ?? '', 'top-left'); ?>><?php echo esc_html__('Top Left', 'tuedion-cookie'); ?></option>
                                            <option value="top-right" <?php selected($trigger['position'] ?? '', 'top-right'); ?>><?php echo esc_html__('Top Right', 'tuedion-cookie'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php echo esc_html__('Corner Offsets (px)', 'tuedion-cookie'); ?></th>
                                    <td>
                                        <label for="trigger_offset_x">X: <input type="number" name="trigger_offset_x" id="trigger_offset_x" value="<?php echo esc_attr((string) ($trigger['offset_x'] ?? 20)); ?>" min="0" max="300" class="small-text tdcc-live-field"></label>
                                        &nbsp;&nbsp;
                                        <label for="trigger_offset_y">Y: <input type="number" name="trigger_offset_y" id="trigger_offset_y" value="<?php echo esc_attr((string) ($trigger['offset_y'] ?? 20)); ?>" min="0" max="300" class="small-text tdcc-live-field"></label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="trigger_visibility_policy"><?php echo esc_html__('Visibility Policy', 'tuedion-cookie'); ?></label></th>
                                    <td>
                                        <select name="trigger_visibility_policy" id="trigger_visibility_policy" class="regular-text">
                                            <option value="after_choice" <?php selected($trigger['visibility_policy'] ?? '', 'after_choice'); ?>>
                                                <?php echo esc_html__('After Visitor Choice (Standard — only appears once banner is answered)', 'tuedion-cookie'); ?>
                                            </option>
                                            <option value="always" <?php selected($trigger['visibility_policy'] ?? '', 'always'); ?>>
                                                <?php echo esc_html__('Always Visible (Persistent from first page load)', 'tuedion-cookie'); ?>
                                            </option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="trigger_mobile_position"><?php echo esc_html__('Mobile Position & Offsets', 'tuedion-cookie'); ?></label></th>
                                    <td>
                                        <select name="trigger_mobile_position" id="trigger_mobile_position" class="regular-text">
                                            <option value="bottom-left" <?php selected($trigger['mobile_position'] ?? '', 'bottom-left'); ?>><?php echo esc_html__('Bottom Left', 'tuedion-cookie'); ?></option>
                                            <option value="bottom-right" <?php selected($trigger['mobile_position'] ?? '', 'bottom-right'); ?>><?php echo esc_html__('Bottom Right', 'tuedion-cookie'); ?></option>
                                            <option value="top-left" <?php selected($trigger['mobile_position'] ?? '', 'top-left'); ?>><?php echo esc_html__('Top Left', 'tuedion-cookie'); ?></option>
                                            <option value="top-right" <?php selected($trigger['mobile_position'] ?? '', 'top-right'); ?>><?php echo esc_html__('Top Right', 'tuedion-cookie'); ?></option>
                                        </select>
                                        <br><br>
                                        <label for="trigger_mobile_offset_x">Mobile X: <input type="number" name="trigger_mobile_offset_x" id="trigger_mobile_offset_x" value="<?php echo esc_attr((string) ($trigger['mobile_offset_x'] ?? 16)); ?>" min="0" max="300" class="small-text"></label>
                                        &nbsp;&nbsp;
                                        <label for="trigger_mobile_offset_y">Mobile Y: <input type="number" name="trigger_mobile_offset_y" id="trigger_mobile_offset_y" value="<?php echo esc_attr((string) ($trigger['mobile_offset_y'] ?? 16)); ?>" min="0" max="300" class="small-text"></label>
                                        <p class="description">
                                            <?php echo esc_html__('Allows avoiding collision with chat widgets (WhatsApp, Tawk.to, etc.) on mobile viewports.', 'tuedion-cookie'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="trigger_path_exclusions"><?php echo esc_html__('Path Exclusions', 'tuedion-cookie'); ?></label></th>
                                    <td>
                                        <textarea name="trigger_path_exclusions" id="trigger_path_exclusions" rows="3" class="large-text code" placeholder="/checkout/&#10;/cart/&#10;/order-received/"><?php echo esc_textarea((string) ($trigger['path_exclusions'] ?? '')); ?></textarea>
                                        <p class="description">
                                            <?php echo esc_html__('Enter URL path prefixes (one per line) where the trigger button must be hidden.', 'tuedion-cookie'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="trigger_aria_label"><?php echo esc_html__('Accessible Label (Aria)', 'tuedion-cookie'); ?></label></th>
                                    <td>
                                        <input type="text" name="trigger_aria_label" id="trigger_aria_label" value="<?php echo esc_attr((string) ($trigger['aria_label'] ?? '')); ?>" placeholder="<?php echo esc_attr__('Default: Cookie Preferences', 'tuedion-cookie'); ?>" class="regular-text">
                                        <p class="description">
                                            <?php echo esc_html__('Screen-reader announcement for WCAG 2.2 AA compliance.', 'tuedion-cookie'); ?>
                                        </p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Tab 5: Revision & Policy -->
                    <div id="tab-revision" class="tdcc-tab-pane <?php echo ($activeTab === 'tab-revision') ? 'is-active' : ''; ?> tdcc-card" role="tabpanel">
                        <h2><?php echo esc_html__('Consent Revision & Governance', 'tuedion-cookie'); ?></h2>
                        <div class="tdcc-warning-box">
                            <strong><?php echo esc_html__('Current Published Revision:', 'tuedion-cookie'); ?> <?php echo esc_html((string) $revision); ?></strong>
                            <p>
                                <?php echo esc_html__('When you make material modifications to categories or tracking services, privacy regulations require re-prompting existing visitors to renew their consent.', 'tuedion-cookie'); ?>
                            </p>
                        </div>
                        <p>
                            <label for="bump_revision" class="tdcc-bump-label">
                                <input type="checkbox" name="bump_revision" id="bump_revision" value="1">
                                <strong><?php echo esc_html(sprintf(__('Increment revision to %d on next save (Prompt existing visitors again)', 'tuedion-cookie'), $revision + 1)); ?></strong>
                            </label>
                        </p>

                        <h3 style="margin-top: 1.5rem; margin-bottom: 0.75rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px;">
                            <?php echo esc_html__('Legal Policy Links', 'tuedion-cookie'); ?>
                        </h3>
                        <p class="description">
                            <?php echo esc_html__('These URLs are embedded in the consent modal footer and legal notices.', 'tuedion-cookie'); ?>
                        </p>
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><label for="legal_privacy_title"><?php echo esc_html__('Privacy Policy Title', 'tuedion-cookie'); ?></label></th>
                                    <td>
                                        <input type="text" name="legal_privacy_title" id="legal_privacy_title" value="<?php echo esc_attr($legal['privacy_title'] ?? ''); ?>" class="regular-text" placeholder="<?php echo esc_attr__('Privacy Policy', 'tuedion-cookie'); ?>">
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="legal_privacy_url"><?php echo esc_html__('Privacy Policy URL', 'tuedion-cookie'); ?></label></th>
                                    <td>
                                        <input type="url" name="legal_privacy_url" id="legal_privacy_url" value="<?php echo esc_url($legal['privacy_url'] ?? ''); ?>" class="regular-text code" placeholder="<?php echo esc_url(get_privacy_policy_url() ?: home_url('/privacy-policy/')); ?>">
                                        <p class="description"><?php echo esc_html__('Default Privacy Policy URL for visitors.', 'tuedion-cookie'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="legal_terms_title"><?php echo esc_html__('Cookie / Terms Title', 'tuedion-cookie'); ?></label></th>
                                    <td>
                                        <input type="text" name="legal_terms_title" id="legal_terms_title" value="<?php echo esc_attr($legal['terms_title'] ?? ''); ?>" class="regular-text" placeholder="<?php echo esc_attr__('Terms of Service', 'tuedion-cookie'); ?>">
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="legal_terms_url"><?php echo esc_html__('Cookie / Imprint Policy URL', 'tuedion-cookie'); ?></label></th>
                                    <td>
                                        <input type="url" name="legal_terms_url" id="legal_terms_url" value="<?php echo esc_url($legal['terms_url'] ?? ''); ?>" class="regular-text code" placeholder="<?php echo esc_url(home_url('/cookie-policy/')); ?>">
                                        <p class="description"><?php echo esc_html__('URL to cookie disclosure, terms, or imprint page.', 'tuedion-cookie'); ?></p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Action Bar -->
                    <div class="tdcc-action-bar">
                        <button type="submit" name="action_type_btn" value="draft" class="button button-secondary tdcc-submit-btn" data-action="draft">
                            <?php echo esc_html__('Save Draft', 'tuedion-cookie'); ?>
                        </button>
                        <button type="submit" name="action_type_btn" value="publish" class="button button-primary tdcc-submit-btn tdcc-publish-btn" data-action="publish">
                            <?php echo esc_html__('Publish Changes', 'tuedion-cookie'); ?>
                        </button>
                    </div>
                </div>

                <!-- Right Column: Sticky Live Responsive Preview (45%) -->
                <div class="tdcc-preview-column">
                    <div class="tdcc-preview-sticky">
                        <!-- Preview Toolbar -->
                        <div class="tdcc-preview-toolbar">
                            <div class="tdcc-toolbar-group" aria-label="Device Viewport">
                                <button type="button" class="tdcc-tool-btn is-active" data-device="desktop" title="Desktop View">
                                    <span class="dashicons dashicons-desktop"></span>
                                </button>
                                <button type="button" class="tdcc-tool-btn" data-device="tablet" title="Tablet View (768px)">
                                    <span class="dashicons dashicons-tablet"></span>
                                </button>
                                <button type="button" class="tdcc-tool-btn" data-device="mobile" title="Mobile View (375px)">
                                    <span class="dashicons dashicons-smartphone"></span>
                                </button>
                            </div>

                            <div class="tdcc-toolbar-group" aria-label="Simulated State">
                                <button type="button" class="tdcc-tool-btn is-active" data-view="banner" title="Banner Modal">
                                    <?php echo esc_html__('Banner', 'tuedion-cookie'); ?>
                                </button>
                                <button type="button" class="tdcc-tool-btn" data-view="preferences" title="Preferences Center">
                                    <?php echo esc_html__('Preferences', 'tuedion-cookie'); ?>
                                </button>
                                <button type="button" class="tdcc-tool-btn" data-view="trigger" title="Privacy Trigger">
                                    <?php echo esc_html__('Trigger', 'tuedion-cookie'); ?>
                                </button>
                            </div>

                            <div class="tdcc-toolbar-group" aria-label="Preview Language">
                                <button type="button" class="tdcc-tool-btn is-active" data-lang="en">EN</button>
                                <button type="button" class="tdcc-tool-btn" data-lang="tr">TR</button>
                            </div>

                            <div class="tdcc-toolbar-group tdcc-toolbar-preset-group" aria-label="Theme Preset">
                                <span class="dashicons dashicons-art" title="<?php echo esc_attr__('Theme Preset', 'tuedion-cookie'); ?>"></span>
                                <select id="tdcc-preview-preset-select" class="tdcc-preview-preset-select" title="<?php echo esc_attr__('Switch Preset Live', 'tuedion-cookie'); ?>">
                                    <?php foreach (ThemeManager::getPresets() as $presetKey => $presetData): ?>
                                        <option value="<?php echo esc_attr($presetKey); ?>" <?php selected($theme['preset'] ?? '', $presetKey); ?>>
                                            <?php echo esc_html($presetData['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Isolated Sandbox Frame Container -->
                        <div class="tdcc-preview-frame-wrap" id="tdcc-preview-viewport">
                            <div class="tdcc-sandbox-window">
                                <div class="tdcc-sandbox-mock-header">
                                    <span class="tdcc-mock-dot"></span>
                                    <span class="tdcc-mock-dot"></span>
                                    <span class="tdcc-mock-dot"></span>
                                    <span class="tdcc-mock-url">example.com/privacy-test</span>
                                </div>
                                <div class="tdcc-sandbox-stage" id="tdcc-preview-stage">
                                    <!-- Dynamic Mock Site Content -->
                                    <div class="tdcc-mock-site">
                                        <div class="tdcc-mock-hero"></div>
                                        <div class="tdcc-mock-paragraph"></div>
                                        <div class="tdcc-mock-paragraph tdcc-short"></div>
                                    </div>

                                    <!-- Rendered Isolated Consent Sandbox -->
                                    <div id="tdcc-sandbox-modal-container"></div>
                                </div>
                            </div>
                        </div>

                        <p class="tdcc-preview-legend">
                            <span class="dashicons dashicons-info"></span>
                            <?php echo esc_html__('Isolated preview. No real cookies are modified.', 'tuedion-cookie'); ?>
                        </p>
                    </div>
                </div>
            </form>
        </div>
        <?php
    }
}
