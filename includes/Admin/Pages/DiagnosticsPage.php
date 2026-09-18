<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Admin\Pages;

use Tuedion\CookieConsent\Diagnostics\SystemReport;
use Tuedion\CookieConsent\Diagnostics\ConfigInspector;
use Tuedion\CookieConsent\Diagnostics\ScriptInventory;
use Tuedion\CookieConsent\Diagnostics\CacheInspector;
use Tuedion\CookieConsent\Diagnostics\ConsentStateInspector;
use Tuedion\CookieConsent\Diagnostics\SupportReportExporter;
use Tuedion\CookieConsent\Integrations\Google\GcmMapping;
use Tuedion\CookieConsent\Integrations\Google\SiteKitDetector;
use Tuedion\CookieConsent\Integrations\RecipeRegistry;
use Tuedion\CookieConsent\Settings\Repository;
use Tuedion\CookieConsent\Settings\Defaults;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Diagnostics & Developer Platform Admin Page.
 * Provides runtime inspection, script scanning, Google stack diagnostics,
 * cache optimization hints, developer documentation, and support report export.
 */
final class DiagnosticsPage
{
    /**
     * Handle admin-post download of system support report before admin HTML rendering.
     */
    public static function handleExportPost(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'tuedion-cookie'));
        }

        $nonce = isset($_GET['_wpnonce']) ? sanitize_key(wp_unslash($_GET['_wpnonce'])) : (isset($_POST['_wpnonce']) ? sanitize_key(wp_unslash($_POST['_wpnonce'])) : '');
        if (!wp_verify_nonce((string) $nonce, 'tdcc_export_support_report_nonce')) {
            wp_die(esc_html__('Security check failed or link expired. Please refresh the page and try again.', 'tuedion-cookie'), 403);
        }

        SupportReportExporter::streamDownload();
        exit;
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'tuedion-cookie'));
        }

        // Backward-compatible fallback
        if (isset($_GET['action']) && $_GET['action'] === 'tdcc_export_support_report') {
            $nonce = isset($_GET['_wpnonce']) ? sanitize_key(wp_unslash($_GET['_wpnonce'])) : (isset($_POST['_wpnonce']) ? sanitize_key(wp_unslash($_POST['_wpnonce'])) : '');
            if (wp_verify_nonce((string) $nonce, 'tdcc_export_support_report_nonce')) {
                SupportReportExporter::streamDownload();
                exit;
            }
        }

        $configReport = ConfigInspector::inspect();
        $cacheReport = CacheInspector::inspect();
        $scriptInventory = ScriptInventory::getInventory();
        $consentState = ConsentStateInspector::inspect();
        $siteKit = SiteKitDetector::getDiagnostics();
        $exportNonce = wp_create_nonce('tdcc_export_support_report_nonce');
        ?>
        <div class="wrap tdcc-admin-wrap">
            <header class="tdcc-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                <div>
                    <h1><?php echo esc_html__('Tuedion Cookie — Diagnostics & Developer Platform', 'tuedion-cookie'); ?></h1>
                    <p class="description" style="margin-top:4px;">
                        <?php echo esc_html__('Deep runtime inspection, script tracker audits, cache compatibility, and developer API references.', 'tuedion-cookie'); ?>
                    </p>
                </div>
                <div class="tdcc-header-actions" style="display:flex; gap:10px;">
                    <a href="<?php echo esc_url(admin_url('admin-post.php?action=tdcc_export_support_report&_wpnonce=' . $exportNonce)); ?>" download="tuedion-support-report-<?php echo esc_attr(gmdate('Y-m-d')); ?>.json" class="button button-primary">
                        <span class="dashicons dashicons-download" style="margin-top:4px;"></span> <?php echo esc_html__('Download Support Report (JSON)', 'tuedion-cookie'); ?>
                    </a>
                </div>
            </header>

            <!-- Diagnostics Navigation Tabs -->
            <nav class="tdcc-tabs" style="margin: 1.5rem 0;">
                <button type="button" class="tdcc-tab is-active" data-target="pane-overview"><?php echo esc_html__('Overview & Health', 'tuedion-cookie'); ?></button>
                <button type="button" class="tdcc-tab" data-target="pane-config"><?php echo esc_html__('Config Inspector', 'tuedion-cookie'); ?></button>
                <button type="button" class="tdcc-tab" data-target="pane-scripts"><?php echo esc_html__('Script Inventory & Trackers', 'tuedion-cookie'); ?></button>
                <button type="button" class="tdcc-tab" data-target="pane-google"><?php echo esc_html__('Google Stack & GCM v2', 'tuedion-cookie'); ?></button>
                <button type="button" class="tdcc-tab" data-target="pane-cache"><?php echo esc_html__('Cache & Optimization Hints', 'tuedion-cookie'); ?></button>
                <button type="button" class="tdcc-tab" data-target="pane-developer"><?php echo esc_html__('Developer Hooks & API', 'tuedion-cookie'); ?></button>
            </nav>

            <!-- TAB 1: OVERVIEW & HEALTH -->
            <div id="pane-overview" class="tdcc-tab-pane is-active">
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:1rem; margin-bottom:1.5rem;">
                    <div class="tdcc-card" style="margin:0; padding:1.25rem;">
                        <div style="font-size:12px; color:#64748b; font-weight:600; text-transform:uppercase;"><?php echo esc_html__('Configuration Status', 'tuedion-cookie'); ?></div>
                        <div style="font-size:18px; font-weight:700; margin-top:6px;">
                            <?php if ($configReport['is_valid']): ?>
                                <span style="color:#16a34a;">&#10004; <?php echo esc_html__('Healthy & Valid', 'tuedion-cookie'); ?></span>
                            <?php else: ?>
                                <span style="color:#dc2626;">&#9888; <?php echo esc_html__('Configuration Warnings', 'tuedion-cookie'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="tdcc-card" style="margin:0; padding:1.25rem;">
                        <div style="font-size:12px; color:#64748b; font-weight:600; text-transform:uppercase;"><?php echo esc_html__('Consent Revision', 'tuedion-cookie'); ?></div>
                        <div style="font-size:20px; font-weight:700; margin-top:4px;">
                            v<?php echo esc_html((string) $configReport['summary']['revision']); ?>
                        </div>
                    </div>
                    <div class="tdcc-card" style="margin:0; padding:1.25rem;">
                        <div style="font-size:12px; color:#64748b; font-weight:600; text-transform:uppercase;"><?php echo esc_html__('Scanned Scripts', 'tuedion-cookie'); ?></div>
                        <div style="font-size:20px; font-weight:700; margin-top:4px;">
                            <?php echo esc_html((string) $scriptInventory['total']); ?> <span style="font-size:13px; font-weight:400; color:#64748b;">(<?php echo count($scriptInventory['managed']); ?> <?php echo esc_html__('managed', 'tuedion-cookie'); ?>)</span>
                        </div>
                    </div>
                    <div class="tdcc-card" style="margin:0; padding:1.25rem;">
                        <div style="font-size:12px; color:#64748b; font-weight:600; text-transform:uppercase;"><?php echo esc_html__('Active Caching Engine', 'tuedion-cookie'); ?></div>
                        <div style="font-size:16px; font-weight:700; margin-top:6px;">
                            <?php if (!empty($cacheReport['active_caching_plugins'])): ?>
                                <span style="color:#16a34a;"><?php echo esc_html($cacheReport['active_caching_plugins'][0]['name']); ?></span>
                            <?php else: ?>
                                <span style="color:#64748b;"><?php echo esc_html__('Standard / None', 'tuedion-cookie'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Current Visitor Consent State -->
                <div class="tdcc-card">
                    <h2><?php echo esc_html__('Current Visitor Consent State (Active Browser Session)', 'tuedion-cookie'); ?></h2>
                    <table class="widefat striped" style="margin-top:1rem;">
                        <tbody>
                            <tr>
                                <th style="width:240px;"><?php echo esc_html__('Cookie Set Status', 'tuedion-cookie'); ?></th>
                                <td>
                                    <?php if ($consentState['has_cookie']): ?>
                                        <span class="tdcc-badge" style="background:#dcfce7; color:#166534;"><?php echo esc_html__('Cookie Present (cc_cookie)', 'tuedion-cookie'); ?></span>
                                    <?php else: ?>
                                        <span class="tdcc-badge" style="background:#fef3c7; color:#92400e;"><?php echo esc_html__('No Cookie Set (Fresh Visitor / Pre-Consent)', 'tuedion-cookie'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html__('Consent UUID', 'tuedion-cookie'); ?></th>
                                <td><code><?php echo esc_html($consentState['consent_uuid'] ?: '—'); ?></code></td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html__('Accepted Categories', 'tuedion-cookie'); ?></th>
                                <td><strong><?php echo esc_html(!empty($consentState['accepted_categories']) ? implode(', ', $consentState['accepted_categories']) : 'None (Only Necessary)'); ?></strong></td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html__('Policy Revision Match', 'tuedion-cookie'); ?></th>
                                <td>
                                    <?php if ($consentState['has_cookie']): ?>
                                        <?php if ($consentState['is_revision_current']): ?>
                                            <span style="color:#16a34a;">&#10004; <?php
                                                echo esc_html(sprintf(
                                                    /* translators: %s: policy revision number */
                                                    __('Current (v%s)', 'tuedion-cookie'),
                                                    (string) $consentState['revision']
                                                ));
                                            ?></span>
                                        <?php else: ?>
                                            <span style="color:#dc2626;">&#9888; <?php
                                                echo esc_html(sprintf(
                                                    /* translators: 1: stored revision number, 2: current config revision number */
                                                    __('Outdated Revision (v%1$s vs current v%2$s) — Banner will re-prompt', 'tuedion-cookie'),
                                                    (string) $consentState['revision'],
                                                    (string) $configReport['summary']['revision']
                                                ));
                                            ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TAB 2: CONFIG INSPECTOR -->
            <div id="pane-config" class="tdcc-tab-pane">
                <div class="tdcc-card">
                    <h2><?php echo esc_html__('Compiled Runtime Configuration (window.tuedionCookieConfig)', 'tuedion-cookie'); ?></h2>
                    <p class="description">
                        <?php echo esc_html__('This JSON payload is injected into the document head and consumed by Orest Bida vanilla-cookieconsent v3.1.0.', 'tuedion-cookie'); ?>
                    </p>

                    <?php if (!empty($configReport['issues'])): ?>
                        <div class="notice notice-warning tdcc-notice" style="margin: 1rem 0;">
                            <?php foreach ($configReport['issues'] as $issue): ?>
                                <p><strong><?php echo esc_html__('Warning:', 'tuedion-cookie'); ?></strong> <?php echo esc_html($issue); ?></p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div style="margin-top:1rem;">
                        <textarea readonly rows="20" class="large-text code" style="font-family:monospace; font-size:12px; line-height:1.5; background:#0f172a; color:#f8fafc; padding:1rem; border-radius:6px;"><?php echo esc_textarea($configReport['formatted_json']); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- TAB 3: SCRIPT INVENTORY & UNMANAGED TRACKERS -->
            <div id="pane-scripts" class="tdcc-tab-pane">
                <?php if (!empty($scriptInventory['unmanaged'])): ?>
                    <div class="notice notice-warning tdcc-notice" style="border-left-color: #eab308; margin-bottom: 1.5rem;">
                        <h3 style="color:#854d0e; margin-top:0.5rem;"><?php echo esc_html__('Unmanaged External Trackers Detected!', 'tuedion-cookie'); ?></h3>
                        <p>
                            <?php echo esc_html__('The following third-party scripts originate from known tracking/advertising domains but have not been mapped to any consent recipe. Visitors may be tracked before consent unless these scripts are assigned to a category.', 'tuedion-cookie'); ?>
                        </p>
                        <table class="widefat striped" style="margin-top:0.5rem;">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__('Handle', 'tuedion-cookie'); ?></th>
                                    <th><?php echo esc_html__('Script URL', 'tuedion-cookie'); ?></th>
                                    <th><?php echo esc_html__('Suggested Category', 'tuedion-cookie'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($scriptInventory['unmanaged'] as $unm): ?>
                                    <tr>
                                        <td><code><?php echo esc_html($unm['handle']); ?></code></td>
                                        <td><code><?php echo esc_html($unm['src']); ?></code></td>
                                        <td><strong><?php echo esc_html($unm['suggested_category']); ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <div class="tdcc-card">
                    <h2><?php echo esc_html__('Enqueued Scripts Inventory', 'tuedion-cookie'); ?> (<?php echo esc_html((string) $scriptInventory['total']); ?>)</h2>
                    <p class="description">
                        <?php echo esc_html__('Audit of all scripts registered in the WordPress execution pipeline and their consent enforcement status.', 'tuedion-cookie'); ?>
                    </p>

                    <table class="widefat striped" style="margin-top:1rem;">
                        <thead>
                            <tr>
                                <th style="width:180px;"><?php echo esc_html__('Script Handle', 'tuedion-cookie'); ?></th>
                                <th><?php echo esc_html__('Classification', 'tuedion-cookie'); ?></th>
                                <th><?php echo esc_html__('Enforced Category', 'tuedion-cookie'); ?></th>
                                <th><?php echo esc_html__('Source URL / Path', 'tuedion-cookie'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Managed Scripts -->
                            <?php foreach ($scriptInventory['managed'] as $item): ?>
                                <tr>
                                    <td><code><?php echo esc_html($item['handle']); ?></code></td>
                                    <td><span class="tdcc-badge" style="background:#dcfce7; color:#166534;"><?php echo esc_html__('Managed by Recipe', 'tuedion-cookie'); ?> (<?php echo esc_html($item['recipe']); ?>)</span></td>
                                    <td><code><?php echo esc_html($item['category']); ?></code></td>
                                    <td><code style="font-size:11px;"><?php echo esc_html($item['src']); ?></code></td>
                                </tr>
                            <?php endforeach; ?>

                            <!-- Core Scripts -->
                            <?php foreach ($scriptInventory['core'] as $item): ?>
                                <tr>
                                    <td><code><?php echo esc_html($item['handle']); ?></code></td>
                                    <td><span class="tdcc-badge" style="background:#f1f5f9; color:#475569;"><?php echo esc_html__('Core / Library (Exempt)', 'tuedion-cookie'); ?></span></td>
                                    <td><code>necessary</code></td>
                                    <td><code style="font-size:11px;"><?php echo esc_html($item['src'] ?: 'embedded core'); ?></code></td>
                                </tr>
                            <?php endforeach; ?>

                            <!-- Functional Scripts -->
                            <?php foreach ($scriptInventory['functional'] as $item): ?>
                                <tr>
                                    <td><code><?php echo esc_html($item['handle']); ?></code></td>
                                    <td><span class="tdcc-badge" style="background:#e0f2fe; color:#0369a1;"><?php echo esc_html__('Theme / Plugin UI', 'tuedion-cookie'); ?></span></td>
                                    <td><code>necessary / functional</code></td>
                                    <td><code style="font-size:11px;"><?php echo esc_html($item['src']); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TAB 4: GOOGLE STACK & GCM V2 -->
            <div id="pane-google" class="tdcc-tab-pane">
                <div class="tdcc-card">
                    <h2><?php echo esc_html__('Google Consent Mode v2 & Google Site Kit Diagnostics', 'tuedion-cookie'); ?></h2>
                    <p class="description">
                        <?php echo esc_html__('Verification of pre-consent default signals, tag ordering in document head, and duplicate tag prevention.', 'tuedion-cookie'); ?>
                    </p>

                    <?php if ($siteKit['has_duplicate_risk']): ?>
                        <div class="notice notice-warning tdcc-notice" style="margin: 1rem 0;">
                            <p><strong><?php echo esc_html__('Duplicate Site Kit Risk:', 'tuedion-cookie'); ?></strong> <?php echo esc_html($siteKit['warning_message']); ?></p>
                        </div>
                    <?php endif; ?>

                    <table class="widefat striped" style="margin-top:1rem; max-width:700px;">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('GCM v2 Signal', 'tuedion-cookie'); ?></th>
                                <th><?php echo esc_html__('Mapped Category', 'tuedion-cookie'); ?></th>
                                <th><?php echo esc_html__('Default Pre-Consent State', 'tuedion-cookie'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (GcmMapping::ALL_SIGNALS as $sig): ?>
                                <?php
                                $cat = $gcmMapping[$sig] ?? 'marketing';
                                $isGranted = ($sig === 'security_storage' || $cat === 'necessary');
                                ?>
                                <tr>
                                    <td><code><?php echo esc_html($sig); ?></code></td>
                                    <td><strong><?php echo esc_html($cat); ?></strong></td>
                                    <td>
                                        <span class="tdcc-badge" style="<?php echo $isGranted ? 'background:#dcfce7;color:#166534;' : 'background:#fee2e2;color:#991b1b;'; ?>">
                                            <?php echo esc_html($isGranted ? 'GRANTED' : 'DENIED'); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div style="margin-top:1.5rem;">
                        <h4><?php echo esc_html__('Timing & Ingestion Rule:', 'tuedion-cookie'); ?></h4>
                        <p class="description">
                            <?php echo esc_html__('Tuedion Cookie hooks GCM v2 default signals at wp_head priority 0. This guarantees that Google tags (gtag.js / GTM) will always read default "denied" states before transmitting hits.', 'tuedion-cookie'); ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- TAB 5: CACHE & OPTIMIZATION HINTS -->
            <div id="pane-cache" class="tdcc-tab-pane">
                <div class="tdcc-card">
                    <h2><?php echo esc_html__('Cache Engine Compatibility & Minification Hints', 'tuedion-cookie'); ?></h2>
                    <p class="description">
                        <?php echo esc_html__('12 performance plugins are actively monitored to prevent delayed JavaScript execution or script merging from breaking cookie consent.', 'tuedion-cookie'); ?>
                    </p>

                    <div style="margin: 1rem 0;">
                        <?php foreach ($cacheReport['hints'] as $hint): ?>
                            <div style="padding: 10px 14px; background:#f8fafc; border-left:4px solid #0284c7; margin-bottom:8px; font-size:13px;">
                                <?php echo esc_html($hint); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <h3><?php echo esc_html__('Exclusion Identifier Patterns Registered:', 'tuedion-cookie'); ?></h3>
                    <p class="description">
                        <?php echo esc_html__('These strings are injected into delay/defer/combine exclusion filters across all active optimizers:', 'tuedion-cookie'); ?>
                    </p>
                    <ul>
                        <?php foreach ($cacheReport['exclusions_summary'] as $pattern): ?>
                            <li><code><?php echo esc_html($pattern); ?></code></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <!-- TAB 6: DEVELOPER HOOKS & CUSTOM RECIPE API -->
            <div id="pane-developer" class="tdcc-tab-pane">
                <div class="tdcc-card">
                    <h2><?php echo esc_html__('Developer Platform & Custom Recipe API', 'tuedion-cookie'); ?></h2>
                    <p class="description">
                        <?php echo esc_html__('Use these declarative hooks, helper functions, and programmatic APIs to customize consent behavior.', 'tuedion-cookie'); ?>
                    </p>

                    <h3><?php echo esc_html__('1. Register Custom Service Recipe via PHP:', 'tuedion-cookie'); ?></h3>
                    <pre class="code">&lt;?php
\Tuedion\CookieConsent\Integrations\CustomRecipeManager::registerRecipe('custom-tracker', [
    'name'        =&gt; 'Custom Analytics Service',
    'category'    =&gt; 'analytics',
    'type'        =&gt; 'script',
    'handles'     =&gt; ['my-tracker-handle'],
    'domains'     =&gt; ['tracker.mycompany.com'],
    'auto_clear'  =&gt; ['/^_my_trk_/'],
    'description' =&gt; 'Internal analytics tracking script.',
]);
?&gt;</pre>

                    <h3><?php echo esc_html__('2. Map Enqueued Script to Category via Filter:', 'tuedion-cookie'); ?></h3>
                    <pre class="code">add_filter('tuedion_cookie_script_category', function ($category, $handle, $src) {
    if ($handle === 'custom-ad-tag') {
        return 'marketing';
    }
    return $category;
}, 10, 3);</pre>

                    <h3><?php echo esc_html__('3. Map Embedded Iframe to Category via Filter:', 'tuedion-cookie'); ?></h3>
                    <pre class="code">add_filter('tuedion_cookie_iframe_category', function ($category, $src) {
    if (str_contains($src, 'myvideoplayer.com')) {
        return 'marketing';
    }
    return $category;
}, 10, 2);</pre>

                    <h3><?php echo esc_html__('4. JavaScript Client-side Event Bridge Listeners:', 'tuedion-cookie'); ?></h3>
                    <pre class="code">&lt;script&gt;
// Native DOM Event
window.addEventListener('tuedion:consent', function (event) {
    console.log('Consent event details:', event.detail);
});

// jQuery Trigger Event
jQuery(document).on('tuedion:consent', function (event, detail) {
    console.log('Consent updated via jQuery:', detail);
});
&lt;/script&gt;</pre>
                </div>
            </div>
        </div>
        <?php
    }
}
