<?php
/**
 * Auswahl für die persönlichen QR-Karten der Schüler:innen.
 * Erwartet: $students, $classes, $filterClass, $filterSearch, $edition
 */
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Check-in</div>
        <h1 class="page-title">Schüler-QR-Karten</h1>
        <p class="page-sub">Persönlicher Code für den Lehrer-Scan — Aufsichten scannen ihn im Raum.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="<?= e($ctx->schoolUrl('/admin/qr-codes')) ?>">← QR-Codes</a>
    </div>
</div>

<?php foreach (page_blocks('admin-qr-schueler', [
    'filter' => 'Filter',
    'liste' => 'Schülerliste',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'filter'): ?>
<div class="card card-pad mb-2">
    <form method="get" class="form-grid">
        <div class="field">
            <label for="f-klasse">Klasse</label>
            <select id="f-klasse" name="klasse">
                <option value="">Alle Klassen</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?= e($class) ?>" <?= $filterClass === $class ? 'selected' : '' ?>><?= e($class) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="f-suche">Suche</label>
            <input class="input" type="search" id="f-suche" name="q" value="<?= e($filterSearch) ?>"
                   placeholder="Name oder Benutzername">
        </div>
        <div class="field" style="align-self:end;">
            <button class="btn btn-primary" type="submit">Filtern</button>
        </div>
    </form>
</div>

<?php elseif ($blockKey === 'liste'): ?>
<?php if ($students === []): ?>
    <div class="empty-state">
        <div class="empty-icon">🔍</div>
        <p>Keine Schüler:innen gefunden.</p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Name</th>
                <th>Klasse</th>
                <th>Benutzername</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($students as $student): ?>
                <tr>
                    <td><?= e(trim((string) $student['lastname'] . ', ' . (string) $student['firstname'])) ?></td>
                    <td><?= e($student['class'] ?? '—') ?></td>
                    <td class="mono text-sm"><?= e($student['username']) ?></td>
                    <td>
                        <div class="row-actions">
                            <a class="btn btn-sm btn-ghost"
                               href="<?= e($ctx->schoolUrl('/admin/qr-codes/schueler/' . (int) $student['id'])) ?>">
                                🎫 QR-Karte
                            </a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="text-sm text-faint mt-2">Es werden höchstens 300 Einträge angezeigt — bei Bedarf Filter nutzen.</p>
<?php endif; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
