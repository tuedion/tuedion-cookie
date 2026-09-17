<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Logs;

use Tuedion\CookieConsent\Settings\Repository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles incoming consent events, anonymizes data, and logs them to custom database table.
 * Strictly adheres to zero-write principle when logging is disabled.
 */
final class ConsentLogger
{
    public static function register(): void
    {
        add_action('wp_ajax_nopriv_tdcc_log_consent', [self::class, 'handleAjaxLog']);
        add_action('wp_ajax_tdcc_log_consent', [self::class, 'handleAjaxLog']);
        add_action('wp_ajax_nopriv_tdcc_refresh_nonce', [self::class, 'handleRefreshNonce']);
        add_action('wp_ajax_tdcc_refresh_nonce', [self::class, 'handleRefreshNonce']);
    }

    /**
     * Check if consent logging is enabled in settings.
     */
    public static function isLoggingEnabled(): bool
    {
        $settings = Repository::getSettings();
        return !empty($settings['logging']['enabled']);
    }

    /**
     * Endpoint for refreshing log nonces when cached HTML nonces expire.
     */
    public static function handleRefreshNonce(): void
    {
        if (!self::isLoggingEnabled()) {
            wp_send_json_error(['message' => 'Logging disabled'], 400);
            return;
        }

        wp_send_json_success([
            'nonce' => wp_create_nonce('tdcc_log_consent_nonce'),
        ]);
    }

