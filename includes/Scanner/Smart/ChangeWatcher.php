<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Scanner\Smart;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Monitors WordPress plugin and theme activation/deactivation events to recommend re-scanning.
 */
final class ChangeWatcher
{
    public const OPTION_KEY = 'tdcc_site_changes_detected';

    /**
     * Register WordPress action hooks for change monitoring.
     */
    public function registerHooks(): void
    {
        add_action('activated_plugin', [$this, 'onPluginActivated'], 10, 2);
        add_action('deactivated_plugin', [$this, 'onPluginDeactivated'], 10, 2);
        add_action('after_switch_theme', [$this, 'onThemeSwitched'], 10, 2);
    }

    /**
     * @param string $plugin Plugin file path relative to wp-content/plugins
     * @param bool $networkWide
     */
    public function onPluginActivated(string $plugin, bool $networkWide = false): void
    {
        // Ignore self-activation
        if (str_contains($plugin, 'tuedion-cookie')) {
            return;
        }

        $this->recordChange('plugin_activated', [
            'plugin'       => $plugin,
            'network_wide' => $networkWide,
        ]);
    }

    /**
     * @param string $plugin
     * @param bool $networkWide
     */
    public function onPluginDeactivated(string $plugin, bool $networkWide = false): void
    {
        if (str_contains($plugin, 'tuedion-cookie')) {
            return;
        }

        $this->recordChange('plugin_deactivated', [
            'plugin'       => $plugin,
            'network_wide' => $networkWide,
        ]);
    }

    /**
     * @param string $newThemeName
     * @param \WP_Theme $newTheme
     */
    public function onThemeSwitched(string $newThemeName, \WP_Theme $newTheme): void
    {
        $this->recordChange('theme_switched', [
            'theme' => $newThemeName,
        ]);
    }

    /**
     * Records a detected change in WordPress options.
     *
     * @param string $type
     * @param array<string, mixed> $context
     */
    public function recordChange(string $type, array $context = []): void
    {
        $data = [
            'type'       => $type,
            'context'    => $context,
            'timestamp'  => time(),
            'date'       => current_time('mysql'),
            'dismissed'  => false,
        ];

        update_option(self::OPTION_KEY, $data, false);
    }

    /**
     * Checks whether pending structural changes exist on the site.
     */
    public function hasChanges(): bool
    {
        $data = get_option(self::OPTION_KEY);
        if (!is_array($data) || empty($data['timestamp'])) {
            return false;
        }

        return empty($data['dismissed']);
    }

    /**
     * Returns change details if any exist.
     *
     * @return array<string, mixed>|null
     */
    public function getPendingChange(): ?array
    {
        $data = get_option(self::OPTION_KEY);
        if (!is_array($data) || empty($data['timestamp'])) {
            return null;
        }

        return $data;
    }

    /**
     * Acknowledges or dismisses the change notification without clearing historical record.
     */
    public function acknowledgeChanges(): void
    {
        $data = get_option(self::OPTION_KEY);
        if (is_array($data)) {
            $data['dismissed'] = true;
            update_option(self::OPTION_KEY, $data, false);
        }
    }

    /**
     * Clears the change flag upon completing a scan.
     */
    public function markScanned(): void
    {
        delete_option(self::OPTION_KEY);
    }
}
