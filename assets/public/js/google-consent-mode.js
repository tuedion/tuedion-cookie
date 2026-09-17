/**
 * Tuedion Cookie — Google Consent Mode v2 Client Engine
 * Dynamically dispatches gtag('consent', 'update') and pushes GTM dataLayer events
 * upon user consent interactions.
 */

(function () {
    'use strict';

    var gcmConfig = window.tuedionGcmConfig || null;
    if (!gcmConfig || !gcmConfig.enabled) {
        return;
    }

    var mapping = gcmConfig.mapping || {};
    var isDebug = Boolean(gcmConfig.debug);

    /**
     * Compute GCM v2 update payload based on accepted category list.
     *
     * @param {string[]} acceptedCategories
     * @return {Object.<string, string>}
     */
    function buildUpdatePayload(acceptedCategories) {
        var payload = {};

        for (var signal in mapping) {
            if (!Object.prototype.hasOwnProperty.call(mapping, signal)) {
                continue;
            }

            var category = mapping[signal];

            // security_storage is always granted
            if (signal === 'security_storage' || category === 'necessary') {
                payload[signal] = 'granted';
                continue;
            }

            if (acceptedCategories && acceptedCategories.indexOf(category) !== -1) {
                payload[signal] = 'granted';
            } else {
                payload[signal] = 'denied';
            }
        }

        return payload;
    }

    /**
     * Dispatch consent update to Google tags and Google Tag Manager.
     *
     * @param {string[]} acceptedCategories
     * @param {string} sourceEvent
     */
    function updateGoogleConsent(acceptedCategories, sourceEvent) {
        if (typeof window.gtag !== 'function') {
            window.dataLayer = window.dataLayer || [];
            window.gtag = function () {
                window.dataLayer.push(arguments);
            };
        }

        var updatePayload = buildUpdatePayload(acceptedCategories);

        // 1. Core gtag update
        window.gtag('consent', 'update', updatePayload);

        // 2. Push event for Google Tag Manager (GTM) custom triggers
        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push({
            event: 'tuedion_consent_update',
            source_event: sourceEvent,
            tuedion_accepted_categories: acceptedCategories,
            consent_signals: updatePayload
        });

        if (isDebug) {
            console.log('[Tuedion Cookie] GCM v2 Update Dispatched (' + sourceEvent + '):', updatePayload);
        }
    }

    /**
     * Restore returning visitor consent state early from cookie or localStorage.
     * Guarantees returning visitors have their consent restored before third-party tags run,
     * without polluting full-page static HTML caches.
     */
    function restoreExistingConsent() {
        var cookieName = gcmConfig.cookieName || 'cc_cookie';
        var expectedRevision = typeof gcmConfig.revision === 'number' ? gcmConfig.revision : 1;

        var raw = '';
        var match = document.cookie.match(new RegExp('(?:^|; )' + cookieName.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
        if (match) {
            try {
                raw = decodeURIComponent(match[1]);
            } catch (e) {
                raw = match[1];
            }
        } else if (window.localStorage) {
            try {
                raw = window.localStorage.getItem(cookieName) || '';
            } catch (e) {}
        }

        if (!raw) {
            return;
        }

        try {
            var parsed = JSON.parse(raw);
            // Validate policy revision
            if (parsed && typeof parsed === 'object' && Array.isArray(parsed.categories)) {
                if (typeof parsed.revision === 'number' && parsed.revision !== expectedRevision) {
                    if (isDebug) {
                        console.log('[Tuedion Cookie] GCM: Cookie revision outdated (' + parsed.revision + ' vs ' + expectedRevision + '). Awaiting renewed consent.');
                    }
                    return;
                }
                updateGoogleConsent(parsed.categories, 'early_restore');
            }
        } catch (e) {}
    }

    restoreExistingConsent();

    /**
     * Handle Tuedion Event Bridge callbacks.
     */
    function onConsentEvent(e) {
        var detail = e.detail || {};
        var cookie = detail.cookie || {};
        var categories = cookie.categories || [];

        // Fallback to CookieConsent API if detail is empty
        if ((!categories || categories.length === 0) && window.CookieConsent && typeof window.CookieConsent.getUserPreferences === 'function') {
            var prefs = window.CookieConsent.getUserPreferences();
            if (prefs && prefs.acceptedCategories) {
                categories = prefs.acceptedCategories;
            }
        }

        updateGoogleConsent(categories, e.type);
    }

    // Listen strictly to single-channel native CustomEvents to prevent duplicate updates
    window.addEventListener('tuedion:consent', onConsentEvent);
    window.addEventListener('tuedion:change', onConsentEvent);

    if (isDebug) {
        console.log('[Tuedion Cookie] GCM v2 Client Listener Registered with Mapping:', mapping);
    }
})();
