/**
 * Schüler-Einschreibung und Schüler-Seiten.
 *
 *   [data-registration-form]  Formular der Einschreibung (data-max = Maximum)
 *   select[data-priority]     Prioritätsauswahl je Aussteller
 *   [data-exhibitor-filter]   Suchfeld, filtert [data-exhibitor-row]
 *   [data-selected-count]     Anzeige der aktuellen Auswahl
 *   [data-selection-hint]     Hinweistext unter dem Speichern-Button
 *   [data-print]              öffnet den Druckdialog (Druckansicht)
 */
(function () {
    'use strict';

    // ---------- Druckdialog ----------
    document.addEventListener('click', function (ev) {
        var trigger = ev.target.closest('[data-print]');
        if (trigger) {
            ev.preventDefault();
            window.print();
        }
    });

    var form = document.querySelector('[data-registration-form]');
    if (!form) { return; }

    var max = parseInt(form.getAttribute('data-max'), 10) || 3;
    var selects = Array.prototype.slice.call(form.querySelectorAll('select[data-priority]'));
    var counter = document.querySelector('[data-selected-count]');
    var hint = document.querySelector('[data-selection-hint]');
    var filter = document.querySelector('[data-exhibitor-filter]');

    function chosen() {
        return selects.filter(function (s) { return s.value !== ''; });
    }

    function update() {
        var picked = chosen();
        if (counter) { counter.textContent = String(picked.length); }
        if (!hint) { return; }

        if (picked.length > max) {
            hint.textContent = 'Du hast mehr als ' + max + ' Aussteller gewählt — bitte reduziere die Auswahl.';
        } else if (picked.length === 0) {
            hint.textContent = 'Noch nichts gewählt.';
        } else {
            hint.textContent = 'Noch ' + (max - picked.length) + ' Auswahl(en) möglich.';
        }
    }

    // Jede Priorität darf nur einmal vergeben werden: die vorherige Zuweisung
    // derselben Priorität wird automatisch geleert.
    selects.forEach(function (select) {
        select.addEventListener('change', function () {
            if (select.value !== '') {
                selects.forEach(function (other) {
                    if (other !== select && other.value === select.value) {
                        other.value = '';
                    }
                });
            }
            update();
        });
    });

    form.addEventListener('submit', function (ev) {
        if (chosen().length > max) {
            ev.preventDefault();
            if (window.BM && window.BM.flash) {
                window.BM.flash('error', 'Bitte wähle höchstens ' + max + ' Aussteller.');
            }
        }
    });

    if (filter) {
        filter.addEventListener('input', function () {
            var term = filter.value.trim().toLowerCase();
            document.querySelectorAll('[data-exhibitor-row]').forEach(function (row) {
                var haystack = row.getAttribute('data-search') || '';
                var visible = term === '' || haystack.indexOf(term) !== -1;
                // Bereits gewählte Zeilen bleiben immer sichtbar
                if (!visible) {
                    var select = row.querySelector('select[data-priority]');
                    visible = !!(select && select.value !== '');
                }
                row.hidden = !visible;
            });
        });
    }

    update();
})();
