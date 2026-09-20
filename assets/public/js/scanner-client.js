/**
 * Tuedion Cookie - Client-Side Audit Scanner Script v3.0
 * Runs inside the sandboxed audit iframe to detect active trackers, delayed scripts,
 * dynamic network beacons, embedded iframes, pixels, and cookies in the browser runtime.
 * 
 * Security Guarantees:
 * - NEVER reads or transmits cookie values or raw document.cookie strings.
 * - Filters out all WordPress authentication and admin session cookies.
 * - Extracts HTML5 Storage key names ONLY (never reads or transmits localStorage/sessionStorage values).
 * - Only sends postMessage targeting window.location.origin (no wildcard '*').
 * - Strictly avoids triggering destructive or form submission actions during simulated interactions.
 */
(function() {
    'use strict';

    var recordedNetwork = [];
    var recordedPixels = [];
    var recordedDynamicScripts = [];

    // 1. Intercept Network Beacons (Fetch & XMLHttpRequest) for tracking telemetry
    try {
        if (window.fetch) {
            var origFetch = window.fetch;
            window.fetch = function() {
                var url = arguments[0];
                if (typeof url === 'string') {
                    if (url.indexOf('http') === 0 && url.indexOf(window.location.origin) !== 0) {
                        recordedNetwork.push(url.slice(0, 300));
                    }
                } else if (url && url.url) {
                    if (url.url.indexOf('http') === 0 && url.url.indexOf(window.location.origin) !== 0) {
                        recordedNetwork.push(url.url.slice(0, 300));
                    }
                }
                return origFetch.apply(this, arguments);
            };
        }

        if (window.XMLHttpRequest) {
            var origOpen = window.XMLHttpRequest.prototype.open;
            window.XMLHttpRequest.prototype.open = function(method, url) {
                if (typeof url === 'string' && url.indexOf('http') === 0 && url.indexOf(window.location.origin) !== 0) {
                    recordedNetwork.push(url.slice(0, 300));
                }
                return origOpen.apply(this, arguments);
            };
        }
    } catch (e) {
        // Network interception ignored if restricted
    }

    // 2. Observe dynamically added scripts via MutationObserver
    try {
        var observer = new MutationObserver(function(mutations) {
            for (var m = 0; m < mutations.length; m++) {
                var added = mutations[m].addedNodes;
                for (var n = 0; n < added.length; n++) {
                    var node = added[n];
                    if (node.nodeName === 'SCRIPT' && node.src) {
                        recordedDynamicScripts.push(node.src);
                    }
                }
            }
        });
        if (document.documentElement) {
            observer.observe(document.documentElement, { childList: true, subtree: true });
        }
    } catch (e) {
        // MutationObserver ignored if unsupported
    }

    // 3. Main execution routine on page load
    window.addEventListener('load', function() {
        // Safe simulated scroll to trigger lazy-loaded trackers, Google Maps, YouTube embeds
        try {
            var docHeight = Math.max(
                document.body.scrollHeight,
                document.documentElement.scrollHeight,
                800
            );
            window.scrollTo({ top: Math.min(docHeight / 2, 1000), behavior: 'smooth' });
        } catch (e) {
            // Scroll fallback
        }

        // Wait for delayed trackers (e.g. GTM timers, Hotjar initialization, Meta Pixel beacons)
        setTimeout(function() {
            var blockedPrefixes = [
                'wordpress_',
                'wordpress_logged_in_',
                'wordpress_sec_',
                'wp-settings-',
                'wp-settings-time-',
                'wordpress_test_cookie'
            ];

            // A. Extract non-sensitive cookie names only (NO values)
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

            // B. Extract scripts (external URLs and capped inline signatures)
            var scripts = [];
            var seenScripts = {};
            var scriptEls = document.querySelectorAll('script');
            var inlineSignatures = /(?:gtag|dataLayer|fbq|clarity|hjid|_hjSettings|ttq|pintrk|ym\(|_paq|Intercom|\$crisp|Tawk_API|_linkedin_partner_id|twq|uetq|criteo_q|_hsq)/i;

            for (var s = 0; s < scriptEls.length; s++) {
                var scriptEl = scriptEls[s];
                if (scriptEl.src) {
                    if (!seenScripts[scriptEl.src]) {
                        seenScripts[scriptEl.src] = true;
                        scripts.push({
                            type: 'src',
                            value: scriptEl.src
                        });
                    }
                } else if (scriptEl.textContent) {
                    var text = scriptEl.textContent;
                    if (inlineSignatures.test(text)) {
                        scripts.push({
                            type: 'inline',
                            value: text.slice(0, 300)
                        });
                    }
                }
            }

            // Add dynamically recorded scripts
            for (var ds = 0; ds < recordedDynamicScripts.length; ds++) {
                var dSrc = recordedDynamicScripts[ds];
                if (!seenScripts[dSrc]) {
                    seenScripts[dSrc] = true;
                    scripts.push({
                        type: 'src',
                        value: dSrc
                    });
                }
            }

            // C. Extract iframes
            var iframes = [];
            var iframeEls = document.querySelectorAll('iframe');
            for (var f = 0; f < iframeEls.length; f++) {
                var iSrc = iframeEls[f].src || iframeEls[f].getAttribute('data-src');
                if (iSrc && iframes.indexOf(iSrc) === -1) {
                    iframes.push(iSrc);
                }
            }

            // D. Extract tracking pixel images (1x1 or 0x0 or beacon query strings)
            var imgEls = document.querySelectorAll('img');
            for (var im = 0; im < imgEls.length; im++) {
                var img = imgEls[im];
                var iUrl = img.src || img.getAttribute('data-src') || '';
                if (!iUrl || iUrl.indexOf('http') !== 0) continue;

                var isPixel = (img.naturalWidth === 1 && img.naturalHeight === 1)
                    || (img.width === 1 && img.height === 1)
                    || iUrl.indexOf('facebook.com/tr') !== -1
                    || iUrl.indexOf('google-analytics.com/collect') !== -1
                    || iUrl.indexOf('linkedin.com/px') !== -1;

                if (isPixel && recordedPixels.indexOf(iUrl) === -1) {
                    recordedPixels.push(iUrl.slice(0, 300));
                }
            }

            // E. Extract HTML5 Storage Key Names ONLY (Never values)
            var storageKeys = [];
            try {
                if (typeof window.localStorage !== 'undefined' && window.localStorage.length > 0) {
                    var lLen = Math.min(window.localStorage.length, 50);
                    for (var l = 0; l < lLen; l++) {
                        var lKey = window.localStorage.key(l);
                        if (lKey && typeof lKey === 'string' && storageKeys.indexOf(lKey) === -1) {
                            storageKeys.push(lKey.slice(0, 100));
                        }
                    }
                }
                if (typeof window.sessionStorage !== 'undefined' && window.sessionStorage.length > 0) {
                    var sLen = Math.min(window.sessionStorage.length, 50);
                    for (var ss = 0; ss < sLen; ss++) {
                        var sKey = window.sessionStorage.key(ss);
                        if (sKey && typeof sKey === 'string' && storageKeys.indexOf(sKey) === -1) {
                            storageKeys.push(sKey.slice(0, 100));
                        }
                    }
                }
            } catch (e) {
                // Storage access blocked or restricted by browser policy
            }

            // F. Extract discovered internal links for Crawler Queue depth
            var discoveredLinks = [];
            var anchorEls = document.querySelectorAll('a[href]');
            var curHost = window.location.host;
            for (var a = 0; a < anchorEls.length; a++) {
                var href = anchorEls[a].href;
                if (!href || href.indexOf('http') !== 0) continue;
                try {
                    var aUrl = new URL(href);
                    if (aUrl.host === curHost && discoveredLinks.indexOf(href) === -1) {
                        discoveredLinks.push(href);
                        if (discoveredLinks.length >= 30) break;
                    }
                } catch (e) {
                    // Invalid URL format
                }
            }

            var payload = {
                type: 'tdcc_scan_result',
                url: window.location.href,
                cookies: cookieNames,
                scripts: scripts,
                iframes: iframes,
                pixels: recordedPixels,
                network: recordedNetwork.slice(0, 30),
                storage: storageKeys,
                discovered_links: discoveredLinks
            };

            // Post only to same origin parent
            if (window.parent && window.parent !== window) {
                window.parent.postMessage(payload, window.location.origin);
            }
        }, 1800);
    });
})();
