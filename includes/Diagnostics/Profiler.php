<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Diagnostics;

use Tuedion\CookieConsent\Integrations\CacheCompatibility;
use Tuedion\CookieConsent\Settings\Repository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tuedion Cookie — Enterprise Telemetry Profiler & Parametric Isolation Engine.
 *
 * Provides granular URL-based subsystem isolation:
 * - ?tdcc_safe=1             => Complete frontend bypass (zero assets/hooks)
 * - ?tdcc_disable_scripts=1  => Disables ScriptEnforcer (script rewriting)
 * - ?tdcc_disable_iframes=1  => Disables IframeEnforcer (iframe blocking)
 * - ?tdcc_disable_gcm=1      => Disables Google Consent Mode v2 head injection
 * - ?tdcc_disable_modal=1    => Disables CookieConsent.run() execution in browser
 * - ?tdcc_disable_trigger=1  => Disables Persistent Privacy Trigger button
 * - ?tdcc_debug=1            => Injects live floating HUD and console telemetry
 */
final class Profiler
{
    private static float $startTime = 0.0;
    private static int $startMemory = 0;

    /**
     * @var list<array{handle: string, src: string, action: string, category: string|null, service: string|null, time_ms: float}>
     */
    private static array $scriptEvents = [];

    /**
     * @var list<array{src: string, category: string, service: string, time_ms: float}>
     */
    private static array $iframeEvents = [];

    public static function init(): void
    {
        if (self::$startTime === 0.0) {
            self::$startTime = microtime(true);
            self::$startMemory = memory_get_usage();
        }

        if (self::isDebugActive() && !is_admin()) {
            add_action('wp_footer', [self::class, 'renderDebugOverlay'], 9999);
            add_action('send_headers', [self::class, 'injectDebugHeaders']);
            if (!headers_sent()) {
                nocache_headers();
            }
        }
    }

    /**
     * Determine if current user is authorized to invoke diagnostics or bypass flags.
     * Security: WP_DEBUG alone must never grant bypass capability to anonymous visitors.
     */
    private static function canDebug(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Check if complete safe mode (bypass) is requested.
     */
    public static function isBypassed(): bool
    {
        if (!self::canDebug()) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only debug query parameter.
        return !empty($_GET['tdcc_safe']) || !empty($_GET['tdcc_bypass']);
    }

    /**
     * Check if ScriptEnforcer is disabled via query param.
     */
    public static function isScriptEnforcerDisabled(): bool
    {
        if (!self::canDebug()) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only debug query parameter.
        return self::isBypassed() || !empty($_GET['tdcc_disable_scripts']);
    }

    /**
     * Check if IframeEnforcer is disabled via query param.
     */
    public static function isIframeEnforcerDisabled(): bool
    {
        if (!self::canDebug()) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only debug query parameter.
        return self::isBypassed() || !empty($_GET['tdcc_disable_iframes']);
    }

    /**
     * Check if GCM v2 head tag is disabled via query param.
     */
    public static function isGcmDisabled(): bool
    {
        if (!self::canDebug()) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only debug query parameter.
        return self::isBypassed() || !empty($_GET['tdcc_disable_gcm']);
    }

    /**
     * Check if client-side CookieConsent.run() is disabled via query param.
     */
    public static function isModalDisabled(): bool
    {
        if (!self::canDebug()) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only debug query parameter.
        return self::isBypassed() || !empty($_GET['tdcc_disable_modal']);
    }

    /**
     * Check if Persistent Privacy Trigger is disabled via query param.
     */
    public static function isTriggerDisabled(): bool
    {
        if (!self::canDebug()) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only debug query parameter.
        return self::isBypassed() || !empty($_GET['tdcc_disable_trigger']);
    }

    /**
     * Check if telemetry / debug HUD is active.
     */
    public static function isDebugActive(): bool
    {
        if (!self::canDebug()) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only debug query parameter.
        if (!empty($_GET['tdcc_debug'])) {
            return true;
        }

        $settings = Repository::getSettings();
        return !empty($settings['advanced']['debug_mode']);
    }

    /**
     * Record script inspection or transformation event.
     */
    public static function recordScript(string $handle, string $src, string $action, ?string $category = null, ?string $service = null): void
    {
        self::$scriptEvents[] = [
            'handle'   => $handle,
            'src'      => $src,
            'action'   => $action,
            'category' => $category,
            'service'  => $service,
            'time_ms'  => round((microtime(true) - (self::$startTime ?: microtime(true))) * 1000, 2),
        ];
    }

    /**
     * Record iframe interception event.
     */
    public static function recordIframe(string $src, string $category, string $service): void
    {
        self::$iframeEvents[] = [
            'src'      => $src,
            'category' => $category,
            'service'  => $service,
            'time_ms'  => round((microtime(true) - (self::$startTime ?: microtime(true))) * 1000, 2),
        ];
    }

    /**
     * Get compiled summary for diagnostics and headers.
     *
     * @return array<string, mixed>
     */
    public static function getSummary(): array
    {
        $now = microtime(true);
        $elapsedMs = round(($now - (self::$startTime ?: $now)) * 1000, 2);
        $peakMemMb = round(memory_get_peak_usage(true) / 1048576, 2);

        $blockedCount = 0;
        foreach (self::$scriptEvents as $ev) {
            if ($ev['action'] === 'BLOCKED') {
                $blockedCount++;
            }
        }

        return [
            'elapsed_ms'       => $elapsedMs,
            'peak_memory_mb'   => $peakMemMb,
            'scripts_total'    => count(self::$scriptEvents),
            'scripts_blocked'  => $blockedCount,
            'iframes_blocked'  => count(self::$iframeEvents),
            'cache_plugins'    => CacheCompatibility::getActiveCachePlugins(),
            'isolation_flags'  => [
                'safe_mode'        => self::isBypassed(),
                'disable_scripts'  => self::isScriptEnforcerDisabled(),
                'disable_iframes'  => self::isIframeEnforcerDisabled(),
                'disable_gcm'      => self::isGcmDisabled(),
                'disable_modal'    => self::isModalDisabled(),
                'disable_trigger'  => self::isTriggerDisabled(),
            ],
            'script_events'    => self::$scriptEvents,
            'iframe_events'    => self::$iframeEvents,
        ];
    }

    /**
     * Inject diagnostic HTTP headers.
     */
    public static function injectDebugHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        $summary = self::getSummary();
        header('X-Tuedion-Cookie-Debug: 1');
        header(sprintf('X-Tuedion-Cookie-Profile: time=%sms; mem=%sMB; scripts=%d; blocked=%d; iframes=%d',
            $summary['elapsed_ms'],
            $summary['peak_memory_mb'],
            $summary['scripts_total'],
            $summary['scripts_blocked'],
            $summary['iframes_blocked']
        ));
    }

