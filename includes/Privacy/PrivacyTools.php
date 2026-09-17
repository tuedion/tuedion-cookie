<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Privacy;

use Tuedion\CookieConsent\Consent\CookieTableBuilder;
use Tuedion\CookieConsent\Consent\LanguageResolver;
use Tuedion\CookieConsent\Logs\ConsentLogTable;
use Tuedion\CookieConsent\Settings\Repository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WordPress Core Privacy Tools Integration (GDPR Exporter, Eraser, Policy Content, and Live Declarations).
 */
final class PrivacyTools
{
    public static function register(): void
    {
        add_action('admin_init', [self::class, 'addPrivacyPolicyContent']);
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'registerDataExporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'registerDataEraser']);
        add_shortcode('tuedion_cookie_policy_link', [self::class, 'renderPolicyLinkShortcode']);
        add_shortcode('tuedion_cookie_declaration', [self::class, 'renderCookieDeclarationShortcode']);
        add_shortcode('tuedion_cookie_table', [self::class, 'renderCookieTableShortcode']);
    }

    /**
     * Add suggested privacy policy content to the WordPress Privacy Policy guide.
     */
    public static function addPrivacyPolicyContent(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $content = sprintf(
            '<h2>%s</h2>
            <p>%s</p>
            <h3>%s</h3>
            <p>%s</p>
            <h3>%s</h3>
            <p>%s</p>',
            esc_html__('Cookie Consent and Privacy Choices', 'tuedion-cookie'),
            esc_html__('We use Tuedion Cookie to provide an enterprise-grade consent management banner and preference center. When you visit our website, you have full granular control over which cookie categories (Strictly Necessary, Functionality, Analytics, Marketing) you wish to permit.', 'tuedion-cookie'),
            esc_html__('How We Store Your Consent', 'tuedion-cookie'),
            esc_html__('Your choices are saved in a local first-party cookie named "cc_cookie" on your device for up to 182 days. When consent logging is enabled by the site administrator, an anonymized record with masked IP address and timestamp is kept for legal audit compliance under GDPR Article 7(1).', 'tuedion-cookie'),
            esc_html__('Reversing or Updating Consent', 'tuedion-cookie'),
            esc_html__('Consent is always reversible. You can reopen your preferences at any time by clicking the persistent Cookie Preferences button located at the corner of your screen.', 'tuedion-cookie')
        );

        wp_add_privacy_policy_content('Tuedion Cookie', wp_kses_post($content));
    }

    /**
     * Register personal data exporter.
     *
     * @param array<string, array<string, mixed>> $exporters
     * @return array<string, array<string, mixed>>
     */
    public static function registerDataExporter(array $exporters): array
    {
        $exporters['tuedion-cookie-consent'] = [
            'exporter_friendly_name' => __('Tuedion Cookie Consent Records', 'tuedion-cookie'),
            'callback'               => [self::class, 'exportConsentData'],
        ];

        return $exporters;
    }

    /**
     * Personal data exporter callback.
     *
     * @param string $emailAddress
     * @param int $page
     * @return array{data: list<array<string, mixed>>, done: bool}
     */
    public static function exportConsentData(string $emailAddress, int $page = 1): array
    {
        $exportItems = [
            [
                'group_id'    => 'tuedion-cookie-consent',
                'group_label' => __('Cookie Consent Records', 'tuedion-cookie'),
                'item_id'     => 'consent-privacy-notice',
                'data'        => [
                    [
                        'name'  => __('Data Architecture', 'tuedion-cookie'),
                        'value' => __('Tuedion Cookie stores visitor consent choices locally in the browser ("cc_cookie") and records pseudonymized audit logs with masked IP addresses and UUIDs without linking records to personal email addresses.', 'tuedion-cookie'),
                    ],
                ],
            ],
        ];

        return [
            'data' => $exportItems,
            'done' => true,
        ];
    }

    /**
     * Register personal data eraser.
     *
     * @param array<string, array<string, mixed>> $erasers
     * @return array<string, array<string, mixed>>
     */
    public static function registerDataEraser(array $erasers): array
    {
        $erasers['tuedion-cookie-consent'] = [
            'eraser_friendly_name' => __('Tuedion Cookie Consent Records', 'tuedion-cookie'),
            'callback'             => [self::class, 'eraseConsentData'],
        ];

        return $erasers;
    }

    /**
     * Personal data eraser callback.
     *
     * @param string $emailAddress
     * @param int $page
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    public static function eraseConsentData(string $emailAddress, int $page = 1): array
    {
        return [
            'items_removed'  => false,
            'items_retained' => false,
            'messages'       => [__('Tuedion Cookie stores consent logs pseudonymously without collecting personal email addresses; no database records are linked to this email address.', 'tuedion-cookie')],
            'done'           => true,
        ];
    }

    /**
     * Shortcode helper: [tuedion_cookie_policy_link]
     */
    public static function renderPolicyLinkShortcode(): string
    {
        return sprintf(
            '<button type="button" class="tdcc-open-preferences-link" data-cc="show-preferencesModal">%s</button>',
            esc_html__('Manage Cookie Preferences', 'tuedion-cookie')
        );
    }

