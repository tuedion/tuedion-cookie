/**
 * Tuedion Cookie - Admin Cookie Scanner Controller v3.0
 * Orchestrates multi-mode crawls (Quick, Smart, Full), iframe runtime audits,
 * progress tracking, unknown resource governance, and diff tracking.
 */
document.addEventListener('DOMContentLoaded', function() {
    'use strict';

    var config = window.tdccScannerConfig || {};
    var scanBtns = document.querySelectorAll('.tdcc-client-scan-btn');
    if (!scanBtns.length) {
        return;
    }

    var ajaxUrl = config.ajaxUrl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');
    var nonce = config.nonce || '';
    var strings = config.strings || {
        scanningPages: 'Scanning pages...',
        analyzingResults: 'Analyzing results...',
        scanComplete: 'Scan complete! Updating preferences...',
        timeoutError: 'Scan timed out. Please try again.',
        networkError: 'Network error during scan processing.',
        genericError: 'Error occurred during scan.',
        noTargetsSelected: 'Please select at least one page or post type to scan.'
    };

    // 1. Mode Cards Selector and Dynamic Panel Switching
    var modeCards = document.querySelectorAll('.tdcc-mode-card');
    var modePanels = document.querySelectorAll('.tdcc-mode-panel');

    function getActiveMode() {
        var activeRadio = document.querySelector('input[name="active_scan_mode"]:checked');
        return activeRadio ? activeRadio.value : 'quick';
    }

    function setActiveMode(mode) {
        modeCards.forEach(function(card) {
            if (card.getAttribute('data-mode') === mode) {
                card.classList.add('is-active');
                var radio = card.querySelector('input[type="radio"]');
                if (radio) radio.checked = true;
            } else {
                card.classList.remove('is-active');
            }
        });

        modePanels.forEach(function(panel) {
            if (panel.id === 'tdcc-panel-mode-' + mode) {
                panel.classList.add('is-active');
            } else {
                panel.classList.remove('is-active');
            }
        });
    }

    modeCards.forEach(function(card) {
        card.addEventListener('click', function() {
            var mode = this.getAttribute('data-mode');
            if (mode) {
                setActiveMode(mode);
            }
        });
    });

    // 2. Dynamic Scope Chips Checkbox Sync
    var scopeChips = document.querySelectorAll('#tdcc-scanner-scope-items .tdcc-scope-chip');
    scopeChips.forEach(function(chip) {
        var cb = chip.querySelector('input[type="checkbox"]');
        if (!cb) return;

        cb.addEventListener('change', function() {
            if (this.checked) {
                chip.classList.add('is-checked');
            } else {
                chip.classList.remove('is-checked');
            }
        });
    });

    // Progress Bar DOM Elements
    var progressContainer = document.getElementById('tdcc-scanner-progress');
    var progressBar = document.getElementById('tdcc-progress-bar');
    var progressPercent = document.getElementById('tdcc-progress-percent');
    var progressLabel = document.getElementById('tdcc-progress-label');
    var progressStep = document.getElementById('tdcc-progress-step');
    var progressUrl = document.getElementById('tdcc-progress-url');

    // 3. Scan Trigger Handler
    scanBtns.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var _btn = this;
            var originalText = _btn.innerHTML;

            // If button specified a mode override (e.g. drift notice "Run Smart Scan Now")
            var overrideMode = _btn.getAttribute('data-mode');
            if (overrideMode) {
                setActiveMode(overrideMode);
            }

            var currentMode = getActiveMode();
            var btnNonce = _btn.getAttribute('data-nonce') || nonce;

            _btn.innerHTML = '<span class="dashicons dashicons-update tdcc-spin"></span> ' + strings.scanningPages;
            _btn.disabled = true;

            if (progressContainer) {
                progressContainer.classList.add('is-active');
                if (progressBar) progressBar.style.width = '5%';
                if (progressPercent) progressPercent.textContent = '5%';
                if (progressLabel) progressLabel.textContent = 'Planning crawl targets for ' + currentMode.toUpperCase() + ' Scan...';
                if (progressStep) progressStep.textContent = 'Discovering pages, sitemaps and menus...';
                if (progressUrl) progressUrl.textContent = '';
            }

            var scanStartTime = Math.floor(Date.now() / 1000);

            // Step 1: Request Planned URLs from Server
            var planFd = new URLSearchParams();
            planFd.append('action', 'tdcc_plan_scan_urls');
            planFd.append('nonce', btnNonce);
            planFd.append('mode', currentMode);

            if (currentMode === 'quick') {
                var stratEl = document.getElementById('tdcc-quick-strategy');
                var sizeEl = document.getElementById('tdcc-quick-sample-size');
                planFd.append('strategy', stratEl ? stratEl.value : 'representative');
                planFd.append('sample_size', sizeEl ? sizeEl.value : '1');

                var checkedScope = document.querySelectorAll('#tdcc-scanner-scope-items input[type="checkbox"]:checked');
                checkedScope.forEach(function(cb) {
                    planFd.append('targets[]', cb.value);
                });
            } else if (currentMode === 'smart') {
                var sitemapEl = document.getElementById('tdcc-smart-sitemap');
                var menusEl = document.getElementById('tdcc-smart-menus');
                var criticalEl = document.getElementById('tdcc-smart-critical');
                var maxUrlsEl = document.getElementById('tdcc-smart-max-urls');
                var excludeEl = document.getElementById('tdcc-smart-exclude');
                var includeEl = document.getElementById('tdcc-smart-include');

                if (sitemapEl && sitemapEl.checked) planFd.append('include_sitemap', '1');
                if (menusEl && menusEl.checked) planFd.append('include_menus', '1');
                if (criticalEl && criticalEl.checked) planFd.append('include_critical', '1');
                if (maxUrlsEl) planFd.append('max_urls', maxUrlsEl.value);
                if (excludeEl && excludeEl.value) planFd.append('exclude_rules', excludeEl.value);
                if (includeEl && includeEl.value) planFd.append('include_rules', includeEl.value);
            }

            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: planFd.toString()
            })
            .then(function(res) {
                if (!res.ok) {
                    throw new Error('Server returned HTTP ' + res.status);
                }
                return res.json();
            })
            .then(function(res) {
                if (!res.success || !res.data || !Array.isArray(res.data.targets) || res.data.targets.length === 0) {
                    var failMsg = (res && res.data && res.data.message) 
                        ? res.data.message 
                        : 'No target pages could be planned for scanning. Please check your scope settings.';
                    cleanupAndFail(failMsg);
                    return;
                }

                executeCrawlQueue(res.data.targets);
            })
            .catch(function(err) {
                var errDetail = (err && err.message) ? (' (' + err.message + ')') : '';
                cleanupAndFail(strings.networkError + errDetail);
            });

            // Queue Variables
            var currentUrlIndex = 0;
            var combinedData = {
                mode: currentMode,
                start_time: scanStartTime,
                cookies: [],
                scripts: [],
                iframes: [],
                storage: [],
                pixels: [],
                network: [],
                scanned_urls: [],
                discovered_urls: []
            };
            var iframe = null;
            var timeout = null;
            var onScanMessage = null;

            function cleanup() {
                if (timeout) {
                    clearTimeout(timeout);
                    timeout = null;
                }
                if (iframe) {
                    iframe.remove();
                    iframe = null;
                }
                if (onScanMessage) {
                    window.removeEventListener('message', onScanMessage);
                    onScanMessage = null;
                }
            }

            function cleanupAndFail(msg) {
                cleanup();
                _btn.innerHTML = originalText;
                _btn.disabled = false;
                if (progressContainer) {
                    progressContainer.classList.remove('is-active');
                }
                if (window.tdccShowToast) {
                    window.tdccShowToast('error', msg || strings.timeoutError);
                } else {
                    alert(msg || strings.timeoutError);
                }
            }

            function executeCrawlQueue(targetsToScan) {
                combinedData.discovered_urls = targetsToScan.map(function(t) { return t.url; });

                function scanNextUrl() {
                    if (currentUrlIndex >= targetsToScan.length) {
                        finishScan();
                        return;
                    }

                    var target = targetsToScan[currentUrlIndex];
                    var stepNum = currentUrlIndex + 1;
                    var totalSteps = targetsToScan.length;
                    var percent = Math.min(92, Math.round((currentUrlIndex / totalSteps) * 85) + 8);

                    _btn.innerHTML = '<span class="dashicons dashicons-update tdcc-spin"></span> Scanning ' + stepNum + '/' + totalSteps + '...';

                    if (progressBar) progressBar.style.width = percent + '%';
                    if (progressPercent) progressPercent.textContent = percent + '%';
                    if (progressLabel) progressLabel.textContent = 'Scanning ' + stepNum + '/' + totalSteps + ': ' + (target.label || target.key);
                    if (progressStep) progressStep.textContent = 'Auditing cookies, delayed scripts & embeds...';
                    if (progressUrl) {
                        var displayUrl = target.url.replace('?tdcc_audit=1', '').replace('&tdcc_audit=1', '');
                        progressUrl.textContent = displayUrl;
                    }

                    iframe = document.createElement('iframe');
                    iframe.style.display = 'none';
                    iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin');
                    iframe.src = target.url;
                    document.body.appendChild(iframe);

                    // 20s safety timeout per page
                    timeout = setTimeout(function() {
                        cleanupAndFail(strings.timeoutError + ' (' + (target.label || target.key) + ')');
                    }, 20000);
                }

                function finishScan() {
                    cleanup();
                    _btn.innerHTML = '<span class="dashicons dashicons-update tdcc-spin"></span> ' + strings.analyzingResults;

                    if (progressBar) progressBar.style.width = '100%';
                    if (progressPercent) progressPercent.textContent = '100%';
                    if (progressLabel) progressLabel.textContent = strings.analyzingResults;
                    if (progressStep) progressStep.textContent = 'Cataloging discovered cookies and computing diff...';
                    if (progressUrl) progressUrl.textContent = '';

                    combinedData.scanned_urls = targetsToScan.map(function(t) { return t.url; });
                    combinedData.duration = Math.max(1, Math.floor(Date.now() / 1000) - scanStartTime);

                    var fd = new URLSearchParams();
                    fd.append('action', 'tdcc_run_cookie_scan');
                    fd.append('nonce', btnNonce);
                    fd.append('payload', JSON.stringify(combinedData));

                    fetch(ajaxUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: fd.toString()
                    })
                    .then(function(res) {
                        if (!res.ok) {
                            throw new Error('Server returned HTTP ' + res.status);
                        }
                        return res.json();
                    })
                    .then(function(res) {
                        if (res.success) {
                            if (progressLabel) progressLabel.textContent = strings.scanComplete;
                            if (progressStep) progressStep.textContent = 'Updating preferences table...';
                            setTimeout(function() {
                                window.location.reload();
                            }, 800);
                        } else {
                            var errMsg = (res.data && res.data.message) ? res.data.message : strings.genericError;
                            cleanupAndFail(errMsg);
                        }
                    })
                    .catch(function(err) {
                        var errDetail = (err && err.message) ? (' (' + err.message + ')') : '';
                        cleanupAndFail(strings.networkError + errDetail);
                    });
                }

                onScanMessage = function(event) {
                    if (event.origin !== window.location.origin) return;
                    if (!iframe || event.source !== iframe.contentWindow) return;

                    if (event.data && event.data.type === 'tdcc_scan_result') {
                        if (timeout) {
                            clearTimeout(timeout);
                            timeout = null;
                        }
                        if (iframe) {
                            iframe.remove();
                            iframe = null;
                        }

                        // Merge cookies (names only)
                        if (Array.isArray(event.data.cookies)) {
                            event.data.cookies.forEach(function(cName) {
                                if (typeof cName === 'string' && cName && combinedData.cookies.indexOf(cName) === -1) {
                                    combinedData.cookies.push(cName);
                                }
                            });
                        }

                        // Merge scripts
                        if (Array.isArray(event.data.scripts)) {
                            combinedData.scripts = combinedData.scripts.concat(event.data.scripts);
                        }

                        // Merge iframes
                        if (Array.isArray(event.data.iframes)) {
                            combinedData.iframes = combinedData.iframes.concat(event.data.iframes);
                        }

                        // Merge storage
                        if (Array.isArray(event.data.storage)) {
                            event.data.storage.forEach(function(sKey) {
                                if (typeof sKey === 'string' && sKey && combinedData.storage.indexOf(sKey) === -1) {
                                    combinedData.storage.push(sKey);
                                }
                            });
                        }

                        // Merge pixels
                        if (Array.isArray(event.data.pixels)) {
                            combinedData.pixels = combinedData.pixels.concat(event.data.pixels);
                        }

                        // Merge network beacons
                        if (Array.isArray(event.data.network)) {
                            combinedData.network = combinedData.network.concat(event.data.network);
                        }

                        currentUrlIndex++;
                        scanNextUrl();
                    }
                }

                window.addEventListener('message', onScanMessage);
                scanNextUrl();
            }
        });
    });

    // 4. Unknown Resources Management Actions
    function sendUnknownAction(subAction, resourceId, extraData, callback) {
        var fd = new URLSearchParams();
        fd.append('action', 'tdcc_manage_unknown_resource');
        fd.append('nonce', nonce);
        fd.append('sub_action', subAction);
        if (resourceId) fd.append('resource_id', resourceId);

        if (extraData) {
            for (var k in extraData) {
                if (extraData.hasOwnProperty(k)) {
                    fd.append(k, extraData[k]);
                }
            }
        }

        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: fd.toString()
        })
        .then(function(res) { return res.json(); })
        .then(function(res) {
            if (res.success) {
                var msg = (res.data && res.data.message) ? res.data.message : 'Action completed successfully.';
                if (window.tdccShowToast) {
                    window.tdccShowToast('success', msg);
                }
                if (callback) callback(res);
            } else {
                var err = (res.data && res.data.message) ? res.data.message : 'Action failed.';
                if (window.tdccShowToast) {
                    window.tdccShowToast('error', err);
                } else {
                    alert(err);
                }
            }
        })
        .catch(function() {
            alert('Network error while processing resource action.');
        });
    }

    // Ignore Unknown Resource
    document.querySelectorAll('.tdcc-ignore-unknown-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var id = this.getAttribute('data-id');
            if (!id) return;
            var row = document.querySelector('tr[data-unknown-row="' + id + '"]');
            sendUnknownAction('ignore', id, null, function() {
                if (row) {
                    row.classList.add('is-ignored');
                    setTimeout(function() { window.location.reload(); }, 600);
                }
            });
        });
    });

    // Restore (Unignore) Unknown Resource
    document.querySelectorAll('.tdcc-unignore-unknown-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var id = this.getAttribute('data-id');
            if (!id) return;
            sendUnknownAction('unignore', id, null, function() {
                setTimeout(function() { window.location.reload(); }, 600);
            });
        });
    });

    // Delete Unknown Resource
    document.querySelectorAll('.tdcc-delete-unknown-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var id = this.getAttribute('data-id');
            if (!id) return;
            if (!confirm('Are you sure you want to delete this unknown resource record?')) return;

            var row = document.querySelector('tr[data-unknown-row="' + id + '"]');
            sendUnknownAction('delete', id, null, function() {
                if (row) row.remove();
            });
        });
    });

    // Clear All Unknown Resources
    var clearUnknownsBtn = document.getElementById('tdcc-clear-unknowns-btn');
    if (clearUnknownsBtn) {
        clearUnknownsBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to clear all unknown resource records?')) return;
            sendUnknownAction('clear_all', '', null, function() {
                window.location.reload();
            });
        });
    }

    // Categorize Unknown Resource as Custom Service
    document.querySelectorAll('.tdcc-categorize-unknown-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var id = this.getAttribute('data-id');
            var domain = this.getAttribute('data-domain') || '';
            if (!id) return;

            var defaultName = domain ? domain.replace(/^www\./, '') : 'Custom Service';
            var serviceName = prompt('Enter a friendly name for this service:', defaultName);
            if (!serviceName || !serviceName.trim()) return;

            var category = prompt('Enter service category (marketing, analytics, functionality, necessary):', 'marketing');
            if (!category) category = 'marketing';
            category = category.trim().toLowerCase();

            sendUnknownAction('categorize', id, {
                service_name: serviceName.trim(),
                service_category: category,
                service_domain: domain
            }, function() {
                setTimeout(function() { window.location.reload(); }, 800);
            });
        });
    });

    // 5. Open Cookie Database Definitions Updater Handler
    var updateDbBtn = document.getElementById('tdcc-update-cookie-db-btn');
    if (updateDbBtn) {
        updateDbBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var _uBtn = this;
            var origHtml = _uBtn.innerHTML;
            _uBtn.innerHTML = '<span class="dashicons dashicons-update tdcc-spin"></span> Updating...';
            _uBtn.disabled = true;

            var uNonce = _uBtn.getAttribute('data-nonce') || nonce;
            var uFd = new URLSearchParams();
            uFd.append('action', 'tdcc_update_cookie_database');
            uFd.append('nonce', uNonce);

            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: uFd.toString()
            })
            .then(function(res) { return res.json(); })
            .then(function(res) {
                if (res.success) {
                    var msg = (res.data && res.data.message) ? res.data.message : 'Cookie definitions updated successfully.';
                    if (window.tdccShowToast) {
                        window.tdccShowToast('success', msg);
                    } else {
                        alert(msg);
                    }
                    setTimeout(function() { window.location.reload(); }, 1200);
                } else {
                    var err = (res.data && res.data.message) ? res.data.message : 'Failed to update cookie definitions.';
                    if (window.tdccShowToast) {
                        window.tdccShowToast('error', err);
                    } else {
                        alert(err);
                    }
                    _uBtn.innerHTML = origHtml;
                    _uBtn.disabled = false;
                }
            })
            .catch(function() {
                var netErr = 'Network error while updating cookie definitions.';
                if (window.tdccShowToast) {
                    window.tdccShowToast('error', netErr);
                } else {
                    alert(netErr);
                }
                _uBtn.innerHTML = origHtml;
                _uBtn.disabled = false;
            });
        });
    }

    // 6. Reset / Clear All Scan Findings & Discovered Cookies
    function executeResetScan(btnElement) {
        var origHtml = btnElement.innerHTML;
        btnElement.innerHTML = '<span class="dashicons dashicons-update tdcc-spin"></span> ' + (strings.clearingScan || 'Clearing...');
        btnElement.disabled = true;

        var rNonce = btnElement.getAttribute('data-nonce') || nonce;
        var rFd = new URLSearchParams();
        rFd.append('action', 'tdcc_reset_scan_results');
        rFd.append('nonce', rNonce);

        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: rFd.toString()
        })
        .then(function(res) { return res.json(); })
        .then(function(res) {
            if (res.success) {
                var msg = (res.data && res.data.message) ? res.data.message : (strings.resetSuccess || 'All scan findings cleared successfully.');
                if (window.tdccShowToast) {
                    window.tdccShowToast('success', msg);
                } else {
                    alert(msg);
                }
                setTimeout(function() { window.location.reload(); }, 1000);
            } else {
                var err = (res.data && res.data.message) ? res.data.message : (strings.resetError || 'Failed to clear scan results.');
                if (window.tdccShowToast) {
                    window.tdccShowToast('error', err);
                } else {
                    alert(err);
                }
                btnElement.innerHTML = origHtml;
                btnElement.disabled = false;
            }
        })
        .catch(function() {
            var netErr = strings.networkError || 'Network error while clearing scan findings.';
            if (window.tdccShowToast) {
                window.tdccShowToast('error', netErr);
            } else {
                alert(netErr);
            }
            btnElement.innerHTML = origHtml;
            btnElement.disabled = false;
        });
    }

    var resetBtns = document.querySelectorAll('#tdcc-reset-scan-btn, .tdcc-trigger-reset-scan');
    resetBtns.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var _btn = this;
            var confirmMsg = strings.confirmReset || 'Are you sure you want to clear all discovered cookies, scanned services, and scan history?';

            if (window.Swal) {
                window.Swal.fire({
                    title: 'Clear Scan Results?',
                    text: confirmMsg,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc2626',
                    cancelButtonColor: '#64748b',
                    confirmButtonText: 'Yes, clear all',
                    cancelButtonText: 'Cancel'
                }).then(function(result) {
                    if (result.isConfirmed) {
                        executeResetScan(_btn);
                    }
                });
            } else {
                if (window.confirm(confirmMsg)) {
                    executeResetScan(_btn);
                }
            }
        });
    });
});

