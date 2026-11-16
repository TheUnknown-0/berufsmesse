<?php
/**
 * Hilfe-Ruf verwalten: Kategorien und Textfeld, Probealarm, wer im Dienst ist,
 * Verlauf aller Rufe der aktiven Edition.
 * Erwartet: $edition, $eventDay, $categories, $textEnabled, $history, $onDuty, $openTest
 */
$formAction = $ctx->schoolUrl('/admin/hilfe');
$duration = static function (array $call): string {
    if ($call['closed_at'] === null) {
        return 'offen';
    }
    $seconds = max(0, (int) strtotime((string) $call['closed_at']) - (int) strtotime((string) $call['created_at']));

    return $seconds < 60 ? $seconds . ' s' : intdiv($seconds, 60) . ' min';
};
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Messetag</div>
        <h1 class="page-title">Hilfe-Ruf verwalten</h1>
        <p class="page-sub">
            Wer im Aufsichtsplan einem Raum zugeteilt ist und das Recht „Hilfe rufen“ hat,
            kann am Messetag aus diesem Raum Hilfe rufen. Wer „Hilfe-Rufe empfangen“ darf,
            bekommt Rufe in der App, sobald der Schalter „Im Dienst“ an ist.
        </p>
    </div>
    <div class="page-actions">
        <a class="btn" href="<?= e($ctx->schoolUrl('/hilfe')) ?>">🆘 Zur Hilfe-Ruf-Seite</a>
        <a class="btn" href="<?= e($ctx->schoolUrl('/admin/aufsicht')) ?>">🦺 Aufsichtsplan</a>
    </div>
</div>

