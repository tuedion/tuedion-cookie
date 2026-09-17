/**
 * Tuedion Cookie — Persistent Privacy Trigger Interactive Engine
 * Controls visibility policies, modal collision avoidance, and showPreferences() call.
 */

(function () {
    'use strict';

    function initTrigger() {
        var wrapper = document.getElementById('tdcc-persistent-trigger-wrapper');
        var button = document.getElementById('tdcc-persistent-trigger-btn');

        if (!wrapper || !button) {
            return;
        }

        var policy = wrapper.getAttribute('data-policy') || 'after_choice';

        // 1. Check current consent state
        function updateVisibility() {
            var hasConsent = false;
            if (window.CookieConsent && typeof window.CookieConsent.validConsent === 'function') {
                hasConsent = window.CookieConsent.validConsent();
            }

            if (policy === 'always' || hasConsent) {
                wrapper.classList.add('is-visible');
            } else {
                wrapper.classList.remove('is-visible');
            }
        }

        // Run after DOM & CookieConsent ready
        if (window.CookieConsent) {
            updateVisibility();
        } else {
            window.addEventListener('load', updateVisibility);
        }

        // 2. React to consent events from Event Bridge
        window.addEventListener('tuedion:consent', function () {
            wrapper.classList.add('is-visible');
        });

        window.addEventListener('tuedion:first_consent', function () {
            wrapper.classList.add('is-visible');
        });

        // 3. Click handler: Re-open preferences center
        button.addEventListener('click', function (e) {
            e.preventDefault();
            if (window.CookieConsent && typeof window.CookieConsent.showPreferences === 'function') {
                window.CookieConsent.showPreferences();
            }
        });

        // 4. Collision management: Hide trigger cleanly while any CookieConsent modal is open
        window.addEventListener('cc:onModalShow', function () {
            wrapper.classList.add('is-modal-open');
        });

        window.addEventListener('cc:onModalHide', function () {
            wrapper.classList.remove('is-modal-open');
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTrigger);
    } else {
        initTrigger();
    }
})();
