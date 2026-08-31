<?php
/**
 * Benutzerliste mit Filtern und Pagination.
 * Erwartet: $users, $total, $page, $pages, $search, $roleFilter, $classFilter,
 * $classes, $counts, $withoutPassword, $roleLabels, $generated,
 * $canCreate, $canEdit, $canDelete, $canImport, $canReset
 */

$listUrl = $ctx->schoolUrl('/admin/benutzer');

/** Baut eine Listen-URL mit den aktuellen Filtern. */
$pageUrl = static function (int $target) use ($listUrl, $search, $roleFilter, $classFilter): string {
    $query = array_filter([
        'q' => $search,
        'rolle' => $roleFilter,
        'klasse' => $classFilter,
        'seite' => $target > 1 ? (string) $target : '',
    ], static fn (string $value): bool => $value !== '');

    return $listUrl . ($query === [] ? '' : '?' . http_build_query($query));
};

$roleBadge = static function (string $role): string {
    return match ($role) {
        'admin' => 'badge badge-danger',
        'school_admin' => 'badge badge-accent',
        'orga' => 'badge badge-primary',
        'teacher' => 'badge badge-info',
        'exhibitor' => 'badge badge-warning',
        default => 'badge',
    };
};

$adminCount = ($counts['admin'] ?? 0) + ($counts['school_admin'] ?? 0) + ($counts['orga'] ?? 0);
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">Benutzer</h1>
        <p class="page-sub"><?= e((string) $total) ?> Konten in dieser Schule.</p>
    </div>
    <div class="page-actions">
        <?php if ($canImport): ?>
            <a class="btn btn-ghost" href="<?= e($ctx->schoolUrl('/admin/benutzer/import')) ?>">📥 CSV-Import</a>
        <?php endif; ?>
        <?php if ($canCreate): ?>
            <a class="btn btn-primary" href="<?= e($ctx->schoolUrl('/admin/benutzer/neu')) ?>">➕ Neuer Benutzer</a>
        <?php endif; ?>
    </div>
</div>

<?php $userBlocks = page_blocks('admin-benutzer', [
    'kennzahlen' => 'Kennzahlen',
    'filter' => 'Filter & Suche',
    'liste' => 'Benutzerliste',
]); ?>
<?php foreach ($userBlocks as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'kennzahlen'): ?>
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-value"><?= e((string) array_sum($counts)) ?></div>
        <div class="stat-label">Konten gesamt</div>
    </div>
    <div class="stat-card stat-accent">
        <div class="stat-value"><?= e((string) $adminCount) ?></div>
        <div class="stat-label">Verwaltung (Admin & Orga)</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= e((string) ($counts['teacher'] ?? 0)) ?></div>
        <div class="stat-label">Lehrkräfte</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= e((string) ($counts['student'] ?? 0)) ?></div>
        <div class="stat-label">Schüler:innen</div>
    </div>
    <div class="stat-card<?= $withoutPassword > 0 ? ' stat-danger' : '' ?>">
        <div class="stat-value"><?= e((string) $withoutPassword) ?></div>
        <div class="stat-label">Ohne Passwort</div>
    </div>
</div>

