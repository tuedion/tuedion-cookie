<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Integrations\Adapters;

use Tuedion\CookieConsent\Integrations\RecipeRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Form Plugins Adapter (Contact Form 7, WPForms, Gravity Forms, Fluent Forms).
 * Handles CAPTCHA and anti-spam verification script gating, ensuring form submissions
 * are never broken silently when security tokens require consent.
 */
final class FormsAdapter
{
    public static function register(): void
    {
        // Contact Form 7
        add_filter('wpcf7_form_elements', [self::class, 'filterContactForm7Elements']);

        // WPForms
        add_action('wpforms_frontend_output', [self::class, 'outputWPFormsCaptchaNotice'], 20, 2);

        // Gravity Forms
        add_filter('gform_get_form_filter', [self::class, 'filterGravityFormsOutput'], 20, 2);

        // Fluent Forms
        add_action('fluentform/form_element_start', [self::class, 'outputFluentFormsCaptchaNotice'], 20, 1);
    }

    /**
     * Detect which supported form plugins are currently active.
     *
     * @return array<string, bool>
     */
    public static function getActiveFormPlugins(): array
    {
        return [
            'contact_form_7' => defined('WPCF7_VERSION'),
            'wpforms'        => defined('WPFORMS_VERSION'),
            'gravity_forms'  => class_exists('GFForms'),
            'fluent_forms'   => defined('FLUENTFORM_VERSION'),
        ];
    }

    /**
     * Check if any supported form plugin is active.
     */
    public static function isAnyActive(): bool
    {
        $active = self::getActiveFormPlugins();
        return in_array(true, $active, true);
    }

    /**
     * Inspect if CAPTCHA recipes are configured under a non-necessary category.
     */
    public static function isCaptchaConsentRequired(): bool
    {
        $recipes = RecipeRegistry::getAll();
        $captchaKeys = ['google-recaptcha', 'hcaptcha', 'cloudflare-turnstile'];

        foreach ($captchaKeys as $key) {
            if (isset($recipes[$key]) && ($recipes[$key]['category'] ?? 'necessary') !== 'necessary') {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate an accessible consent notice snippet for forms with gated security tokens.
     */
    public static function renderCaptchaNotice(): string
    {
        return sprintf(
            '<div class="tdcc-form-captcha-notice" style="margin: 12px 0; padding: 10px 14px; background: #f8fafc; border-left: 3px solid #64748b; font-size: 13px; color: #334155;">
                <strong>%s</strong> %s <a href="#" class="tdcc-open-preferences-link" data-cc="show-preferencesModal" style="text-decoration:underline; font-weight:600;">%s</a>
            </div>',
            esc_html__('Security Notice:', 'tuedion-cookie'),
            esc_html__('This form uses automated spam protection that requires your consent to load.', 'tuedion-cookie'),
            esc_html__('Update Cookie Preferences', 'tuedion-cookie')
        );
    }

    /**
     * Filter Contact Form 7 form HTML elements.
     */
    public static function filterContactForm7Elements(string $elements): string
    {
        if (self::isCaptchaConsentRequired() && (str_contains($elements, 'wpcf7-recaptcha') || str_contains($elements, 'wpcf7-form-control-wrap'))) {
            // Append notice before submit button if captcha token is in elements
            if (str_contains($elements, 'wpcf7-submit')) {
                $notice = self::renderCaptchaNotice();
                $replaced = preg_replace('/(<(?:input|button)[^>]*class=["\'][^"\']*wpcf7-submit)/i', $notice . '$1', $elements, 1);
                if (is_string($replaced) && $replaced !== '') {
                    return $replaced;
                }
            }
        }

        return $elements;
    }

    /**
     * Action callback for WPForms output.
     *
     * @param mixed $formData Form configuration array or data.
     * @param mixed $form Form post object or null.
     */
    public static function outputWPFormsCaptchaNotice(mixed $formData = null, mixed $form = null): void
    {
        if (!self::isCaptchaConsentRequired()) {
            return;
        }

        $hasCaptcha = false;
        if (is_array($formData)) {
            $hasCaptcha = !empty($formData['settings']['recaptcha']) || !empty($formData['settings']['turnstile']) || !empty($formData['settings']['hcaptcha']);
            if (!$hasCaptcha && isset($formData['fields']) && is_array($formData['fields'])) {
                foreach ($formData['fields'] as $field) {
                    $type = (string) ($field['type'] ?? '');
                    if (in_array($type, ['recaptcha', 'turnstile', 'hcaptcha', 'captcha'], true)) {
                        $hasCaptcha = true;
                        break;
                    }
                }
            }
        }

        if ($hasCaptcha) {
            echo self::renderCaptchaNotice();
        }
    }

    /**
     * Filter Gravity Forms output.
     *
     * @param string $formHtml
     * @param mixed $form
     * @return string
     */
    public static function filterGravityFormsOutput(string $formHtml, mixed $form = null): string
    {
        if (self::isCaptchaConsentRequired() && str_contains($formHtml, 'gform_recaptcha')) {
            $notice = self::renderCaptchaNotice();
            return str_replace('<div class="ginput_recaptcha', $notice . '<div class="ginput_recaptcha', $formHtml);
        }

        return $formHtml;
    }

    /**
     * Action callback for Fluent Forms element start.
     *
     * @param mixed $form Fluent form object or array.
     */
    public static function outputFluentFormsCaptchaNotice(mixed $form = null): void
    {
        if (!self::isCaptchaConsentRequired()) {
            return;
        }

        $hasCaptcha = false;
        if (is_object($form) && isset($form->form_fields)) {
            $fieldsJson = is_string($form->form_fields) ? $form->form_fields : (string) wp_json_encode($form->form_fields);
            if (stripos($fieldsJson, 'recaptcha') !== false || stripos($fieldsJson, 'turnstile') !== false || stripos($fieldsJson, 'hcaptcha') !== false) {
                $hasCaptcha = true;
            }
        }

        if ($hasCaptcha) {
            echo self::renderCaptchaNotice();
        }
    }
}
