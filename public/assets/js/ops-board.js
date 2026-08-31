/**
 * Leitstand: aktualisiert die Kennzahlen des Messetags im Hintergrund.
 *
 * Der Server liefert unter /api/leitstand denselben Zustand, aus dem die
 * Seite gerendert wurde; hier werden nur die veränderlichen Stellen neu
 * geschrieben — markiert über data-ops-*.
 */
(function () {
    'use strict';

    var INTERVAL = 20000;

    var apiUrl = window.location.pathname.replace(/\/admin\/leitstand.*$/, '/api/leitstand');
    var toggle = document.querySelector('[data-ops-autorefresh]');
    var timer = null;

    function el(selector) {
        return document.querySelector(selector);
    }

    function text(node, value) {
        if (node) { node.textContent = value; }
    }

    /** Erzeugt eine Fortschrittszeile (Label, Zusatz, Balken). */
    function bar(label, meta, percent) {
        var wrap = document.createElement('div');

        var head = document.createElement('div');
        head.className = 'cluster';

        var name = document.createElement('span');
        name.style.flex = '1';
        name.style.minWidth = '140px';
        name.textContent = label;
        head.appendChild(name);

        var info = document.createElement('span');
        info.className = 'text-sm text-soft';
        info.textContent = meta;
        head.appendChild(info);

        var track = document.createElement('div');
        track.className = 'progress';
        var fill = document.createElement('span');
        fill.style.width = Math.min(100, percent) + '%';
        track.appendChild(fill);

        wrap.appendChild(head);
        wrap.appendChild(track);
        return wrap;
    }

    function renderSlot(slot) {
        var box = el('[data-ops-slot]');
        if (!box) { return; }
        box.textContent = '';

        if (!slot) {
            var empty = document.createElement('div');
            empty.className = 'empty-state';
            empty.innerHTML = '';
            var icon = document.createElement('div');
            icon.className = 'empty-icon';
            icon.textContent = '🕒';
            var p = document.createElement('p');
            p.textContent = 'Gerade läuft kein Zeitslot.';
            empty.appendChild(icon);
            empty.appendChild(p);
            box.appendChild(empty);
            return;
        }

        box.appendChild(bar(
            slot.label + '  ' + slot.start + '–' + slot.end,
            slot.progress + ' % vorbei' + (slot.is_break ? ' · Pause' : ''),
            slot.progress
        ));
    }

    function renderTotals(totals) {
        var box = el('[data-ops-totals]');
        if (!box) { return; }

        var values = [
            totals.slot_present,
            totals.slot_missing,
            totals.slot_quote + ' %',
            totals.day_quote + ' %'
        ];
        box.querySelectorAll('.stat-value').forEach(function (node, index) {
            if (index < values.length) { node.textContent = String(values[index]); }
        });

        // Rote Kachel nur, wenn wirklich jemand fehlt
        var missingCard = box.querySelectorAll('.stat-card')[1];
        if (missingCard) { missingCard.classList.toggle('stat-danger', totals.slot_missing > 0); }
    }

    function renderSilent(rows) {
        var box = el('[data-ops-silent]');
        if (!box) { return; }
        box.textContent = '';

        if (!rows.length) {
            var ok = document.createElement('p');
            ok.className = 'text-faint mb-0';
            ok.textContent = 'Bei allen Ausstellern ist jemand angekommen.';
            box.appendChild(ok);
            return;
        }

        var wrap = document.createElement('div');
        wrap.className = 'stack';
        rows.forEach(function (row) {
            var line = document.createElement('div');
            line.className = 'cluster';

            var name = document.createElement('strong');
            name.style.flex = '1';
            name.textContent = row.name;

            var meta = document.createElement('span');
            meta.className = 'text-sm text-soft';
            meta.textContent = (row.room_number ? 'Raum ' + row.room_number + ' · ' : '')
                + row.erwartet + ' erwartet';

            line.appendChild(name);
            line.appendChild(meta);
            wrap.appendChild(line);
        });
        box.appendChild(wrap);
    }

    function renderRooms(rooms) {
        var box = el('[data-ops-rooms]');
        if (!box) { return; }
        box.textContent = '';

        if (!rooms.length) {
            var none = document.createElement('p');
            none.className = 'text-faint mb-0';
            none.textContent = 'Keine Daten für den laufenden Slot.';
            box.appendChild(none);
            return;
        }

        var stack = document.createElement('div');
        stack.className = 'stack';
        rooms.forEach(function (room) {
            stack.appendChild(bar(
                room.label,
                room.present + ' / ' + room.capacity + (room.over ? ' — über Kapazität' : ''),
                room.load
            ));
        });
        box.appendChild(stack);
    }

    function refresh() {
        window.BM.fetchJson(apiUrl).then(function (data) {
            if (!data || !data.success) { return; }
            var state = data.state;

            text(el('[data-ops-time]'), state.time);
            renderSlot(state.slot);
            renderTotals(state.totals);
            renderSilent(state.silent_exhibitors);
            renderRooms(state.rooms);
        }).catch(function () {
            // Netzhänger am Messetag sind normal — beim nächsten Lauf erneut.
        });
    }

    function start() {
        if (timer === null) { timer = window.setInterval(refresh, INTERVAL); }
    }

    function stop() {
        if (timer !== null) { window.clearInterval(timer); timer = null; }
    }

    if (toggle) {
        toggle.addEventListener('change', function () {
            toggle.checked ? start() : stop();
        });
        if (toggle.checked) { start(); }
    } else {
        start();
    }

    // Im Hintergrund nicht weiterpollen
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stop();
        } else if (!toggle || toggle.checked) {
            refresh();
            start();
        }
    });
})();
