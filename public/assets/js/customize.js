/**
 * Darstellung: Drag & Drop für Navigations- und Widget-Anordnung.
 * Listen: ul[data-sortable] mit li[data-key]; Sichtbarkeit über
 * [data-toggle-hidden]; Speichern via BM.fetchJson.
 */
(function () {
    'use strict';

    var dragged = null;

    document.querySelectorAll('[data-sortable]').forEach(function (list) {
        list.addEventListener('dragstart', function (ev) {
            var item = ev.target.closest('.sort-item');
            if (!item) { return; }
            dragged = item;
            item.classList.add('dragging');
            ev.dataTransfer.effectAllowed = 'move';
        });
        list.addEventListener('dragend', function () {
            if (dragged) { dragged.classList.remove('dragging'); }
            dragged = null;
        });
        list.addEventListener('dragover', function (ev) {
            if (!dragged || dragged.parentElement !== list) { return; }
            ev.preventDefault();
            var after = null;
            list.querySelectorAll('.sort-item:not(.dragging)').forEach(function (item) {
                var rect = item.getBoundingClientRect();
                if (ev.clientY < rect.top + rect.height / 2 && after === null) { after = item; }
            });
            if (after === null) { list.appendChild(dragged); }
            else { list.insertBefore(dragged, after); }
        });
    });

    document.addEventListener('click', function (ev) {
        var toggle = ev.target.closest('[data-toggle-hidden]');
        if (toggle) {
            var item = toggle.closest('.sort-item');
            var hidden = item.classList.toggle('is-hidden');
            toggle.setAttribute('aria-pressed', hidden ? 'true' : 'false');
            toggle.textContent = hidden ? '🚫 ausgeblendet' : '👁 sichtbar';
            return;
        }
        if (ev.target.closest('[data-save-nav]')) { save('#nav-editor'); }
        if (ev.target.closest('[data-save-dashboard]')) { save('#dashboard-editor'); }
    });

    function collect(list) {
        var order = [], hidden = [];
        list.querySelectorAll('.sort-item').forEach(function (item) {
            order.push(item.getAttribute('data-key'));
            if (item.classList.contains('is-hidden')) { hidden.push(item.getAttribute('data-key')); }
        });
        return { order: order, hidden: hidden };
    }

    function save(rootSelector) {
        var root = document.querySelector(rootSelector);
        if (!root) { return; }
        var payload = {};
        root.querySelectorAll('[data-sortable]').forEach(function (list) {
            payload[list.getAttribute('data-section')] = collect(list);
        });
        BM.fetchJson(root.getAttribute('data-save-url'), { json: payload }).then(function (res) {
            if (res.success) {
                BM.flash('success', 'Anordnung gespeichert.');
            } else {
                BM.flash('error', res.error || 'Speichern fehlgeschlagen.');
            }
        });
    }
})();
