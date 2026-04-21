/**
 * Warehouse Audit Scanner
 *
 * Wrapper around html5-qrcode (with native BarcodeDetector fast-path when
 * available). Exposes:
 *   AuditScanner.start(viewportElId, onScanCallback, onErrorCallback)
 *   AuditScanner.stop()
 *   AuditScanner.isActive()
 *   AuditScanner.isSupported() -> bool
 *
 * The onScanCallback receives { decodedText, format } and is responsible
 * for POSTing the scan. The scanner stays running between scans (continuous
 * mode) and applies a short client-side dedup window to prevent the same
 * barcode from firing dozens of times as the camera holds still.
 */
(function () {
    'use strict';

    var html5QrCode = null;
    var isActive = false;
    var lastScanCache = { text: null, timestamp: 0 };
    var DEDUP_WINDOW_MS = 2500; // ignore same decoded text within 2.5s
    var beepAudio = null;

    function initBeep() {
        if (beepAudio) return;
        // Short synthetic beep via Web Audio API — no external file needed
        try {
            var AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            beepAudio = new AudioCtx();
        } catch (e) { /* no audio */ }
    }

    function playBeep() {
        if (!beepAudio) return;
        try {
            var o = beepAudio.createOscillator();
            var g = beepAudio.createGain();
            o.type = 'sine';
            o.frequency.value = 920;
            g.gain.value = 0.12;
            o.connect(g);
            g.connect(beepAudio.destination);
            o.start();
            g.gain.exponentialRampToValueAtTime(0.0001, beepAudio.currentTime + 0.12);
            o.stop(beepAudio.currentTime + 0.13);
        } catch (e) { /* ignore */ }
    }

    function vibrate() {
        if (navigator.vibrate) {
            try { navigator.vibrate(30); } catch (e) { /* ignore */ }
        }
    }

    /**
     * Returns null if camera scanning is available, or a human-readable
     * reason string if it isn't. Callers use this to decide whether to
     * show a scan button or fall back to manual entry.
     *
     * Checks (in order):
     *   1. Secure context - getUserMedia is blocked on http:// except
     *      on localhost. This catches the common deploy mistake of
     *      serving the portal over plain HTTP on a public host.
     *   2. Html5Qrcode library loaded
     *   3. Browser has navigator.mediaDevices.getUserMedia
     */
    function getSupportIssue() {
        if (typeof window.isSecureContext !== 'undefined' && !window.isSecureContext) {
            return 'Camera access requires HTTPS (or localhost). Open this page over https to enable scanning.';
        }
        if (typeof window.Html5Qrcode === 'undefined') {
            return 'Scanner library not loaded — check that vendor/html5-qrcode/html5-qrcode.min.js is reachable.';
        }
        if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
            return 'This browser does not support camera access. Use manual entry instead.';
        }
        return null;
    }

    function isSupported() {
        return getSupportIssue() === null;
    }

    function start(viewportElId, onScan, onError) {
        if (isActive) return;
        var issue = getSupportIssue();
        if (issue) {
            if (onError) onError(new Error(issue));
            return;
        }
        initBeep();

        var viewportEl = document.getElementById(viewportElId);
        if (!viewportEl) {
            if (onError) onError(new Error('Scanner viewport element not found: ' + viewportElId));
            return;
        }
        // Clear existing placeholder content
        viewportEl.innerHTML = '';

        try {
            html5QrCode = new window.Html5Qrcode(viewportElId, { verbose: false });
        } catch (e) {
            if (onError) onError(e);
            return;
        }

        var config = {
            fps: 12,
            qrbox: function (vw, vh) {
                var min = Math.min(vw, vh);
                return { width: Math.floor(min * 0.75), height: Math.floor(min * 0.55) };
            },
            aspectRatio: 1.333,
            experimentalFeatures: {
                // Attempts native BarcodeDetector API before JS fallback
                useBarCodeDetectorIfSupported: true
            },
            rememberLastUsedCamera: true,
            supportedScanTypes: []
        };

        // Prefer back camera on mobile. html5-qrcode only accepts the bare
        // string form or { exact: 'environment' }; it rejects { ideal: ... }
        // with "facingMode should be string or object with exact as key".
        // The string form is a soft preference that falls back to any
        // available camera if there's no rear camera at all.
        var cameraConstraints = { facingMode: 'environment' };

        html5QrCode.start(
            cameraConstraints,
            config,
            function (decodedText, decodedResult) {
                // Dedup window
                var now = Date.now();
                if (lastScanCache.text === decodedText && (now - lastScanCache.timestamp) < DEDUP_WINDOW_MS) {
                    return;
                }
                lastScanCache = { text: decodedText, timestamp: now };

                playBeep();
                vibrate();

                if (onScan) {
                    var format = (decodedResult && decodedResult.result && decodedResult.result.format && decodedResult.result.format.formatName) || 'unknown';
                    onScan({ decodedText: decodedText, format: format });
                }
            },
            function (errorMessage) {
                // Ignored: per-frame decode errors are normal when no code is in view
            }
        ).then(function () {
            isActive = true;
        }).catch(function (err) {
            if (onError) onError(err);
        });
    }

    function stop() {
        if (!html5QrCode || !isActive) return Promise.resolve();
        return html5QrCode.stop()
            .then(function () {
                try { html5QrCode.clear(); } catch (e) { /* ignore */ }
                isActive = false;
                html5QrCode = null;
                lastScanCache = { text: null, timestamp: 0 };
            })
            .catch(function (e) {
                isActive = false;
                html5QrCode = null;
                return Promise.resolve();
            });
    }

    window.AuditScanner = {
        start: start,
        stop: stop,
        isActive: function () { return isActive; },
        isSupported: isSupported,
        getSupportIssue: getSupportIssue,
    };
})();
