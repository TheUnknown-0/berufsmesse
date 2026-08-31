/**
 * Selbst-Check-in: Kamera-Scan und manuelle Code-Eingabe.
 * Erwartet #checkin-app mit data-endpoint und optionalem data-token.
 */
(function () {
    'use strict';

    var app = document.getElementById('checkin-app');
    if (!app) { return; }

    var endpoint = app.getAttribute('data-endpoint');
    var resultBox = document.getElementById('checkin-result');
    var cameraBox = document.getElementById('checkin-camera');
    var startBtn = document.getElementById('checkin-start');
    var stopBtn = document.getElementById('checkin-stop');
    var form = document.getElementById('checkin-form');
    var input = document.getElementById('checkin-token');
    var busy = false;

    function show(type, message) {
        resultBox.textContent = '';
        var box = document.createElement('div');
        box.className = 'alert alert-' + type;
        box.textContent = message;
        resultBox.appendChild(box);
    }

    function setCameraRunning(running) {
        startBtn.hidden = running;
        stopBtn.hidden = !running;
    }

    function submit(token) {
        if (busy) { return; }
        token = (token || '').trim();
        if (!token) {
            show('warning', 'Bitte zuerst einen Code eingeben.');
            return;
        }

        busy = true;
        show('info', 'Check-in läuft …');

        BM.fetchJson(endpoint, { json: { token: token } }).then(function (data) {
            busy = false;
            if (data.success) {
                show(data.already ? 'info' : 'success', data.message);
                if (input) { input.value = ''; }
            } else {
                show('error', data.error || 'Der Check-in hat nicht geklappt.');
            }
        }).catch(function () {
            busy = false;
            show('error', 'Keine Verbindung zum Server. Bitte erneut versuchen.');
        });
    }

    startBtn.addEventListener('click', function () {
        setCameraRunning(true);
        show('info', 'Halte den QR-Code am Stand vor die Kamera.');
        BMScanner.start({
            container: cameraBox,
            onScan: function (text) { submit(text); },
            onError: function (message) {
                setCameraRunning(false);
                show('warning', message);
            }
        });
    });

    stopBtn.addEventListener('click', function () {
        BMScanner.stop();
        cameraBox.textContent = '';
        setCameraRunning(false);
    });

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        submit(input.value);
    });

    // Token aus der URL (?token=…) direkt verarbeiten
    var prefill = app.getAttribute('data-token');
    if (prefill) { submit(prefill); }
})();
