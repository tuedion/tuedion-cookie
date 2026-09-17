<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class ImportExport
{
    /**
     * Export all settings as a versioned JSON string.
     */
    public static function export(): string
    {
        $payload = [
            'generator'      => 'Tuedion Cookie',
            'version'        => TUEDION_COOKIE_VERSION,
            'schema_version' => TUEDION_COOKIE_SCHEMA_VERSION,
            'exported_at'    => current_time('mysql'),
            'settings'       => Repository::getSettings(),
        ];

        return (string) wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Validate and import settings from JSON string.
     *
     * @return array{success: bool, errors: list<string>}
     */
    public static function import(string $json): array
    {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            return [
                'success' => false,
                'errors'  => [esc_html__('Invalid JSON payload provided.', 'tuedion-cookie')],
            ];
        }

        $rawSettings = $data['settings'] ?? $data;
        if (!is_array($rawSettings)) {
            return [
                'success' => false,
                'errors'  => [esc_html__('Settings object missing from import payload.', 'tuedion-cookie')],
            ];
        }

        $validation = Validator::validate($rawSettings);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'errors'  => $validation['errors'],
            ];
        }

        $updated = Repository::updateSettings($validation['sanitized']);
        if ($updated) {
            Compiler::clearCache();
        }

        return [
            'success' => $updated,
            'errors'  => $updated ? [] : [esc_html__('Failed to save imported settings to database.', 'tuedion-cookie')],
        ];
    }
}
