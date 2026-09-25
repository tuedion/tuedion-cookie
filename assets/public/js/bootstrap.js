/**
 * Tuedion Cookie — Public Bootstrap
 * Connects Orest Bida vanilla-cookieconsent with Tuedion Event Bridge,
 * handles dynamic iframe placeholder unlocking, and manages reload on revoke.
 */

(function () {
    'use strict';

    var config = window.tuedionCookieConfig || {};
    var lastAcceptedCategories = [];
    var lastAcceptedServices = {};

    if (config.disableModal) {
        console.warn('[Tuedion Cookie Telemetry] Modal & CookieConsent execution bypassed (?tdcc_disable_modal=1).');
        return;
    }

    /**
     * 0. PostMessage Shield: Prevents DOMException flood from Elementor / YouTube API polling.
     * When an unconsented embed frame is blocked or about:blank, third-party scripts (Elementor, YT API)
     * continuously send postMessage to 'https://www.youtube.com' or 'https://player.vimeo.com'.
     * The browser checks recipient window origin against targetOrigin and throws DOMException if mismatched.
     * We intercept postMessage and silently absorb attempts directed to unconsented same-origin / blank frames.
     */
    function setupPostMessageProtection() {
        if (typeof Window === 'undefined' || !Window.prototype || !Window.prototype.postMessage) return;

        var nativePostMessage = Window.prototype.postMessage;
        Window.prototype.postMessage = function (message, targetOrigin, transfer) {
            try {
                if (targetOrigin && targetOrigin !== '*' && targetOrigin !== '/') {
                    var targetStr = String(targetOrigin).toLowerCase();
                    if (targetStr.indexOf('youtube') !== -1 || targetStr.indexOf('vimeo') !== -1) {
                        try {
                            // If this window is about:blank or on the current domain, origins do not match targetOrigin
                            var winLoc = this.location.href;
                            if (winLoc === 'about:blank' || winLoc === '' || winLoc.indexOf(window.location.origin) === 0) {
                                // Silently absorb postMessage to neutralized frame
                                return;
                            }
                        } catch (crossOriginAccessErr) {
                            // Accessing this.location throws cross-origin error only when the iframe
                            // is already a REAL cross-origin frame (e.g. real youtube.com embed after consent).
                            // In this case, pass through to native postMessage!
                        }
                    }
                }
                return nativePostMessage.apply(this, arguments);
            } catch (err) {
                // Absorb target origin mismatch DOMException cleanly
                if (err && err.name === 'DOMException' && typeof targetOrigin === 'string' &&
                    (targetOrigin.indexOf('youtube') !== -1 || targetOrigin.indexOf('vimeo') !== -1)) {
                    return;
                }
                throw err;
            }
        };
    }

    // Execute postMessage protection immediately before any vendor script attaches listeners
    setupPostMessageProtection();

    /**
     * Instant consent checker reading directly from cc_cookie.
     * Operates synchronously before CookieConsent UMD finishes booting.
     * Supports both whole-category and service-level checks.
     */
    function hasMarketingConsent(service) {
        if (window.CookieConsent && typeof window.CookieConsent.acceptedCategory === 'function') {
            if (window.CookieConsent.acceptedCategory('marketing')) {
                return true;
            }
            if (service && typeof window.CookieConsent.acceptedService === 'function') {
                return window.CookieConsent.acceptedService(service, 'marketing');
            }
        }
        try {
            var match = document.cookie.match(/(?:^|;)\s*cc_cookie\s*=\s*([^;]+)/);
            if (!match) return false;
            var val = JSON.parse(decodeURIComponent(match[1]));
            if (Array.isArray(val.categories) && val.categories.indexOf('marketing') !== -1) {
                return true;
            }
            if (service && val.services && Array.isArray(val.services.marketing)) {
                return val.services.marketing.indexOf(service) !== -1;
            }
            return false;
        } catch (e) {
            return false;
        }
    }

    /**
     * Parse arbitrary URL into known embed service, ID, and extra query params.
     */
    function parseEmbedSource(url) {
        if (!url || typeof url !== 'string') return null;
        var u = url.trim();
        if (u === '' || u === 'about:blank' || u.indexOf('data:') === 0) return null;

        // 1. YouTube (youtube.com, youtube-nocookie.com, youtu.be)
        var ytMatch = u.match(/(?:youtube(?:-nocookie)?\.com\/(?:embed\/|watch\?v=|v\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/i);
        if (ytMatch) {
            var id = ytMatch[1];
            var params = '';
            var qIdx = u.indexOf('?');
            if (qIdx !== -1) {
                params = u.substring(qIdx + 1);
            }
            return { service: 'youtube', id: id, params: params, originalSrc: u };
        }

        // 2. Vimeo
        var vimeoMatch = u.match(/(?:player\.)?vimeo\.com\/(?:video\/)?([0-9]+)/i);
        if (vimeoMatch) {
            return { service: 'vimeo', id: vimeoMatch[1], params: '', originalSrc: u };
        }

        // 3. Google Maps
        if (u.indexOf('google.com/maps') !== -1 || u.indexOf('maps.google.') !== -1 || /google\.[a-z.]+\/maps/i.test(u)) {
            return { service: 'google-maps', id: u, params: '', originalSrc: u };
        }

        return null;
    }

    /**
     * Extract original URL from iframe element attributes or parent Elementor widget metadata.
     */
    function extractIframeUrl(iframe) {
        if (!iframe) return '';

        // 1. Explicit data attributes
        var blockedSrc = iframe.getAttribute('data-tdcc-blocked-src');
        if (blockedSrc) return blockedSrc;

        var origSrc = iframe.getAttribute('data-original-src');
        if (origSrc) return origSrc;

        var lazySrc = iframe.getAttribute('data-lazy-src') || iframe.getAttribute('data-src');
        if (lazySrc && lazySrc.indexOf('about:') === -1 && lazySrc.indexOf('data:') !== 0) return lazySrc;

        var src = iframe.getAttribute('src');
        if (src && src.indexOf('about:') === -1 && src.indexOf('data:') !== 0) return src;

        try {
            if (iframe.src && iframe.src.indexOf('about:') === -1 && iframe.src.indexOf('data:') !== 0) {
                return iframe.src;
            }
        } catch (e) {}

        // 2. Elementor widget container check
        var elementorWidget = iframe.closest && iframe.closest('.elementor-widget-video');
        if (elementorWidget) {
            var settingsAttr = elementorWidget.getAttribute('data-settings');
            if (settingsAttr) {
                try {
                    var s = JSON.parse(settingsAttr);
                    if (s && s.youtube_url) return s.youtube_url;
                    if (s && s.vimeo_url) return s.vimeo_url;
                } catch (e) {}
            }
        }

        return '';
    }

    /**
     * Transform an unmanaged iframe element into an Orest Bida iframemanager DIV.
     * Instantly stops any ongoing browser network request and replaces iframe in DOM.
     */
    function transformIframeToDiv(iframe) {
        if (!iframe || iframe.hasAttribute('data-tdcc-processed')) return null;
        if (iframe.getAttribute('data-tdcc-consented') === '1' || (iframe.closest && iframe.closest('div[data-service], .cll'))) {
            return null;
        }

        var rawSrc = extractIframeUrl(iframe);
        var parsed = parseEmbedSource(rawSrc);
        if (!parsed) {
            return null;
        }

        if (hasMarketingConsent(parsed.service)) {
            return null;
        }

        iframe.setAttribute('data-tdcc-processed', '1');

        // Immediately abort browser network request
        try {
            iframe.src = 'about:blank';
        } catch (e) {}

        var div = document.createElement('div');
        div.className = (iframe.className ? iframe.className + ' ' : '') + 'tdcc-managed';
        div.setAttribute('data-service', parsed.service);
        div.setAttribute('data-id', parsed.id);
        if (parsed.params) {
            div.setAttribute('data-params', parsed.params);
        }
        div.setAttribute('data-autoscale', '');
        div.setAttribute('data-no-lazy', '1');
        div.setAttribute('data-original-src', parsed.originalSrc);

        if (parsed.service === 'youtube') {
            div.setAttribute('data-thumbnail', 'https://i3.ytimg.com/vi/' + parsed.id + '/hqdefault.jpg');
            div.setAttribute('data-title', iframe.getAttribute('title') || 'YouTube');
        } else if (parsed.service === 'vimeo') {
            div.setAttribute('data-thumbnail', 'https://vumbnail.com/' + parsed.id + '.jpg');
            div.setAttribute('data-title', iframe.getAttribute('title') || 'Vimeo');
        } else if (parsed.service === 'google-maps') {
            div.setAttribute('data-title', iframe.getAttribute('title') || 'Google Maps');
        }

        if (iframe.getAttribute('style')) {
            div.setAttribute('style', iframe.getAttribute('style'));
        } else if (iframe.getAttribute('height')) {
            var h = iframe.getAttribute('height');
            if (h) {
                div.style.minHeight = (/^\d+$/.test(h)) ? (h + 'px') : h;
            }
        }

        if (iframe.parentNode) {
            iframe.parentNode.replaceChild(div, iframe);
        }

        return div;
    }

    /**
     * Monkey-patch native HTMLIFrameElement APIs to intercept iframe src assignments
     * before the browser initiates unconsented network requests (Elementor, WP Rocket, AJAX).
     */
    function setupPrototypeInterception() {
        if (typeof HTMLIFrameElement === 'undefined') return;

        var origSetAttribute = HTMLIFrameElement.prototype.setAttribute;
        HTMLIFrameElement.prototype.setAttribute = function (name, val) {
            if (this.getAttribute('data-tdcc-consented') === '1' || this.hasAttribute('data-tdcc-consented')) {
                return origSetAttribute.call(this, name, val);
            }
            if (this.closest && this.closest('div[data-service], .cll')) {
                return origSetAttribute.call(this, name, val);
            }
            if (name === 'src' || name === 'data-lazy-src') {
                var parsed = parseEmbedSource(val);
                if (parsed && !hasMarketingConsent(parsed.service)) {
                    this.setAttribute('data-tdcc-blocked-src', val);
                    this.setAttribute('data-tdcc-service', parsed.service);
                    this.setAttribute('data-tdcc-id', parsed.id);
                    if (parsed.params) this.setAttribute('data-tdcc-params', parsed.params);
                    var self = this;
                    setTimeout(function () {
                        if (self.getAttribute('data-tdcc-consented') === '1' || (self.closest && self.closest('div[data-service], .cll'))) {
                            return;
                        }
                        if (self.parentNode) {
                            var div = transformIframeToDiv(self);
                            if (div && window.iframemanager) {
                                initIframeManager();
                            }
                        }
                    }, 0);
                    if (name === 'src') {
                        return origSetAttribute.call(this, 'src', 'about:blank');
                    }
                    return;
                }
            }
            return origSetAttribute.call(this, name, val);
        };

        var srcDesc = Object.getOwnPropertyDescriptor(HTMLIFrameElement.prototype, 'src');
        if (srcDesc && srcDesc.set) {
            Object.defineProperty(HTMLIFrameElement.prototype, 'src', {
                set: function (val) {
                    if (this.getAttribute('data-tdcc-consented') === '1' || this.hasAttribute('data-tdcc-consented')) {
                        return srcDesc.set.call(this, val);
                    }
                    if (this.closest && this.closest('div[data-service], .cll')) {
                        return srcDesc.set.call(this, val);
                    }
                    var parsed = parseEmbedSource(val);
                    if (parsed && !hasMarketingConsent(parsed.service)) {
                        this.setAttribute('data-tdcc-blocked-src', val);
                        this.setAttribute('data-tdcc-service', parsed.service);
                        this.setAttribute('data-tdcc-id', parsed.id);
                        if (parsed.params) this.setAttribute('data-tdcc-params', parsed.params);
                        var self = this;
                        setTimeout(function () {
                            if (self.getAttribute('data-tdcc-consented') === '1' || (self.closest && self.closest('div[data-service], .cll'))) {
                                return;
                            }
                            if (self.parentNode) {
                                var div = transformIframeToDiv(self);
                                if (div && window.iframemanager) {
                                    initIframeManager();
                                }
                            }
                        }, 0);
                        return srcDesc.set.call(this, 'about:blank');
                    }
                    return srcDesc.set.call(this, val);
                },
                get: function () {
                    return this.getAttribute('data-tdcc-blocked-src') || srcDesc.get.call(this);
                },
                configurable: true
            });
        }
    }

    // Execute prototype interception immediately
    setupPrototypeInterception();

    function cloneServices(servicesObj) {
        var clone = {};
        if (servicesObj && typeof servicesObj === 'object') {
            for (var k in servicesObj) {
                if (Object.prototype.hasOwnProperty.call(servicesObj, k)) {
                    clone[k] = Array.isArray(servicesObj[k]) ? servicesObj[k].slice() : [];
                }
            }
        }
        return clone;
    }

    /**
     * Resilient waiter for CookieConsent vendor library.
     * Prevents race conditions with asynchronous or delayed script execution engines (WP Rocket, Cloudflare).
     */
    function waitForCookieConsent(callback, maxWaitMs) {
        var start = Date.now();
        var timeout = maxWaitMs || 5000;

        function check() {
            if (window.CookieConsent) {
                callback(window.CookieConsent);
            } else if (Date.now() - start < timeout) {
                setTimeout(check, 50);
            } else {
                console.error('[Tuedion Cookie] CookieConsent vendor library failed to load within ' + timeout + 'ms.');
            }
        }

        check();
    }

    /**
     * Check if a specific iframe wrapper is consented by category AND service.
     */
    function isIframeAccepted(wrapper) {
        if (!window.CookieConsent) {
            return false;
        }

        var category = wrapper.getAttribute('data-tdcc-category');
        var service = wrapper.getAttribute('data-tdcc-service');

        if (!category || !window.CookieConsent.acceptedCategory(category)) {
            return false;
        }

        // Service-level consent check
        if (service && typeof window.CookieConsent.acceptedService === 'function') {
            return window.CookieConsent.acceptedService(service, category);
        }

        return true;
    }

    /**
     * Synchronize all embedded iframes according to current consent status.
     * Uses Orest Bida iframemanager if available.
     */
    function syncIframes() {
        if (!window.CookieConsent || !window.tdccIframeManager) return;

        var isMarketingAccepted = window.CookieConsent.acceptedCategory('marketing');

        if (isMarketingAccepted) {
            window.tdccIframeManager.acceptService('all');
            return;
        }

        // Granular service-level consent check (Google Maps, YouTube, Vimeo)
        var knownServices = ['youtube', 'vimeo', 'google-maps'];
        for (var s = 0; s < knownServices.length; s++) {
            var svc = knownServices[s];
            var isSvcAccepted = false;

            if (typeof window.CookieConsent.acceptedService === 'function') {
                isSvcAccepted = window.CookieConsent.acceptedService(svc, 'marketing');
            }

            if (!isSvcAccepted) {
                var prefs = typeof window.CookieConsent.getUserPreferences === 'function' ? window.CookieConsent.getUserPreferences() : null;
                var acceptedMap = (prefs && prefs.acceptedServices && prefs.acceptedServices.marketing) || [];
                isSvcAccepted = Array.isArray(acceptedMap) && acceptedMap.indexOf(svc) !== -1;
            }

            if (isSvcAccepted) {
                window.tdccIframeManager.acceptService(svc);
            } else {
                window.tdccIframeManager.rejectService(svc);
            }
        }
    }

    // Alias for backward compatibility
    var activateConsentedIframes = syncIframes;

    /**
     * Initialize Orest Bida IframeManager for dynamic iframe interception.
     */
    function initIframeManager() {
        if (!window.iframemanager) return;

        var im = iframemanager();
        window.tdccIframeManager = im;

        var docLang = (document.documentElement && document.documentElement.lang) || 'tr';
        var currentLang = docLang.substring(0, 2).toLowerCase();
        if (['tr', 'en', 'de', 'fr', 'es', 'it', 'nl', 'ar', 'ru'].indexOf(currentLang) === -1) {
            currentLang = 'en';
        }

        // Clean up any extraneous duplicate .cll wrappers from repeated runs
        var managedDivs = document.querySelectorAll('div[data-service]');
        for (var k = 0; k < managedDivs.length; k++) {
            var existingClls = managedDivs[k].querySelectorAll('.cll');
            if (existingClls.length > 1) {
                for (var c = 1; c < existingClls.length; c++) {
                    existingClls[c].remove();
                }
            }
        }

        im.run({
            currLang: currentLang,
            onChange: function (data) {
                if (data.eventSource && data.eventSource.type === 'click') {
                    if (window.CookieConsent) {
                        var curMarketing = (window.CookieConsent.getUserPreferences && window.CookieConsent.getUserPreferences().acceptedServices && window.CookieConsent.getUserPreferences().acceptedServices.marketing) || [];
                        var updated = curMarketing.slice();
                        for (var i = 0; i < data.changedServices.length; i++) {
                            if (updated.indexOf(data.changedServices[i]) === -1) {
                                updated.push(data.changedServices[i]);
                            }
                        }
                        window.CookieConsent.acceptService(updated, 'marketing');
                    }
                }
            },
            services: {
                youtube: {
                    embedUrl: 'https://www.youtube.com/embed/{data-id}',
                    thumbnailUrl: 'https://i3.ytimg.com/vi/{data-id}/hqdefault.jpg',
                    iframe: {
                        allow: 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share; fullscreen;',
                        'data-tdcc-consented': '1',
                    },
                    languages: {
                        tr: {
                            notice: 'Bu videoyu izlemek için lütfen çerezleri kabul edin. Bu içerik YouTube tarafından sağlanmaktadır.',
                            loadBtn: 'Çerezleri Kabul Et ve İzle',
                            loadAllBtn: 'Her Zaman İzin Ver'
                        },
                        en: {
                            notice: 'To watch this video, please accept cookies. This content is hosted by YouTube.',
                            loadBtn: 'Accept Cookies & Watch',
                            loadAllBtn: 'Always Allow'
                        },
                        de: {
                            notice: 'Um dieses Video anzusehen, akzeptieren Sie bitte die Cookies. Dieser Inhalt wird von YouTube bereitgestellt.',
                            loadBtn: 'Cookies akzeptieren und ansehen',
                            loadAllBtn: 'Immer erlauben'
                        },
                        fr: {
                            notice: 'Pour regarder cette vidéo, veuillez accepter les cookies. Ce contenu est hébergé par YouTube.',
                            loadBtn: 'Accepter les cookies et regarder',
                            loadAllBtn: 'Toujours autoriser'
                        },
                        es: {
                            notice: 'Para ver este vídeo, por favor acepte las cookies. Este contenido está alojado en YouTube.',
                            loadBtn: 'Aceptar cookies y ver',
                            loadAllBtn: 'Permitir siempre'
                        },
                        it: {
                            notice: 'Per guardare questo video, accetta i cookie. Questo contenuto è ospitato da YouTube.',
                            loadBtn: 'Accetta i cookie e guarda',
                            loadAllBtn: 'Consenti sempre'
                        },
                        nl: {
                            notice: 'Om deze video te bekijken, dient u cookies te accepteren. Deze inhoud wordt gehost door YouTube.',
                            loadBtn: 'Cookies accepteren en bekijken',
                            loadAllBtn: 'Altijd toestaan'
                        },
                        ar: {
                            notice: 'لمشاهدة هذا الفيديو، يرجى قبول ملفات تعريف الارتباط. هذا المحتوى مستضاف بواسطة YouTube.',
                            loadBtn: 'قبول ملفات تعريف الارتباط والمشاهدة',
                            loadAllBtn: 'السماح دائمًا'
                        },
                        ru: {
                            notice: 'Чтобы посмотреть это видео, пожалуйста, примите файлы cookie. Этот контент предоставлен YouTube.',
                            loadBtn: 'Принять файлы cookie и смотреть',
                            loadAllBtn: 'Всегда разрешать'
                        }
                    }
                },
                vimeo: {
                    embedUrl: 'https://player.vimeo.com/video/{data-id}',
                    thumbnailUrl: 'https://vumbnail.com/{data-id}.jpg',
                    iframe: {
                        allow: 'autoplay; fullscreen; picture-in-picture;',
                        'data-tdcc-consented': '1',
                    },
                    languages: {
                        tr: {
                            notice: 'Bu videoyu izlemek için lütfen çerezleri kabul edin. Bu içerik Vimeo tarafından sağlanmaktadır.',
                            loadBtn: 'Çerezleri Kabul Et ve İzle',
                            loadAllBtn: 'Her Zaman İzin Ver'
                        },
                        en: {
                            notice: 'To watch this video, please accept cookies. This content is hosted by Vimeo.',
                            loadBtn: 'Accept Cookies & Watch',
                            loadAllBtn: 'Always Allow'
                        },
                        de: {
                            notice: 'Um dieses Video anzusehen, akzeptieren Sie bitte die Cookies. Dieser Inhalt wird von Vimeo bereitgestellt.',
                            loadBtn: 'Cookies akzeptieren und ansehen',
                            loadAllBtn: 'Immer erlauben'
                        },
                        fr: {
                            notice: 'Pour regarder cette vidéo, veuillez accepter les cookies. Ce contenu est hébergé par Vimeo.',
                            loadBtn: 'Accepter les cookies et regarder',
                            loadAllBtn: 'Toujours autoriser'
                        },
                        es: {
                            notice: 'Para ver este vídeo, por favor acepte las cookies. Este contenido está alojado en Vimeo.',
                            loadBtn: 'Aceptar cookies y ver',
                            loadAllBtn: 'Permitir siempre'
                        },
                        it: {
                            notice: 'Per guardare questo video, accetta i cookie. Questo contenuto è ospitato da Vimeo.',
                            loadBtn: 'Accetta i cookie e guarda',
                            loadAllBtn: 'Consenti sempre'
                        },
                        nl: {
                            notice: 'Om deze video te bekijken, dient u cookies te accepteren. Deze inhoud wordt gehost door Vimeo.',
                            loadBtn: 'Cookies accepteren en bekijken',
                            loadAllBtn: 'Altijd toestaan'
                        },
                        ar: {
                            notice: 'لمشاهدة هذا الفيديو، يرجى قبول ملفات تعريف الارتباط. هذا المحتوى مستضاف بواسطة Vimeo.',
                            loadBtn: 'قبول ملفات تعريف الارتباط والمشاهدة',
                            loadAllBtn: 'السماح دائمًا'
                        },
                        ru: {
                            notice: 'Чтобы посмотреть это видео, пожалуйста, примите файлы cookie. Этот контент предоставлен Vimeo.',
                            loadBtn: 'Принять файлы cookie и смотреть',
                            loadAllBtn: 'Всегда разрешать'
                        }
                    }
                },
                'google-maps': {
                    embedUrl: '{data-id}',
                    iframe: {
                        allow: 'fullscreen;',
                        'data-tdcc-consented': '1',
                    },
                    languages: {
                        tr: {
                            notice: 'Haritayı görüntülemek için lütfen çerezleri kabul edin. Bu içerik Google Haritalar tarafından sağlanmaktadır.',
                            loadBtn: 'Haritayı Yükle',
                            loadAllBtn: 'Her Zaman İzin Ver'
                        },
                        en: {
                            notice: 'To view this map, please accept cookies. This map is hosted by Google Maps.',
                            loadBtn: 'Load Map',
                            loadAllBtn: 'Always Allow'
                        },
                        de: {
                            notice: 'Um diese Karte anzusehen, akzeptieren Sie bitte die Cookies. Diese Karte wird von Google Maps bereitgestellt.',
                            loadBtn: 'Karte laden',
                            loadAllBtn: 'Immer erlauben'
                        },
                        fr: {
                            notice: 'Pour afficher cette carte, veuillez accepter les cookies. Cette carte est hébergée par Google Maps.',
                            loadBtn: 'Charger la carte',
                            loadAllBtn: 'Toujours autoriser'
                        },
                        es: {
                            notice: 'Para ver este mapa, por favor acepte las cookies. Este mapa está alojado en Google Maps.',
                            loadBtn: 'Cargar mapa',
                            loadAllBtn: 'Permitir siempre'
                        },
                        it: {
                            notice: 'Per visualizzare questa mappa, accetta i cookie. Questa mappa è ospitata da Google Maps.',
                            loadBtn: 'Carica mappa',
                            loadAllBtn: 'Consenti sempre'
                        },
                        nl: {
                            notice: 'Om deze kaart te bekijken, dient u cookies te accepteren. Deze kaart wordt gehost door Google Maps.',
                            loadBtn: 'Kaart laden',
                            loadAllBtn: 'Altijd toestaan'
                        },
                        ar: {
                            notice: 'لعرض هذه الخريطة، يرجى قبول ملفات تعريف الارتباط. هذه الخريطة مستضافة بواسطة Google Maps.',
                            loadBtn: 'تحميل الخريطة',
                            loadAllBtn: 'السماح دائمًا'
                        },
                        ru: {
                            notice: 'Чтобы просмотреть эту карту, пожалуйста, примите файлы cookie. Эта карта предоставлена Google Maps.',
                            loadBtn: 'Загрузить карту',
                            loadAllBtn: 'Всегда разрешать'
                        }
                    }
                }
            }
        });

        // If marketing or individual services are already accepted in current session, synchronize immediately
        if (hasMarketingConsent()) {
            im.acceptService('all');
        } else {
            var knownServices = ['youtube', 'vimeo', 'google-maps'];
            for (var ks = 0; ks < knownServices.length; ks++) {
                if (hasMarketingConsent(knownServices[ks])) {
                    im.acceptService(knownServices[ks]);
                }
            }
        }
    }

    /**
     * Attach delegated click listeners for inline iframe unlock and preferences buttons.
     */
    function setupIframeDelegation() {
        document.addEventListener('click', function (e) {
            // Iframemanager load buttons (.c-l-b and .c-la-b)
            var imLoadBtn = e.target.closest('.c-l-b, .c-la-b');
            if (imLoadBtn && window.CookieConsent) {
                var parentDiv = imLoadBtn.closest('div[data-service]');
                var serviceName = parentDiv ? parentDiv.getAttribute('data-service') : 'youtube';
                if (typeof window.CookieConsent.acceptService === 'function') {
                    var accepted = window.CookieConsent.acceptService(serviceName, 'marketing');
                    if (!accepted && typeof window.CookieConsent.acceptCategory === 'function') {
                        window.CookieConsent.acceptCategory('marketing');
                    }
                } else if (typeof window.CookieConsent.acceptCategory === 'function') {
                    window.CookieConsent.acceptCategory('marketing');
                }

                // Resilient fallback: ensure iframe container becomes visible even if onload is delayed
                if (parentDiv) {
                    setTimeout(function () {
                        if (!parentDiv.classList.contains('c-h-b')) {
                            var ifr = parentDiv.querySelector('iframe');
                            if (ifr && ifr.src && ifr.src !== 'about:blank') {
                                parentDiv.classList.add('c-h-b');
                            }
                        }
                    }, 1200);
                }
            }

            // Unlock button inside placeholder
            var unlockBtn = e.target.closest('[data-tdcc-unlock-category]');
            if (unlockBtn) {
                e.preventDefault();
                var cat = unlockBtn.getAttribute('data-tdcc-unlock-category');
                var svc = unlockBtn.getAttribute('data-tdcc-service');
                if (window.CookieConsent) {
                    if (svc && typeof window.CookieConsent.acceptService === 'function') {
                        var okSvc = window.CookieConsent.acceptService(svc, cat);
                        if (!okSvc && cat) {
                            window.CookieConsent.acceptCategory(cat);
                        }
                    } else if (cat) {
                        window.CookieConsent.acceptCategory(cat);
                    }
                    syncIframes();
                }
                return;
            }

            // Manage preferences link inside placeholder
            var prefBtn = e.target.closest('[data-tdcc-open-preferences]');
            if (prefBtn) {
                e.preventDefault();
                if (window.CookieConsent) {
                    window.CookieConsent.showPreferences();
                }
            }
        });
    }

    /**
     * Transmit anonymized consent audit log to server if logging is enabled.
     */
    function logConsent(actionType, detail) {
        if (!config.loggingEnabled || !config.ajaxUrl || !config.logNonce) {
            return;
        }

        var cookie = (detail && detail.cookie) || {};
        var categories = cookie.categories || [];
        var servicesObj = cookie.services || {};

        // Derive aligned action type
        var prefs = (window.CookieConsent && typeof window.CookieConsent.getUserPreferences === 'function') ? window.CookieConsent.getUserPreferences() : null;
        var acceptType = prefs ? prefs.acceptType : null;
        var computedAction = actionType;
        if (actionType === 'first_consent' || actionType === 'consent') {
            if (acceptType === 'all') computedAction = 'accept_all';
            else if (acceptType === 'necessary') computedAction = 'accept_necessary';
            else if (acceptType === 'custom') computedAction = 'custom';
        } else if (actionType === 'change') {
            if (acceptType === 'all') computedAction = 'accept_all';
            else if (acceptType === 'necessary') computedAction = 'accept_necessary';
            else computedAction = 'change';
        }

        var formData = new FormData();
        formData.append('action', 'tdcc_log_consent');
        formData.append('nonce', config.logNonce);
        formData.append('action_type', computedAction);
        formData.append('consent_uuid', cookie.consentId || '');

        for (var i = 0; i < categories.length; i++) {
            formData.append('categories[]', categories[i]);
        }

        // Send all selected services
        if (Array.isArray(servicesObj)) {
            for (var s = 0; s < servicesObj.length; s++) {
                formData.append('services[]', servicesObj[s]);
            }
        } else if (servicesObj && typeof servicesObj === 'object') {
            for (var cat in servicesObj) {
                if (Object.prototype.hasOwnProperty.call(servicesObj, cat) && Array.isArray(servicesObj[cat])) {
                    for (var j = 0; j < servicesObj[cat].length; j++) {
                        formData.append('services[]', servicesObj[cat][j]);
                    }
                }
            }
        }

        function sendLog(nonceToUse, isRetry) {
            if (nonceToUse) {
                formData.set('nonce', nonceToUse);
            }
            if (typeof fetch === 'function') {
                fetch(config.ajaxUrl, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                }).then(function (res) {
                    // Check for 403 (expired nonce from static cache) and retry once with fresh nonce
                    if (res.status === 403 && !isRetry) {
                        fetch(config.ajaxUrl + '?action=tdcc_refresh_nonce', {
                            method: 'GET',
                            credentials: 'same-origin'
                        }).then(function (nRes) { return nRes.json(); })
                        .then(function (nData) {
                            if (nData && nData.success && nData.data && nData.data.nonce) {
                                config.logNonce = nData.data.nonce;
                                sendLog(nData.data.nonce, true);
                            }
                        }).catch(function () {});
                    }
                }).catch(function () {});
            }
        }

        sendLog(config.logNonce, false);
    }

    // Preserve and wire upstream callbacks to Tuedion Event Bridge
    config.onFirstConsent = function (detail) {
        lastAcceptedCategories = (detail.cookie && detail.cookie.categories) || [];
        lastAcceptedServices = cloneServices(detail.cookie && detail.cookie.services);

        if (window.TuedionCookieEventBridge) {
            window.TuedionCookieEventBridge.dispatch('tuedion:first_consent', detail);
            window.TuedionCookieEventBridge.dispatch('tuedion:consent', detail);
        }

        logConsent('first_consent', detail);
        syncIframes();
    };

    config.onConsent = function (detail) {
        lastAcceptedCategories = (detail.cookie && detail.cookie.categories) || [];
        lastAcceptedServices = cloneServices(detail.cookie && detail.cookie.services);

        if (window.TuedionCookieEventBridge) {
            window.TuedionCookieEventBridge.dispatch('tuedion:consent', detail);
        }

        syncIframes();
    };

    config.onChange = function (detail) {
        var currentCategories = (detail.cookie && detail.cookie.categories) || [];
        var currentServices = (detail.cookie && detail.cookie.services) || {};

        var categoryRevoked = false;
        for (var i = 0; i < lastAcceptedCategories.length; i++) {
            var prevCat = lastAcceptedCategories[i];
            if (currentCategories.indexOf(prevCat) === -1) {
                categoryRevoked = true;
                break;
            }
        }

        // Detect if any previously accepted service was revoked
        var serviceRevoked = false;
        if (lastAcceptedServices && typeof lastAcceptedServices === 'object') {
            for (var c in lastAcceptedServices) {
                if (Object.prototype.hasOwnProperty.call(lastAcceptedServices, c) && Array.isArray(lastAcceptedServices[c])) {
                    var currentCatServices = (currentServices && Array.isArray(currentServices[c])) ? currentServices[c] : [];
                    for (var s = 0; s < lastAcceptedServices[c].length; s++) {
                        var prevSvc = lastAcceptedServices[c][s];
                        if (currentCatServices.indexOf(prevSvc) === -1) {
                            serviceRevoked = true;
                            break;
                        }
                    }
                }
                if (serviceRevoked) break;
            }
        }

        var hasRevocation = categoryRevoked || serviceRevoked;

        lastAcceptedCategories = currentCategories;
        lastAcceptedServices = cloneServices(currentServices);

        if (window.TuedionCookieEventBridge) {
            window.TuedionCookieEventBridge.dispatch('tuedion:change', detail);
        }

        logConsent('change', detail);

        if (hasRevocation && config.reloadOnRevoke) {
            setTimeout(function () {
                window.location.reload();
            }, 200);
            return;
        }

        syncIframes();
    };

    // Public namespace reference
    window.TuedionCookie = {
        api: window.CookieConsent,
        config: config,
        unlockCategory: function (category) {
            if (window.CookieConsent) {
                window.CookieConsent.acceptCategory(category);
                syncIframes();
            }
        },
        activateIframes: syncIframes,
        syncIframes: syncIframes,
        showPreferences: function () {
            if (window.CookieConsent) {
                window.CookieConsent.showPreferences();
            }
        }
    };

    function prepareConfig(cfg) {
        if (!cfg || !cfg.categories) {
            return cfg;
        }

        // Convert string-serialized regexes (e.g. "/^_ga/") into real RegExp objects for CookieConsent autoClear
        for (var catKey in cfg.categories) {
            if (Object.prototype.hasOwnProperty.call(cfg.categories, catKey)) {
                var cat = cfg.categories[catKey];
                if (cat && cat.autoClear && Array.isArray(cat.autoClear.cookies)) {
                    for (var i = 0; i < cat.autoClear.cookies.length; i++) {
                        var cookieItem = cat.autoClear.cookies[i];
                        if (cookieItem && typeof cookieItem.name === 'string') {
                            var nameStr = cookieItem.name.trim();
                            if (nameStr.charAt(0) === '/' && nameStr.charAt(nameStr.length - 1) === '/' && nameStr.length > 2) {
                                try {
                                    cookieItem.name = new RegExp(nameStr.slice(1, -1));
                                } catch (e) {
                                    // Keep as string fallback
                                }
                            }
                        }
                    }
                }
            }
        }

        // Ensure marketing category defines standard embed services so CookieConsent.acceptService() succeeds
        if (cfg.categories && cfg.categories.marketing) {
            if (!cfg.categories.marketing.services || typeof cfg.categories.marketing.services !== 'object') {
                cfg.categories.marketing.services = {};
            }
            var defaultServices = {
                'google-maps': { label: 'Google Maps' },
                'youtube': { label: 'YouTube' },
                'vimeo': { label: 'Vimeo' }
            };
            for (var dKey in defaultServices) {
                if (!cfg.categories.marketing.services[dKey]) {
                    cfg.categories.marketing.services[dKey] = defaultServices[dKey];
                }
            }
        }

        return cfg;
    }

    function initCookieConsent() {
        if (!document.body) {
            return;
        }

        waitForCookieConsent(function (cc) {
            try {
                var processedConfig = prepareConfig(config);
                if (config.debug) {
                    console.log('[Tuedion Cookie Telemetry] Invoking CookieConsent.run() with config:', processedConfig);
                }
                var runResult = cc.run(processedConfig);
                if (runResult && typeof runResult.catch === 'function') {
                    runResult.catch(function (err) {
                        console.error('[Tuedion Cookie] CookieConsent run failed:', err);
                    });
                }
                activateConsentedIframes();
            } catch (err) {
                console.error('[Tuedion Cookie] Exception during CookieConsent initialization:', err);
            }
        }, 5000);
    }

    /**
     * Scan the DOM synchronously for any unmanaged iframes (from static page cache or late inline HTML)
     * and convert them into iframemanager compliant DIVs before network requests continue.
     */
    function scanAndTransformExistingIframes() {
        if (hasMarketingConsent()) return;

        var iframes = document.querySelectorAll('iframe:not([data-tdcc-processed])');
        var transformedCount = 0;
        for (var i = 0; i < iframes.length; i++) {
            if (transformIframeToDiv(iframes[i])) {
                transformedCount++;
            }
        }

        if (transformedCount > 0 && window.iframemanager) {
            initIframeManager();
        }
    }

    /**
     * Intercept dynamically injected iframes (Elementor/WP Rocket/AJAX)
     */
    function setupDynamicIframeInterceptor() {
        scanAndTransformExistingIframes();

        if (!window.MutationObserver) return;

        var observer = new MutationObserver(function (mutations) {
            if (hasMarketingConsent()) return;

            var needsReinit = false;
            for (var m = 0; m < mutations.length; m++) {
                var mutation = mutations[m];

                // Handle attribute mutations (e.g. WP Rocket swapping data-lazy-src or src)
                if (mutation.type === 'attributes' && mutation.target && mutation.target.tagName === 'IFRAME') {
                    if (mutation.target.getAttribute('data-tdcc-consented') === '1' || (mutation.target.closest && mutation.target.closest('div[data-service], .cll'))) {
                        continue;
                    }
                    if (transformIframeToDiv(mutation.target)) {
                        needsReinit = true;
                    }
                    continue;
                }

                // Handle newly added nodes
                for (var n = 0; n < mutation.addedNodes.length; n++) {
                    var node = mutation.addedNodes[n];
                    if (node.nodeType !== 1) continue;

                    if (node.tagName === 'IFRAME') {
                        if (node.getAttribute('data-tdcc-consented') === '1' || (node.closest && node.closest('div[data-service], .cll'))) {
                            continue;
                        }
                        if (transformIframeToDiv(node)) {
                            needsReinit = true;
                        }
                    } else if (node.querySelectorAll) {
                        var nested = node.querySelectorAll('iframe:not([data-tdcc-processed]):not([data-tdcc-consented])');
                        for (var j = 0; j < nested.length; j++) {
                            if (nested[j].getAttribute('data-tdcc-consented') === '1' || (nested[j].closest && nested[j].closest('div[data-service], .cll'))) {
                                continue;
                            }
                            if (transformIframeToDiv(nested[j])) {
                                needsReinit = true;
                            }
                        }
                    }
                }
            }

            if (needsReinit && window.iframemanager) {
                initIframeManager();
            }
        });

        var targetRoot = document.documentElement || document.body;
        if (targetRoot) {
            observer.observe(targetRoot, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['src', 'data-src', 'data-lazy-src']
            });
        }
    }

    // Start observer and immediate scan as early as possible
    setupDynamicIframeInterceptor();

    function boot() {
        scanAndTransformExistingIframes();
        initIframeManager();
        setupIframeDelegation();

        if (document.body) {
            initCookieConsent();
        } else {
            document.addEventListener('DOMContentLoaded', initCookieConsent);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
        window.addEventListener('load', scanAndTransformExistingIframes);
    } else {
        boot();
    }
})();

