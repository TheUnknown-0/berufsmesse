/**
 * Retro-Easter-Egg: Konami-Code (↑↑↓↓←→←→BA) schaltet den
 * Retro-Terminal-Look um. Bewusst das einzige Easter Egg.
 */
(function () {
    'use strict';

    var seq = ['ArrowUp', 'ArrowUp', 'ArrowDown', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'ArrowLeft', 'ArrowRight', 'b', 'a'];
    var pos = 0;

    try {
        if (localStorage.getItem('bm-retro') === '1') {
            document.documentElement.setAttribute('data-retro', '');
        }
    } catch (e) { /* egal */ }

    document.addEventListener('keydown', function (ev) {
        var key = ev.key.length === 1 ? ev.key.toLowerCase() : ev.key;
        pos = (key === seq[pos]) ? pos + 1 : (key === seq[0] ? 1 : 0);
        if (pos !== seq.length) { return; }
        pos = 0;

        var on = document.documentElement.toggleAttribute('data-retro');
        try { localStorage.setItem('bm-retro', on ? '1' : '0'); } catch (e) { /* egal */ }
        if (window.BM) {
            window.BM.flash(on ? 'success' : 'info', on ? '▚▚ RETRO-MODUS AKTIVIERT ▚▚' : 'Retro-Modus deaktiviert.');
        }
    });
})();
