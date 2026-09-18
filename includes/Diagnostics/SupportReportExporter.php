<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Diagnostics;

use Tuedion\CookieConsent\Settings\Repository;
use Tuedion\CookieConsent\Settings\Schema;
use Tuedion\CookieConsent\Integrations\Google\SiteKitDetector;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Support Report Exporter.
 * Compiles a comprehensive diagnostics package with sensitive data automatically masked.
 */
final class SupportReportExporter
{
    /**
     * Keys that must be masked in configuration dumps.
     */
    private const SENSITIVE_KEYS = [
        'password',
        'secret',
        'salt',
        'auth_key',
        'api_key',
        'token',
        'email',
        'nonce',
    ];

    /**
     * Compile complete sanitized system diagnostics data.
     *
     * @return array<string, mixed>
     */
    public static function compilePackage(): array
    {
        $theme = wp_get_theme();
        $settings = Repository::getSettings();
        $maskedSettings = self::maskSensitiveValues($settings);

        $activePlugins = [];
        if (function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $allPlugins = get_plugins();
            $activeList = (array) get_option('active_plugins', []);

            foreach ($activeList as $pluginFile) {
                if (isset($allPlugins[$pluginFile])) {
                    $activePlugins[] = [
                        'name'    => $allPlugins[$pluginFile]['Name'] ?? $pluginFile,
                        'version' => $allPlugins[$pluginFile]['Version'] ?? 'unknown',
                        'file'    => $pluginFile,
                    ];
                }
            }
        }

        $configReport = ConfigInspector::inspect();
        $cacheReport = CacheInspector::inspect();
        $scriptReport = ScriptInventory::getInventory();
        $siteKitReport = SiteKitDetector::getDiagnostics();

        return [
            'generator'      => 'Tuedion Cookie Support Exporter',
            'generated_at'   => gmdate('Y-m-d H:i:s') . ' UTC',
            'environment'    => [
                'tuedion_cookie_version' => TUEDION_COOKIE_VERSION,
                'schema_version'         => get_option(Schema::SCHEMA_VERSION_OPTION, '0.0.0'),
                'wordpress_version'      => get_bloginfo('version'),
                'php_version'            => PHP_VERSION,
                'server_software'        => isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : 'unknown',
                'php_memory_limit'       => ini_get('memory_limit'),
                'php_max_execution_time' => ini_get('max_execution_time'),
                'multisite'              => is_multisite(),
                'locale'                 => get_locale(),
            ],
            'theme'          => [
                'name'       => $theme->get('Name'),
                'version'    => $theme->get('Version'),
                'is_child'   => $theme->parent() !== false,
            ],
            'active_plugins' => $activePlugins,
            'settings'       => $maskedSettings,
            'config_summary' => $configReport['summary'],
            'cache'          => $cacheReport,
            'google_stack'   => $siteKitReport,
            'scripts'        => [
                'total_scanned' => $scriptReport['total'],
                'managed_count' => count($scriptReport['managed']),
                'core_count'    => count($scriptReport['core']),
                'unmanaged'     => $scriptReport['unmanaged'],
            ],
        ];
    }

    /**
     * Recursively mask sensitive fields.
     *
     * @param mixed $data
     * @return mixed
     */
    public static function maskSensitiveValues(mixed $data): mixed
    {
        if (is_array($data)) {
            $cleaned = [];
            foreach ($data as $k => $v) {
                $keyLower = strtolower((string) $k);
                $isSensitive = false;

                foreach (self::SENSITIVE_KEYS as $sensitive) {
                    if (str_contains($keyLower, $sensitive)) {
                        $isSensitive = true;
                        break;
                    }
                }

                if ($isSensitive && is_string($v) && $v !== '') {
                    $cleaned[$k] = '***MASKED***';
                } else {
                    $cleaned[$k] = self::maskSensitiveValues($v);
                }
            }
            return $cleaned;
        }

        return $data;
    }

    /**
     * Stream JSON download of the support package.
     */
    public static function streamDownload(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized.', 'tuedion-cookie'));
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $package = self::compilePackage();
        $json = wp_json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $filename = 'tuedion-support-report-' . gmdate('Y-m-d') . '.json';

        header('Content-Description: File Transfer');
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0, no-cache');
        header('Pragma: public');
        header('Content-Length: ' . strlen($json));

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Direct JSON stream for file download.
        echo $json;
        exit;
    }
}
