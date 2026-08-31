/**
 * Benutzerverwaltung: Passwort-Reset-Modal vorbereiten, einmalig erzeugtes
 * Passwort anzeigen und in die Zwischenablage kopieren.
 * Konventionen (Markup in templates/pages/users/index.php):
 *   [data-reset-action] [data-reset-name] → füllt #modal-passwort
 *   [data-auto-open="1"] auf <dialog>     → beim Laden öffnen
 *   [data-copy-target="id"]               → Wert des Feldes kopieren
 */
(function () {
    'use strict';

    // Vor dem Öffnen (app.js reagiert in der Bubble-Phase) das Ziel eintragen.
    document.addEventListener('click', function (ev) {
        var trigger = ev.target.closest('[data-reset-action]');
        if (!trigger) { return; }

        var dialog = document.getElementById('modal-passwort');
        if (!dialog) { return; }

        var form = dialog.querySelector('[data-reset-form]');
        if (form) { form.setAttribute('action', trigger.getAttribute('data-reset-action')); }

        var target = dialog.querySelector('[data-reset-target]');
        if (target) { target.textContent = trigger.getAttribute('data-reset-name') || ''; }

        var preselect = dialog.querySelector('input[name="modus"][value="zufaellig"]');
        if (preselect) { preselect.checked = true; }
    }, true);

    // Einmalige Anzeige des frisch erzeugten Passworts
    document.querySelectorAll('dialog[data-auto-open="1"]').forEach(function (dialog) {
        if (typeof dialog.showModal === 'function') { dialog.showModal(); }
    });

    // Kopier-Knopf
    document.addEventListener('click', function (ev) {
        var button = ev.target.closest('[data-copy-target]');
        if (!button) { return; }

        var field = document.getElementById(button.getAttribute('data-copy-target'));
        if (!field) { return; }

        var done = function () { window.BM.flash('success', 'Passwort in die Zwischenablage kopiert.'); };
        var failed = function () { window.BM.flash('warning', 'Kopieren nicht möglich — bitte manuell markieren.'); };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(field.value).then(done, failed);
            return;
        }
        try {
            field.select();
            document.execCommand('copy') ? done() : failed();
        } catch (e) {
            failed();
        }
    });
})();
