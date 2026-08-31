/**
 * Admin-Dashboard: Diagramme mit Chart.js (lokal, global `Chart`).
 *
 * Datenquelle: [data-dashboard][data-charts] enthält das JSON
 *   { exhibitors: {labels, values}, slots: {labels, used, capacity} }.
 * Fehlt es, wird [data-stats-url] nachgeladen.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-dashboard]');
    if (!root || typeof window.Chart === 'undefined') { return; }

    function cssVar(name, fallback) {
        var value = getComputedStyle(document.documentElement).getPropertyValue(name);
        return value && value.trim() !== '' ? value.trim() : fallback;
    }

    var primary = cssVar('--primary', '#0d6e62');
    var accent = cssVar('--accent', '#e8a020');
    var ink = cssVar('--ink-soft', '#5a6663');
    var border = cssVar('--border', '#e0d8c9');

    var baseOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { labels: { color: ink } }
        },
        scales: {
            x: { ticks: { color: ink }, grid: { color: border } },
            y: { ticks: { color: ink }, grid: { color: border } }
        }
    };

    function renderExhibitors(data) {
        var canvas = document.getElementById('chart-exhibitors');
        if (!canvas || !data || !data.labels || data.labels.length === 0) { return; }

        new window.Chart(canvas, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [{
                    label: 'Anmeldungen',
                    data: data.values,
                    backgroundColor: primary,
                    borderRadius: 4
                }]
            },
            options: Object.assign({}, baseOptions, {
                indexAxis: 'y',
                plugins: { legend: { display: false } }
            })
        });
    }

    function renderSlots(data) {
        var canvas = document.getElementById('chart-slots');
        if (!canvas || !data || !data.labels || data.labels.length === 0) { return; }

        new window.Chart(canvas, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [
                    { label: 'Belegt', data: data.used, backgroundColor: primary, borderRadius: 4 },
                    { label: 'Kapazität', data: data.capacity, backgroundColor: accent, borderRadius: 4 }
                ]
            },
            options: baseOptions
        });
    }

    function render(charts) {
        if (!charts) { return; }
        renderExhibitors(charts.exhibitors);
        renderSlots(charts.slots);
    }

    var raw = root.getAttribute('data-charts');
    if (raw) {
        try {
            render(JSON.parse(raw));
            return;
        } catch (e) { /* fällt auf das Nachladen zurück */ }
    }

    var url = root.getAttribute('data-stats-url');
    if (url && window.BM && window.BM.fetchJson) {
        window.BM.fetchJson(url, { method: 'GET' }).then(function (response) {
            if (response && response.success) {
                render(response.charts);
            }
        });
    }
})();
