/**
 * Offline-Puffer für den Lehrer-Scanner.
 *
 * Im Schulgebäude bricht das WLAN gern mitten im Slot weg. Statt den Scan
 * zu verlieren, wandert er in eine Warteschlange im localStorage und wird
 * nachgesendet, sobald der Server wieder antwortet. Der Server verarbeitet
 * Nachträge idempotent (UNIQUE auf Anwesenheit), doppelt gesendete Scans
 * schaden also nicht.
 *
 * Stellt window.BMQueue bereit:
 *   BMQueue.push(url, payload)  → Scan einreihen (mit Zeitstempel)
 *   BMQueue.flush()             → Warteschlange abarbeiten
 *   BMQueue.size()              → Anzahl wartender Scans
 *   BMQueue.onChange(fn)        → über Änderungen informiert werden
 */
(function () {
    'use strict';

    var KEY = 'bm-scan-queue';
    var RETRY_MS = 15000;
    var listeners = [];
    var flushing = false;

    function read() {
        try {
            var raw = window.localStorage.getItem(KEY);
            var parsed = raw ? JSON.parse(raw) : [];
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    function write(items) {
        try {
            window.localStorage.setItem(KEY, JSON.stringify(items));
        } catch (e) {
            // Speicher voll oder blockiert — dann bleibt nur der Direktversand.
        }
        listeners.forEach(function (fn) { fn(items.length); });
    }

    /** Scan einreihen. Der Zeitstempel hält fest, WANN wirklich gescannt wurde. */
    function push(url, payload) {
        var items = read();
        items.push({
            url: url,
            payload: payload,
            recorded_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
            tries: 0
        });
        write(items);
    }

    /**
     * Warteschlange abarbeiten. Ein Eintrag fliegt raus, wenn der Server
     * geantwortet hat — auch bei fachlicher Ablehnung, denn ein zweiter
     * Versuch würde daran nichts ändern.
     */
    function flush() {
        if (flushing) { return Promise.resolve(); }
        var items = read();
        if (!items.length) { return Promise.resolve(); }

        flushing = true;
        var entry = items[0];
        var body = Object.assign({}, entry.payload, {
            offline_recorded_at: entry.recorded_at,
            force: 1
        });

        return window.BM.fetchJson(entry.url, { json: body }).then(function (data) {
            var rest = read().slice(1);
            write(rest);
            flushing = false;

            if (data && data.success === false && data.error) {
                window.BM.flash('warning', 'Nachgetragener Scan abgelehnt: ' + data.error);
            }
            return rest.length ? flush() : null;
        }).catch(function () {
            // Weiterhin kein Netz — Eintrag bleibt stehen, Versuch zählen.
            var current = read();
            if (current.length) {
                current[0].tries = (current[0].tries || 0) + 1;
                write(current);
            }
            flushing = false;
        });
    }

    window.BMQueue = {
        push: push,
        flush: flush,
        size: function () { return read().length; },
        onChange: function (fn) {
            listeners.push(fn);
            fn(read().length);
        }
    };

    window.addEventListener('online', flush);
    window.setInterval(flush, RETRY_MS);
    if (navigator.onLine !== false) { flush(); }
})();
