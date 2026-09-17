<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Diagnostics;

use Tuedion\CookieConsent\Settings\Compiler;
use Tuedion\CookieConsent\Settings\Repository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Runtime Configuration Inspector.
 * Validates compiled CookieConsent JSON configuration and analyzes integrity.
 */
final class ConfigInspector
{
    /**
     * Inspect and validate compiled runtime configuration.
     *
     * @return array{
     *     config: array<string, mixed>,
     *     formatted_json: string,
     *     is_valid: bool,
     *     summary: array<string, mixed>,
     *     issues: list<string>
     * }
     */
    public static function inspect(): array
    {
        $config = Compiler::compile();
        $issues = [];

        // 1. Validate categories
        $categories = $config['categories'] ?? [];
        if (!isset($categories['necessary'])) {
            $issues[] = __('The "necessary" category is missing from the compiled categories.', 'tuedion-cookie');
        }

        // 2. Validate languages
        $translations = $config['language']['translations'] ?? [];
        if (empty($translations)) {
            $issues[] = __('No language translations were loaded in runtime configuration.', 'tuedion-cookie');
        }

        // Check if current language exists
        $defaultLang = $config['language']['default'] ?? 'en';
        if (!isset($translations[$defaultLang])) {
            $issues[] = sprintf(__('The default language "%s" is not defined in translations.', 'tuedion-cookie'), $defaultLang);
        }

        // 3. AutoClear validation
        $autoClearCount = 0;
        foreach ($categories as $catId => $catConfig) {
            if (!empty($catConfig['autoClear']['cookies'])) {
                $autoClearCount += count($catConfig['autoClear']['cookies']);
            }
        }

        $formattedJson = (string) wp_json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $summary = [
            'revision'          => (int) ($config['revision'] ?? 1),
            'default_language'  => (string) $defaultLang,
            'languages_count'   => count($translations),
            'categories_count'  => count($categories),
            'autoclear_rules'   => $autoClearCount,
            'root_element'      => (string) ($config['root'] ?? 'body'),
            'auto_show'         => !empty($config['autoShow']),
            'disable_page_interaction' => !empty($config['disablePageInteraction']),
        ];

        return [
            'config'         => $config,
            'formatted_json' => $formattedJson,
            'is_valid'       => empty($issues),
            'summary'        => $summary,
            'issues'         => $issues,
        ];
    }
}