    /**
     * Handle incoming AJAX consent log payload with abuse protection and allowlist validation.
     */
    public static function handleAjaxLog(): void
    {
        // 1. Zero writes when disabled: exit immediately
        if (!self::isLoggingEnabled()) {
            wp_send_json_success(['logged' => false, 'reason' => 'logging_disabled']);
            return;
        }

        // 2. Validate nonce
        if (!check_ajax_referer('tdcc_log_consent_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
            return;
        }

        $settings = Repository::getSettings();
        $revision = (int) ($settings['revision'] ?? 1);

        // 3. Strict IP anonymization (No raw IP default)
        $remoteIp = sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
        $anonymizedIp = function_exists('wp_privacy_anonymize_ip')
            ? wp_privacy_anonymize_ip($remoteIp)
            : (string) preg_replace('/\.\d+$/', '.0', $remoteIp);

        // Rate limiting per anonymized IP (max 20 log submissions per minute)
        $throttleKey = 'tdcc_rl_' . md5($anonymizedIp);
        $reqCount = (int) get_transient($throttleKey);
        if ($reqCount > 20) {
            wp_send_json_error(['message' => 'Rate limit exceeded'], 429);
            return;
        }
        set_transient($throttleKey, $reqCount + 1, MINUTE_IN_SECONDS);

        // Strict UUID v4 check to prevent CSV formula injection & malformed data
        $rawUuid = sanitize_text_field(wp_unslash($_POST['consent_uuid'] ?? ''));
        $isUuidValid = (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $rawUuid);
        $consentUuid = $isUuidValid ? strtolower($rawUuid) : wp_generate_uuid4();

        // Validate and sanitize actionType against allowlist
        $actionRaw = sanitize_key((string) wp_unslash($_POST['action_type'] ?? 'consent'));
        $validActions = ['first_consent', 'consent', 'change', 'accept_all', 'accept_necessary', 'custom'];
        $actionType = in_array($actionRaw, $validActions, true) ? $actionRaw : 'consent';

        // Sanitize and allowlist categories
        $rawCategories = isset($_POST['categories']) && is_array($_POST['categories']) ? $_POST['categories'] : [];
        $sanitizedCategories = array_values(array_map('sanitize_key', $rawCategories));

        $allowedCategories = ['necessary'];
        if (!empty($settings['categories']) && is_array($settings['categories'])) {
            foreach ($settings['categories'] as $cat) {
                if (is_array($cat) && !empty($cat['id'])) {
                    $allowedCategories[] = sanitize_key((string) $cat['id']);
                }
            }
        }
        $allowedCategories = array_unique($allowedCategories);
        $categories = array_slice(array_values(array_intersect($sanitizedCategories, $allowedCategories)), 0, 20);

        // Sanitize and allowlist services
        $rawServices = isset($_POST['services']) && is_array($_POST['services']) ? $_POST['services'] : [];
        $sanitizedServices = array_values(array_map('sanitize_key', $rawServices));

        $allowedServices = array_keys(\Tuedion\CookieConsent\Integrations\RecipeRegistry::getAll());
        if (!empty($settings['services']) && is_array($settings['services'])) {
            foreach ($settings['services'] as $svc) {
                if (is_array($svc) && !empty($svc['id'])) {
                    $allowedServices[] = sanitize_key((string) $svc['id']);
                }
            }
        }
        $allowedServices = array_unique($allowedServices);
        $services = array_slice(array_values(array_intersect($sanitizedServices, $allowedServices)), 0, 50);

        // Deduplication to avoid duplicate logging of the same event within 15 seconds
        $dupKey = 'tdcc_dup_' . md5($consentUuid . $actionType . wp_json_encode($categories) . wp_json_encode($services));
        if (get_transient($dupKey)) {
            wp_send_json_success([
                'logged'       => true,
                'deduplicated' => true,
                'consent_uuid' => $consentUuid,
            ]);
            return;
        }
        set_transient($dupKey, true, 15);

        // 4. User agent privacy hash (non-reversible)
        $rawUa = sanitize_text_field((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $uaHash = hash('sha256', $rawUa . (defined('AUTH_SALT') ? AUTH_SALT : 'tdcc_salt'));

        // 5. Insert record into custom table
        global $wpdb;
        $tableName = ConsentLogTable::getTableName();

        $inserted = $wpdb->insert(
            $tableName,
            [
                'consent_uuid'    => $consentUuid,
                'action'          => $actionType,
                'categories'      => (string) wp_json_encode($categories),
                'services'        => (string) wp_json_encode($services),
                'revision'        => $revision,
                'anonymized_ip'   => $anonymizedIp,
                'user_agent_hash' => $uaHash,
                'created_at'      => current_time('mysql', true),
            ],
            [
                '%s', // consent_uuid
                '%s', // action
                '%s', // categories
                '%s', // services
                '%d', // revision
                '%s', // anonymized_ip
                '%s', // user_agent_hash
                '%s', // created_at
            ]
        );

        if ($inserted === false) {
            wp_send_json_error(['message' => 'Failed to log consent record'], 500);
            return;
        }

        wp_send_json_success([
            'logged'       => true,
            'consent_uuid' => $consentUuid,
            'id'           => $wpdb->insert_id,
        ]);
    }

    /**
     * Query consent log records for admin list table and export.
     *
     * @param array<string, mixed> $args
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public static function queryLogs(array $args = []): array
    {
        global $wpdb;
        $tableName = ConsentLogTable::getTableName();

        $page     = max(1, (int) ($args['page'] ?? 1));
        $perPage  = max(1, min(200, (int) ($args['per_page'] ?? 20)));
        $offset   = ($page - 1) * $perPage;
        $search   = sanitize_text_field((string) ($args['search'] ?? ''));
        $action   = sanitize_key((string) ($args['action'] ?? ''));
        $revision = isset($args['revision']) && $args['revision'] !== '' ? (int) $args['revision'] : null;

        $where = ['1=1'];
        $params = [];

        if ($search !== '') {
            $where[] = '(consent_uuid LIKE %s OR anonymized_ip LIKE %s)';
            $wildcard = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $wildcard;
            $params[] = $wildcard;
        }

        if ($action !== '') {
            $where[] = 'action = %s';
            $params[] = $action;
        }

        if ($revision !== null) {
            $where[] = 'revision = %d';
            $params[] = $revision;
        }

        $whereClause = implode(' AND ', $where);

        // Count total matching
        $countSql = "SELECT COUNT(*) FROM {$tableName} WHERE {$whereClause}";
        if (!empty($params)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $total = (int) $wpdb->get_var($wpdb->prepare($countSql, $params));
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $total = (int) $wpdb->get_var($countSql);
        }

        // Fetch paginated items
        $dataSql = "SELECT * FROM {$tableName} WHERE {$whereClause} ORDER BY id DESC LIMIT %d OFFSET %d";
        $fetchParams = array_merge($params, [$perPage, $offset]);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $items = (array) $wpdb->get_results($wpdb->prepare($dataSql, $fetchParams), ARRAY_A);

        return [
            'items' => $items,
            'total' => $total,
        ];
    }
}
