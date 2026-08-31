<?php
/**
 * CSV-Import für Benutzerkonten.
 * Erwartet: $result (null vor dem Import), $columns, $maxBytes, $importRoles
 */
$example = "firstname;lastname;username;email;role;class;password\n"
    . "Max;Mustermann;mmustermann;max.mustermann@schule.de;student;10A;\n"
    . "Erika;Musterfrau;emusterfrau;erika.musterfrau@schule.de;student;10B;\n"
    . "Hans;Lehrer;hlehrer;hans.lehrer@schule.de;teacher;;\n"
    . "Anna;Orga;aorga;anna.orga@schule.de;orga;;startpasswort";
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a class="text-soft" href="<?= e($ctx->schoolUrl('/admin/benutzer')) ?>">← Benutzer</a></div>
        <h1 class="page-title">Benutzer importieren</h1>
        <p class="page-sub">Mehrere Konten auf einmal aus einer CSV-Datei anlegen.</p>
    </div>
</div>

<?php $importBlocks = page_blocks('admin-benutzer-import', [
    'ergebnis' => 'Import-Ergebnis',
    'formular' => 'CSV-Upload & Formatbeschreibung',
]); ?>
<?php foreach ($importBlocks as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'ergebnis'): ?>
<?php if ($result !== null): ?>
    <div class="card mb-2">
        <div class="card-header"><h3 style="margin:0;">Ergebnis</h3></div>
        <div class="card-body">
            <div class="stat-grid" style="margin-bottom:12px;">
                <div class="stat-card stat-success">
                    <div class="stat-value"><?= e((string) $result['imported']) ?></div>
                    <div class="stat-label">importiert</div>
                </div>
                <div class="stat-card stat-accent">
                    <div class="stat-value"><?= e((string) $result['skipped']) ?></div>
                    <div class="stat-label">übersprungen</div>
                </div>
                <div class="stat-card<?= $result['errors'] !== [] ? ' stat-danger' : '' ?>">
                    <div class="stat-value"><?= e((string) count($result['errors'])) ?></div>
                    <div class="stat-label">Fehler</div>
                </div>
            </div>

            <?php if ($result['errors'] !== []): ?>
                <div class="alert alert-error">
                    <strong>Nicht importierte Zeilen</strong>
                    <ul>
                        <?php foreach ($result['errors'] as $error): ?>
                            <li><?= e($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($result['notes'] !== []): ?>
                <div class="alert alert-warning">
                    <strong>Übersprungene Zeilen</strong>
                    <ul>
                        <?php foreach ($result['notes'] as $note): ?>
                            <li><?= e($note) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($result['created'] !== []): ?>
                <p class="text-sm text-soft">Angelegt:</p>
                <div class="chip-row">
                    <?php foreach ($result['created'] as $username): ?>
                        <span class="badge badge-success mono"><?= e($username) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="card-footer">
            <a class="btn btn-primary" href="<?= e($ctx->schoolUrl('/admin/benutzer')) ?>">Zur Benutzerliste</a>
        </div>
    </div>
<?php endif; ?>

<?php elseif ($blockKey === 'formular'): ?>
<div class="grid-2">
    <form class="card card-pad" method="post" enctype="multipart/form-data"
          action="<?= e($ctx->schoolUrl('/admin/benutzer/import')) ?>">
        <?= $csrf->field() ?>
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= e((string) $maxBytes) ?>">

        <div class="field">
            <label for="csv">CSV-Datei</label>
            <input class="input" type="file" id="csv" name="csv" accept=".csv,.txt" required>
            <div class="hint">
                Semikolon-getrennt, UTF-8 (BOM wird toleriert), maximal
                <?= e((string) (int) ($maxBytes / 1024 / 1024)) ?> MB.
            </div>
        </div>

        <button class="btn btn-primary" type="submit">Import starten</button>
    </form>

    <div class="card card-pad">
        <h3 class="mt-0">Format</h3>
        <p class="text-sm text-soft">
            Die <strong>erste Zeile wird als Kopfzeile übersprungen</strong>. Danach je Zeile in dieser
            Reihenfolge:
        </p>
        <div class="chip-row mb-2">
            <?php foreach ($columns as $column): ?>
                <span class="badge badge-primary mono"><?= e($column) ?></span>
            <?php endforeach; ?>
        </div>
        <ul class="text-sm">
            <li><strong>Pflicht:</strong> firstname, lastname, username, role.</li>
            <li><strong>Erlaubte Rollen:</strong> <span class="mono"><?= e(implode(', ', $importRoles)) ?></span>.
                <?php if (!in_array('admin', $importRoles, true)): ?>
                    Zeilen mit <span class="mono">admin</span> oder <span class="mono">school_admin</span>
                    werden übersprungen — diese Rollen darf nur ein globaler Administrator importieren.
                <?php endif; ?>
            </li>
            <li><strong>Leeres Passwort</strong> → Konto ohne Passwort; die Zugangsdaten kommen später aus
                dem Zugangsdaten-PDF. Ein gesetztes Passwort braucht mindestens 8 Zeichen.</li>
            <li>Benutzernamen, die es in dieser Schule schon gibt (oder die in der Datei doppelt vorkommen),
                werden übersprungen.</li>
            <li>Alle Konten müssen ihr Passwort beim ersten Login ändern.</li>
        </ul>

        <hr class="divider">
        <p class="text-sm text-soft">Beispiel:</p>
        <div class="table-wrap">
            <pre class="mono text-sm" style="padding:12px; margin:0; overflow-x:auto;"><?= e($example) ?></pre>
        </div>
    </div>
</div>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
