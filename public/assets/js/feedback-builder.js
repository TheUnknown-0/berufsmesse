/**
 * Fragen-Baukasten für Feedback-Bögen.
 *
 * Markup-Konventionen (templates/pages/feedback-admin/form.php):
 *   [data-question-list]      → Container der Fragen
 *   [data-question]           → eine Frage
 *   [data-add-question]       → Knopf „Frage hinzufügen“ (klont #frage-vorlage)
 *   [data-move-up|down]       → Reihenfolge ändern
 *   [data-remove-question]    → Frage entfernen
 *   [data-question-type]      → Typ-Auswahl; blendet Optionen/Skala ein
 *   [data-question-options]   → Block mit den Antwortoptionen
 *   [data-question-scale]     → Block mit den Skalen-Einstellungen
 *
 * Die Feldnamen (questions[i][...]) werden nach jeder Änderung neu
 * durchnummeriert — der Index bestimmt serverseitig die Reihenfolge.
 */
(function () {
    'use strict';

    var CHOICE_TYPES = ['single_choice', 'multi_choice', 'dropdown'];

    var list = document.querySelector('[data-question-list]');
    var template = document.getElementById('frage-vorlage');
    if (!list || !template) { return; }

    var emptyState = document.querySelector('[data-question-empty]');

    /** Feldnamen neu durchnummerieren und Positionsanzeige aktualisieren. */
    function reindex() {
        var questions = list.querySelectorAll('[data-question]');
        questions.forEach(function (question, index) {
            question.querySelectorAll('[name]').forEach(function (field) {
                field.name = field.name.replace(/^questions\[[^\]]*\]/, 'questions[' + index + ']');
            });
            var number = question.querySelector('[data-question-number]');
            if (number) { number.textContent = (index + 1) + '.'; }
        });
        if (emptyState) { emptyState.hidden = questions.length > 0; }
    }

    /** Optionen-/Skalenblock passend zum gewählten Fragetyp ein- oder ausblenden. */
    function applyType(question) {
        var select = question.querySelector('[data-question-type]');
        if (!select) { return; }

        var type = select.value;
        var options = question.querySelector('[data-question-options]');
        var scale = question.querySelector('[data-question-scale]');

        if (options) { options.hidden = CHOICE_TYPES.indexOf(type) === -1; }
        if (scale) { scale.hidden = type !== 'scale'; }
    }

    document.addEventListener('click', function (ev) {
        var add = ev.target.closest('[data-add-question]');
        if (add) {
            var clone = template.content.firstElementChild.cloneNode(true);
            clone.querySelectorAll('[name]').forEach(function (field) {
                field.name = field.name.replace('__INDEX__', String(list.children.length));
            });
            list.appendChild(clone);
            applyType(clone);
            reindex();
            var first = clone.querySelector('input[type="text"]');
            if (first) { first.focus(); }
            return;
        }

        var question = ev.target.closest('[data-question]');
        if (!question) { return; }

        if (ev.target.closest('[data-remove-question]')) {
            var filled = question.querySelector('input[type="text"]');
            if (filled && filled.value.trim() !== ''
                && !window.confirm('Frage „' + filled.value.trim() + '“ entfernen? Bereits gegebene Antworten darauf gehen beim Speichern verloren.')) {
                return;
            }
            question.remove();
            reindex();
            return;
        }

        if (ev.target.closest('[data-move-up]')) {
            var previous = question.previousElementSibling;
            if (previous) { list.insertBefore(question, previous); reindex(); }
            return;
        }

        if (ev.target.closest('[data-move-down]')) {
            var next = question.nextElementSibling;
            if (next) { list.insertBefore(next, question); reindex(); }
        }
    });

    document.addEventListener('change', function (ev) {
        if (!ev.target.matches('[data-question-type]')) { return; }
        var question = ev.target.closest('[data-question]');
        if (question) { applyType(question); }
    });

    // Ausgangszustand herstellen (z. B. nach einem Validierungsfehler).
    list.querySelectorAll('[data-question]').forEach(applyType);
    reindex();
})();