    /**
     * Render complete cookie declaration for privacy/cookie policy pages.
     * Shortcode: [tuedion_cookie_declaration]
     *
     * @param array<string, mixed>|string $atts
     * @return string
     */
    public static function renderCookieDeclarationShortcode($atts = []): string
    {
        $atts = shortcode_atts([
            'lang'        => '',
            'show_button' => 'true',
            'show_intro'  => 'true',
        ], is_array($atts) ? $atts : [], 'tuedion_cookie_declaration');

        $lang = !empty($atts['lang']) ? sanitize_key((string) $atts['lang']) : LanguageResolver::getCurrentLanguage();
        $settings = Repository::getSettings();
        $categories = (array) ($settings['categories'] ?? []);

        $renderedCategories = [];
        foreach ($categories as $cat) {
            if (!is_array($cat)) {
                continue;
            }
            $catId = (string) ($cat['id'] ?? '');
            if ($catId === '') {
                continue;
            }
            $table = CookieTableBuilder::build($catId, $lang);
            if ($table === null || empty($table['body'])) {
                continue;
            }

            $renderedCategories[] = [
                'id'          => $catId,
                'label'       => (string) ($cat['label'] ?? $catId),
                'description' => (string) ($cat['description'] ?? ''),
                'table'       => $table,
            ];
        }

        ob_start();
        ?>
        <div class="tdcc-declaration-wrap" data-tdcc-lang="<?php echo esc_attr($lang); ?>">
            <?php if ($atts['show_intro'] === 'true'): ?>
                <div class="tdcc-declaration-intro">
                    <p>
                        <?php echo esc_html__('This website uses cookies to personalize content, analyze traffic, and ensure you receive the best user experience. In accordance with data privacy regulations (GDPR / ePrivacy), below is a comprehensive list of all active cookies and tracking services categorized by their purpose.', 'tuedion-cookie'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if (empty($renderedCategories)): ?>
                <div class="tdcc-declaration-empty">
                    <p><?php echo esc_html__('No third-party tracking cookies are currently active on this site.', 'tuedion-cookie'); ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($renderedCategories as $item): ?>
                    <div class="tdcc-declaration-category" id="tdcc-cat-<?php echo esc_attr($item['id']); ?>">
                        <div class="tdcc-declaration-cat-header">
                            <h3 class="tdcc-declaration-cat-title"><?php echo esc_html($item['label']); ?></h3>
                            <?php if (!empty($item['description'])): ?>
                                <p class="tdcc-declaration-cat-desc"><?php echo esc_html($item['description']); ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="tdcc-table-responsive">
                            <table class="tdcc-declaration-table">
                                <thead>
                                    <tr>
                                        <th scope="col"><?php echo esc_html($item['table']['headers']['name'] ?? 'Cookie'); ?></th>
                                        <th scope="col"><?php echo esc_html($item['table']['headers']['domain'] ?? 'Domain'); ?></th>
                                        <th scope="col"><?php echo esc_html($item['table']['headers']['duration'] ?? 'Duration'); ?></th>
                                        <th scope="col"><?php echo esc_html($item['table']['headers']['desc'] ?? 'Description'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($item['table']['body'] as $row): ?>
                                        <tr>
                                            <td class="tdcc-cell-name"><code><?php echo esc_html($row['name'] ?? ''); ?></code></td>
                                            <td class="tdcc-cell-domain"><?php echo esc_html($row['domain'] ?? ''); ?></td>
                                            <td class="tdcc-cell-duration"><span class="tdcc-badge-duration"><?php echo esc_html($row['duration'] ?? ''); ?></span></td>
                                            <td class="tdcc-cell-desc"><?php echo esc_html($row['desc'] ?? ''); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($atts['show_button'] === 'true'): ?>
                <div class="tdcc-declaration-actions">
                    <button type="button" class="tdcc-declaration-manage-btn" data-cc="show-preferencesModal">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5c0-.6.1-1.2.2-1.7"></path><circle cx="8.5" cy="8.5" r="1.5"></circle><circle cx="7" cy="15" r="1.5"></circle><circle cx="15.5" cy="15.5" r="1.5"></circle></svg>
                        <span><?php echo esc_html__('Change your consent', 'tuedion-cookie'); ?></span>
                    </button>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Render a single category cookie table.
     * Shortcode: [tuedion_cookie_table category="analytics"]
     *
     * @param array<string, mixed>|string $atts
     * @return string
     */
    public static function renderCookieTableShortcode($atts = []): string
    {
        $atts = shortcode_atts([
            'category' => 'necessary',
            'lang'     => '',
        ], is_array($atts) ? $atts : [], 'tuedion_cookie_table');

        $lang = !empty($atts['lang']) ? sanitize_key((string) $atts['lang']) : LanguageResolver::getCurrentLanguage();
        $catId = sanitize_key((string) $atts['category']);

        $table = CookieTableBuilder::build($catId, $lang);
        if ($table === null || empty($table['body'])) {
            return '';
        }

        ob_start();
        ?>
        <div class="tdcc-table-responsive">
            <table class="tdcc-declaration-table">
                <thead>
                    <tr>
                        <th scope="col"><?php echo esc_html($table['headers']['name'] ?? 'Cookie'); ?></th>
                        <th scope="col"><?php echo esc_html($table['headers']['domain'] ?? 'Domain'); ?></th>
                        <th scope="col"><?php echo esc_html($table['headers']['duration'] ?? 'Duration'); ?></th>
                        <th scope="col"><?php echo esc_html($table['headers']['desc'] ?? 'Description'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($table['body'] as $row): ?>
                        <tr>
                            <td class="tdcc-cell-name"><code><?php echo esc_html($row['name'] ?? ''); ?></code></td>
                            <td class="tdcc-cell-domain"><?php echo esc_html($row['domain'] ?? ''); ?></td>
                            <td class="tdcc-cell-duration"><span class="tdcc-badge-duration"><?php echo esc_html($row['duration'] ?? ''); ?></span></td>
                            <td class="tdcc-cell-desc"><?php echo esc_html($row['desc'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
