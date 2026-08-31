/**
 * Aussteller-Portal & Aussteller-Konten:
 *   data-copy="<Text>"  → Text in die Zwischenablage kopieren
 * (Einladungslinks werden manuell verteilt, es wird keine E-Mail versendet.)
 */
(function () {
    'use strict';

    function notify(type, message) {
        if (window.BM && typeof window.BM.flash === 'function') {
            window.BM.flash(type, message);
        }
    }

    function fallbackCopy(text) {
        var field = document.createElement('textarea');
        field.value = text;
        field.setAttribute('readonly', 'readonly');
        field.style.position = 'fixed';
        field.style.opacity = '0';
        document.body.appendChild(field);
        field.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        document.body.removeChild(field);
        return ok;
    }

    document.addEventListener('click', function (ev) {
        var trigger = ev.target.closest('[data-copy]');
        if (!trigger) { return; }
        ev.preventDefault();

        var text = trigger.getAttribute('data-copy') || '';
        var label = trigger.textContent;

        function done() {
            notify('success', 'Link in die Zwischenablage kopiert.');
            trigger.textContent = '✅ Kopiert';
            setTimeout(function () { trigger.textContent = label; }, 2000);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function () {
                if (fallbackCopy(text)) { done(); } else { notify('error', 'Kopieren nicht möglich — bitte manuell markieren.'); }
            });
        } else if (fallbackCopy(text)) {
            done();
        } else {
            notify('error', 'Kopieren nicht möglich — bitte manuell markieren.');
        }
    });
})();
