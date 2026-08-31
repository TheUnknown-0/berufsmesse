/**
 * Lehrer-Scanner: Schüler-QR-Codes scannen, Raum-Roster live abgleichen.
 * Erwartet #scan-app mit data-checkin-url und data-roster-url.
 */
(function () {
    'use strict';

    var app = document.getElementById('scan-app');
    if (!app) { return; }

    var checkinUrl = app.getAttribute('data-checkin-url');
    var rosterUrl = app.getAttribute('data-roster-url');

    var roomSelect = document.getElementById('scan-room');
    var slotSelect = document.getElementById('scan-slot');
    var resultBox = document.getElementById('scan-result');
    var cameraBox = document.getElementById('scan-camera');
    var startBtn = document.getElementById('scan-start');
    var stopBtn = document.getElementById('scan-stop');
    var form = document.getElementById('scan-form');
    var input = document.getElementById('scan-token');
    var refreshBtn = document.getElementById('scan-refresh');

    var presentBox = document.getElementById('scan-present');
    var missingBox = document.getElementById('scan-missing');
    var counter = document.getElementById('scan-counter');
    var roomLabel = document.getElementById('scan-room-label');

    var dialog = document.getElementById('scan-confirm');
    var dialogText = document.getElementById('scan-confirm-text');
    var dialogOk = document.getElementById('scan-confirm-ok');

    var busy = false;
    var pending = null; // Zurückgestellter Check-in bei falschem Raum

    function show(type, message) {
        resultBox.textContent = '';
        var box = document.createElement('div');
        box.className = 'alert alert-' + type;
        box.textContent = message;
        resultBox.appendChild(box);
    }

    function person(entry, extra) {
        var row = document.createElement('div');
        row.className = 'cluster';
        row.style.justifyContent = 'space-between';

        var name = document.createElement('span');
        name.textContent = entry.name + (entry.class ? ' · ' + entry.class : '');
        row.appendChild(name);

        if (extra) { row.appendChild(extra); }
        return row;
    }

    function badge(text, variant) {
        var el = document.createElement('span');
        el.className = 'badge' + (variant ? ' badge-' + variant : '');
        el.textContent = text;
        return el;
    }

    function renderRoster(data) {
        presentBox.textContent = '';
        missingBox.textContent = '';

        roomLabel.textContent = data.exhibitor
            ? data.room + ' — ' + data.exhibitor
            : data.room + ' — kein aktiver Aussteller';
        counter.textContent = data.present_count + ' / ' + data.expected_count;

        if (!data.present.length) {
            presentBox.appendChild(badge('Noch niemand eingecheckt.'));
        }
        data.present.forEach(function (entry) {
            var tags = document.createElement('span');
            tags.className = 'chip-row';
            tags.appendChild(badge(entry.time, 'success'));
            if (entry.wrong_room) { tags.appendChild(badge('falscher Raum', 'warning')); }
            presentBox.appendChild(person(entry, tags));
        });

        if (!data.missing.length) {
            missingBox.appendChild(badge('Vollzählig.', 'success'));
        }
        data.missing.forEach(function (entry) {
            var btn = document.createElement('button');
            btn.className = 'btn btn-sm btn-ghost';
            btn.type = 'button';
            btn.textContent = 'Manuell einchecken';
            btn.addEventListener('click', function () {
                sendCheckin({ student_user_id: entry.id }, false);
            });
            missingBox.appendChild(person(entry, btn));
        });
    }

    function loadRoster() {
        var url = rosterUrl + '?room=' + encodeURIComponent(roomSelect.value)
            + '&slot=' + encodeURIComponent(slotSelect.value);

        BM.fetchJson(url, { method: 'GET' }).then(function (data) {
            if (data.success) {
                renderRoster(data);
            } else {
                roomLabel.textContent = data.error || 'Der Raum-Abgleich ist nicht verfügbar.';
            }
        }).catch(function () {
            roomLabel.textContent = 'Keine Verbindung zum Server.';
        });
    }

    function sendCheckin(payload, force) {
        if (busy) { return; }
        busy = true;

        payload.room_id = parseInt(roomSelect.value, 10);
        payload.timeslot_id = parseInt(slotSelect.value, 10);
        if (force) { payload.force = 1; }

        BM.fetchJson(checkinUrl, { json: payload }).then(function (data) {
            busy = false;

            if (data.status === 'requires_confirmation') {
                pending = payload;
                dialogText.textContent = data.message;
                if (dialog && typeof dialog.showModal === 'function') {
                    dialog.showModal();
                } else {
                    show('warning', data.message);
                }
                return;
            }

            if (data.success) {
                show(data.status === 'already' ? 'info' : 'success', data.message);
                if (input) { input.value = ''; }
                loadRoster();
            } else {
                show('error', data.error || data.message || 'Der Check-in hat nicht geklappt.');
            }
        }).catch(function () {
            busy = false;

            // Kein Netz: Scan puffern statt verwerfen (siehe scan-queue.js).
            if (window.BMQueue) {
                window.BMQueue.push(checkinUrl, payload);
                show('warning', 'Offline — der Scan ist gespeichert und wird nachgetragen.');
                if (input) { input.value = ''; }
                return;
            }
            show('error', 'Keine Verbindung zum Server. Bitte erneut versuchen.');
        });
    }

    // Anzeige der wartenden Scans
    if (window.BMQueue) {
        var queueBadge = document.querySelector('[data-scan-queue]');
        window.BMQueue.onChange(function (count) {
            if (!queueBadge) { return; }
            queueBadge.hidden = count === 0;
            queueBadge.textContent = count === 1
                ? '1 Scan wartet auf Übertragung'
                : count + ' Scans warten auf Übertragung';
        });
    }

    if (dialogOk) {
        dialogOk.addEventListener('click', function () {
            if (dialog) { dialog.close(); }
            if (pending) {
                var payload = pending;
                pending = null;
                sendCheckin(payload, true);
            }
        });
    }
    if (dialog) {
        dialog.addEventListener('close', function () { pending = null; });
    }

    startBtn.addEventListener('click', function () {
        startBtn.hidden = true;
        stopBtn.hidden = false;
        show('info', 'Bereit — persönliche QR-Codes der Schüler:innen scannen.');
        BMScanner.start({
            container: cameraBox,
            onScan: function (text) { sendCheckin({ student_token: text }, false); },
            onError: function (message) {
                startBtn.hidden = false;
                stopBtn.hidden = true;
                show('warning', message);
            }
        });
    });

    stopBtn.addEventListener('click', function () {
        BMScanner.stop();
        cameraBox.textContent = '';
        startBtn.hidden = false;
        stopBtn.hidden = true;
    });

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var token = (input.value || '').trim();
        if (!token) {
            show('warning', 'Bitte zuerst einen Code eingeben.');
            return;
        }
        sendCheckin({ student_token: token }, false);
    });

    refreshBtn.addEventListener('click', loadRoster);
    roomSelect.addEventListener('change', loadRoster);
    slotSelect.addEventListener('change', loadRoster);

    loadRoster();
    setInterval(loadRoster, 20000);
})();
