/**
 * Versionsdialog (templates/partials/version-modal.php).
 *
 * - Öffnet den Dialog, wenn die Adresse auf #version endet (Ziel der
 *   Update-Benachrichtigung).
 * - data-version-auto: Prüfung nach der Anmeldung bzw. einmal täglich,
 *   ohne dass die Seite auf GitHub warten muss.
 * - [data-version-check]: Knopf „Nach neuen Versionen suchen“.
 * - [data-update-banner]: Update-Hinweis oben auf der Seite; Ausblenden gilt
 *   für diese Version bis zum Ende der Browsersitzung.
 */
(function () {
    'use strict';

    var dialog = document.getElementById('version-modal');
    if (!dialog) { return; }

    var url = dialog.getAttribute('data-version-url');
    var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var status = dialog.querySelector('[data-version-status]');
    var button = dialog.querySelector('[data-version-check]');

    function dismissKey(version) { return 'update-banner-hidden:' + version; }

    function isDismissed(version) {
        try { return sessionStorage.getItem(dismissKey(version)) === '1'; } catch (e) { return false; }
    }

    function setupBanner(banner) {
        var version = banner.getAttribute('data-update-banner');
        if (isDismissed(version)) { banner.remove(); return; }
        banner.hidden = false;
        var close = banner.querySelector('[data-update-banner-close]');
        if (close) {
            close.addEventListener('click', function () {
                try { sessionStorage.setItem(dismissKey(version), '1'); } catch (e) { /* Storage evtl. blockiert */ }
                banner.remove();
            });
        }
    }

    function showBanner(html) {
        var old = document.querySelector('[data-update-banner]');
        if (old || !html) { return; }
        var main = document.querySelector('.app-content');
        if (!main) { return; }
        var holder = document.createElement('div');
        holder.innerHTML = html;
        var banner = holder.querySelector('[data-update-banner]');
        if (!banner) { return; }
        main.insertBefore(banner, main.firstChild);
        setupBanner(banner);
    }

    function showError(message) {
        if (!status) { return; }
        var box = document.createElement('div');
        box.className = 'alert alert-error';
        box.textContent = message;
        status.innerHTML = '';
        status.appendChild(box);
    }

    function check(force) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-Token': token,
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ force: !!force })
        }).then(function (res) {
            return res.json().catch(function () { return null; });
        }).then(function (data) {
            if (!data || !data.success) {
                throw new Error((data && data.error) || 'Die Prüfung ist fehlgeschlagen.');
            }
            if (status && typeof data.html === 'string') { status.innerHTML = data.html; }
            document.querySelectorAll('[data-version-badge]').forEach(function (badge) {
                badge.hidden = !data.available;
            });
            if (data.available) { showBanner(data.banner); }
            return data;
        });
    }

    document.querySelectorAll('[data-update-banner]').forEach(setupBanner);

    if (window.location.hash === '#version' && typeof dialog.showModal === 'function') {
        dialog.showModal();
    }

    if (url && button) {
        button.addEventListener('click', function () {
            var label = button.textContent;
            button.disabled = true;
            button.textContent = 'Suche läuft …';
            check(true).catch(function (err) {
                showError(err.message);
            }).then(function () {
                button.disabled = false;
                button.textContent = label;
            });
        });
    }

    if (url && dialog.hasAttribute('data-version-auto')) {
        check(false).catch(function () { /* Fehler stehen im Dialog, sobald er geöffnet wird */ });
    }
})();
