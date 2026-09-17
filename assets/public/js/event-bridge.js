/**
 * Tuedion Cookie — Event Bridge
 * Dual-dispatch integration layer for Native DOM events and jQuery events.
 */

(function () {
    'use strict';

    window.TuedionCookieEventBridge = {
        /**
         * Dispatch event through both Modern CustomEvent and jQuery triggers.
         *
         * @param {string} eventName Event name (e.g. 'tuedion:consent', 'tuedion:change')
         * @param {Object} detail Event payload
         */
        dispatch: function (eventName, detail) {
            detail = detail || {};

            // 1. Modern Native DOM CustomEvent
            try {
                var nativeEvent = new CustomEvent(eventName, {
                    detail: detail,
                    bubbles: true,
                    cancelable: false
                });
                window.dispatchEvent(nativeEvent);
            } catch (e) {
                // Fallback for older browsers
                var fallbackEvent = document.createEvent('CustomEvent');
                fallbackEvent.initCustomEvent(eventName, true, false, detail);
                window.dispatchEvent(fallbackEvent);
            }

            // 2. jQuery Event Trigger (for WordPress themes and older plugins)
            if (window.jQuery && typeof window.jQuery === 'function') {
                try {
                    window.jQuery(document).trigger(eventName, [detail]);
                } catch (jqError) {
                    console.warn('[Tuedion Cookie] jQuery trigger failed:', jqError);
                }
            }
        }
    };
})();
