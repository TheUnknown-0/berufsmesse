/**
 * Aussteller per Drag & Drop oder Auswahlliste einem Raum zuordnen.
 * Wird von zwei Seiten genutzt: der Raumplanung und der Räume-Übersicht.
 *
 * Markup-Konventionen:
 *   [data-roomplan]            → Wurzel; data-assign-url trägt den Endpunkt,
 *                                data-reload="1" lädt nach dem Ablegen neu
 *   [data-exhibitor="ID"]      → ziehbare Karte
 *   [data-room="ID"]           → Ablagefläche (0 = „nicht zugeteilt“)
 *   [data-room-list="ID"]      → Container, in den die Karte einsortiert wird
 *   [data-room-count="ID"]     → Zähler-Badge des Raums
 *   [data-move-exhibitor="ID"] → Auswahlliste als Fallback ohne Drag & Drop
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-roomplan]');
    if (!root) { return; }

    var apiUrl = root.getAttribute('data-assign-url');
    // Seiten mit statischen Formularen (Räume-Übersicht) müssen nach dem
    // Verschieben neu laden — sonst zeigen die Auswahllisten „Aussteller
    // zuweisen“ weiter Namen an, die längst einen Raum haben.
    var reloadAfterMove = root.getAttribute('data-reload') === '1';
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

        // Auf Seiten, die gleich neu laden, sieht eine verschobene Karte kurz
        // fremd aus (die Übersicht stellt zugeteilte und offene Aussteller
        // unterschiedlich dar). Statt das zu kaschieren, wird der Zwischen-
        // zustand als „wird gespeichert“ kenntlich gemacht.
        if (reloadAfterMove) { card.classList.add('is-saving'); }

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
                card.classList.remove('is-saving');
                window.BM.flash('error', (data && data.error) || 'Die Zuteilung konnte nicht gespeichert werden.');
                return;
            }
            window.BM.flash('success', data.exhibitor.name + (Number(roomId) === 0
                ? ' ist jetzt ohne Raum.'
                : ' wurde dem Raum zugeteilt.'));

            if (reloadAfterMove) {
                window.setTimeout(function () { window.location.reload(); }, 700);
            }
        }).catch(function () {
            card.classList.remove('is-saving');
            window.BM.flash('error', 'Die Zuteilung konnte nicht gespeichert werden — bitte Seite neu laden.');
        });
    }

    // ---------- Drag & Drop ----------

    /**
     * Hebt genau EINE Ablagefläche hervor.
     *
     * Beim schnellen Ziehen über mehrere Räume verschluckt der Browser gern
     * ein `dragleave` — dann blieben mehrere Flächen gleichzeitig markiert und
     * es war nicht mehr erkennbar, wo die Karte landen würde. Statt auf
     * `dragleave` zu vertrauen, wird die vorige Markierung hier immer aktiv
     * entfernt (dieselbe Logik nutzt auch die Finger-Bedienung weiter unten).
     */
    var mouseZone = null;

    function highlightMouse(zone) {
        if (zone === mouseZone) { return; }
        if (mouseZone) { mouseZone.classList.remove('is-drop-target'); }
        if (zone) { zone.classList.add('is-drop-target'); }
        mouseZone = zone;
    }

    document.addEventListener('dragstart', function (ev) {
        var card = ev.target.closest('[data-exhibitor]');
        if (!card) { return; }
        dragged = card;
        card.classList.add('is-dragging');
        if (ev.dataTransfer) { ev.dataTransfer.effectAllowed = 'move'; }
    });

    document.addEventListener('dragend', function () {
        if (dragged) { dragged.classList.remove('is-dragging'); }
        dragged = null;
        highlightMouse(null);
        // Sicherheitsnetz, falls doch eine Markierung haengen geblieben ist.
        document.querySelectorAll('[data-room].is-drop-target').forEach(function (zone) {
            zone.classList.remove('is-drop-target');
        });
    });

    document.addEventListener('dragover', function (ev) {
        var zone = ev.target.closest('[data-room]');
        if (!zone || !dragged) { return; }
        ev.preventDefault();
        if (ev.dataTransfer) { ev.dataTransfer.dropEffect = 'move'; }
        highlightMouse(zone);
    });

    document.addEventListener('drop', function (ev) {
        var zone = ev.target.closest('[data-room]');
        if (!zone || !dragged) { return; }
        ev.preventDefault();
        highlightMouse(null);
        move(dragged, zone.getAttribute('data-room'));
    });

    // ---------- Ziehen mit dem Finger (Tablet, Handy) ----------
    //
    // HTML5-Drag-and-Drop gibt es auf Touch-Geräten nicht — dort passiert mit
    // den Handlern oben schlicht nichts. Dieselbe Bedienung wird deshalb über
    // Pointer-Events nachgebaut: Karte kurz halten, dann zieht eine schwebende
    // Kopie mit dem Finger mit.
    //
    // Warum erst nach kurzem Halten? Griffe die Geste sofort beim Aufsetzen,
    // ließe sich die Seite über den Karten nicht mehr scrollen — bei einer
    // langen Ausstellerliste unbrauchbar. Bewegt sich der Finger vorher, war
    // es eine Wischgeste und wir halten uns raus.

    var HOLD_MS = 220;      // Halten, bis das Ziehen beginnt
    var MOVE_TOLERANCE = 10; // Wackeln in Pixeln, das noch als Halten zählt

    var touch = {
        card: null,      // Karte, sobald das Ziehen läuft
        candidate: null, // Karte, solange nur gehalten wird
        timer: null,
        ghost: null,
        zone: null,
        startX: 0,
        startY: 0,
        pointerId: null
    };

    function createGhost(card, x, y) {
        var rect = card.getBoundingClientRect();
        var ghost = card.cloneNode(true);
        var select = ghost.querySelector('[data-move-exhibitor]');
        if (select) { select.remove(); } // Bedienelemente in der Kopie stören nur

        ghost.removeAttribute('data-exhibitor');
        ghost.className = card.className + ' drag-ghost';
        ghost.style.width = rect.width + 'px';
        ghost.style.left = (x - rect.width / 2) + 'px';
        ghost.style.top = (y - rect.height / 2) + 'px';
        document.body.appendChild(ghost);

        return ghost;
    }

    function zoneAt(x, y) {
        var stack = document.elementsFromPoint(x, y) || [];
        for (var i = 0; i < stack.length; i++) {
            var zone = stack[i].closest ? stack[i].closest('[data-room]') : null;
            if (zone) { return zone; }
        }

        return null;
    }

    function highlight(zone) {
        if (zone === touch.zone) { return; }
        if (touch.zone) { touch.zone.classList.remove('is-drop-target'); }
        if (zone) { zone.classList.add('is-drop-target'); }
        touch.zone = zone;
    }

    /** Am oberen/unteren Rand mitscrollen, damit weit entfernte Räume erreichbar bleiben. */
    function edgeScroll(y) {
        var margin = 80;
        if (y < margin) {
            window.scrollBy(0, -12);
        } else if (y > window.innerHeight - margin) {
            window.scrollBy(0, 12);
        }
    }

    function resetTouch() {
        if (touch.timer) { window.clearTimeout(touch.timer); }
        if (touch.ghost) { touch.ghost.remove(); }
        if (touch.card) {
            touch.card.classList.remove('is-dragging');
            touch.card.style.touchAction = '';
        }
        highlight(null);
        touch.card = null;
        touch.candidate = null;
        touch.timer = null;
        touch.ghost = null;
        touch.pointerId = null;
    }

    document.addEventListener('pointerdown', function (ev) {
        if (ev.pointerType === 'mouse' || !ev.isPrimary) { return; }

        var card = ev.target.closest('[data-exhibitor]');
        if (!card || !card.hasAttribute('draggable')) { return; }
        // Auf der Auswahlliste soll die Liste bedienbar bleiben.
        if (ev.target.closest('select, button, a, input')) { return; }

        touch.candidate = card;
        touch.startX = ev.clientX;
        touch.startY = ev.clientY;
        touch.pointerId = ev.pointerId;

        touch.timer = window.setTimeout(function () {
            touch.card = touch.candidate;
            touch.candidate = null;
            if (!touch.card) { return; }

            // Erst jetzt das Scrollen abschalten — der Finger ruht bereits,
            // es läuft also keine Scrollbewegung, die wir abwürgen würden.
            touch.card.style.touchAction = 'none';
            touch.card.classList.add('is-dragging');
            touch.ghost = createGhost(touch.card, touch.startX, touch.startY);

            try { touch.card.setPointerCapture(touch.pointerId); } catch (e) { /* egal */ }
            if (window.navigator.vibrate) { window.navigator.vibrate(15); }
        }, HOLD_MS);
    });

    document.addEventListener('pointermove', function (ev) {
        // Noch in der Haltephase: Wischt der Finger, war Scrollen gemeint.
        if (touch.candidate) {
            if (Math.abs(ev.clientX - touch.startX) > MOVE_TOLERANCE
                || Math.abs(ev.clientY - touch.startY) > MOVE_TOLERANCE) {
                window.clearTimeout(touch.timer);
                touch.candidate = null;
                touch.timer = null;
            }
            return;
        }

        if (!touch.card || !touch.ghost) { return; }
        ev.preventDefault();

        var rect = touch.ghost.getBoundingClientRect();
        touch.ghost.style.left = (ev.clientX - rect.width / 2) + 'px';
        touch.ghost.style.top = (ev.clientY - rect.height / 2) + 'px';

        highlight(zoneAt(ev.clientX, ev.clientY));
        edgeScroll(ev.clientY);
    }, { passive: false });

    document.addEventListener('pointerup', function (ev) {
        if (!touch.card) { resetTouch(); return; }

        var zone = zoneAt(ev.clientX, ev.clientY);
        var card = touch.card;
        resetTouch();

        if (zone) { move(card, zone.getAttribute('data-room')); }
    });

    document.addEventListener('pointercancel', resetTouch);

    // ---------- Fallback ohne Drag & Drop ----------

    document.addEventListener('change', function (ev) {
        if (!ev.target.matches('[data-move-exhibitor]')) { return; }
        var card = ev.target.closest('[data-exhibitor]');
        if (card) { move(card, ev.target.value); }
    });
})();