    /**
     * Render the floating Live Diagnostic HUD at the bottom-right of the frontend.
     */
    public static function renderDebugOverlay(): void
    {
        $summary = self::getSummary();
        $cachePlugins = !empty($summary['cache_plugins']) ? implode(', ', $summary['cache_plugins']) : 'None Detected';
        $jsonData = wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $serverHost = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $serverUri  = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $currentUrl = (is_ssl() ? 'https://' : 'http://') . $serverHost . $serverUri;
        $urlWithoutParams = strtok($currentUrl, '?') ?: $currentUrl;

        ?>
        <!-- TUEDION COOKIE PRO DIAGNOSTIC HUD -->
        <div id="tdcc-debug-hud" style="position:fixed;bottom:16px;right:16px;z-index:9999999;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px;line-height:1.4;background:#0f172a;color:#f8fafc;border:1px solid #38bdf8;border-radius:10px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.5),0 8px 10px -6px rgba(0,0,0,0.5);max-width:440px;width:calc(100vw - 32px);">
            <div style="background:#1e293b;padding:10px 14px;border-top-left-radius:9px;border-top-right-radius:9px;border-bottom:1px solid #334155;display:flex;align-items:center;justify-content:space-between;cursor:pointer;" onclick="var b=document.getElementById('tdcc-debug-body');b.style.display=b.style.display==='none'?'block':'none';">
                <span style="font-weight:700;color:#38bdf8;display:flex;align-items:center;gap:6px;">
                    <span style="font-size:14px;">🛡️</span> Tuedion Pro Telemetry
                </span>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="background:#0284c7;color:#fff;padding:2px 8px;border-radius:9999px;font-size:11px;font-weight:600;"><?php echo esc_html((string)$summary['elapsed_ms']); ?> ms</span>
                    <span style="color:#94a3b8;font-size:11px;">▼ click to toggle</span>
                </div>
            </div>
            <div id="tdcc-debug-body" style="padding:14px;max-height:480px;overflow-y:auto;display:block;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px;">
                    <div style="background:#090d16;padding:8px;border-radius:6px;border:1px solid #1e293b;">
                        <div style="color:#94a3b8;font-size:10px;text-transform:uppercase;">Scripts Blocked</div>
                        <div style="font-size:18px;font-weight:700;color:<?php echo $summary['scripts_blocked'] > 0 ? '#fbbf24' : '#34d399'; ?>;">
                            <?php echo esc_html((string)$summary['scripts_blocked']); ?> <span style="font-size:12px;color:#64748b;">/ <?php echo esc_html((string)$summary['scripts_total']); ?></span>
                        </div>
                    </div>
                    <div style="background:#090d16;padding:8px;border-radius:6px;border:1px solid #1e293b;">
                        <div style="color:#94a3b8;font-size:10px;text-transform:uppercase;">Iframes Blocked</div>
                        <div style="font-size:18px;font-weight:700;color:<?php echo $summary['iframes_blocked'] > 0 ? '#38bdf8' : '#34d399'; ?>;">
                            <?php echo esc_html((string)$summary['iframes_blocked']); ?>
                        </div>
                    </div>
                </div>

                <div style="margin-bottom:12px;background:#090d16;padding:8px 10px;border-radius:6px;border:1px solid #1e293b;font-size:11px;">
                    <div style="color:#94a3b8;margin-bottom:2px;"><strong>Cache / Delayer:</strong> <?php echo esc_html($cachePlugins); ?></div>
                    <div style="color:#94a3b8;"><strong>Peak PHP Memory:</strong> <?php echo esc_html((string)$summary['peak_memory_mb']); ?> MB</div>
                </div>

                <!-- Isolation Switches -->
                <div style="margin-bottom:14px;">
                    <div style="color:#cbd5e1;font-weight:600;font-size:11px;margin-bottom:6px;text-transform:uppercase;">Surgical Isolation Toggles:</div>
                    <div style="display:flex;flex-wrap:wrap;gap:4px;">
                        <a href="<?php echo esc_url(add_query_arg('tdcc_disable_scripts', '1', $currentUrl)); ?>" style="display:inline-block;padding:4px 8px;border-radius:4px;background:<?php echo $summary['isolation_flags']['disable_scripts'] ? '#dc2626' : '#1e293b'; ?>;color:#fff;text-decoration:none;font-size:11px;border:1px solid #334155;">
                            <?php echo $summary['isolation_flags']['disable_scripts'] ? '✓ Scripts Disabled' : 'Disable Scripts'; ?>
                        </a>
                        <a href="<?php echo esc_url(add_query_arg('tdcc_disable_iframes', '1', $currentUrl)); ?>" style="display:inline-block;padding:4px 8px;border-radius:4px;background:<?php echo $summary['isolation_flags']['disable_iframes'] ? '#dc2626' : '#1e293b'; ?>;color:#fff;text-decoration:none;font-size:11px;border:1px solid #334155;">
                            <?php echo $summary['isolation_flags']['disable_iframes'] ? '✓ Iframes Disabled' : 'Disable Iframes'; ?>
                        </a>
                        <a href="<?php echo esc_url(add_query_arg('tdcc_disable_modal', '1', $currentUrl)); ?>" style="display:inline-block;padding:4px 8px;border-radius:4px;background:<?php echo $summary['isolation_flags']['disable_modal'] ? '#dc2626' : '#1e293b'; ?>;color:#fff;text-decoration:none;font-size:11px;border:1px solid #334155;">
                            <?php echo $summary['isolation_flags']['disable_modal'] ? '✓ Modal Disabled' : 'Disable Modal'; ?>
                        </a>
                        <a href="<?php echo esc_url(add_query_arg('tdcc_disable_gcm', '1', $currentUrl)); ?>" style="display:inline-block;padding:4px 8px;border-radius:4px;background:<?php echo $summary['isolation_flags']['disable_gcm'] ? '#dc2626' : '#1e293b'; ?>;color:#fff;text-decoration:none;font-size:11px;border:1px solid #334155;">
                            <?php echo $summary['isolation_flags']['disable_gcm'] ? '✓ GCM Disabled' : 'Disable GCM'; ?>
                        </a>
                        <a href="<?php echo esc_url(add_query_arg('tdcc_safe', '1', $currentUrl)); ?>" style="display:inline-block;padding:4px 8px;border-radius:4px;background:#7f1d1d;color:#fff;text-decoration:none;font-size:11px;border:1px solid #ef4444;">
                            Bypass Plugin
                        </a>
                        <a href="<?php echo esc_url($urlWithoutParams . '?tdcc_debug=1'); ?>" style="display:inline-block;padding:4px 8px;border-radius:4px;background:#0369a1;color:#fff;text-decoration:none;font-size:11px;border:1px solid #0ea5e9;">
                            Reset URL
                        </a>
                    </div>
                </div>

                <!-- Intercepted scripts detail -->
                <div style="color:#cbd5e1;font-weight:600;font-size:11px;margin-bottom:6px;text-transform:uppercase;">Evaluated Scripts:</div>
                <div style="background:#090d16;padding:8px;border-radius:6px;border:1px solid #1e293b;max-height:160px;overflow-y:auto;font-size:11px;">
                    <?php if (empty($summary['script_events'])): ?>
                        <div style="color:#64748b;">No scripts intercepted or evaluated on this page.</div>
                    <?php else: ?>
                        <?php foreach ($summary['script_events'] as $ev): ?>
                            <div style="padding:4px 0;border-bottom:1px solid #1e293b;display:flex;justify-content:space-between;align-items:center;">
                                <span style="color:#f8fafc;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:240px;" title="<?php echo esc_attr($ev['handle']); ?>">
                                    <?php echo esc_html($ev['handle']); ?>
                                </span>
                                <span style="font-size:10px;padding:2px 6px;border-radius:4px;background:<?php echo $ev['action'] === 'BLOCKED' ? '#854d0e' : '#1e3a8a'; ?>;color:#fff;">
                                    <?php echo esc_html($ev['action']); ?> (<?php echo esc_html($ev['category'] ?? 'immune'); ?>)
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div style="margin-top:10px;font-size:10px;color:#64748b;display:flex;justify-content:space-between;">
                    <span>Open browser console (F12) for full trace</span>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=tuedion-cookie-diagnostics')); ?>" style="color:#38bdf8;text-decoration:none;">Admin Diagnostics &rarr;</a>
                </div>
            </div>
        </div>

        <script data-cfasync="false" data-pagespeed-no-defer data-no-defer="1" data-rocketignore="true">
            (function() {
                var summary = <?php echo wp_json_encode($summary); ?>;
                console.group('🛡️ [Tuedion Cookie Pro Telemetry]');
                console.log('⚡ Execution Time:', summary.elapsed_ms + ' ms');
                console.log('💾 Peak PHP Memory:', summary.peak_memory_mb + ' MB');
                console.log('🚀 Cache Plugins:', summary.cache_plugins);
                console.log('🚫 Scripts Blocked:', summary.scripts_blocked, 'of', summary.scripts_total);
                console.table(summary.script_events);
                if (summary.iframe_events && summary.iframe_events.length > 0) {
                    console.log('🖼️ Iframes Blocked:', summary.iframes_blocked);
                    console.table(summary.iframe_events);
                }
                console.groupEnd();

                // Live client timing monitor
                window.addEventListener('load', function() {
                    var nav = performance.getEntriesByType('navigation')[0];
                    if (nav) {
                        console.log('⏱️ [Browser Navigation Timings]', {
                            'TTFB (Response Start)': Math.round(nav.responseStart) + 'ms',
                            'DOM Interactive': Math.round(nav.domInteractive) + 'ms',
                            'DOM Complete': Math.round(nav.domComplete) + 'ms',
                            'Load Event End': Math.round(nav.loadEventEnd) + 'ms'
                        });
                    }
                });

                // Catch and display any unhandled JS error
                window.addEventListener('error', function(err) {
                    var hudBody = document.getElementById('tdcc-debug-body');
                    if (hudBody) {
                        var errDiv = document.createElement('div');
                        errDiv.style.cssText = 'background:#7f1d1d;color:#fecaca;padding:8px;border-radius:6px;margin-top:8px;font-size:11px;word-break:break-word;';
                        errDiv.innerHTML = '<strong>❌ Uncaught JS Error:</strong> ' + (err.message || 'Unknown Error') + '<br><small>' + (err.filename || '') + ':' + (err.lineno || '');
                        hudBody.prepend(errDiv);
                    }
                });
            })();
        </script>
        <?php
    }
}