<?php elseif ($blockKey === 'filter'): ?>
<form class="card card-pad mb-2" method="get" action="<?= e($listUrl) ?>">
    <div class="form-grid">
        <div class="field">
            <label for="q">Suche</label>
            <input class="input" type="search" id="q" name="q" value="<?= e($search) ?>"
                   placeholder="Name oder Benutzername">
        </div>
        <div class="field">
            <label for="rolle">Rolle</label>
            <select id="rolle" name="rolle">
                <option value="">Alle Rollen</option>
                <?php foreach ($roleLabels as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $roleFilter === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="klasse">Klasse</label>
            <select id="klasse" name="klasse">
                <option value="">Alle Klassen</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?= e($class) ?>"<?= $classFilter === (string) $class ? ' selected' : '' ?>><?= e($class) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="cluster">
        <button class="btn btn-primary" type="submit">Filtern</button>
        <a class="btn btn-ghost" href="<?= e($listUrl) ?>">Zurücksetzen</a>
    </div>
</form>

<?php elseif ($blockKey === 'liste'): ?>
<?php if ($users === []): ?>
    <div class="empty-state">
        <div class="empty-icon">👥</div>
        <p>Keine Benutzer gefunden.</p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Name</th>
                <th>Benutzername</th>
                <th>E-Mail</th>
                <th>Klasse</th>
                <th>Rolle</th>
                <th>Passwort</th>
                <th style="text-align:right;">Aktionen</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $row): ?>
                <?php
                $id = (int) $row['id'];
                $fullName = trim((string) $row['firstname'] . ' ' . (string) $row['lastname']);
                $role = (string) $row['role'];
                $isSelf = $auth->id() === $id;
                ?>
                <tr>
                    <td>
                        <?= e($fullName !== '' ? $fullName : '—') ?>
                        <?php if ($isSelf): ?>
                            <span class="badge badge-info">Du</span>
                        <?php endif; ?>
                    </td>
                    <td class="mono"><?= e($row['username']) ?></td>
                    <td class="text-sm text-soft"><?= e($row['email'] ?? '—') ?></td>
                    <td><?= e($row['class'] ?? '—') ?></td>
                    <td><span class="<?= e($roleBadge($role)) ?>"><?= e($roleLabels[$role] ?? $role) ?></span></td>
                    <td>
                        <?php if ((int) $row['no_password'] === 1): ?>
                            <span class="badge badge-danger">kein Passwort</span>
                        <?php elseif ((int) $row['must_change_password'] === 1): ?>
                            <span class="badge badge-warning">Wechsel nötig</span>
                        <?php else: ?>
                            <span class="badge badge-success">gesetzt</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="row-actions">
                            <?php if ($canEdit): ?>
                                <a class="btn btn-sm btn-ghost"
                                   href="<?= e($ctx->schoolUrl('/admin/benutzer/' . $id . '/bearbeiten')) ?>">Bearbeiten</a>
                            <?php endif; ?>
                            <?php if ($canReset): ?>
                                <button class="btn btn-sm btn-ghost" type="button"
                                        data-open-modal="modal-passwort"
                                        data-reset-action="<?= e($ctx->schoolUrl('/admin/benutzer/' . $id . '/passwort')) ?>"
                                        data-reset-name="<?= e($fullName !== '' ? $fullName : (string) $row['username']) ?>">🔑 Passwort</button>
                            <?php endif; ?>
                            <?php if ($canDelete && !$isSelf): ?>
                                <form method="post"
                                      action="<?= e($ctx->schoolUrl('/admin/benutzer/' . $id . '/loeschen')) ?>"
                                      data-confirm="Benutzer „<?= e($row['username']) ?>“ wirklich löschen? Alle Anmeldungen und Rechte dieses Kontos gehen verloren.">
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

    <?php if ($pages > 1): ?>
        <div class="cluster mt-2">
            <?php if ($page > 1): ?>
                <a class="btn btn-sm btn-ghost" href="<?= e($pageUrl($page - 1)) ?>">← Zurück</a>
            <?php endif; ?>
            <span class="text-sm text-soft">Seite <?= e((string) $page) ?> von <?= e((string) $pages) ?></span>
            <?php if ($page < $pages): ?>
                <a class="btn btn-sm btn-ghost" href="<?= e($pageUrl($page + 1)) ?>">Weiter →</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>

<?php if ($canReset): ?>
    <dialog class="modal" id="modal-passwort">
        <form method="post" action="" data-reset-form>
            <?= $csrf->field() ?>
            <div class="modal-header">
                <h3>Passwort zurücksetzen</h3>
                <button class="modal-close" type="button" data-close-modal aria-label="Schließen">×</button>
            </div>
            <div class="modal-body">
                <p class="text-sm text-soft">
                    Konto: <strong data-reset-target>—</strong>
                </p>
                <div class="field">
                    <label>Was soll passieren?</label>
                    <label class="checkbox-row">
                        <input type="radio" name="modus" value="zufaellig" checked>
                        <span>Zufälliges Passwort erzeugen (wird einmalig angezeigt)</span>
                    </label>
                    <label class="checkbox-row">
                        <input type="radio" name="modus" value="leeren">
                        <span>Passwort entfernen — Zugangsdaten kommen aus dem Zugangsdaten-PDF</span>
                    </label>
                    <div class="hint">In beiden Fällen muss das Konto beim nächsten Login ein neues Passwort setzen.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-ghost" type="button" data-close-modal>Abbrechen</button>
                <button class="btn btn-primary" type="submit">Zurücksetzen</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>

<?php if ($generated !== null): ?>
    <dialog class="modal" id="modal-neues-passwort" data-auto-open="1">
        <div class="modal-header">
            <h3>Neues Passwort</h3>
            <button class="modal-close" type="button" data-close-modal aria-label="Schließen">×</button>
        </div>
        <div class="modal-body">
            <div class="alert alert-warning">
                Dieses Passwort wird nur jetzt angezeigt. Notiere es und gib es persönlich weiter.
            </div>
            <p class="text-sm text-soft">
                <?= e($generated['name']) ?> · <span class="mono"><?= e($generated['username']) ?></span>
            </p>
            <div class="field">
                <label for="generiertes-passwort">Passwort</label>
                <input class="input mono" type="text" id="generiertes-passwort" readonly
                       value="<?= e($generated['password']) ?>">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" type="button"
                    data-copy-target="generiertes-passwort">Kopieren</button>
            <button class="btn btn-primary" type="button" data-close-modal>Fertig</button>
        </div>
    </dialog>
<?php endif; ?>
