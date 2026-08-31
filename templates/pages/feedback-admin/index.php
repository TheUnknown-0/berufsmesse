<?php
/**
 * Feedback-Bögen — Übersicht.
 * Erwartet: $forms, $service (FeedbackService), $canCreate, $canEdit,
 * $canDelete, $canRelease, $canEvaluate.
 */
$base = $ctx->schoolUrl('/admin/feedback');

$statusBadge = static function (string $label): string {
    return match ($label) {
        'Offen' => 'badge badge-success',
        'Geplant' => 'badge badge-info',
        'Entwurf' => 'badge badge-warning',
        default => 'badge',
    };
};

$open = array_filter($forms, static fn (array $f): bool => $service->isOpen($f));
$responses = array_sum(array_map(static fn (array $f): int => (int) $f['response_count'], $forms));
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">Feedback</h1>
        <p class="page-sub">Rückmeldungen zur Messe einholen — Bögen anlegen, nach der Messe freischalten und auswerten.</p>
    </div>
    <?php if ($canCreate): ?>
        <div class="page-actions">
            <a class="btn btn-primary" href="<?= e($base . '/neu') ?>">➕ Bogen anlegen</a>
        </div>
    <?php endif; ?>
</div>

<?php foreach (page_blocks('admin-feedback', [
    'kennzahlen' => 'Kennzahlen',
    'liste' => 'Liste der Bögen',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'kennzahlen'): ?>
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-value"><?= e((string) count($forms)) ?></div>
            <div class="stat-label">Bögen</div>
        </div>
        <div class="stat-card stat-success">
            <div class="stat-value"><?= e((string) count($open)) ?></div>
            <div class="stat-label">gerade offen</div>
        </div>
        <div class="stat-card stat-accent">
            <div class="stat-value"><?= e((string) $responses) ?></div>
            <div class="stat-label">Rückmeldungen gesamt</div>
        </div>
    </div>

<?php elseif ($blockKey === 'liste'): ?>
    <?php if ($forms === []): ?>
        <div class="empty-state">
            <div class="empty-icon">💬</div>
            <p>Noch kein Feedback-Bogen angelegt.</p>
            <?php if ($canCreate): ?>
                <a class="btn btn-primary" href="<?= e($base . '/neu') ?>">Ersten Bogen anlegen</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Bogen</th>
                    <th>Zielgruppe</th>
                    <th>Zeitfenster</th>
                    <th>Fragen</th>
                    <th>Antworten</th>
                    <th>Status</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($forms as $form): ?>
                    <?php
                    $id = (int) $form['id'];
                    $label = $service->statusLabel($form);
                    $roles = $service->audienceRoles($form);
                    $roleNames = [
                        'student' => 'Schüler:innen',
                        'teacher' => 'Lehrkräfte',
                        'exhibitor' => 'Aussteller',
                    ];
                    ?>
                    <tr>
                        <td>
                            <strong><?= e($form['title']) ?></strong>
                            <div class="text-sm text-soft">
                                <?= (int) $form['is_anonymous'] === 1 ? 'anonym' : 'namentlich' ?>
                                <?php if ($form['firstname'] !== null): ?>
                                    · angelegt von <?= e(trim((string) $form['firstname'] . ' ' . (string) $form['lastname'])) ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="chip-row">
                                <?php foreach ($roles as $role): ?>
                                    <span class="badge badge-info"><?= e($roleNames[$role]) ?></span>
                                <?php endforeach; ?>
                                <?php if ($roles === []): ?>
                                    <span class="text-faint text-sm">keine</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="text-sm">
                            <?php if ($form['opens_at'] === null && $form['closes_at'] === null): ?>
                                <span class="text-faint">unbegrenzt</span>
                            <?php else: ?>
                                <?= e($form['opens_at'] !== null ? format_datetime($form['opens_at']) : 'sofort') ?>
                                <div class="text-soft">bis <?= e($form['closes_at'] !== null ? format_datetime($form['closes_at']) : 'offen') ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= e((string) $form['question_count']) ?></td>
                        <td><strong><?= e((string) $form['response_count']) ?></strong></td>
                        <td><span class="<?= e($statusBadge($label)) ?>"><?= e($label) ?></span></td>
                        <td>
                            <div class="row-actions">
                                <?php if ($canEdit): ?>
                                    <a class="btn btn-sm btn-ghost" href="<?= e($base . '/' . $id . '/bearbeiten') ?>">Bearbeiten</a>
                                <?php endif; ?>
                                <a class="btn btn-sm btn-ghost" href="<?= e($base . '/' . $id . '/vorschau') ?>">Vorschau</a>
                                <?php if ($canEvaluate && (int) $form['response_count'] > 0): ?>
                                    <a class="btn btn-sm btn-ghost" href="<?= e($base . '/' . $id . '/auswertung') ?>">📊 Auswertung</a>
                                <?php endif; ?>
                                <?php if ($canRelease): ?>
                                    <?php if ((string) $form['status'] !== 'open'): ?>
                                        <form method="post" action="<?= e($base . '/' . $id . '/status') ?>">
                                            <?= $csrf->field() ?>
                                            <input type="hidden" name="status" value="open">
                                            <button class="btn btn-sm btn-accent" type="submit">Freischalten</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="<?= e($base . '/' . $id . '/status') ?>"
                                              data-confirm="Bogen schließen? Es sind danach keine weiteren Rückmeldungen mehr möglich.">
                                            <?= $csrf->field() ?>
                                            <input type="hidden" name="status" value="closed">
                                            <button class="btn btn-sm btn-ghost" type="submit">Schließen</button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($canDelete): ?>
                                    <form method="post" action="<?= e($base . '/' . $id . '/loeschen') ?>"
                                          data-confirm="Bogen „<?= e($form['title']) ?>“ wirklich löschen? Alle <?= e((string) $form['response_count']) ?> Rückmeldungen gehen unwiderruflich verloren.">
                                        <?= $csrf->field() ?>
                                        <button class="btn btn-sm btn-danger-ghost" type="submit">Löschen</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
