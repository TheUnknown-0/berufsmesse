<?php
/**
 * Feedback-Bogen anlegen/bearbeiten — inklusive Fragen-Baukasten.
 * Erwartet: $form (null beim Anlegen), $questions, $responseCount, $old,
 * $edition, $action.
 *
 * Die Fragen werden als questions[i][...] übertragen; die Reihenfolge im
 * Formular ist die Anzeigereihenfolge. Bestehende Fragen behalten ihre id,
 * damit bereits gegebene Antworten erhalten bleiben.
 */

use App\Services\FeedbackService;

$isNew = $form === null;
$value = static fn (string $key, mixed $fallback = ''): string => (string) ($old[$key] ?? ($form[$key] ?? $fallback) ?? '');
$hasOld = isset($old['title']);
$checked = static function (string $key, bool $default) use ($old, $form, $hasOld): bool {
    if ($hasOld) {
        return isset($old[$key]);
    }

    return $form !== null ? (int) $form[$key] === 1 : $default;
};
/** datetime-local erwartet Y-m-dTH:i */
$dateValue = static function (string $key) use ($old, $form): string {
    $raw = (string) ($old[$key] ?? ($form[$key] ?? '') ?? '');
    if ($raw === '') {
        return '';
    }
    $timestamp = strtotime($raw);

    return $timestamp === false ? '' : date('Y-m-d\TH:i', $timestamp);
};
$currentStatus = (string) ($old['status'] ?? ($form['status'] ?? 'draft'));

