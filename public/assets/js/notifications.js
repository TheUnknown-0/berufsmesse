/**
 * Benachrichtigungs-Modal: öffnet sich automatisch, wenn ungelesene
 * Hinweise vorliegen, und markiert sie über die API als gelesen.
 * Wird mit defer eingebunden (siehe partials/notifications-modal.php),
 * läuft also nach app.js und bei fertigem DOM.
 */
(function () {
    'use strict';

    var dialog = document.getElementById('notifications-modal');
    if (!dialog) { return; }

    if (typeof dialog.showModal === 'function' && !dialog.open) {
        try { dialog.showModal(); } catch (e) { /* z. B. bereits geöffnet */ }
    }

    var button = dialog.querySelector('[data-notifications-read]');
    if (!button) { return; }

    button.addEventListener('click', function () {
        var url = dialog.getAttribute('data-notifications-url');
        if (!url || !window.BM) { dialog.close(); return; }

        button.disabled = true;
        window.BM.fetchJson(url, { json: {} }).then(function (res) {
            if (res && res.success) {
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
