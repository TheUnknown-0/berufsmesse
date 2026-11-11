// Läuft synchron im <head>, um Theme-Flackern zu vermeiden.
(function () {
    // Kennzeichnet, dass JavaScript läuft. Ohne dieses Skript behält das
    // Dokument die Klasse `no-js`, und die Navigation wird per CSS fest in
    // den Seitenfluss gestellt statt in eine ausklappbare Leiste.
    document.documentElement.classList.remove('no-js');

    // In der Android-App gibt die App das Theme vor (data-app-theme, siehe layouts/app.php).
    var appTheme = document.documentElement.getAttribute('data-app-theme');
    if (appTheme === 'dark' || appTheme === 'light') {
        document.documentElement.setAttribute('data-theme', appTheme);
        return;
    }

    try {
        var stored = localStorage.getItem('bm-theme');
        var theme = stored === 'dark' || stored === 'light'
            ? stored
            : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', theme);
    } catch (e) {
        document.documentElement.setAttribute('data-theme', 'light');
    }
})();
