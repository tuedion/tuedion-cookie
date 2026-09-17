<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Core;

if (!defined('ABSPATH')) {
    exit;
}

final class Requirements
{
    public static function check(): bool
    {
        $phpSatisfied = version_compare(PHP_VERSION, TUEDION_COOKIE_MIN_PHP, '>=');
        $wpSatisfied  = version_compare(get_bloginfo('version'), TUEDION_COOKIE_MIN_WP, '>=');

        if ($phpSatisfied && $wpSatisfied) {
            return true;
        }

        add_action('admin_notices', static function () use ($phpSatisfied, $wpSatisfied): void {
            if (!current_user_can('activate_plugins')) {
                return;
            }

            $messages = [];
            if (!$phpSatisfied) {
                $messages[] = sprintf(
                    /* translators: 1: required PHP version, 2: current PHP version */
                    esc_html__('Tuedion Cookie requires PHP version %1$s or higher. Your server is running PHP %2$s.', 'tuedion-cookie'),
                    TUEDION_COOKIE_MIN_PHP,
                    PHP_VERSION
                );
            }

            if (!$wpSatisfied) {
                $messages[] = sprintf(
                    /* translators: 1: required WordPress version, 2: current WordPress version */
                    esc_html__('Tuedion Cookie requires WordPress version %1$s or higher. Your site is running WordPress %2$s.', 'tuedion-cookie'),
                    TUEDION_COOKIE_MIN_WP,
                    get_bloginfo('version')
                );
            }

            printf(
                '<div class="notice notice-error"><p><strong>%s:</strong> %s</p></div>',
                esc_html__('Tuedion Cookie Error', 'tuedion-cookie'),
                implode(' ', array_map('esc_html', $messages))
            );
        });

        return false;
    }
}
