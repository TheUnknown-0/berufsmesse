/**
 * Raumplanung: Aussteller per Drag & Drop oder Auswahlliste einem Raum
 * zuordnen. Gespeichert wird sofort über /api/raumplan/zuteilen.
 *
 * Markup-Konventionen (templates/pages/rooms/plan.php):
 *   [data-exhibitor="ID"]      → ziehbare Karte
 *   [data-room="ID"]           → Ablagefläche (0 = „nicht zugeteilt“)
 *   [data-room-list="ID"]      → Container, in den die Karte einsortiert wird
 *   [data-room-count="ID"]     → Zähler-Badge des Raums
 *   [data-move-exhibitor="ID"] → Auswahlliste als Fallback ohne Drag & Drop
 */
(function () {
    'use strict';

    var apiUrl = window.location.pathname.replace(/\/admin\/raumplan.*$/, '/api/raumplan/zuteilen');
    var dragged = null;

    function listFor(roomId) {
        return document.querySelector('[data-room-list="' + roomId + '"]');
    }

    /** Zähler und Leer-Hinweis eines Raums an den Inhalt anpassen. */
    function refreshRoom(roomId) {
        var list = listFor(roomId);
        if (!list) { return; }

        var count = list.querySelectorAll('[data-exhibitor]').length;
        var badge = document.querySelector('[data-room-count="' + roomId + '"]');
        if (badge) { badge.textContent = String(count); }

        var body = list.closest('[data-room]');
        var hint = body ? body.querySelector('[data-empty-hint]') : null;
        if (hint) { hint.hidden = count > 0; }

        // Warnung „mehrere Aussteller im Raum“ nachziehen
        if (body && roomId !== '0') {
            var warning = body.querySelector('[data-room-warning]');
            if (count > 1 && !warning) {
                warning = document.createElement('div');
                warning.className = 'alert alert-warning';
                warning.setAttribute('data-room-warning', roomId);
                body.insertBefore(warning, list);
            }
            if (warning) {
                warning.hidden = count <= 1;
                if (count > 1) {
                    warning.textContent = count + ' Aussteller in einem Raum — sie teilen sich die Plätze.';
                }
            }
        }
    }

    /** Karte in einen anderen Raum umhängen und speichern. */
    function move(card, roomId) {
        var previousList = card.parentElement;
        var target = listFor(roomId);
        if (!target || previousList === target) { return; }

        target.appendChild(card);
        refreshRoom(previousList.getAttribute('data-room-list'));
        refreshRoom(roomId);

        // Auswahlliste der Karte mitziehen
        var select = card.querySelector('[data-move-exhibitor]');
        if (select) { select.value = roomId; }

        window.BM.fetchJson(apiUrl, {
            json: {
                exhibitor_id: Number(card.getAttribute('data-exhibitor')),
                room_id: Number(roomId)
            }
        }).then(function (data) {
            if (!data || !data.success) {
                window.BM.flash('error', (data && data.error) || 'Die Zuteilung konnte nicht gespeichert werden.');
                return;
            }
            window.BM.flash('success', data.exhibitor.name + (Number(roomId) === 0
                ? ' ist jetzt ohne Raum.'
                : ' wurde dem Raum zugeteilt.'));
        }).catch(function () {
            window.BM.flash('error', 'Die Zuteilung konnte nicht gespeichert werden — bitte Seite neu laden.');
        });
    }

    // ---------- Drag & Drop ----------

    document.addEventListener('dragstart', function (ev) {
        var card = ev.target.closest('[data-exhibitor]');
        if (!card) { return; }
        dragged = card;
        card.style.opacity = '0.5';
        if (ev.dataTransfer) { ev.dataTransfer.effectAllowed = 'move'; }
    });

    document.addEventListener('dragend', function () {
        if (dragged) { dragged.style.opacity = ''; }
        dragged = null;
        document.querySelectorAll('[data-room]').forEach(function (zone) {
            zone.style.outline = '';
        });
    });

    document.addEventListener('dragover', function (ev) {
        var zone = ev.target.closest('[data-room]');
        if (!zone || !dragged) { return; }
        ev.preventDefault();
        if (ev.dataTransfer) { ev.dataTransfer.dropEffect = 'move'; }
        zone.style.outline = '2px dashed currentColor';
    });

    document.addEventListener('dragleave', function (ev) {
        var zone = ev.target.closest('[data-room]');
        if (zone) { zone.style.outline = ''; }
    });

    document.addEventListener('drop', function (ev) {
        var zone = ev.target.closest('[data-room]');
        if (!zone || !dragged) { return; }
        ev.preventDefault();
        zone.style.outline = '';
        move(dragged, zone.getAttribute('data-room'));
    });

    // ---------- Fallback ohne Drag & Drop ----------

    document.addEventListener('change', function (ev) {
        if (!ev.target.matches('[data-move-exhibitor]')) { return; }
        var card = ev.target.closest('[data-exhibitor]');
        if (card) { move(card, ev.target.value); }
    });
})();
