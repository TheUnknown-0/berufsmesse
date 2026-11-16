/**
 * Hilfe-Ruf im Web.
 *
 * Auf jeder Seite (#help-call-bar aus dem Layout): offene Rufe als Banner mit
 * „Ich komme“ bzw. dem Status des eigenen Rufs, am Messetag der Knopf
 * „Hilfe rufen“. Auf /hilfe zusätzlich das Formular (#help-call-form) und die
 * Liste (#help-call-list). Kein Ton: Klingeln gibt es nur in der App.
 *
 * Zustand kommt von GET {statusUrl}, Aktionen gehen an {statusUrl}/rufen,
 * /{id}/kommen, /{id}/mehr, /{id}/schliessen und liefern den neuen Zustand.
 */
(function () {
    'use strict';

    var bar = document.getElementById('help-call-bar');
    if (!bar || !window.BM) { return; }

    var statusUrl = bar.getAttribute('data-status-url');
    var pageUrl = bar.getAttribute('data-page-url');
    var form = document.getElementById('help-call-form');
    var list = document.getElementById('help-call-list');
    var onPage = !!form;

    var state = null;
    var loadedAt = 0;
    var timer = null;
    var busy = false;
    var draft = { room: null, category: null, message: '' };

    // ---------------------------------------------------------- Hilfen

    function h(tag, attrs, children) {
        var el = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (key) {
            var value = attrs[key];
            if (value === null || value === undefined || value === false) { return; }
            if (key === 'text') { el.textContent = value; }
            else if (key === 'onclick') { el.addEventListener('click', value); }
            else if (key === 'oninput') { el.addEventListener('input', value); }
            else if (key === 'onchange') { el.addEventListener('change', value); }
            else { el.setAttribute(key, value === true ? '' : value); }
        });
        (children || []).forEach(function (child) {
            if (child === null || child === undefined || child === false) { return; }
            el.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
        });
        return el;
    }

    function ago(seconds) {
        seconds = Math.max(0, Math.round(seconds + (Date.now() - loadedAt) / 1000));
        if (seconds < 60) { return 'gerade eben'; }
        var minutes = Math.floor(seconds / 60);
        return minutes === 1 ? 'vor 1 Minute' : 'vor ' + minutes + ' Minuten';
    }

    function names(responders) {
        return responders.map(function (r) { return r.name; }).join(', ');
    }

    function formatDate(iso) {
        if (!iso) { return ''; }
        var parts = String(iso).split('-');
        return parts.length === 3 ? parts[2] + '.' + parts[1] + '.' + parts[0] : iso;
    }

    // ---------------------------------------------------------- Laden & Aktionen

    function load() {
        BM.fetchJson(statusUrl).then(function (data) {
            if (data && data.success) { apply(data); } else { schedule(30); }
        }, function () { schedule(30); });
    }

    function act(path, body, okMessage) {
        if (busy) { return; }
        busy = true;
        render();
        BM.fetchJson(statusUrl + path, { json: body || {} }).then(function (data) {
            busy = false;
            if (data && data.success) {
                if (okMessage) { BM.flash('success', okMessage); }
            } else {
                BM.flash('error', (data && data.error) || 'Das hat nicht geklappt.');
            }
            if (data && Array.isArray(data.calls)) { apply(data); } else { render(); load(); }
        }, function () {
            busy = false;
            BM.flash('error', 'Server nicht erreichbar.');
            render();
        });
    }

    function apply(data) {
        state = data;
        loadedAt = Date.now();
        render();
        // Empfänger und Verwaltung immer, Rufende nur am Messetag oder solange ein Ruf offen ist.
        var needed = data.can_receive || data.can_manage || data.event_day || data.calls.length > 0;
        schedule(needed ? (data.web_poll_seconds || 10) : 0);
    }

    function schedule(seconds) {
        clearTimeout(timer);
        if (seconds > 0) { timer = setTimeout(load, seconds * 1000); }
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && state) { load(); }
    });

    // ---------------------------------------------------------- Darstellung

    function render() {
        if (!state) { return; }
        renderBar();
        if (onPage) {
            // Nicht neu zeichnen, während jemand tippt oder den Raum wählt.
            var active = document.activeElement;
            var typing = active && form.contains(active) && (active.tagName === 'TEXTAREA' || active.tagName === 'SELECT');
            if (!typing) { renderForm(); }
            renderList();
        }
    }

    function callCard(call) {
        var coming = call.responders.length > 0;
        var classes = 'help-call' + (call.is_test ? ' is-test' : '') + (coming && !call.nobody_coming ? ' is-coming' : '');

        var head = h('div', { 'class': 'help-call-head' }, [
            call.is_test ? h('span', { 'class': 'help-test-badge', text: 'TEST' }) : null,
            h('span', { 'class': 'help-call-title', text: '🆘 ' + (call.is_test ? call.category : call.title) }),
            call.is_test ? null : h('span', { text: call.category }),
            call.mine ? h('span', { 'class': 'badge badge-primary', text: call.i_am_caller ? 'Dein Ruf' : 'Dein Raum' }) : null
        ]);

        var meta = h('div', { 'class': 'help-call-meta', text:
            'Gerufen ' + ago(call.age_seconds) + (call.caller ? ' von ' + call.caller.name : '')
            + (call.more_count > 0 ? ' · mehr Hilfe angefordert' : '') });

        var status;
        if (coming) {
            var who = names(call.responders);
            status = h('div', { 'class': 'help-call-status', text:
                '🏃 ' + who + (call.responders.length === 1 ? ' ist unterwegs.' : ' sind unterwegs.') });
        } else if (call.nobody_coming) {
            status = h('div', { 'class': 'help-call-status is-warning', text: '⚠️ Noch niemand unterwegs.' });
        } else {
            status = h('div', { 'class': 'help-call-status', text: 'Wartet auf eine Zusage …' });
        }

        var actions = h('div', { 'class': 'help-call-actions' }, [
            call.may_respond ? h('button', {
                'class': 'btn btn-primary', type: 'button', disabled: busy,
                text: coming ? 'Ich komme auch' : 'Ich komme',
                onclick: function () { act('/' + call.id + '/kommen', {}, 'Zusage gesendet.'); }
            }) : null,
            call.i_respond ? h('span', { 'class': 'badge badge-success', text: 'Du bist unterwegs' }) : null,
            call.may_escalate ? h('button', {
                'class': 'btn btn-danger', type: 'button', disabled: busy,
                text: 'Brauche mehr Hilfe',
                onclick: function () { act('/' + call.id + '/mehr', {}, 'Es klingelt erneut bei allen im Dienst.'); }
            }) : null,
            call.may_close ? h('button', {
                'class': 'btn', type: 'button', disabled: busy,
                text: 'Ruf schließen',
                onclick: function () {
                    if (window.confirm('Ruf schließen? Er verschwindet dann bei allen.')) {
                        act('/' + call.id + '/schliessen', {}, 'Ruf geschlossen.');
                    }
                }
            }) : null
        ]);

        return h('div', { 'class': classes }, [
            head,
            call.message ? h('div', { 'class': 'help-call-message', text: '„' + call.message + '“' }) : null,
            meta,
            status,
            actions
        ]);
    }

    function renderBar() {
        bar.textContent = '';
        if (onPage) { return; } // Die Seite zeigt Rufe und Formular selbst.
        state.calls.forEach(function (call) { bar.appendChild(callCard(call)); });

        var fab = document.getElementById('help-fab');
        if (state.call_enabled) {
            if (!fab) {
                fab = h('a', { id: 'help-fab', 'class': 'help-fab', href: pageUrl, text: '🆘 Hilfe rufen' });
                document.body.appendChild(fab);
            }
        } else if (fab) {
            fab.remove();
        }
    }

    function renderForm() {
        form.textContent = '';
        var openRooms = {};
        state.calls.forEach(function (call) {
            if (call.room && !call.is_test) { openRooms[call.room.id] = true; }
        });

        if (!state.can_call) {
            if (state.can_receive || state.can_manage) {
                form.appendChild(h('div', { 'class': 'alert alert-info' }, [
                    h('span', { text: 'ℹ️' }),
                    h('div', { text: 'Du bist keinem Raum zugeteilt und kannst deshalb nicht selbst rufen. Offene Rufe erscheinen unten und auf jeder Seite oben.' })
                ]));
            } else {
                form.appendChild(h('div', { 'class': 'alert alert-info' }, [
                    h('span', { text: 'ℹ️' }),
                    h('div', { text: 'Du bist im Aufsichtsplan keinem Raum zugeteilt. Hilfe rufen geht nur aus eingeteilten Räumen.' })
                ]));
            }
            return;
        }

        if (!state.event_day) {
            var date = state.edition && state.edition.event_date ? ' (' + formatDate(state.edition.event_date) + ')' : '';
            form.appendChild(h('div', { 'class': 'alert alert-info' }, [
                h('span', { text: 'ℹ️' }),
                h('div', { text: 'Der Knopf „Hilfe rufen“ ist nur am Messetag' + date + ' aktiv. Deine Räume: '
                    + state.rooms.map(function (r) { return r.label; }).join(', ') + '.' })
            ]));
            return;
        }

        var free = state.rooms.filter(function (r) { return !openRooms[r.id]; });
        if (free.length === 0) {
            form.appendChild(h('div', { 'class': 'alert alert-warning' }, [
                h('span', { text: '🆘' }),
                h('div', { text: 'Für deinen Raum läuft bereits ein Ruf, siehe unten.' })
            ]));
            return;
        }

        if (draft.room === null || !free.some(function (r) { return r.id === draft.room; })) {
            var pre = free.filter(function (r) { return r.preselected; })[0] || free[0];
            draft.room = pre.id;
        }
        if (draft.category !== null && state.categories.indexOf(draft.category) === -1) { draft.category = null; }

        var card = h('div', { 'class': 'card card-pad' });
        card.appendChild(h('h3', { text: '🆘 Hilfe rufen' }));

        if (free.length === 1) {
            card.appendChild(h('p', {}, ['Raum: ', h('strong', { text: free[0].label })]));
        } else {
            var select = h('select', { 'class': 'input', id: 'help-room', onchange: function (ev) {
                draft.room = parseInt(ev.target.value, 10);
            } });
            free.forEach(function (room) {
                var option = h('option', { value: String(room.id), text: room.label });
                if (room.id === draft.room) { option.selected = true; }
                select.appendChild(option);
            });
            card.appendChild(h('div', { 'class': 'field' }, [h('label', { 'for': 'help-room', text: 'Raum' }), select]));
        }

        var categories = h('div', { 'class': 'help-categories', role: 'group', 'aria-label': 'Kategorie' });
        state.categories.forEach(function (category) {
            categories.appendChild(h('button', {
                type: 'button',
                'class': 'help-category',
                'aria-pressed': draft.category === category ? 'true' : 'false',
                text: category,
                onclick: function () { draft.category = category; renderForm(); }
            }));
        });
        card.appendChild(h('div', { 'class': 'field' }, [h('label', { text: 'Was ist los?' }), categories]));

        if (state.text_enabled) {
            var text = h('textarea', {
                'class': 'input', id: 'help-message', rows: '2', maxlength: String(state.max_text),
                placeholder: 'Optional, kurz',
                oninput: function (ev) { draft.message = ev.target.value; }
            });
            text.value = draft.message;
            card.appendChild(h('div', { 'class': 'field' }, [h('label', { 'for': 'help-message', text: 'Hinweis (optional)' }), text]));
        }

        if (state.on_duty === 0) {
            card.appendChild(h('div', { 'class': 'alert alert-warning' }, [
                h('span', { text: '⚠️' }),
                h('div', { text: 'Gerade ist niemand mit der App im Dienst. Der Ruf erscheint trotzdem bei allen, die die Berufsmesse im Browser geöffnet haben.' })
            ]));
        } else {
            card.appendChild(h('p', { 'class': 'text-sm text-faint', text: 'Gerade im Dienst: ' + state.on_duty + (state.on_duty === 1 ? ' Person' : ' Personen') }));
        }

        card.appendChild(h('button', {
            type: 'button',
            'class': 'btn btn-danger help-send',
            disabled: busy || draft.category === null,
            text: draft.category === null ? 'Bitte Kategorie wählen' : '🆘 Hilfe rufen: ' + draft.category,
            onclick: function () {
                act('/rufen', { room_id: draft.room, category: draft.category, message: draft.message }, 'Hilfe gerufen.');
                draft.category = null;
                draft.message = '';
            }
        }));
        form.appendChild(card);
    }

    function renderList() {
        list.textContent = '';
        if (state.calls.length === 0) {
            list.appendChild(h('div', { 'class': 'card card-pad text-faint', text: 'Gerade ist kein Hilfe-Ruf offen.' }));
            return;
        }
        state.calls.forEach(function (call) { list.appendChild(callCard(call)); });
    }

    // Laufende Zeitangaben zwischen den Abfragen auffrischen (nicht beim Tippen).
    setInterval(function () { if (state) { render(); } }, 20000);

    load();
}());
