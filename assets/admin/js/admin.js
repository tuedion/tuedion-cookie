/**
 * Tuedion Cookie — Admin Interactive Engine
 * Handles Live Preview sandbox, viewport switches, tab navigation, and wizard flow.
 */

(function ($) {
    'use strict';

    var currentView = 'banner'; // 'banner', 'preferences', 'trigger'
    var currentLang = 'en';     // 'en', 'tr'
    var currentDevice = 'desktop';

    var isProgrammaticChange = false;

    $(document).ready(function () {
        initTabs();
        initThemePresets();
        initLivePreview();
        initWizard();
        initSubmitActions();
        initScannerCatalogue();
        initReactiveServiceCatalogue();
        initShortcodeCopy();
    });

    /**
     * Tab navigation handler with state persistence
     */
    function initTabs() {
        $('.tdcc-tabs .tdcc-tab').on('click', function () {
            var target = $(this).data('target');
            if (!target) return;

            $('.tdcc-tabs .tdcc-tab').removeClass('is-active');
            $(this).addClass('is-active');

            $('.tdcc-tab-pane').removeClass('is-active');
            $('#' + target).addClass('is-active');

            $('#tdcc-active-tab').val(target);
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', '#' + target);
            }
        });

        // Activate tab from URL hash if present
        if (window.location.hash) {
            var hashTab = window.location.hash.replace('#', '');
            var $btn = $('.tdcc-tabs .tdcc-tab[data-target="' + hashTab + '"]');
            if ($btn.length) {
                $btn.trigger('click');
            }
        }
    }

    /**
     * Built-in Preset Definitions (Resilient fallback)
     */
    var PRESET_DEFAULTS = {
        light: {
            name: 'Clean Light',
            colors: {
                bg_color: '#ffffff',
                text_color: '#0f172a',
                subtext_color: '#475569',
                border_color: '#e2e8f0',
                card_bg: '#f8fafc',
                btn_primary_bg: '#2563eb',
                btn_primary_color: '#ffffff',
                btn_secondary_bg: '#f1f5f9',
                btn_secondary_color: '#334155',
                toggle_on_bg: '#2563eb'
            }
        },
        dark: {
            name: 'Midnight Dark',
            colors: {
                bg_color: '#0f172a',
                text_color: '#f8fafc',
                subtext_color: '#94a3b8',
                border_color: '#334155',
                card_bg: '#1e293b',
                btn_primary_bg: '#3b82f6',
                btn_primary_color: '#ffffff',
                btn_secondary_bg: '#1e293b',
                btn_secondary_color: '#e2e8f0',
                toggle_on_bg: '#3b82f6'
            }
        },
        corporate: {
            name: 'Corporate Navy',
            colors: {
                bg_color: '#ffffff',
                text_color: '#0b192c',
                subtext_color: '#475569',
                border_color: '#dbeafe',
                card_bg: '#f0f7ff',
                btn_primary_bg: '#1e3a8a',
                btn_primary_color: '#ffffff',
                btn_secondary_bg: '#eff6ff',
                btn_secondary_color: '#1e3a8a',
                toggle_on_bg: '#1e3a8a'
            }
        },
        emerald: {
            name: 'Emerald Nature',
            colors: {
                bg_color: '#ffffff',
                text_color: '#064e3b',
                subtext_color: '#374151',
                border_color: '#d1fae5',
                card_bg: '#f0fdf4',
                btn_primary_bg: '#059669',
                btn_primary_color: '#ffffff',
                btn_secondary_bg: '#ecfdf5',
                btn_secondary_color: '#065f46',
                toggle_on_bg: '#059669'
            }
        },
        slate: {
            name: 'Minimal Slate',
            colors: {
                bg_color: '#18181b',
                text_color: '#fafafa',
                subtext_color: '#a1a1aa',
                border_color: '#3f3f46',
                card_bg: '#27272a',
                btn_primary_bg: '#fafafa',
                btn_primary_color: '#18181b',
                btn_secondary_bg: '#27272a',
                btn_secondary_color: '#e4e4e7',
                toggle_on_bg: '#38bdf8'
            }
        }
    };

    /**
     * Apply a Theme Preset across form fields and preview live
     */
    function applyPreset(presetKey, origin) {
        if (!presetKey) {
            return;
        }

        // Sync both preset selectors (Form & Preview column)
        if (origin !== 'form') {
            $('#theme_preset').val(presetKey);
        }
        if (origin !== 'preview') {
            $('#tdcc-preview-preset-select').val(presetKey);
        }

        if (presetKey === 'custom') {
            renderSandbox();
            return;
        }

        var presets = (window.tuedionCookieAdminData && window.tuedionCookieAdminData.themePresets)
            ? window.tuedionCookieAdminData.themePresets
            : PRESET_DEFAULTS;

        var presetData = presets[presetKey] || PRESET_DEFAULTS[presetKey];
        if (!presetData || !presetData.colors) {
            return;
        }

        var colors = presetData.colors;

        isProgrammaticChange = true;
        try {
            Object.keys(colors).forEach(function (token) {
                var hexVal = colors[token];
                var hexLower = (typeof hexVal === 'string') ? hexVal.toLowerCase() : hexVal;
                var $colorInput = $('#' + token);
                var $hexInput = $('#' + token + '_hex');

                if ($colorInput.length) {
                    $colorInput.val(hexLower);
                    if ($colorInput[0]) {
                        $colorInput[0].value = hexLower;
                    }
                }
                if ($hexInput.length) {
                    $hexInput.val(hexLower);
                    if ($hexInput[0]) {
                        $hexInput[0].value = hexLower;
                    }
                }

                // Add visual pulse effect on color row in Base Surfaces & Typography
                var $group = $colorInput.closest('.tdcc-color-group');
                if ($group.length) {
                    $group.addClass('tdcc-color-updated');
                    setTimeout(function () {
                        $group.removeClass('tdcc-color-updated');
                    }, 600);
                }
            });
        } finally {
            isProgrammaticChange = false;
        }

        // Live re-render without saving
        renderSandbox();
    }

    /**
     * Theme Preset & Color Pickers Event Listeners
     */
    function initThemePresets() {
        // Change listener for Form Preset selector (Design Presets & Complete Color Customization)
        $(document).on('change', '#theme_preset', function () {
            applyPreset($(this).val(), 'form');
        });

        // Change listener for Preview Toolbar Preset selector (in tdcc-preview-column)
        $(document).on('change', '#tdcc-preview-preset-select', function () {
            applyPreset($(this).val(), 'preview');
        });

        // Two-Way Color Picker <-> Hex Text Input Sync
        $(document).on('input change', '.tdcc-color-input, .tdcc-color-picker', function () {
            if (isProgrammaticChange) return;
            var val = $(this).val();
            var hexTarget = $(this).data('hex');
            if (hexTarget && $(hexTarget).length) {
                $(hexTarget).val(val);
                if ($(hexTarget)[0]) {
                    $(hexTarget)[0].value = val;
                }
            }
            $('#theme_preset').val('custom');
            $('#tdcc-preview-preset-select').val('custom');
            renderSandbox();
        });

        $(document).on('input change keyup paste blur', '.tdcc-hex-input', function () {
            if (isProgrammaticChange) return;
            var val = $(this).val().trim();
            if (!val) return;
            if (val.charAt(0) !== '#') {
                val = '#' + val;
            }
            var colorTarget = $(this).data('color');
            if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
                var hexLower = val.toLowerCase();
                if (colorTarget && $(colorTarget).length) {
                    $(colorTarget).val(hexLower);
                    if ($(colorTarget)[0]) {
                        $(colorTarget)[0].value = hexLower;
                    }
                }
                $('#theme_preset').val('custom');
                $('#tdcc-preview-preset-select').val('custom');
                renderSandbox();
            } else if (/^#[0-9A-Fa-f]{3}$/.test(val)) {
                var r = val[1], g = val[2], b = val[3];
                var fullHex = ('#' + r + r + g + g + b + b).toLowerCase();
                if (colorTarget && $(colorTarget).length) {
                    $(colorTarget).val(fullHex);
                    if ($(colorTarget)[0]) {
                        $(colorTarget)[0].value = fullHex;
                    }
                }
                $('#theme_preset').val('custom');
                $('#tdcc-preview-preset-select').val('custom');
                renderSandbox();
            }
        });
    }

    /**
     * Interactive Live Preview Sandbox
     */
    function initLivePreview() {
        var $wrap = $('.tdcc-admin-wrap');
        var $stage = $('#tdcc-sandbox-modal-container');

        if (!$stage.length) {
            return;
        }

        // 1. Device Viewport Switcher
        $('.tdcc-preview-toolbar [data-device]').on('click', function () {
            currentDevice = $(this).data('device');
            $('.tdcc-preview-toolbar [data-device]').removeClass('is-active');
            $(this).addClass('is-active');

            $wrap.attr('data-device', currentDevice);
        });

        // 2. Simulation State Switcher (Banner / Preferences / Trigger)
        $('.tdcc-preview-toolbar [data-view]').on('click', function () {
            currentView = $(this).data('view');
            $('.tdcc-preview-toolbar [data-view]').removeClass('is-active');
            $(this).addClass('is-active');

            renderSandbox();
        });

        // 3. Language Switcher (EN / TR)
        $('.tdcc-preview-toolbar [data-lang]').on('click', function () {
            currentLang = $(this).data('lang');
            $('.tdcc-preview-toolbar [data-lang]').removeClass('is-active');
            $(this).addClass('is-active');

            renderSandbox();
        });

        // 6. Form inputs listener with debouncing
        var debounceTimer = null;
        $('.tdcc-live-field').on('input change', function () {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () {
                renderSandbox();
            }, 100);
        });

        // Initial Render
        renderSandbox();
    }

    /**
     * Render the isolated mockup inside the sandbox container
     */
    function renderSandbox() {
        var $container = $('#tdcc-sandbox-modal-container');
        if (!$container.length) {
            return;
        }

        $container.empty();

        // Color tokens
        var bgColor = $('#bg_color').val() || '#ffffff';
        var textColor = $('#text_color').val() || '#1e293b';
        var subtextColor = $('#subtext_color').val() || '#64748b';
        var borderColor = $('#border_color').val() || '#e2e8f0';
        var cardBg = $('#card_bg').val() || '#f8fafc';
        var btnPrimaryBg = $('#btn_primary_bg').val() || '#1e293b';
        var btnPrimaryColor = $('#btn_primary_color').val() || '#ffffff';
        var btnSecondaryBg = $('#btn_secondary_bg').val() || '#f1f5f9';
        var btnSecondaryColor = $('#btn_secondary_color').val() || '#334155';
        var toggleOnBg = $('#toggle_on_bg').val() || '#2563eb';

        var cssVariables = {
            '--sb-bg': bgColor,
            '--sb-text': textColor,
            '--sb-subtext': subtextColor,
            '--sb-border': borderColor,
            '--sb-card-bg': cardBg,
            '--sb-btn-primary-bg': btnPrimaryBg,
            '--sb-btn-primary-color': btnPrimaryColor,
            '--sb-btn-secondary-bg': btnSecondaryBg,
            '--sb-btn-secondary-color': btnSecondaryColor,
            '--sb-toggle-on-bg': toggleOnBg
        };

        // Standard DOM style.setProperty ensures CSS variables update reliably across all browsers
        var targets = [
            $container[0],
            document.getElementById('tdcc-preview-stage'),
            document.getElementById('tdcc-preview-viewport')
        ];

        targets.forEach(function (el) {
            if (el && el.style && el.style.setProperty) {
                Object.keys(cssVariables).forEach(function (prop) {
                    el.style.setProperty(prop, cssVariables[prop]);
                });
            }
        });

        // Form values
        var layout = $('#banner_layout').val() || 'box';
        var position = $('#banner_position').val() || 'bottom-right';
        var equalWeight = $('#equal_weight_buttons').is(':checked');
        var showReject = $('#show_reject_button').is(':checked');
        var showManage = $('#show_manage_button').is(':checked');

        var prefLayout = $('#pref_layout').val() || 'box';
        var prefPosition = $('#pref_position').val() || 'right';

        var triggerEnabled = $('#trigger_enabled').is(':checked');
        var triggerMode = $('#trigger_mode').val() || 'both';
        var triggerPos = $('#trigger_position').val() || 'bottom-left';
        var triggerOffsetX = parseInt($('#trigger_offset_x').val(), 10) || 20;
        var triggerOffsetY = parseInt($('#trigger_offset_y').val(), 10) || 20;

        // Texts dictionary
        var dict = {
            en: {
                bannerTitle: 'We value your privacy',
                bannerDesc: 'This website uses cookies to enhance browsing, personalize content, and analyze our traffic.',
                acceptAll: 'Accept All',
                reject: 'Reject Non-Essential',
                manage: 'Manage Preferences',
                prefTitle: 'Privacy Preference Center',
                prefDesc: 'Manage your consent preferences for each cookie category.',
                prefSave: 'Save Preferences',
                triggerText: 'Cookie Preferences',
                catNecessary: 'Strictly Necessary',
                catAnalytics: 'Analytics & Performance',
                catMarketing: 'Marketing'
            },
            tr: {
                bannerTitle: 'Gizliliğinize Değer Veriyoruz',
                bannerDesc: 'Web sitemizde gezinme deneyiminizi geliştirmek, kişiselleştirilmiş içerik ve analiz için çerezler kullanılır.',
                acceptAll: 'Tümünü Kabul Et',
                reject: 'Yalnızca Zorunlular',
                manage: 'Tercihleri Yönet',
                prefTitle: 'Gizlilik Tercih Merkezi',
                prefDesc: 'Her çerez kategorisi için onay tercihlerinizi belirleyin.',
                prefSave: 'Tercihleri Kaydet',
                triggerText: 'Çerez Tercihleri',
                catNecessary: 'Zorunlu Çerezler',
                catAnalytics: 'Analitik ve Performans',
                catMarketing: 'Pazarlama'
            }
        };

        var t = dict[currentLang] || dict.en;

        if (currentView === 'banner') {
            // Render Mock Banner
            var posClass = 'pos-' + position;
            var layoutClass = 'layout-' + layout.replace(/\s+/g, '-');
            var equalClass = equalWeight ? 'tdcc-equal-weight' : '';

            var $banner = $('<div class="tdcc-mock-banner-box ' + posClass + ' ' + layoutClass + ' ' + equalClass + '"></div>');
            $banner.css({
                'background-color': bgColor,
                'border-color': borderColor,
                'color': textColor
            });

            var $title = $('<h3 class="tdcc-mock-title">' + $('<div>').text(t.bannerTitle).html() + '</h3>').css('color', textColor);
            var $desc = $('<p class="tdcc-mock-desc">' + $('<div>').text(t.bannerDesc).html() + '</p>').css('color', subtextColor);
            $banner.append($title).append($desc);

            var $btns = $('<div class="tdcc-mock-btns"></div>');
            var $btnPrimary = $('<button type="button" class="tdcc-mock-btn tdcc-mock-btn-primary">' + $('<div>').text(t.acceptAll).html() + '</button>').css({
                'background-color': btnPrimaryBg,
                'border-color': btnPrimaryBg,
                'color': btnPrimaryColor
            });
            $btns.append($btnPrimary);

            if (showReject) {
                var $btnReject = $('<button type="button" class="tdcc-mock-btn">' + $('<div>').text(t.reject).html() + '</button>').css({
                    'background-color': btnSecondaryBg,
                    'border-color': borderColor,
                    'color': btnSecondaryColor
                });
                $btns.append($btnReject);
            }
            if (showManage) {
                var $btnManage = $('<button type="button" class="tdcc-mock-btn">' + $('<div>').text(t.manage).html() + '</button>').css({
                    'background-color': btnSecondaryBg,
                    'border-color': borderColor,
                    'color': btnSecondaryColor
                });
                $btns.append($btnManage);
            }

            $banner.append($btns);
            $container.append($banner);

        } else if (currentView === 'preferences') {
            // Render Mock Preferences Modal
            var drawerClass = (prefLayout === 'bar') ? 'drawer-' + prefPosition : '';
            var $backdrop = $('<div class="tdcc-mock-pref-backdrop ' + drawerClass + '"></div>');
            var $window = $('<div class="tdcc-mock-pref-window"></div>');
            $window.css({
                'background-color': bgColor,
                'border-color': borderColor,
                'color': textColor
            });

            var $prefTitle = $('<h3 class="tdcc-mock-title">' + $('<div>').text(t.prefTitle).html() + '</h3>').css('color', textColor);
            var $prefDesc = $('<p class="tdcc-mock-desc">' + $('<div>').text(t.prefDesc).html() + '</p>').css('color', subtextColor);
            $window.append($prefTitle).append($prefDesc);

            // Mock Category Items with interactive toggle switches
            var $cats = $('<div class="tdcc-mock-cat-list"></div>');

            var $cat1 = $('<div class="tdcc-mock-cat-item"><span class="tdcc-mock-cat-title">' + t.catNecessary + '</span><span class="tdcc-badge tdcc-badge-enabled">Required</span></div>');
            var $cat2 = $('<div class="tdcc-mock-cat-item"><span class="tdcc-mock-cat-title">' + t.catAnalytics + '</span><label class="tdcc-mock-toggle"><input type="checkbox" checked><span class="tdcc-mock-toggle-slider"></span></label></div>');
            var $cat3 = $('<div class="tdcc-mock-cat-item"><span class="tdcc-mock-cat-title">' + t.catMarketing + '</span><label class="tdcc-mock-toggle"><input type="checkbox"><span class="tdcc-mock-toggle-slider"></span></label></div>');

            [$cat1, $cat2, $cat3].forEach(function ($cat) {
                $cat.css({
                    'background-color': cardBg,
                    'border-color': borderColor
                });
                $cat.find('.tdcc-mock-cat-title').css('color', textColor);
                $cats.append($cat);
            });

            $window.append($cats);

            var $prefBtns = $('<div class="tdcc-mock-btns" style="margin-top:16px;"></div>');
            var $prefSaveBtn = $('<button type="button" class="tdcc-mock-btn tdcc-mock-btn-primary" style="width:100%">' + $('<div>').text(t.prefSave).html() + '</button>').css({
                'background-color': btnPrimaryBg,
                'border-color': btnPrimaryBg,
                'color': btnPrimaryColor
            });
            $prefBtns.append($prefSaveBtn);
            $window.append($prefBtns);

            $backdrop.append($window);
            $container.append($backdrop);

        } else if (currentView === 'trigger') {
            // Render Mock Trigger
            if (!triggerEnabled) {
                $container.append('<div style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:#94a3b8; font-size:12px;">Trigger is disabled in settings.</div>');
                return;
            }

            var $trig = $('<div class="tdcc-mock-trigger-btn pos-' + triggerPos + '"></div>');
            $trig.css({
                bottom: triggerOffsetY + 'px',
                left: (triggerPos.indexOf('left') !== -1) ? triggerOffsetX + 'px' : 'auto',
                right: (triggerPos.indexOf('right') !== -1) ? triggerOffsetX + 'px' : 'auto',
                'background-color': bgColor,
                'border-color': borderColor,
                'color': textColor
            });

            var labelHtml = '';
            if (triggerMode === 'icon' || triggerMode === 'both') {
                labelHtml += '<svg style="width:16px;height:16px;fill:' + toggleOnBg + ';flex-shrink:0" viewBox="0 0 24 24"><path d="M21.598 11.064a1.006 1.006 0 0 0-.854-.172A3.993 3.993 0 0 1 15.8 6.002a4.015 4.015 0 0 1-.027-.514 1 1 0 0 0-.916-.992 9.99 9.99 0 0 0-5.171.862 10.024 10.024 0 0 0-5.46 8.528A10.028 10.028 0 0 0 13.9 23.998a10.03 10.03 0 0 0 7.91-4.708 1 1 0 0 0-.212-1.226zM12 22a8 8 0 0 1-7.98-7.55 8.019 8.019 0 0 1 4.364-6.822 6.014 6.014 0 0 0 7.37 7.37A8.026 8.026 0 0 1 12 22z"/><circle cx="8.5" cy="14.5" r="1.5"/><circle cx="14.5" cy="16.5" r="1.5"/><circle cx="11.5" cy="10.5" r="1"/><circle cx="15.5" cy="12.5" r="1"/></svg>';
            }
            if (triggerMode === 'text' || triggerMode === 'both') {
                labelHtml += '<span>' + $('<div>').text(t.triggerText).html() + '</span>';
            }

            $trig.html(labelHtml);
            $container.append($trig);
        }
    }

    /**
     * Wizard Navigation
     */
    function initWizard() {
        var $wizard = $('.tdcc-wizard-container');
        if (!$wizard.length) {
            return;
        }

        var currentStep = 1;
        var totalSteps = 5;

        function showStep(step) {
            currentStep = step;
            $('.tdcc-wizard-steps .tdcc-step').removeClass('is-active');
            $('.tdcc-wizard-steps .tdcc-step[data-step="' + step + '"]').addClass('is-active');

            $('.tdcc-wizard-panel').removeClass('is-active');
            $('.tdcc-wizard-panel[data-panel="' + step + '"]').addClass('is-active');
        }

        $('.tdcc-wizard-next').on('click', function () {
            if (currentStep < totalSteps) {
                showStep(currentStep + 1);
            }
        });

        $('.tdcc-wizard-prev').on('click', function () {
            if (currentStep > 1) {
                showStep(currentStep - 1);
            }
        });
    }

    /**
     * Submit actions (Save Draft vs Publish Changes)
     */
    function initSubmitActions() {
        $('.tdcc-submit-btn').on('click', function (e) {
            var action = $(this).data('action') || $(this).val() || 'draft';
            $('#tdcc-action-type').val(action);

            var bumpChecked = $('#bump_revision').is(':checked');

            if (action === 'publish') {
                var confirmMsg = bumpChecked
                    ? 'You are publishing changes with an incremented revision. Returning visitors will be asked to renew their consent. Proceed?'
                    : 'Publish these settings to your public website?';

                if (!window.confirm(confirmMsg)) {
                    e.preventDefault();
                    return false;
                }
            }
        });

        // Delegate form confirmation
        $(document).on('submit', 'form[data-confirm]', function (e) {
            var msg = $(this).attr('data-confirm') || 'Are you sure?';
            if (!window.confirm(msg)) {
                e.preventDefault();
                return false;
            }
        });
    }

    /**
     * Scanner & Service Catalogue Tab Navigation and Live Search
     */
    function initScannerCatalogue() {
        // Nav Pills Switcher (Detected on Site / All Presets / Cookies)
        $(document).on('click', '.tdcc-nav-pill', function () {
            var target = $(this).data('target');
            $('.tdcc-nav-pill').removeClass('is-active');
            $(this).addClass('is-active');

            $('.tdcc-catalogue-pane').removeClass('is-active');
            var $newPane = $('#' + target);
            $newPane.addClass('is-active');

            // Persist active catalogue pane across operations
            try {
                sessionStorage.setItem('tdcc_active_catalogue_pane', target);
            } catch (e) {}

            // Re-apply current search filter if user typed anything
            var q = ($('#tdcc-preset-search').val() || '').toLowerCase().trim();
            if (q) {
                $newPane.find('tbody tr').each(function () {
                    var rowText = $(this).text().toLowerCase();
                    $(this).toggle(rowText.indexOf(q) !== -1);
                });
            } else {
                $newPane.find('tbody tr').show();
            }
        });

        // Restore active catalogue tab from sessionStorage
        try {
            var savedPane = sessionStorage.getItem('tdcc_active_catalogue_pane');
            if (savedPane) {
                var $targetPill = $('.tdcc-nav-pill[data-target="' + savedPane + '"]');
                if ($targetPill.length && !$targetPill.hasClass('is-active')) {
                    $targetPill.trigger('click');
                }
            }
        } catch (e) {}

        // Quick Search across tables
        $('#tdcc-preset-search').on('input', function () {
            var q = $(this).val().toLowerCase().trim();
            $('.tdcc-catalogue-pane.is-active tbody tr').each(function () {
                var rowText = $(this).text().toLowerCase();
                $(this).toggle(rowText.indexOf(q) !== -1);
            });
        });

        // Loading state on scan button submit
        $('#tdcc-run-scan-btn').closest('form').on('submit', function () {
            var $btn = $('#tdcc-run-scan-btn');
            $btn.prop('disabled', true);
            $btn.find('.dashicons').addClass('dashicons-spin');
        });
    }

    /**
     * Floating Toast Notification Manager
     */
    function tdccShowToast(type, message) {
        var $container = $('#tdcc-toast-container');
        if (!$container.length) {
            $container = $('<div class="tdcc-toast-container" id="tdcc-toast-container" role="status" aria-live="polite"></div>');
            $('body').append($container);
        }

        var isSuccess = (type === 'success');
        var iconClass = isSuccess ? 'dashicons-yes' : 'dashicons-warning';
        var $toast = $(
            '<div class="tdcc-toast tdcc-toast-' + (isSuccess ? 'success' : 'error') + '">' +
                '<span class="dashicons ' + iconClass + ' tdcc-toast-icon"></span>' +
                '<div class="tdcc-toast-msg">' + escapeHtml(message) + '</div>' +
                '<button type="button" class="tdcc-toast-close" aria-label="Close">&times;</button>' +
            '</div>'
        );

        $container.append($toast);

        // Entrance animation
        setTimeout(function () {
            $toast.addClass('is-visible');
        }, 15);

        // Auto-dismiss after 3.5 seconds
        var timer = setTimeout(function () {
            dismissToast($toast);
        }, 3500);

        $toast.find('.tdcc-toast-close').on('click', function () {
            clearTimeout(timer);
            dismissToast($toast);
        });

        function dismissToast($el) {
            $el.removeClass('is-visible');
            setTimeout(function () {
                $el.remove();
            }, 300);
        }
    }

    window.tdccShowToast = tdccShowToast;

    /**
     * HTML entity escaper for safe DOM insertion
     */
    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /**
     * Dynamic Service Row Insertion into Configured Services Table
     */
    function addServiceRowToTable(svc, deleteUrl) {
        var $tbody = $('#tdcc-configured-services-tbody');
        if (!$tbody.length) return;

        // Remove placeholder row if present
        $tbody.find('.tdcc-no-services-row').remove();

        // Check if row already exists
        if ($tbody.find('tr[data-service-id="' + svc.id + '"]').length) {
            return;
        }

        var delUrl = deleteUrl || ('admin.php?page=tuedion-cookie-categories&action=delete_svc&svc_id=' + encodeURIComponent(svc.id));
        var cookiesArr = (svc.cookies && Array.isArray(svc.cookies)) ? svc.cookies : [];
        var cookiesStr = cookiesArr.length ? cookiesArr.join(', ') : '';
        var delConfirm = (window.tuedionCookieAdminData && window.tuedionCookieAdminData.strings && window.tuedionCookieAdminData.strings.deleteConfirm)
            ? window.tuedionCookieAdminData.strings.deleteConfirm
            : 'Delete this service?';
        var delLabel = (window.tuedionCookieAdminData && window.tuedionCookieAdminData.strings && window.tuedionCookieAdminData.strings.delete)
            ? window.tuedionCookieAdminData.strings.delete
            : 'Delete';

        var $row = $(
            '<tr data-service-id="' + escapeHtml(svc.id) + '" data-category="' + escapeHtml(svc.category || '') + '" class="tdcc-highlight-row">' +
                '<td><code>' + escapeHtml(svc.id) + '</code></td>' +
                '<td><strong>' + escapeHtml(svc.label || svc.id) + '</strong></td>' +
                '<td><code>' + escapeHtml(svc.category || '') + '</code></td>' +
                '<td><code>' + escapeHtml(cookiesStr) + '</code></td>' +
                '<td>' +
                    '<a href="' + escapeHtml(delUrl) + '" class="button button-link-delete tdcc-delete-svc-btn" data-service-id="' + escapeHtml(svc.id) + '" data-confirm="' + escapeHtml(delConfirm) + '">' +
                        escapeHtml(delLabel) +
                    '</a>' +
                '</td>' +
            '</tr>'
        );

        $tbody.append($row);
    }

    /**
     * Increment or decrement the category services counter
     */
    function updateCategoryCount(catId, delta) {
        if (!catId) return;
        var $countCell = $('td.tdcc-cat-service-count[data-cat-count="' + catId + '"]');
        if ($countCell.length) {
            var current = parseInt($countCell.text().trim(), 10) || 0;
            var updated = Math.max(0, current + delta);
            $countCell.text(updated).addClass('is-updated');
            setTimeout(function () {
                $countCell.removeClass('is-updated');
            }, 500);
        }
    }

    /**
     * Restore "+ Add Service" / "+ Add to Preferences" form buttons in catalogue tables when deleted
     */
    function restorePresetActionCells(svcId) {
        if (!svcId) return;
        var strings = (window.tuedionCookieAdminData && window.tuedionCookieAdminData.strings) ? window.tuedionCookieAdminData.strings : {};
        var addSvcText = strings.addService || '+ Add Service';
        var addPrefText = strings.addToPreferences || '+ Add to Preferences';

        $('[data-preset-cell="' + svcId + '"]').each(function () {
            var $cell = $(this);
            var isDetected = $cell.closest('#pane-detected').length > 0;
            var label = isDetected ? addPrefText : addSvcText;
            var context = isDetected ? 'detected' : 'catalogue';

            var formHtml =
                '<form method="post" action="" class="tdcc-add-preset-form tdcc-animate-pop" data-preset="' + escapeHtml(svcId) + '">' +
                    '<input type="hidden" name="preset_key" value="' + escapeHtml(svcId) + '">' +
                    '<button type="submit" name="tuedion_add_preset_service" class="button button-small button-secondary tdcc-add-preset-btn" data-preset="' + escapeHtml(svcId) + '" data-context="' + context + '">' +
                        escapeHtml(label) +
                    '</button>' +
                '</form>';

            $cell.html(formHtml);
        });
    }

    /**
     * Reactive Service Catalogue Interactive Engine:
     * - Asynchronous 1-click Preset addition without page reload
     * - Live DOM badge synchronization across all tabs
     * - Real-time insertion into Configured Services table
     * - Asynchronous Service deletion with category counter decrements
     * - 1-Click Asynchronous sync for detected services
     */
    function initReactiveServiceCatalogue() {
        var adminData = window.tuedionCookieAdminData || {};
        var strings = adminData.strings || {};

        // 1. Handle Asynchronous Preset Add (+ Add Service & + Add to Preferences)
        $(document).on('submit', '.tdcc-add-preset-form', function (e) {
            e.preventDefault();

            var $form = $(this);
            var $btn = $form.find('.tdcc-add-preset-btn');
            if ($btn.prop('disabled')) {
                return false;
            }

            var presetKey = $btn.data('preset') || $form.find('input[name="preset_key"]').val();
            if (!presetKey) {
                return false;
            }

            var origHtml = $btn.html();
            $btn.prop('disabled', true);
            $btn.html('<span class="dashicons dashicons-update dashicons-spin" style="margin-right:4px;vertical-align:middle;"></span> ' + (strings.adding || 'Adding...'));

            $.ajax({
                url: adminData.ajaxUrl || 'admin-ajax.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'tdcc_add_preset_service',
                    preset_key: presetKey,
                    nonce: adminData.nonce
                }
            }).done(function (res) {
                if (res && res.success) {
                    var svc = (res.data && res.data.service) ? res.data.service : null;
                    var successMsg = (res.data && res.data.message)
                        ? res.data.message
                        : (strings.saved || 'Service added successfully.');

                    // Synchronize both Pane 1 (Detected) and Pane 2 (Catalogue)
                    $('[data-preset-cell="' + presetKey + '"]').each(function () {
                        var $cell = $(this);
                        var isDetected = $cell.closest('#pane-detected').length > 0;
                        if (isDetected) {
                            $cell.html(
                                '<span class="tdcc-badge tdcc-badge-enabled tdcc-status-in-prefs tdcc-animate-pop">' +
                                    '<span class="dashicons dashicons-yes"></span> ' +
                                    (strings.configuredInPrefs || 'Configured in Preferences') +
                                '</span>'
                            );
                        } else {
                            $cell.html(
                                '<span class="tdcc-badge-in-prefs tdcc-status-in-prefs tdcc-animate-pop">' +
                                    '<span class="dashicons dashicons-yes"></span> ' +
                                    (strings.inPreferences || 'In Preferences') +
                                '</span>'
                            );
                        }
                    });

                    // Append row to the Configured Services overview table
                    if (svc) {
                        addServiceRowToTable(svc, res.data.delete_url);
                        if (svc.category) {
                            updateCategoryCount(svc.category, 1);
                        }
                    }

                    tdccShowToast('success', successMsg);
                } else {
                    $btn.prop('disabled', false).html(origHtml);
                    var errMsg = (res && res.data && res.data.message)
                        ? res.data.message
                        : (strings.error || 'An error occurred.');
                    tdccShowToast('error', errMsg);
                }
            }).fail(function () {
                $btn.prop('disabled', false).html(origHtml);
                tdccShowToast('error', strings.error || 'An error occurred. Please try again.');
            });

            return false;
        });

        // 2. Handle Asynchronous Service Deletion
        $(document).on('click', '.tdcc-delete-svc-btn', function (e) {
            e.preventDefault();

            var $btn = $(this);
            var svcId = $btn.data('service-id');
            var confirmMsg = $btn.data('confirm') || (strings.deleteConfirm || 'Delete this service?');

            if (!window.confirm(confirmMsg)) {
                return false;
            }

            var $row = $btn.closest('tr');
            $row.css({ opacity: '0.4', pointerEvents: 'none' });

            $.ajax({
                url: adminData.ajaxUrl || 'admin-ajax.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'tdcc_delete_service',
                    service_id: svcId,
                    nonce: adminData.nonce
                }
            }).done(function (res) {
                if (res && res.success) {
                    var catId = (res.data && res.data.category) ? res.data.category : $row.data('category');

                    $row.fadeOut(250, function () {
                        $row.remove();
                        var $tbody = $('#tdcc-configured-services-tbody');
                        if ($tbody.find('tr').length === 0) {
                            $tbody.html(
                                '<tr class="tdcc-no-services-row"><td colspan="5"><em>' +
                                (strings.noServices || 'No granular services defined yet. Trackers will be governed at category level.') +
                                '</em></td></tr>'
                            );
                        }
                    });

                    if (catId) {
                        updateCategoryCount(catId, -1);
                    }

                    // Restore "+ Add Service" / "+ Add to Preferences" in catalogue panes
                    restorePresetActionCells(svcId);

                    var successMsg = (res.data && res.data.message)
                        ? res.data.message
                        : (strings.deleted || 'Service removed from cookie preferences.');
                    tdccShowToast('success', successMsg);
                } else {
                    $row.css({ opacity: '1', pointerEvents: 'auto' });
                    var errMsg = (res && res.data && res.data.message)
                        ? res.data.message
                        : (strings.error || 'An error occurred.');
                    tdccShowToast('error', errMsg);
                }
            }).fail(function () {
                $row.css({ opacity: '1', pointerEvents: 'auto' });
                tdccShowToast('error', strings.error || 'An error occurred. Please try again.');
            });

            return false;
        });

        // 3. Handle Asynchronous "Sync Detected to Preferences"
        $(document).on('submit', '.tdcc-sync-detected-form', function (e) {
            e.preventDefault();

            var $btn = $('#tdcc-sync-detected-btn');
            if ($btn.prop('disabled')) {
                return false;
            }

            var origHtml = $btn.html();
            $btn.prop('disabled', true);
            $btn.html('<span class="dashicons dashicons-update dashicons-spin" style="margin-right:4px;vertical-align:middle;"></span> ' + (strings.syncing || 'Syncing services...'));

            $.ajax({
                url: adminData.ajaxUrl || 'admin-ajax.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'tdcc_sync_scanned_services',
                    nonce: adminData.nonce
                }
            }).done(function (res) {
                $btn.prop('disabled', false).html(origHtml);

                if (res && res.success) {
                    var syncedCount = (res.data && typeof res.data.synced !== 'undefined') ? res.data.synced : 0;

                    // Update all cells in detected pane
                    $('#pane-detected .tdcc-action-cell').each(function () {
                        var $cell = $(this);
                        var presetKey = $cell.data('preset-cell');

                        $cell.html(
                            '<span class="tdcc-badge tdcc-badge-enabled tdcc-status-in-prefs tdcc-animate-pop">' +
                                '<span class="dashicons dashicons-yes"></span> ' +
                                (strings.configuredInPrefs || 'Configured in Preferences') +
                            '</span>'
                        );

                        // Also reflect in catalogue pane
                        if (presetKey) {
                            $('#pane-catalogue [data-preset-cell="' + presetKey + '"]').html(
                                '<span class="tdcc-badge-in-prefs tdcc-status-in-prefs tdcc-animate-pop">' +
                                    '<span class="dashicons dashicons-yes"></span> ' +
                                    (strings.inPreferences || 'In Preferences') +
                                '</span>'
                            );
                        }
                    });

                    var msg = syncedCount > 0
                        ? (syncedCount + ' ' + (strings.syncSuccess || 'Detected services synchronized to preferences.'))
                        : (strings.saved || 'All detected services are already in your preferences.');
                    tdccShowToast('success', msg);
                } else {
                    var errMsg = (res && res.data && res.data.message)
                        ? res.data.message
                        : (strings.error || 'An error occurred.');
                    tdccShowToast('error', errMsg);
                }
            }).fail(function () {
                $btn.prop('disabled', false).html(origHtml);
                tdccShowToast('error', strings.error || 'An error occurred. Please try again.');
            });

            return false;
        });
    }

    /**
     * One-click shortcode copy to clipboard with fallback
     */
    function initShortcodeCopy() {
        $(document).on('click', '.tdcc-copy-shortcode-btn', function (e) {
            e.preventDefault();
            var code = $(this).data('code') || '';
            if (!code) return;
            var $btn = $(this);
            var origHtml = $btn.html();

            function showCopied() {
                var copiedText = $btn.data('copied-text') || 'Copied!';
                $btn.html('<span class="dashicons dashicons-yes" style="font-size:14px;width:14px;height:14px;line-height:14px;vertical-align:text-top;"></span> ' + copiedText);
                $btn.addClass('button-primary');
                setTimeout(function () {
                    $btn.html(origHtml).removeClass('button-primary');
                }, 2200);
            }

            function fallbackCopy(text, cb) {
                var textArea = document.createElement('textarea');
                textArea.value = text;
                textArea.style.position = 'fixed';
                textArea.style.left = '-999999px';
                textArea.style.top = '-999999px';
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                try {
                    document.execCommand('copy');
                    if (cb) cb();
                } catch (err) {}
                textArea.remove();
            }

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(code).then(showCopied).catch(function () {
                    fallbackCopy(code, showCopied);
                });
            } else {
                fallbackCopy(code, showCopied);
            }
        });
    }

})(jQuery);

