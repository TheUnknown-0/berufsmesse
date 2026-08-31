/**
 * Kamera-QR-Scanner (jsQR muss vorher geladen sein: vendor/jsqr.min.js).
 *
 * Verwendung:
 *   BMScanner.start({ container: el, onScan: fn(text), onError: fn(msg), onReady: fn() });
 *   BMScanner.stop();
 *
 * Es wird kein Bild übertragen — die Auswertung passiert vollständig im Browser.
 */
(function () {
    'use strict';

    var stream = null;
    var timer = null;
    var video = null;
    var lastCode = '';
    var lastAt = 0;

    var SCAN_INTERVAL = 200;   // ms zwischen zwei Frame-Auswertungen
    var REPEAT_BLOCK = 2500;   // ms, in denen derselbe Code ignoriert wird

    function fail(onError, message) {
        stop();
        if (typeof onError === 'function') { onError(message); }
    }

    function start(options) {
        options = options || {};
        var container = options.container;
        if (!container) { return; }

        stop();

        if (typeof jsQR !== 'function') {
            fail(options.onError, 'Die Scanner-Bibliothek konnte nicht geladen werden. Bitte den Code eintippen.');
            return;
        }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            fail(options.onError, 'Dieser Browser unterstützt keinen Kamera-Zugriff. Bitte den Code eintippen.');
            return;
        }

        video = document.createElement('video');
        video.setAttribute('playsinline', '');
        video.setAttribute('muted', '');
        video.muted = true;
        video.style.width = '100%';
        video.style.maxWidth = '420px';
        video.style.borderRadius = '10px';
        video.style.background = '#000';

        var canvas = document.createElement('canvas');
        var ctx = canvas.getContext('2d', { willReadFrequently: true });

        container.textContent = '';
        container.appendChild(video);

        navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } },
            audio: false
        }).then(function (mediaStream) {
            stream = mediaStream;
            video.srcObject = mediaStream;

            var playPromise = video.play();
            if (playPromise && typeof playPromise.catch === 'function') {
                playPromise.catch(function () { /* Autoplay-Blockade ist unkritisch */ });
            }

            video.addEventListener('loadedmetadata', function () {
                canvas.width = video.videoWidth || 640;
                canvas.height = video.videoHeight || 480;
                if (typeof options.onReady === 'function') { options.onReady(); }

                timer = setInterval(function () {
                    if (!video || video.readyState !== 4) { return; }
                    try {
                        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                        var frame = ctx.getImageData(0, 0, canvas.width, canvas.height);
                        var found = jsQR(frame.data, frame.width, frame.height, { inversionAttempts: 'dontInvert' });
                        if (!found || !found.data) { return; }

                        var now = Date.now();
                        if (found.data === lastCode && now - lastAt < REPEAT_BLOCK) { return; }
                        lastCode = found.data;
                        lastAt = now;

                        video.style.outline = '4px solid #2e7d4f';
                        setTimeout(function () {
                            if (video) { video.style.outline = 'none'; }
                        }, 600);

                        if (typeof options.onScan === 'function') { options.onScan(found.data); }
                    } catch (err) {
                        /* Einzelne Frames dürfen fehlschlagen (z. B. Tab im Hintergrund) */
                    }
                }, SCAN_INTERVAL);
            }, { once: true });
        }).catch(function (err) {
            var message = 'Die Kamera konnte nicht gestartet werden. Bitte den Code eintippen.';
            if (err && (err.name === 'NotAllowedError' || err.name === 'SecurityError')) {
                message = 'Der Kamera-Zugriff wurde abgelehnt. Erlaube ihn in den Browser-Einstellungen oder tippe den Code ein.';
            } else if (err && err.name === 'NotFoundError') {
                message = 'Es wurde keine Kamera gefunden. Bitte den Code eintippen.';
            }
            fail(options.onError, message);
        });
    }

    function stop() {
        if (timer) { clearInterval(timer); timer = null; }
        if (stream) {
            stream.getTracks().forEach(function (track) { track.stop(); });
            stream = null;
        }
        if (video) {
            video.srcObject = null;
            if (video.parentNode) { video.parentNode.removeChild(video); }
            video = null;
        }
        lastCode = '';
        lastAt = 0;
    }

    function isActive() {
        return stream !== null;
    }

    // Kamera freigeben, wenn die Seite verlassen wird
    window.addEventListener('pagehide', stop);

    window.BMScanner = { start: start, stop: stop, isActive: isActive };
})();
