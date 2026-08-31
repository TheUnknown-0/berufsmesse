/**
 * Manuelle Anwesenheitspflege in der Verwaltungsliste.
 * Erwartet #attendance-table mit data-endpoint; je Zeile data-user/-exhibitor/-slot.
 */
(function () {
    'use strict';

    var table = document.getElementById('attendance-table');
    if (!table) { return; }

    var endpoint = table.getAttribute('data-endpoint');

    function badge(text, variant) {
        var el = document.createElement('span');
        el.className = 'badge' + (variant ? ' badge-' + variant : '');
        el.textContent = text;
        return el;
    }

    table.addEventListener('click', function (ev) {
        var button = ev.target.closest('.js-mark');
        if (!button || button.disabled) { return; }

        var row = button.closest('tr');
        var action = button.getAttribute('data-action');
        var payload = {
            action: action,
            user_id: parseInt(row.getAttribute('data-user'), 10),
            exhibitor_id: parseInt(row.getAttribute('data-exhibitor'), 10),
            timeslot_id: parseInt(row.getAttribute('data-slot'), 10)
        };

        button.disabled = true;
        BM.fetchJson(endpoint, { json: payload }).then(function (data) {
            button.disabled = false;
            if (!data.success) {
                BM.flash('error', data.error || 'Die Änderung wurde nicht gespeichert.');
                return;
            }

            // Die Zeile spiegelt danach immer den Stand der Datenbank wider —
            // auch wenn die Seite veraltet war ("bereits" / "nicht_vorhanden").
            var status = row.querySelector('.js-status');
            status.textContent = '';
            if (action === 'anwesend') {
                status.appendChild(badge('✓ ' + (data.checked_in_at || ''), 'success'));
                if (data.status === 'gesetzt') { status.appendChild(badge('Manuell')); }
                button.setAttribute('data-action', 'abwesend');
                button.textContent = 'Zurücksetzen';
            } else {
                status.appendChild(badge('fehlt', 'danger'));
                button.setAttribute('data-action', 'anwesend');
                button.textContent = 'Anwesend';
            }
            BM.flash('success', data.message);
        }).catch(function () {
            button.disabled = false;
            BM.flash('error', 'Keine Verbindung zum Server.');
        });
    });
})();
