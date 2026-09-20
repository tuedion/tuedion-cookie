/**
 * Tuedion Cookie - Admin Cookie Scanner Controller
 * Orchestrates iframe page audits and dispatches results to admin-ajax.
 */
document.addEventListener('DOMContentLoaded', function() {
    'use strict';

    var config = window.tdccScannerConfig || {};
    var scanBtns = document.querySelectorAll('.tdcc-client-scan-btn');
    if (!scanBtns.length) {
        return;
    }

    var defaultUrls = [window.location.origin + '/?tdcc_audit=1'];
    var urlsToScan = Array.isArray(config.urlsToScan) && config.urlsToScan.length > 0
        ? config.urlsToScan
        : defaultUrls;

    var ajaxUrl = config.ajaxUrl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');
    var nonce = config.nonce || '';
    var strings = config.strings || {
        scanningPages: 'Scanning pages...',
        analyzingResults: 'Analyzing results...',
        timeoutError: 'Scan timed out. Please try again.',
        networkError: 'Network error during scan processing.',
        genericError: 'Error occurred during scan.'
    };

    scanBtns.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var _btn = this;
            var originalText = _btn.innerHTML;
            _btn.innerHTML = '<span class="dashicons dashicons-update tdcc-spin"></span> ' + strings.scanningPages;
            _btn.disabled = true;

            var btnNonce = _btn.getAttribute('data-nonce') || nonce;

            var currentUrlIndex = 0;
            var combinedData = { cookies: [], scripts: [], iframes: [] };
            var iframe = null;
            var timeout = null;

            function cleanup() {
                if (timeout) {
                    clearTimeout(timeout);
                    timeout = null;
                }
                if (iframe) {
                    iframe.remove();
                    iframe = null;
                }
                window.removeEventListener('message', onScanMessage);
            }

            function cleanupAndFail(msg) {
                cleanup();
                _btn.innerHTML = originalText;
                _btn.disabled = false;
                alert(msg || strings.timeoutError);
            }

            function scanNextUrl() {
                if (currentUrlIndex >= urlsToScan.length) {
                    finishScan();
                    return;
                }

                var url = urlsToScan[currentUrlIndex];
                _btn.innerHTML = '<span class="dashicons dashicons-update tdcc-spin"></span> Scanning ' + (currentUrlIndex + 1) + '/' + urlsToScan.length + '...';

                iframe = document.createElement('iframe');
                iframe.style.display = 'none';
                iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin');
                iframe.src = url;
                document.body.appendChild(iframe);

                timeout = setTimeout(function() {
                    cleanupAndFail(strings.timeoutError);
                }, 20000);
            }

            function finishScan() {
                cleanup();
                _btn.innerHTML = '<span class="dashicons dashicons-update tdcc-spin"></span> ' + strings.analyzingResults;

                var fd = new URLSearchParams();
                fd.append('action', 'tdcc_run_cookie_scan');
                fd.append('nonce', btnNonce);
                fd.append('payload', JSON.stringify(combinedData));

                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: fd.toString()
                })
                .then(function(res) {
                    return res.json();
                })
                .then(function(res) {
                    if (res.success) {
                        window.location.reload();
                    } else {
                        var errMsg = (res.data && res.data.message) ? res.data.message : strings.genericError;
                        alert(errMsg);
                        _btn.innerHTML = originalText;
                        _btn.disabled = false;
                    }
                })
                .catch(function() {
                    alert(strings.networkError);
                    _btn.innerHTML = originalText;
                    _btn.disabled = false;
                });
            }

            function onScanMessage(event) {
                // Strict origin check: only accept messages from own origin
                if (event.origin !== window.location.origin) {
                    return;
                }

                // Strict source check: only accept message from the active audit iframe
                if (!iframe || event.source !== iframe.contentWindow) {
                    return;
                }

                if (event.data && event.data.type === 'tdcc_scan_result') {
                    if (timeout) {
                        clearTimeout(timeout);
                        timeout = null;
                    }
                    if (iframe) {
                        iframe.remove();
                        iframe = null;
                    }

                    // Merge sanitized cookies (names only)
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

                    currentUrlIndex++;
                    scanNextUrl();
                }
            }

            window.addEventListener('message', onScanMessage);
            scanNextUrl();
        });
    });
});
