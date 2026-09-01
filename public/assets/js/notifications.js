/**
 * Benachrichtigungs-Modal: öffnet sich bei ungelesenen Hinweisen und markiert
 * sie über die API als gelesen.
 *
 * Es öffnet BEWUSST nicht auf jeder Seite erneut: Wer es wegklickt, ohne
 * „Alles gelesen“ zu drücken, hatte es vorher auf jeder Folgeseite wieder vor
 * sich — am Messetag auf dem Handy ein Dauerärgernis. Das Wegklicken wird
 * deshalb für diese Sitzung gemerkt (sessionStorage, je Hinweis-Stand).
 * Die Hinweise selbst bleiben ungelesen und stehen weiter in der Glocke.
 *
 * Wird mit defer eingebunden (siehe partials/notifications-modal.php),
 * läuft also nach app.js und bei fertigem DOM.
 */
(function () {
    'use strict';

    var dialog = document.getElementById('notifications-modal');
    if (!dialog) { return; }

    // Kennung des aktuellen Hinweis-Stands: Kommt ein NEUER Hinweis dazu,
    // ändert sie sich und das Modal meldet sich wieder.
    var stamp = dialog.getAttribute('data-notifications-stamp') || '';
    var storageKey = 'bm.notifications.dismissed';

    function readDismissed() {
        try { return window.sessionStorage.getItem(storageKey); } catch (e) { return null; }
    }

    function markDismissed() {
        try { window.sessionStorage.setItem(storageKey, stamp); } catch (e) { /* privater Modus */ }
    }

    var alreadyDismissed = stamp !== '' && readDismissed() === stamp;

    if (!alreadyDismissed && typeof dialog.showModal === 'function' && !dialog.open) {
        try { dialog.showModal(); } catch (e) { /* z. B. bereits geöffnet */ }
    }

    // Schließen ohne „Alles gelesen“ → für diese Sitzung nicht wieder aufdrängen.
    dialog.addEventListener('close', markDismissed);

    var button = dialog.querySelector('[data-notifications-read]');
    if (!button) { return; }

    button.addEventListener('click', function () {
        var url = dialog.getAttribute('data-notifications-url');
        if (!url || !window.BM) { dialog.close(); return; }

        button.disabled = true;
        window.BM.fetchJson(url, { json: {} }).then(function (res) {
            if (res && res.success) {
                markDismissed();
                dialog.close();
                dialog.remove();
            } else {
                button.disabled = false;
                window.BM.flash('error', (res && res.error) || 'Die Hinweise konnten nicht markiert werden.');
            }
        }).catch(function () {
            button.disabled = false;
            window.BM.flash('error', 'Keine Verbindung zum Server.');
        });
    });
})();
