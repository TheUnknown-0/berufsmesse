/**
 * Live-Monitor der Anwesenheit: Kacheln je Raum/Aussteller, Auto-Refresh.
 * Erwartet #live-app mit data-endpoint und data-interval.
 */
(function () {
    'use strict';

    var app = document.getElementById('live-app');
    if (!app) { return; }

    var endpoint = app.getAttribute('data-endpoint');
    var interval = parseInt(app.getAttribute('data-interval'), 10) || 10000;

    var slotSelect = document.getElementById('live-slot');
    var updated = document.getElementById('live-updated');
    var totalsBox = document.getElementById('live-totals');
    var tilesBox = document.getElementById('live-tiles');
    var latestBox = document.getElementById('live-latest');
    var timer = null;

    function statCard(value, label, variant) {
        var card = document.createElement('div');
        card.className = 'stat-card' + (variant ? ' ' + variant : '');
        var v = document.createElement('div');
        v.className = 'stat-value';
        v.textContent = value;
        var l = document.createElement('div');
        l.className = 'stat-label';
        l.textContent = label;
        card.appendChild(v);
        card.appendChild(l);
        return card;
    }

    function renderTotals(data) {
        totalsBox.textContent = '';
        totalsBox.appendChild(statCard(data.total_pct + '%', 'Anwesenheitsquote', 'stat-success'));
        totalsBox.appendChild(statCard(String(data.total_present), 'Eingecheckt'));
        totalsBox.appendChild(statCard(String(data.total_missing), 'Noch offen', 'stat-danger'));
        totalsBox.appendChild(statCard(String(data.total_wrong), 'Falscher Raum', 'stat-accent'));
    }

    function renderTiles(tiles) {
        tilesBox.textContent = '';
        if (!tiles.length) {
            var empty = document.createElement('p');
            empty.className = 'text-faint';
            empty.textContent = 'Keine aktiven Aussteller.';
            tilesBox.appendChild(empty);
            return;
        }

        tiles.forEach(function (tile) {
            var wrap = document.createElement('div');

            var head = document.createElement('div');
            head.className = 'cluster';
            head.style.justifyContent = 'space-between';

            var title = document.createElement('span');
            title.textContent = (tile.room ? tile.room + ' · ' : '') + tile.exhibitor;
            head.appendChild(title);

            var count = document.createElement('span');
            count.className = 'text-soft text-sm';
            count.textContent = tile.present + ' / ' + tile.expected
                + (tile.wrong ? ' · ' + tile.wrong + '× falscher Raum' : '');
            head.appendChild(count);

            var bar = document.createElement('div');
            bar.className = 'progress';
            var fill = document.createElement('span');
            fill.style.width = tile.pct + '%';
            bar.appendChild(fill);

            wrap.appendChild(head);
            wrap.appendChild(bar);
            tilesBox.appendChild(wrap);
        });
    }

    function renderLatest(entries) {
        latestBox.textContent = '';
        if (!entries.length) {
            var empty = document.createElement('p');
            empty.className = 'text-faint';
            empty.textContent = 'Noch keine Check-ins in diesem Zeitslot.';
            latestBox.appendChild(empty);
            return;
        }

        entries.forEach(function (entry) {
            var row = document.createElement('div');
            row.className = 'cluster';
            row.style.justifyContent = 'space-between';

            var left = document.createElement('span');
            left.textContent = entry.time + ' · ' + entry.name + (entry.class ? ' (' + entry.class + ')' : '');
            row.appendChild(left);

            var right = document.createElement('span');
            right.className = 'text-soft text-sm';
            right.textContent = entry.exhibitor + (entry.wrong_room ? ' · falscher Raum' : '');
            row.appendChild(right);

            latestBox.appendChild(row);
        });
    }

    function load() {
        if (!slotSelect || !slotSelect.value) { return; }
        var url = endpoint + '?slot=' + encodeURIComponent(slotSelect.value);

        BM.fetchJson(url, { method: 'GET' }).then(function (data) {
            if (!data.success) {
                updated.textContent = data.error || 'Fehler beim Laden';
                return;
            }
            renderTotals(data);
            renderTiles(data.tiles);
            renderLatest(data.latest || []);
            updated.textContent = 'Stand ' + data.updated_at + ' Uhr';
        }).catch(function () {
            updated.textContent = 'Keine Verbindung';
        });
    }

    slotSelect.addEventListener('change', load);
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            if (timer) { clearInterval(timer); timer = null; }
        } else if (!timer) {
            load();
            timer = setInterval(load, interval);
        }
    });

    load();
    timer = setInterval(load, interval);
})();
