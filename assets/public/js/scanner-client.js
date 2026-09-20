/**
 * Tuedion Cookie - Client-Side Audit Scanner Script
 * Runs inside the sandboxed audit iframe to detect active trackers, iframes, and non-sensitive cookies.
 * 
 * Security:
 * - Never transmits raw document.cookie or cookie values.
 * - Explicitly excludes WordPress authentication and settings session cookies.
 * - Targets window.location.origin strictly via postMessage (no wildcard '*').
 */
(function() {
    'use strict';

    window.addEventListener('load', function() {
        setTimeout(function() {
            var blockedPrefixes = [
                'wordpress_',
                'wordpress_logged_in_',
                'wordpress_sec_',
                'wp-settings-',
                'wp-settings-time-',
                'wordpress_test_cookie'
            ];

            // 1. Extract non-sensitive cookie names only (NO values)
            var cookieNames = [];
            if (document.cookie) {
                var pairs = document.cookie.split(';');
                for (var i = 0; i < pairs.length; i++) {
                    var item = pairs[i].trim();
                    if (!item) continue;
                    var sepIndex = item.indexOf('=');
                    var name = sepIndex >= 0 ? item.slice(0, sepIndex).trim() : item;
                    if (!name) continue;

                    var isBlocked = false;
                    for (var b = 0; b < blockedPrefixes.length; b++) {
                        if (name.indexOf(blockedPrefixes[b]) === 0) {
                            isBlocked = true;
                            break;
                        }
                    }

                    if (!isBlocked && cookieNames.indexOf(name) === -1) {
                        cookieNames.push(name);
                    }
                }
            }

            // 2. Extract scripts (external URLs and capped inline signatures only)
            var scripts = [];
            var scriptEls = document.querySelectorAll('script');
            for (var s = 0; s < scriptEls.length; s++) {
                var scriptEl = scriptEls[s];
                if (scriptEl.src) {
                    scripts.push({
                        type: 'src',
                        value: scriptEl.src
                    });
                } else if (scriptEl.textContent) {
                    var text = scriptEl.textContent;
                    if (/(?:gtag|dataLayer|fbq|clarity|hjid|ttq)/i.test(text)) {
                        scripts.push({
                            type: 'inline',
                            value: text.slice(0, 300)
                        });
                    }
                }
            }

            // 3. Extract iframes
            var iframes = [];
            var iframeEls = document.querySelectorAll('iframe');
            for (var f = 0; f < iframeEls.length; f++) {
                if (iframeEls[f].src) {
                    iframes.push(iframeEls[f].src);
                }
            }

            var payload = {
                type: 'tdcc_scan_result',
                url: window.location.href,
                cookies: cookieNames,
                scripts: scripts,
                iframes: iframes
            };

            // Post only to same origin parent
            if (window.parent && window.parent !== window) {
                window.parent.postMessage(payload, window.location.origin);
            }
        }, 2000);
    });
})();