/** Rendert die Felder einer Frage. $index = '__INDEX__' für die Vorlage. */
$questionFields = static function (string $index, ?array $question): void {
    $type = (string) ($question['type'] ?? 'short_text');
    $optionText = '';
    foreach ($question['options'] ?? [] as $option) {
        $optionText .= (string) $option['label'] . "\n";
    }
    $name = static fn (string $field): string => 'questions[' . $index . '][' . $field . ']';
    ?>
    <input type="hidden" name="<?= e($name('id')) ?>" value="<?= e((string) ($question['id'] ?? 0)) ?>">

    <div class="form-grid">
        <div class="field">
            <label>Fragetext *</label>
            <input class="input" type="text" name="<?= e($name('label')) ?>" required maxlength="500"
                   value="<?= e((string) ($question['label'] ?? '')) ?>" placeholder="Wie hat dir die Messe gefallen?">
        </div>
        <div class="field">
            <label>Fragetyp</label>
            <select name="<?= e($name('type')) ?>" data-question-type>
                <?php foreach (FeedbackService::TYPES as $key => $typeLabel): ?>
                    <option value="<?= e($key) ?>"<?= $type === $key ? ' selected' : '' ?>><?= e($typeLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="field">
        <label>Erläuterung (optional)</label>
        <input class="input" type="text" name="<?= e($name('help_text')) ?>" maxlength="500"
               value="<?= e((string) ($question['help_text'] ?? '')) ?>"
               placeholder="Zusatzhinweis, der unter der Frage steht">
    </div>

    <div class="field" data-question-options<?= in_array($type, FeedbackService::CHOICE_TYPES, true) ? '' : ' hidden' ?>>
        <label>Antwortoptionen — eine pro Zeile *</label>
        <textarea name="<?= e($name('options')) ?>" rows="4"
                  placeholder="Sehr gut&#10;Gut&#10;Geht so&#10;Schlecht"><?= e(rtrim($optionText)) ?></textarea>
        <div class="hint">Mindestens zwei Optionen. Bereits vergebene Antworten bleiben erhalten, solange die Beschriftung gleich bleibt.</div>
    </div>

    <div data-question-scale<?= $type === 'scale' ? '' : ' hidden' ?>>
        <div class="form-grid">
            <div class="field">
                <label>Skala von</label>
                <input class="input" type="number" name="<?= e($name('scale_min')) ?>" min="0" max="9"
                       value="<?= e((string) ($question['scale_min'] ?? 1)) ?>">
            </div>
            <div class="field">
                <label>bis</label>
                <input class="input" type="number" name="<?= e($name('scale_max')) ?>" min="2" max="10"
                       value="<?= e((string) ($question['scale_max'] ?? 5)) ?>">
            </div>
        </div>
        <div class="form-grid">
            <div class="field">
                <label>Beschriftung unterer Wert</label>
                <input class="input" type="text" name="<?= e($name('scale_min_label')) ?>" maxlength="100"
                       value="<?= e((string) ($question['scale_min_label'] ?? '')) ?>" placeholder="trifft gar nicht zu">
            </div>
            <div class="field">
                <label>Beschriftung oberer Wert</label>
                <input class="input" type="text" name="<?= e($name('scale_max_label')) ?>" maxlength="100"
                       value="<?= e((string) ($question['scale_max_label'] ?? '')) ?>" placeholder="trifft voll zu">
            </div>
        </div>
    </div>

    <label class="checkbox-row">
        <input type="checkbox" name="<?= e($name('is_required')) ?>" value="1"<?= (int) ($question['is_required'] ?? 0) === 1 ? ' checked' : '' ?>>
        <span>Pflichtfrage</span>
    </label>
    <?php
};
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">
            <a class="text-soft" href="<?= e($ctx->schoolUrl('/admin/feedback')) ?>">← Feedback</a>
        </div>
        <h1 class="page-title"><?= $isNew ? 'Neuer Feedback-Bogen' : e((string) $form['title']) ?></h1>
        <p class="page-sub">Messe: <?= e((string) $edition['name']) ?></p>
    </div>
    <?php if (!$isNew): ?>
        <div class="page-actions">
            <a class="btn btn-ghost" href="<?= e($ctx->schoolUrl('/admin/feedback/' . (int) $form['id'] . '/vorschau')) ?>">Vorschau</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($responseCount > 0): ?>
    <div class="alert alert-warning">
        Für diesen Bogen liegen bereits <strong><?= e((string) $responseCount) ?></strong> Rückmeldungen vor.
        Bestehende Fragen kannst du umformulieren — wird eine Frage oder eine Antwortoption
        <strong>entfernt</strong>, gehen die zugehörigen Antworten verloren.
    </div>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>">
    <?= $csrf->field() ?>

    <div class="card card-pad">
        <div class="field">
            <label for="title">Titel *</label>
            <input class="input" type="text" id="title" name="title" required maxlength="200"
                   value="<?= e($value('title')) ?>" placeholder="Rückmeldung zur Berufsmesse <?= e((string) $edition['name']) ?>">
        </div>

        <div class="field">
            <label for="description">Einleitung</label>
            <textarea id="description" name="description" rows="3"
                      placeholder="Kurzer Text, der über den Fragen steht."><?= e($value('description')) ?></textarea>
        </div>

        <hr class="divider">

        <div class="field">
            <label>Zielgruppe *</label>
            <label class="checkbox-row">
                <input type="checkbox" name="audience_students" value="1"<?= $checked('audience_students', true) ? ' checked' : '' ?>>
                <span>Schüler:innen</span>
            </label>
            <label class="checkbox-row">
                <input type="checkbox" name="audience_teachers" value="1"<?= $checked('audience_teachers', false) ? ' checked' : '' ?>>
                <span>Lehrkräfte</span>
            </label>
            <label class="checkbox-row">
                <input type="checkbox" name="audience_exhibitors" value="1"<?= $checked('audience_exhibitors', false) ? ' checked' : '' ?>>
                <span>Aussteller (im Aussteller-Portal)</span>
            </label>
        </div>

        <label class="checkbox-row">
            <input type="checkbox" name="is_anonymous" value="1"<?= $checked('is_anonymous', true) ? ' checked' : '' ?>>
            <span>Anonym auswerten</span>
        </label>
        <div class="hint">
            Anonym: Es wird nur festgehalten, <em>dass</em> jemand abgegeben hat — Antworten enthalten weder Name
            noch Klasse. Ohne Häkchen ist jede Rückmeldung namentlich zuzuordnen; die Ausfüllenden werden darauf hingewiesen.
        </div>
    </div>

    <div class="card card-pad">
        <h2 class="mt-0">Freischaltung</h2>

        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="draft"<?= $currentStatus === 'draft' ? ' selected' : '' ?>>Entwurf — für niemanden sichtbar</option>
                <option value="open"<?= $currentStatus === 'open' ? ' selected' : '' ?>>Freigeschaltet — Zielgruppe kann ausfüllen</option>
                <option value="closed"<?= $currentStatus === 'closed' ? ' selected' : '' ?>>Geschlossen — keine weiteren Rückmeldungen</option>
            </select>
        </div>

        <div class="form-grid">
            <div class="field">
                <label for="opens_at">Geöffnet ab (optional)</label>
                <input class="input" type="datetime-local" id="opens_at" name="opens_at" value="<?= e($dateValue('opens_at')) ?>">
                <div class="hint">Typisch: der Abend des Messetags (<?= e(format_date($edition['event_date'] ?? null)) ?>).</div>
            </div>
            <div class="field">
                <label for="closes_at">Geschlossen ab (optional)</label>
                <input class="input" type="datetime-local" id="closes_at" name="closes_at" value="<?= e($dateValue('closes_at')) ?>">
                <div class="hint">Leer lassen für kein Fristende.</div>
            </div>
        </div>
        <div class="hint">
            Beides zusammen: Der Bogen ist nur ausfüllbar, wenn der Status auf „Freigeschaltet“ steht
            <strong>und</strong> das Zeitfenster passt.
        </div>

        <div class="field">
            <label for="thank_you_text">Text nach dem Absenden</label>
            <textarea id="thank_you_text" name="thank_you_text" rows="2" maxlength="1000"
                      placeholder="Danke für deine Rückmeldung!"><?= e($value('thank_you_text')) ?></textarea>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="mt-0 mb-0">Fragen</h2>
            <button class="btn btn-sm btn-accent" type="button" data-add-question>➕ Frage hinzufügen</button>
        </div>
        <div class="card-body">
            <div data-question-list>
                <?php foreach ($questions as $index => $question): ?>
                    <div class="card card-pad" data-question>
                        <div class="cluster">
                            <strong class="text-soft" data-question-number><?= e((string) ($index + 1)) ?>.</strong>
                            <div class="row-actions" style="margin-left:auto;">
                                <button class="btn btn-sm btn-ghost" type="button" data-move-up aria-label="Nach oben">↑</button>
                                <button class="btn btn-sm btn-ghost" type="button" data-move-down aria-label="Nach unten">↓</button>
                                <button class="btn btn-sm btn-danger-ghost" type="button" data-remove-question>Entfernen</button>
                            </div>
                        </div>
                        <?php $questionFields((string) $index, $question); ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="empty-state" data-question-empty<?= $questions === [] ? '' : ' hidden' ?>>
                <div class="empty-icon">❓</div>
                <p>Noch keine Fragen. Füge die erste hinzu.</p>
            </div>
        </div>
    </div>

    <div class="cluster">
        <button class="btn btn-primary" type="submit"><?= $isNew ? 'Bogen anlegen' : 'Änderungen speichern' ?></button>
        <a class="btn btn-ghost" href="<?= e($ctx->schoolUrl('/admin/feedback')) ?>">Abbrechen</a>
    </div>
</form>

<template id="frage-vorlage">
    <div class="card card-pad" data-question>
        <div class="cluster">
            <strong class="text-soft" data-question-number>1.</strong>
            <div class="row-actions" style="margin-left:auto;">
                <button class="btn btn-sm btn-ghost" type="button" data-move-up aria-label="Nach oben">↑</button>
                <button class="btn btn-sm btn-ghost" type="button" data-move-down aria-label="Nach unten">↓</button>
                <button class="btn btn-sm btn-danger-ghost" type="button" data-remove-question>Entfernen</button>
            </div>
        </div>
        <?php $questionFields('__INDEX__', null); ?>
    </div>
</template>
