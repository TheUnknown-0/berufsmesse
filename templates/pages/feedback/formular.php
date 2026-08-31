<?php
/**
 * Feedback-Bogen ausfüllen (oder Vorschau im Admin-Bereich).
 * Erwartet: $form, $questions, $service (FeedbackService), $preview (bool), $action.
 */
$anonymous = (int) $form['is_anonymous'] === 1;
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">
            <?php if ($preview): ?>
                <a class="text-soft" href="<?= e($ctx->schoolUrl('/admin/feedback')) ?>">← Feedback</a>
            <?php else: ?>
                <a class="text-soft" href="<?= e($ctx->schoolUrl('/feedback')) ?>">← Übersicht</a>
            <?php endif; ?>
        </div>
        <h1 class="page-title"><?= e((string) $form['title']) ?></h1>
        <?php if (!empty($form['description'])): ?>
            <p class="page-sub"><?= nl2br(e((string) $form['description'])) ?></p>
        <?php endif; ?>
    </div>
</div>

<?php if ($preview): ?>
    <div class="alert alert-info">
        Vorschau — so sieht der Bogen für die Zielgruppe aus. Eingaben werden hier nicht gespeichert.
    </div>
<?php endif; ?>

<div class="alert <?= $anonymous ? 'alert-success' : 'alert-warning' ?>">
    <?php if ($anonymous): ?>
        Deine Antworten werden <strong>anonym</strong> ausgewertet. Gespeichert wird nur, dass du
        abgegeben hast — nicht, was du geantwortet hast.
    <?php else: ?>
        Diese Rückmeldung wird <strong>namentlich</strong> erfasst: Deine Antworten sind deinem Konto zugeordnet.
    <?php endif; ?>
</div>

<?php if ($questions === []): ?>
    <div class="empty-state">
        <div class="empty-icon">❓</div>
        <p>Dieser Bogen enthält noch keine Fragen.</p>
    </div>
<?php else: ?>
    <form method="post" action="<?= e($action) ?>">
        <?= $csrf->field() ?>

        <?php foreach ($questions as $index => $question): ?>
            <?php
            $id = (int) $question['id'];
            $type = (string) $question['type'];
            $required = (int) $question['is_required'] === 1;
            $name = 'antwort[' . $id . ']';
            $fieldId = 'frage-' . $id;
            ?>
            <div class="card card-pad">
                <div class="field">
                    <label for="<?= e($fieldId) ?>">
                        <?= e((string) ($index + 1)) ?>. <?= e((string) $question['label']) ?>
                        <?php if ($required): ?><span class="text-soft">*</span><?php endif; ?>
                    </label>
                    <?php if (!empty($question['help_text'])): ?>
                        <div class="hint"><?= e((string) $question['help_text']) ?></div>
                    <?php endif; ?>

                    <?php if ($type === 'short_text'): ?>
                        <input class="input" type="text" id="<?= e($fieldId) ?>" name="<?= e($name) ?>"
                               maxlength="500"<?= $required ? ' required' : '' ?>>

                    <?php elseif ($type === 'long_text'): ?>
                        <textarea id="<?= e($fieldId) ?>" name="<?= e($name) ?>" rows="4"
                                  maxlength="5000"<?= $required ? ' required' : '' ?>></textarea>

                    <?php elseif ($type === 'dropdown'): ?>
                        <select id="<?= e($fieldId) ?>" name="<?= e($name) ?>"<?= $required ? ' required' : '' ?>>
                            <option value="">Bitte wählen …</option>
                            <?php foreach ($question['options'] as $option): ?>
                                <option value="<?= e((string) $option['id']) ?>"><?= e((string) $option['label']) ?></option>
                            <?php endforeach; ?>
                        </select>

                    <?php elseif ($type === 'single_choice'): ?>
                        <?php foreach ($question['options'] as $option): ?>
                            <label class="checkbox-row">
                                <input type="radio" name="<?= e($name) ?>" value="<?= e((string) $option['id']) ?>"<?= $required ? ' required' : '' ?>>
                                <span><?= e((string) $option['label']) ?></span>
                            </label>
                        <?php endforeach; ?>

                    <?php elseif ($type === 'multi_choice'): ?>
                        <?php foreach ($question['options'] as $option): ?>
                            <label class="checkbox-row">
                                <input type="checkbox" name="<?= e($name) ?>[]" value="<?= e((string) $option['id']) ?>">
                                <span><?= e((string) $option['label']) ?></span>
                            </label>
                        <?php endforeach; ?>
                        <?php if ($required): ?>
                            <div class="hint">Mindestens eine Antwort auswählen.</div>
                        <?php endif; ?>

                    <?php elseif ($type === 'yes_no'): ?>
                        <label class="checkbox-row">
                            <input type="radio" name="<?= e($name) ?>" value="1"<?= $required ? ' required' : '' ?>>
                            <span>Ja</span>
                        </label>
                        <label class="checkbox-row">
                            <input type="radio" name="<?= e($name) ?>" value="0">
                            <span>Nein</span>
                        </label>

                    <?php elseif ($type === 'scale'): ?>
                        <?php
                        $min = (int) $question['scale_min'];
                        $max = (int) $question['scale_max'];
                        ?>
                        <div class="cluster">
                            <?php if (!empty($question['scale_min_label'])): ?>
                                <span class="text-sm text-soft"><?= e((string) $question['scale_min_label']) ?></span>
                            <?php endif; ?>
                            <?php for ($step = $min; $step <= $max; $step++): ?>
                                <label class="checkbox-row">
                                    <input type="radio" name="<?= e($name) ?>" value="<?= e((string) $step) ?>"<?= $required ? ' required' : '' ?>>
                                    <span><?= e((string) $step) ?></span>
                                </label>
                            <?php endfor; ?>
                            <?php if (!empty($question['scale_max_label'])): ?>
                                <span class="text-sm text-soft"><?= e((string) $question['scale_max_label']) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="cluster">
            <button class="btn btn-primary btn-lg" type="submit"<?= $preview ? ' disabled' : '' ?>>Absenden</button>
            <?php if (!$preview): ?>
                <a class="btn btn-ghost" href="<?= e($ctx->schoolUrl('/feedback')) ?>">Abbrechen</a>
            <?php endif; ?>
        </div>
        <?php if (!$preview): ?>
            <p class="text-sm text-soft">Nach dem Absenden lässt sich der Bogen nicht mehr ändern.</p>
        <?php endif; ?>
    </form>
<?php endif; ?>
