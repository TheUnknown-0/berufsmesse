/**
 * Orga-Tool: Countdown, Board (Status per Ziehen) und Mitgliederzuordnung.
 *
 *   [data-countdown="ISO"]   zählt jede Sekunde herunter
 *   [data-board]             Karten [data-task] zwischen Spalten [data-status] ziehen
 *   [data-member-form]       Leitung setzt Mitgliedschaft; Filterfeld für lange Listen
 */
(function () {
    'use strict';

    // ---------- Countdown ----------
    function pad(n) { return (n < 10 ? '0' : '') + n; }

    function tick() {
        document.querySelectorAll('[data-countdown]').forEach(function (box) {
            var target = new Date(box.getAttribute('data-countdown')).getTime();
            var diff = Math.max(0, Math.floor((target - Date.now()) / 1000));
            var values = {
                d: Math.floor(diff / 86400),
                h: Math.floor((diff % 86400) / 3600),
                m: Math.floor((diff % 3600) / 60),
                s: diff % 60
            };
            Object.keys(values).forEach(function (unit) {
                var el = box.querySelector('[data-unit="' + unit + '"]');
                if (el) { el.textContent = pad(values[unit]); }
            });
            box.classList.toggle('is-over', diff === 0);
            var over = box.parentNode.querySelector('[data-countdown-over]');
            if (over) { over.hidden = diff !== 0; }
        });
    }
    if (document.querySelector('[data-countdown]')) {
        tick();
        setInterval(tick, 1000);
    }

    // ---------- Board ----------
    // Delegiert, damit es auch nach dem Austausch durch die Live-Suche greift.
    var dragged = null;
    var sourceCol = null;

    function updateCounts(board) {
        board.querySelectorAll('.board-col').forEach(function (col) {
            var counter = col.querySelector('[data-col-count]');
            if (counter) { counter.textContent = col.querySelectorAll('[data-task]').length; }
        });
    }

    document.addEventListener('dragstart', function (ev) {
        var card = ev.target.closest && ev.target.closest('[data-board] [data-task][draggable="true"]');
        if (!card) { return; }
        dragged = card;
        sourceCol = card.closest('.board-col');
        card.classList.add('is-dragging');
        ev.dataTransfer.effectAllowed = 'move';
        ev.dataTransfer.setData('text/plain', card.getAttribute('data-task'));
    });

    document.addEventListener('dragend', function () {
        if (dragged) { dragged.classList.remove('is-dragging'); }
        document.querySelectorAll('.board-col.is-drop-target').forEach(function (c) { c.classList.remove('is-drop-target'); });
        dragged = null;
    });

    document.addEventListener('dragover', function (ev) {
        if (!dragged) { return; }
        var col = ev.target.closest && ev.target.closest('[data-board] .board-col');
        if (!col) { return; }
        ev.preventDefault();
        ev.dataTransfer.dropEffect = 'move';
        document.querySelectorAll('.board-col.is-drop-target').forEach(function (c) {
            if (c !== col) { c.classList.remove('is-drop-target'); }
        });
        col.classList.add('is-drop-target');
    });

    document.addEventListener('drop', function (ev) {
        if (!dragged) { return; }
        var col = ev.target.closest && ev.target.closest('[data-board] .board-col');
        if (!col) { return; }
        ev.preventDefault();
        col.classList.remove('is-drop-target');
        if (col === sourceCol) { return; }

        var card = dragged;
        var from = sourceCol;
        var board = col.closest('[data-board]');
        var status = col.getAttribute('data-status');
        var url = board.getAttribute('data-status-url').replace('{id}', card.getAttribute('data-task'));

        col.appendChild(card);
        updateCounts(board);

        BM.fetchJson(url, { json: { status: status } }).then(function (res) {
            if (res && res.success) {
                BM.flash('success', 'Status: ' + res.label);
                if (status === 'done') { card.classList.remove('is-overdue'); }
            } else {
                from.appendChild(card);
                updateCounts(board);
                BM.flash('error', (res && res.error) || 'Status konnte nicht gespeichert werden.');
            }
        }).catch(function () {
            from.appendChild(card);
            updateCounts(board);
            BM.flash('error', 'Keine Verbindung zum Server.');
        });
    });

    // ---------- Mitgliederzuordnung ----------
    document.addEventListener('change', function (ev) {
        var form = ev.target.closest && ev.target.closest('[data-member-form]');
        if (!form) { return; }
        var row = ev.target.closest('[data-member-row]');
        if (!row) { return; }
        var member = row.querySelector('[data-member]');
        var leader = row.querySelector('[data-leader]');
        // Leitung ist immer auch Mitglied; wer austritt, verliert die Leitung.
        if (ev.target === leader && leader.checked) { member.checked = true; }
        if (ev.target === member && !member.checked) { leader.checked = false; }
    });

    document.addEventListener('input', function (ev) {
        if (!ev.target.matches || !ev.target.matches('[data-member-filter]')) { return; }
        var needle = ev.target.value.trim().toLowerCase();
        ev.target.closest('form').querySelectorAll('[data-member-row]').forEach(function (row) {
            row.hidden = needle !== '' && row.getAttribute('data-search').indexOf(needle) === -1;
        });
    });
})();