<?php foreach (page_blocks('admin-hilfe', [
    'hinweise' => 'Hinweise',
    'einstellungen' => 'Kategorien und Textfeld',
    'probealarm' => 'Probealarm',
    'im-dienst' => 'Im Dienst',
    'verlauf' => 'Verlauf',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'hinweise'): ?>
    <?php if ($edition === null): ?>
        <div class="alert alert-warning"><span>⚠️</span><div>Es ist keine Messe aktiv.</div></div>
    <?php elseif (($edition['event_date'] ?? null) === null): ?>
        <div class="alert alert-warning"><span>⚠️</span><div>Für die aktive Messe ist noch kein Datum eingetragen. Der Knopf „Hilfe rufen“ ist nur am Messetag aktiv.</div></div>
    <?php elseif ($eventDay): ?>
        <div class="alert alert-success"><span>✅</span><div>Heute ist Messetag: Der Knopf „Hilfe rufen“ ist aktiv.</div></div>
    <?php else: ?>
        <div class="alert alert-info"><span>ℹ️</span><div>Der Knopf „Hilfe rufen“ ist am Messetag (<?= e(format_date($edition['event_date'])) ?>) aktiv. Einen Probealarm kannst du jederzeit auslösen.</div></div>
    <?php endif; ?>

<?php elseif ($blockKey === 'einstellungen'): ?>
    <div class="card card-pad">
        <h3>Kategorien und Textfeld</h3>
        <form method="post" action="<?= e($formAction) ?>">
            <?= $csrf->field() ?>
            <input type="hidden" name="action" value="einstellungen">
            <div class="field">
                <label for="help-categories">Kategorien</label>
                <textarea class="input" id="help-categories" name="categories" rows="6" required><?= e(implode("\n", $categories)) ?></textarea>
                <div class="hint">Eine pro Zeile, höchstens <?= e((string) \App\Services\HelpCalls::MAX_CATEGORIES) ?>. Die Reihenfolge gilt auch im Knopf.</div>
            </div>
            <label class="checkbox-row">
                <span class="switch">
                    <input type="checkbox" name="text_enabled" value="1"<?= $textEnabled ? ' checked' : '' ?>>
                    <span class="slider"></span>
                </span>
                <span>Optionales Textfeld anbieten (höchstens <?= e((string) \App\Services\HelpCalls::MAX_MESSAGE) ?> Zeichen)</span>
            </label>
            <div class="mt-2"><button class="btn btn-primary" type="submit">Speichern</button></div>
        </form>
    </div>

<?php elseif ($blockKey === 'probealarm'): ?>
    <div class="card card-pad mt-2">
        <h3>Probealarm</h3>
        <?php if ($openTest !== null): ?>
            <p>
                <span class="badge badge-warning">TEST läuft</span>
                seit <?= e(format_datetime($openTest['created_at'])) ?>.
                <?php if ($openTest['responses'] !== []): ?>
                    Zugesagt: <?= e(implode(', ', array_column($openTest['responses'], 'name'))) ?>.
                <?php else: ?>
                    Noch keine Zusage.
                <?php endif; ?>
            </p>
            <form method="post" action="<?= e($formAction) ?>">
                <?= $csrf->field() ?>
                <input type="hidden" name="action" value="probealarm_beenden">
                <input type="hidden" name="call_id" value="<?= e((string) (int) $openTest['id']) ?>">
                <button class="btn" type="submit">Probealarm beenden</button>
            </form>
        <?php else: ?>
            <p class="text-soft">
                Klingelt bei allen, die gerade in der App „Im Dienst“ sind, deutlich als TEST markiert
                (nicht bei dir selbst). So lässt sich vor der Messe prüfen, ob die Benachrichtigungen ankommen.
            </p>
            <form method="post" action="<?= e($formAction) ?>" class="cluster"
                  data-confirm="Probealarm jetzt an alle senden, die im Dienst sind?">
                <?= $csrf->field() ?>
                <input type="hidden" name="action" value="probealarm">
                <input class="input" type="text" name="message" maxlength="<?= e((string) \App\Services\HelpCalls::MAX_MESSAGE) ?>"
                       placeholder="Optionaler Hinweis, z. B. „Bitte kurz mit Ich komme bestätigen“" style="min-width:280px;flex:1;">
                <button class="btn btn-danger" type="submit">🔔 Probealarm auslösen</button>
            </form>
        <?php endif; ?>
    </div>

<?php elseif ($blockKey === 'im-dienst'): ?>
    <div class="card card-pad mt-2">
        <h3>Gerade im Dienst (<?= e((string) count($onDuty)) ?>)</h3>
        <?php if ($onDuty === []): ?>
            <p class="text-faint">Niemand. Der Schalter „Im Dienst“ ist in der App auf der Seite „Hilfe-Ruf“.</p>
        <?php else: ?>
            <div class="chip-row">
                <?php foreach ($onDuty as $person): ?>
                    <span class="badge badge-success" title="seit <?= e(format_datetime($person['since'])) ?>"><?= e($person['name']) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($blockKey === 'verlauf'): ?>
    <div class="card mt-2">
        <div class="card-header"><h3>Verlauf (<?= e((string) count($history)) ?>)</h3></div>
        <?php if ($history === []): ?>
            <div class="card-body text-faint">Noch keine Rufe in dieser Messe.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>Zeit</th>
                        <th>Raum</th>
                        <th>Kategorie</th>
                        <th>Gerufen von</th>
                        <th>Zugesagt</th>
                        <th>Mehr Hilfe</th>
                        <th>Geschlossen</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($history as $call): ?>
                        <tr>
                            <td><?= e(format_datetime($call['created_at'])) ?></td>
                            <td>
                                <?php if ((int) $call['is_test'] === 1): ?>
                                    <span class="badge badge-warning">TEST</span>
                                <?php else: ?>
                                    <?= e($call['room_label'] ?? '') ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= e($call['category']) ?>
                                <?php if (!empty($call['message'])): ?>
                                    <div class="text-sm text-faint"><?= e($call['message']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= e(\App\Services\HelpCalls::name($call['caller_firstname'] ?? null, $call['caller_lastname'] ?? null)) ?></td>
                            <td>
                                <?php if ($call['responses'] === []): ?>
                                    <span class="text-faint">niemand</span>
                                <?php else: ?>
                                    <?php foreach ($call['responses'] as $response): ?>
                                        <div class="text-sm"><?= e($response['name']) ?> <span class="text-faint"><?= e(substr((string) $response['at'], 11, 5)) ?></span></div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) $call['more_count'] > 0 ? e((string) (int) $call['more_count']) . '×' : '<span class="text-faint">nein</span>' ?></td>
                            <td>
                                <?php if ($call['status'] === 'open'): ?>
                                    <span class="badge badge-danger">offen</span>
                                <?php else: ?>
                                    <?= e(substr((string) $call['closed_at'], 11, 5)) ?>
                                    <span class="text-faint">(<?= e($duration($call)) ?>,
                                        <?= e(\App\Services\HelpCalls::name($call['closer_firstname'] ?? null, $call['closer_lastname'] ?? null)) ?>)</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
