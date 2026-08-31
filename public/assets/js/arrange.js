/**
 * Anordnen-Modus: Drag & Drop der Seiten-Blöcke direkt auf der Seite.
 * Blöcke werden innerhalb ihres jeweiligen Eltern-Containers sortiert;
 * Speichern schickt die Gesamt-Reihenfolge + ausgeblendete Blöcke.
 */
(function () {
    'use strict';

    var bar = document.getElementById('arrange-bar');
    if (!bar) { return; }

    var dragged = null;

    document.addEventListener('dragstart', function (ev) {
        var block = ev.target.closest('.page-block.is-arranging');
        if (!block) { return; }
        dragged = block;
        block.classList.add('dragging');
        ev.dataTransfer.effectAllowed = 'move';
        try { ev.dataTransfer.setData('text/plain', block.getAttribute('data-block')); } catch (e) { /* IE-Erbe */ }
    });

    document.addEventListener('dragend', function () {
        if (dragged) { dragged.classList.remove('dragging'); }
        dragged = null;
    });

    document.addEventListener('dragover', function (ev) {
        if (!dragged) { return; }
        var over = ev.target.closest('.page-block.is-arranging');
        if (!over || over === dragged || over.parentElement !== dragged.parentElement) { return; }
        ev.preventDefault();
        var rect = over.getBoundingClientRect();
        var before = ev.clientY < rect.top + rect.height / 2;
        over.parentElement.insertBefore(dragged, before ? over : over.nextSibling);
    });

    // Ziel-Rolle wechseln → Seite mit anderer Rollen-Anordnung neu laden
    var roleSelect = bar.querySelector('[data-arrange-role]');
    if (roleSelect) {
        roleSelect.addEventListener('change', function () {
            var url = bar.getAttribute('data-exit-url') + '?anordnen=1';
            if (roleSelect.value !== '') { url += '&rolle=' + encodeURIComponent(roleSelect.value); }
            window.location.href = url;
        });
    }

    document.addEventListener('click', function (ev) {
        var toggle = ev.target.closest('[data-block-toggle]');
        if (toggle) {
            var block = toggle.closest('.page-block');
            var hidden = block.classList.toggle('block-hidden');
            toggle.setAttribute('aria-pressed', hidden ? 'true' : 'false');
            toggle.textContent = hidden ? '🚫 ausgeblendet' : '👁 sichtbar';
            return;
        }
        if (ev.target.closest('[data-arrange-save]')) { save(false); }
        if (ev.target.closest('[data-arrange-reset]')) {
            if (window.confirm('Anordnung dieser Seite auf den Standard zurücksetzen?')) { save(true); }
        }
    });

    function save(reset) {
        var payload = {
            page: bar.getAttribute('data-page'),
            role: bar.getAttribute('data-role') || '',
            order: [],
            hidden: []
        };
        if (reset) { payload.reset = true; }
        document.querySelectorAll('.page-block.is-arranging').forEach(function (block) {
            var key = block.getAttribute('data-block');
            payload.order.push(key);
            if (block.classList.contains('block-hidden')) { payload.hidden.push(key); }
        });

        BM.fetchJson(bar.getAttribute('data-save-url'), { json: payload }).then(function (res) {
            if (res.success) {
                window.location.href = bar.getAttribute('data-exit-url');
            } else {
                BM.flash('error', res.error || 'Speichern fehlgeschlagen.');
            }
        });
    }
})();
