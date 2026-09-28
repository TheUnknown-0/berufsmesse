/**
 * Orga-Tool: Chat (Team, Gesamt, Leitungsrunde).
 *
 * Holt Nachrichten per Polling (neue über `after`, geänderte über `since`),
 * rendert sie ausschließlich über DOM-Knoten und textContent (kein innerHTML
 * mit Nutzertext), unterstützt Antworten, Bearbeiten, Löschen, Anheften,
 * Reaktionen, Dateianhänge und @Erwähnungen mit Vorschlagsliste.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-orga-chat]');
    if (!root) { return; }

    var config;
    try {
        config = JSON.parse(root.getAttribute('data-config'));
    } catch (e) {
        return;
    }

    var POLL_VISIBLE = 4000;
    var POLL_HIDDEN = 15000;
    var GROUP_MINUTES = 5;

    var log = root.querySelector('[data-log]');
    var list = root.querySelector('[data-messages]');
    var olderBtn = root.querySelector('[data-older]');
    var pinnedBox = root.querySelector('[data-pinned]');
    var pinnedList = root.querySelector('[data-pinned-list]');
    var pinnedCount = root.querySelector('[data-pinned-count]');

    var composer = root.querySelector('[data-composer]');
    var input = root.querySelector('[data-input]');
    var fileInput = root.querySelector('[data-file]');
    var modeBar = root.querySelector('[data-mode-bar]');
    var modeText = root.querySelector('[data-mode-text]');
    var fileBar = root.querySelector('[data-file-bar]');
    var fileText = root.querySelector('[data-file-text]');
    var suggest = root.querySelector('[data-suggest]');
    var sendBtn = root.querySelector('[data-send]');

    var messages = {};      // id => Nachricht
    var order = [];         // IDs aufsteigend
    var minId = 0;
    var maxId = 0;
    var since = null;
    var mode = null;        // {type: 'reply'|'edit', id}
    var pollTimer = null;
    var stopped = false;
    var unseen = 0;
    var baseTitle = document.title;
    var openPicker = null;

    var audience = (config.audience || []).map(function (u) {
        return { username: u.username, name: u.name, key: u.username.toLowerCase() };
    });
    var audienceKeys = {};
    audience.forEach(function (u) { audienceKeys[u.key] = true; });
    var me = (config.me || '').toLowerCase();

    // ---------- Hilfen ----------
    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined && text !== null) { node.textContent = text; }
        return node;
    }

    function url(pattern, id, action) {
        return pattern.replace('{id}', String(id)).replace('{action}', action);
    }

    function initials(name) {
        var parts = String(name || '?').trim().split(/\s+/);
        var first = parts[0] ? parts[0].charAt(0) : '?';
        var last = parts.length > 1 ? parts[parts.length - 1].charAt(0) : '';
        return (first + last).toUpperCase();
    }

    function sameDay(a, b) {
        return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    }

    function dayLabel(date) {
        var today = new Date();
        var yesterday = new Date();
        yesterday.setDate(today.getDate() - 1);
        if (sameDay(date, today)) { return 'Heute'; }
        if (sameDay(date, yesterday)) { return 'Gestern'; }
        return date.toLocaleDateString('de-DE', { weekday: 'long', day: '2-digit', month: '2-digit', year: 'numeric' });
    }

    function timeLabel(date) {
        return date.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
    }

    function formatSize(bytes) {
        if (bytes < 1024) { return bytes + ' B'; }
        if (bytes < 1048576) { return Math.round(bytes / 1024) + ' KB'; }
        return (bytes / 1048576).toFixed(1).replace('.', ',') + ' MB';
    }

    function snippet(text, length) {
        var flat = String(text || '').replace(/\s+/g, ' ').trim();
        return flat.length > length ? flat.slice(0, length - 1) + '…' : flat;
    }

    function atBottom() {
        return log.scrollHeight - log.scrollTop - log.clientHeight < 60;
    }

    function scrollToBottom() {
        log.scrollTop = log.scrollHeight;
    }

    // Text mit Links und Erwähnungen als DOM-Knoten.
    var TOKEN = /(https?:\/\/[^\s<>"]+)|(^|[^\p{L}\p{N}_])@([\p{L}\p{N}._-]{2,100})/gu;

    function renderBody(text) {
        var frag = document.createDocumentFragment();
        var last = 0;
        var match;
        TOKEN.lastIndex = 0;
        while ((match = TOKEN.exec(text)) !== null) {
            if (match[1]) {
                frag.appendChild(document.createTextNode(text.slice(last, match.index)));
                var href = match[1].replace(/[.,;:!?)]+$/, '');
                var a = el('a', null, href);
                a.href = href;
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                frag.appendChild(a);
                last = match.index + href.length;
                TOKEN.lastIndex = last;
            } else {
                var prefix = match[2] || '';
                var name = match[3].replace(/\.+$/, '');
                var key = name.toLowerCase();
                if (!audienceKeys[key]) { continue; }
                var start = match.index + prefix.length;
                frag.appendChild(document.createTextNode(text.slice(last, start)));
                frag.appendChild(el('span', 'chat-mention' + (key === me ? ' is-me' : ''), '@' + name));
                last = start + 1 + name.length;
                TOKEN.lastIndex = last;
            }
        }
        frag.appendChild(document.createTextNode(text.slice(last)));
        return frag;
    }

    // ---------- Darstellung ----------
    function actionButton(label, title, handler) {
        var btn = el('button', null, label);
        btn.type = 'button';
        btn.title = title;
        btn.setAttribute('aria-label', title);
        btn.addEventListener('click', handler);
        return btn;
    }

    function renderMessage(m, prev) {
        var created = new Date(m.created_at);
        var continued = prev && !prev.deleted && !m.reply && prev.username === m.username
            && sameDay(new Date(prev.created_at), created)
            && (created - new Date(prev.created_at)) < GROUP_MINUTES * 60000;

        var row = el('div', 'chat-msg' + (m.is_own ? ' is-own' : '') + (continued ? ' is-continued' : ''));
        row.setAttribute('data-id', m.id);

        var avatar = el('span', 'avatar', initials(m.author));
        avatar.setAttribute('aria-hidden', 'true');
        row.appendChild(avatar);

        var main = el('div', 'chat-main');
        var head = el('div', 'chat-head');
        head.appendChild(el('span', 'chat-author', m.author));
        if (m.is_admin) { head.appendChild(el('span', 'badge badge-info', 'Admin')); }
        var time = el('time', 'chat-time', timeLabel(created));
        time.dateTime = m.created_at;
        time.title = created.toLocaleString('de-DE');
        head.appendChild(time);
        if (m.pinned) { head.appendChild(el('span', 'chat-time', '📌 angeheftet')); }
        main.appendChild(head);

        if (m.reply) {
            var ref = el('button', 'chat-reply-ref', '↩ ' + m.reply.author + ': ' + m.reply.snippet);
            ref.type = 'button';
            ref.addEventListener('click', function () { jumpTo(m.reply.id); });
            main.appendChild(ref);
        }

        if (m.deleted) {
            main.appendChild(el('div', 'chat-body is-deleted', 'Nachricht gelöscht'));
        } else {
            if (m.body) {
                var body = el('div', 'chat-body');
                body.appendChild(renderBody(m.body));
                if (m.edited) {
                    body.appendChild(document.createTextNode(' '));
                    body.appendChild(el('span', 'chat-edited', '(bearbeitet)'));
                }
                main.appendChild(body);
            }
            if (m.attachment) {
                var att = el('div', 'chat-attachment');
                if (m.attachment.is_image) {
                    var link = el('a');
                    link.href = m.attachment.url;
                    link.target = '_blank';
                    link.rel = 'noopener';
                    var img = el('img');
                    img.src = m.attachment.url;
                    img.alt = m.attachment.name;
                    img.loading = 'lazy';
                    link.appendChild(img);
                    att.appendChild(link);
                } else {
                    var file = el('a', 'chat-file', '📄 ' + m.attachment.name + ' · ' + formatSize(m.attachment.size));
                    file.href = m.attachment.download_url;
                    att.appendChild(file);
                }
                main.appendChild(att);
            }
            if (m.reactions && m.reactions.length) {
                var reacts = el('div', 'chat-reactions');
                m.reactions.forEach(function (r) {
                    var chip = el('button', 'chat-reaction' + (r.mine ? ' is-mine' : ''), r.emoji + ' ' + r.count);
                    chip.type = 'button';
                    chip.title = r.names.join(', ');
                    if (config.canWrite) {
                        chip.addEventListener('click', function () { react(m.id, r.emoji); });
                    } else {
                        chip.disabled = true;
                    }
                    reacts.appendChild(chip);
                });
                main.appendChild(reacts);
            }
        }
        row.appendChild(main);

        if (!m.deleted) {
            var actions = el('div', 'chat-actions');
            if (config.canWrite) {
                actions.appendChild(actionButton('😊', 'Reagieren', function () { togglePicker(actions, m.id); }));
                actions.appendChild(actionButton('↩', 'Antworten', function () { startReply(m); }));
            }
            if (m.can_edit && config.canWrite) {
                actions.appendChild(actionButton('✏️', 'Bearbeiten', function () { startEdit(m); }));
            }
            if (m.can_pin) {
                actions.appendChild(actionButton('📌', m.pinned ? 'Lösen' : 'Anheften', function () { pin(m.id); }));
            }
            if (m.can_delete) {
                actions.appendChild(actionButton('🗑️', 'Löschen', function () { remove(m); }));
            }
            if (actions.childNodes.length) { row.appendChild(actions); }
        }

        return row;
    }

    function render() {
        var stick = atBottom();
        var frag = document.createDocumentFragment();
        var prev = null;
        order.forEach(function (id) {
            var m = messages[id];
            var created = new Date(m.created_at);
            if (!prev || !sameDay(new Date(prev.created_at), created)) {
                frag.appendChild(el('div', 'chat-day', dayLabel(created)));
                prev = null;
            }
            frag.appendChild(renderMessage(m, prev));
            prev = m;
        });
        list.textContent = '';
        if (!order.length) {
            list.appendChild(el('p', 'text-faint', 'Noch keine Nachrichten. Schreib die erste!'));
        }
        list.appendChild(frag);
        if (stick) { scrollToBottom(); }
    }

    function renderPinned(pinned) {
        if (!pinnedBox) { return; }
        pinnedBox.hidden = !pinned.length;
        pinnedCount.textContent = pinned.length;
        pinnedList.textContent = '';
        pinned.forEach(function (m) {
            var item = el('button', 'chat-pinned-item');
            item.type = 'button';
            item.appendChild(el('strong', null, m.author + ':'));
            item.appendChild(el('span', null, snippet(m.body || (m.attachment ? '📎 ' + m.attachment.name : ''), 120)));
            item.addEventListener('click', function () { jumpTo(m.id); });
            pinnedList.appendChild(item);
        });
    }

    function merge(list, prepend) {
        var changed = false;
        var newFromOthers = 0;
        list.forEach(function (m) {
            var known = Object.prototype.hasOwnProperty.call(messages, m.id);
            // Änderungen an Nachrichten vor dem geladenen Ausschnitt ignorieren.
            if (!known && !prepend && minId > 0 && m.id < minId) { return; }
            if (!known) {
                order.push(m.id);
                if (!m.is_own && !prepend) { newFromOthers++; }
            }
            messages[m.id] = m;
            changed = true;
            maxId = Math.max(maxId, m.id);
            minId = minId === 0 ? m.id : Math.min(minId, m.id);
        });
        if (changed) {
            order.sort(function (a, b) { return a - b; });
        }
        return { changed: changed, newFromOthers: newFromOthers };
    }

    function jumpTo(id) {
        var node = list.querySelector('[data-id="' + id + '"]');
        if (!node) {
            BM.flash('info', 'Die Nachricht ist älter. Lade zuerst ältere Nachrichten.');
            return;
        }
        node.scrollIntoView({ behavior: 'smooth', block: 'center' });
        node.classList.add('is-highlight');
        setTimeout(function () { node.classList.remove('is-highlight'); }, 1600);
    }

    // ---------- Laden ----------
    function query(params) {
        var parts = [];
        Object.keys(params).forEach(function (k) {
            if (params[k] !== null && params[k] !== undefined && params[k] !== '') {
                parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
            }
        });
        return config.messagesUrl + (parts.length ? '?' + parts.join('&') : '');
    }

    function handleFailure(res) {
        if (res && (res.httpStatus === 401 || res.httpStatus === 403 || res.httpStatus === 404)) {
            stopped = true;
            BM.flash('error', res.error || 'Der Chat ist nicht mehr erreichbar.');
            return true;
        }
        return false;
    }

    function initialLoad() {
        BM.fetchJson(query({ gelesen: 1 }), { method: 'GET' }).then(function (res) {
            if (!res || !res.success) {
                handleFailure(res);
                list.textContent = '';
                list.appendChild(el('p', 'text-faint', 'Nachrichten konnten nicht geladen werden.'));
                schedule(10000);
                return;
            }
            since = res.now;
            merge(res.messages, false);
            olderBtn.hidden = !res.has_older;
            renderPinned(res.pinned || []);
            render();
            scrollToBottom();
            schedule();
        }).catch(function () { schedule(10000); });
    }

    function poll() {
        if (stopped) { return; }
        var visible = document.visibilityState === 'visible';
        BM.fetchJson(query({ after: maxId, since: since, gelesen: visible ? 1 : '' }), { method: 'GET' }).then(function (res) {
            if (!res || !res.success) {
                if (!handleFailure(res)) { schedule(10000); }
                return;
            }
            since = res.now;
            var result = merge(res.messages, false);
            if (res.pinned) { renderPinned(res.pinned); }
            if (result.changed) { render(); }
            if (result.newFromOthers && !visible) {
                unseen += result.newFromOthers;
                document.title = '(' + unseen + ') ' + baseTitle;
            }
            schedule();
        }).catch(function () { schedule(10000); });
    }

    function schedule(delay) {
        clearTimeout(pollTimer);
        if (stopped) { return; }
        var wait = delay || (document.visibilityState === 'visible' ? POLL_VISIBLE : POLL_HIDDEN);
        pollTimer = setTimeout(poll, wait);
    }

    function pollNow() {
        clearTimeout(pollTimer);
        poll();
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            unseen = 0;
            document.title = baseTitle;
            pollNow();
        }
    });

    olderBtn.addEventListener('click', function () {
        olderBtn.disabled = true;
        BM.fetchJson(query({ before: minId }), { method: 'GET' }).then(function (res) {
            olderBtn.disabled = false;
            if (!res || !res.success) { return; }
            var height = log.scrollHeight;
            var top = log.scrollTop;
            merge(res.messages, true);
            olderBtn.hidden = !res.has_older;
            render();
            log.scrollTop = top + (log.scrollHeight - height);
        }).catch(function () { olderBtn.disabled = false; });
    });

    // ---------- Aktionen ----------
    function post(action, id, payload) {
        return BM.fetchJson(url(config.messageUrl, id, action), { json: payload || {} }).then(function (res) {
            if (!res || !res.success) {
                BM.flash('error', (res && res.error) || 'Das hat nicht geklappt.');
                return null;
            }
            return res;
        });
    }

    function react(id, emoji) {
        closePicker();
        post('reaktion', id, { emoji: emoji }).then(function (res) { if (res) { pollNow(); } });
    }

    function pin(id) {
        post('anheften', id).then(function (res) {
            if (res) {
                BM.flash('success', res.pinned ? 'Nachricht angeheftet.' : 'Nachricht gelöst.');
                pollNow();
            }
        });
    }

    function remove(m) {
        var text = m.is_own ? 'Nachricht löschen?' : 'Nachricht von ' + m.author + ' als Moderation löschen?';
        if (!window.confirm(text)) { return; }
        post('loeschen', m.id).then(function (res) {
            if (!res) { return; }
            messages[m.id] = Object.assign({}, m, { deleted: true, body: null, attachment: null, reactions: [], pinned: false });
            if (mode && mode.id === m.id) { resetMode(); }
            render();
            pollNow();
        });
    }

    function closePicker() {
        if (openPicker) {
            openPicker.remove();
            openPicker = null;
        }
    }

    function togglePicker(actions, id) {
        if (openPicker && openPicker.parentNode === actions) {
            closePicker();
            actions.classList.remove('is-open');
            return;
        }
        closePicker();
        var picker = el('span', 'chat-emoji-picker');
        (config.reactions || []).forEach(function (emoji) {
            picker.appendChild(actionButton(emoji, 'Reaktion ' + emoji, function () { react(id, emoji); }));
        });
        actions.insertBefore(picker, actions.firstChild);
        actions.classList.add('is-open');
        openPicker = picker;
    }

    document.addEventListener('click', function (ev) {
        if (openPicker && !ev.target.closest('.chat-actions')) {
            var parent = openPicker.parentNode;
            closePicker();
            if (parent) { parent.classList.remove('is-open'); }
        }
    });

    // ---------- Verfassen ----------
    if (!composer) {
        initialLoad();
        return;
    }

    function autoGrow() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 180) + 'px';
    }

    function resetMode() {
        if (mode && mode.type === 'edit') { input.value = ''; }
        mode = null;
        modeBar.hidden = true;
        sendBtn.textContent = 'Senden';
        autoGrow();
    }

    function startReply(m) {
        if (mode && mode.type === 'edit') { input.value = ''; }
        mode = { type: 'reply', id: m.id };
        modeText.textContent = '↩ Antwort an ' + m.author + ': ' + snippet(m.body || (m.attachment ? m.attachment.name : ''), 80);
        modeBar.hidden = false;
        sendBtn.textContent = 'Senden';
        input.focus();
    }

    function startEdit(m) {
        mode = { type: 'edit', id: m.id };
        modeText.textContent = m.is_own ? '✏️ Nachricht bearbeiten' : '✏️ Nachricht von ' + m.author + ' bearbeiten (Moderation)';
        modeBar.hidden = false;
        sendBtn.textContent = 'Speichern';
        input.value = m.body || '';
        clearFile();
        autoGrow();
        input.focus();
    }

    root.querySelector('[data-mode-cancel]').addEventListener('click', resetMode);

    function clearFile() {
        fileInput.value = '';
        fileBar.hidden = true;
    }

    fileInput.addEventListener('change', function () {
        var file = fileInput.files && fileInput.files[0];
        if (!file) { clearFile(); return; }
        if (file.size > config.maxBytes) {
            BM.flash('error', 'Die Datei ist zu groß (max. ' + formatSize(config.maxBytes) + ').');
            clearFile();
            return;
        }
        if (mode && mode.type === 'edit') { resetMode(); }
        fileText.textContent = '📎 ' + file.name + ' · ' + formatSize(file.size);
        fileBar.hidden = false;
    });
    root.querySelector('[data-file-cancel]').addEventListener('click', clearFile);

    var sending = false;

    function send() {
        if (sending) { return; }
        var text = input.value.trim();
        var file = fileInput.files && fileInput.files[0];

        if (mode && mode.type === 'edit') {
            sending = true;
            post('bearbeiten', mode.id, { body: text }).then(function (res) {
                sending = false;
                if (!res) { return; }
                merge([res.message], false);
                resetMode();
                render();
            }).catch(function () { sending = false; });
            return;
        }

        if (!text && !file) { return; }
        var data = new FormData();
        data.append('body', text);
        if (mode && mode.type === 'reply') { data.append('reply_to', String(mode.id)); }
        if (file) { data.append('file', file); }

        sending = true;
        sendBtn.disabled = true;
        BM.fetchJson(config.sendUrl, { method: 'POST', body: data }).then(function (res) {
            sending = false;
            sendBtn.disabled = false;
            if (!res || !res.success) {
                BM.flash('error', (res && res.error) || 'Die Nachricht wurde nicht gesendet.');
                return;
            }
            input.value = '';
            clearFile();
            resetMode();
            merge([res.message], false);
            render();
            scrollToBottom();
            input.focus();
        }).catch(function () {
            sending = false;
            sendBtn.disabled = false;
            BM.flash('error', 'Keine Verbindung. Die Nachricht wurde nicht gesendet.');
        });
    }

    composer.addEventListener('submit', function (ev) {
        ev.preventDefault();
        send();
    });

    // ---------- Erwähnungen ----------
    var suggestions = [];
    var activeIndex = 0;

    function mentionQuery() {
        var before = input.value.slice(0, input.selectionStart);
        var m = before.match(/(^|[^\p{L}\p{N}_])@([\p{L}\p{N}._-]*)$/u);
        return m ? m[2] : null;
    }

    function hideSuggest() {
        suggest.hidden = true;
        suggestions = [];
    }

    function showSuggest() {
        var q = mentionQuery();
        if (q === null) { hideSuggest(); return; }
        var needle = q.toLowerCase();
        suggestions = audience.filter(function (u) {
            return u.key !== me && (u.key.indexOf(needle) === 0 || u.name.toLowerCase().indexOf(needle) !== -1);
        }).slice(0, 8);
        if (!suggestions.length) { hideSuggest(); return; }
        activeIndex = 0;
        suggest.textContent = '';
        suggestions.forEach(function (u, i) {
            var btn = el('button', i === 0 ? 'is-active' : '');
            btn.type = 'button';
            btn.setAttribute('role', 'option');
            btn.appendChild(el('strong', null, u.name));
            btn.appendChild(document.createTextNode(' @' + u.username));
            btn.addEventListener('mousedown', function (ev) { ev.preventDefault(); pick(i); });
            suggest.appendChild(btn);
        });
        suggest.hidden = false;
    }

    function highlight() {
        Array.prototype.forEach.call(suggest.children, function (b, i) {
            b.classList.toggle('is-active', i === activeIndex);
        });
    }

    function pick(i) {
        var u = suggestions[i];
        if (!u) { return; }
        var pos = input.selectionStart;
        var before = input.value.slice(0, pos).replace(/@([\p{L}\p{N}._-]*)$/u, '@' + u.username + ' ');
        input.value = before + input.value.slice(pos);
        input.selectionStart = input.selectionEnd = before.length;
        hideSuggest();
        input.focus();
    }

    input.addEventListener('input', function () {
        autoGrow();
        showSuggest();
    });
    input.addEventListener('blur', function () { setTimeout(hideSuggest, 150); });

    input.addEventListener('keydown', function (ev) {
        if (!suggest.hidden && suggestions.length) {
            if (ev.key === 'ArrowDown') { ev.preventDefault(); activeIndex = (activeIndex + 1) % suggestions.length; highlight(); return; }
            if (ev.key === 'ArrowUp') { ev.preventDefault(); activeIndex = (activeIndex - 1 + suggestions.length) % suggestions.length; highlight(); return; }
            if (ev.key === 'Enter' || ev.key === 'Tab') { ev.preventDefault(); pick(activeIndex); return; }
            if (ev.key === 'Escape') { ev.preventDefault(); hideSuggest(); return; }
        }
        if (ev.key === 'Escape' && mode) {
            ev.preventDefault();
            resetMode();
            return;
        }
        if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) {
            ev.preventDefault();
            send();
        }
        // Pfeil hoch im leeren Feld bearbeitet die letzte eigene Nachricht.
        if (ev.key === 'ArrowUp' && input.value === '' && !mode) {
            for (var i = order.length - 1; i >= 0; i--) {
                var m = messages[order[i]];
                if (m.is_own && m.can_edit) {
                    ev.preventDefault();
                    startEdit(m);
                    break;
                }
            }
        }
    });

    initialLoad();
})();
