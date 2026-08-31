<?php
/**
 * Aussteller anlegen/bearbeiten (Admin) inkl. Logo und Dokumenten.
 * Erwartet: $exhibitor (null beim Anlegen), $values, $industries,
 * $offerTypes, $visibleFieldLabels, $documents.
 * Beim Bearbeiten zusätzlich: $notes (Akquise-Verlauf), $history (frühere Jahrgänge).
 */

use App\Core\Permissions as P;

$schoolId = $ctx->schoolId();
$can = static fn (string $p): bool => $auth->can($p, $schoolId);
$isNew = $exhibitor === null;
// Beim Anlegen gibt es weder Verlauf noch Historie.
$notes = $notes ?? [];
$history = $history ?? [];
$action = $isNew
    ? $ctx->schoolUrl('/admin/aussteller/neu')
    : $ctx->schoolUrl('/admin/aussteller/' . (int) $exhibitor['id']);
$readonly = !$isNew && !$can(P::AUSSTELLER_BEARBEITEN);
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a class="text-soft" href="<?= e($ctx->schoolUrl('/admin/aussteller')) ?>">← Aussteller</a></div>
        <h1 class="page-title"><?= $isNew ? 'Neuer Aussteller' : e($exhibitor['name']) ?></h1>
        <p class="page-sub">Profil, Angebote und Sichtbarkeit für Schüler:innen.</p>
    </div>
    <?php if (!$isNew): ?>
        <div class="page-actions">
            <a class="btn" href="<?= e($ctx->schoolUrl('/aussteller/' . (int) $exhibitor['id'])) ?>">👁️ Schüler-Ansicht</a>
        </div>
    <?php endif; ?>
</div>

<?php foreach (page_blocks('admin-aussteller-formular', [
    'formular' => 'Aussteller-Formular',
    'akquise' => 'Akquise & Historie',
    'logo-entfernen' => 'Logo entfernen',
    'dokumente' => 'Dokumente',
    'loeschen' => 'Aussteller löschen',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'formular'): ?>
<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data">
    <?= $csrf->field() ?>
    <input type="hidden" name="known_categories" value="<?= e(json_encode($values['categories'], JSON_UNESCAPED_UNICODE)) ?>">

    <div class="card mb-2">
        <div class="card-header"><h2>Stammdaten</h2></div>
        <div class="card-body">
            <div class="field">
                <label for="name">Name des Unternehmens *</label>
                <input class="input" type="text" id="name" name="name" required maxlength="200"
                       value="<?= e($values['name']) ?>" <?= $readonly ? 'disabled' : '' ?>>
            </div>
            <div class="field">
                <label for="short_description">Kurzbeschreibung</label>
                <input class="input" type="text" id="short_description" name="short_description" maxlength="500"
                       value="<?= e($values['short_description']) ?>" <?= $readonly ? 'disabled' : '' ?>>
                <div class="hint">Erscheint auf der Aussteller-Kachel (max. 500 Zeichen).</div>
            </div>
            <div class="field">
                <label for="description">Ausführliche Beschreibung</label>
                <textarea id="description" name="description" rows="6" <?= $readonly ? 'disabled' : '' ?>><?= e($values['description']) ?></textarea>
            </div>

            <div class="field">
                <label>Branchen</label>
                <?php if ($industries === []): ?>
                    <div class="hint">Es sind noch keine Branchen angelegt.
                        <?php if ($can(P::BRANCHEN_BEARBEITEN)): ?>
                            <a href="<?= e($ctx->schoolUrl('/admin/branchen')) ?>">Branchen verwalten →</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="form-grid">
                        <?php foreach ($industries as $i => $industry): ?>
                            <label class="checkbox-row" for="cat-<?= e((string) $i) ?>">
                                <input type="checkbox" id="cat-<?= e((string) $i) ?>" name="categories[]"
                                       value="<?= e($industry) ?>"
                                       <?= in_array($industry, $values['categories'], true) ? 'checked' : '' ?>
                                       <?= $readonly ? 'disabled' : '' ?>>
                                <span><?= e($industry) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label for="total_slots">Plätze gesamt</label>
                    <input class="input" type="number" id="total_slots" name="total_slots" min="0" max="9999"
                           value="<?= e((string) $values['total_slots']) ?>" <?= $readonly ? 'disabled' : '' ?>>
                    <div class="hint">Gilt nur für Aussteller ohne Raum.</div>
                </div>
                <div class="field">
                    <label for="active">Sichtbarkeit</label>
                    <label class="checkbox-row" for="active">
                        <input type="checkbox" id="active" name="active" value="1"
                               <?= (int) $values['active'] === 1 ? 'checked' : '' ?> <?= $readonly ? 'disabled' : '' ?>>
                        <span>Aussteller ist aktiv und für Schüler:innen sichtbar</span>
                    </label>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-2">
        <div class="card-header"><h2>Kontakt & Inhalte</h2></div>
        <div class="card-body">
            <div class="form-grid">
                <div class="field">
                    <label for="contact_person">Ansprechpartner:in</label>
                    <input class="input" type="text" id="contact_person" name="contact_person" maxlength="200"
                           value="<?= e($values['contact_person']) ?>" <?= $readonly ? 'disabled' : '' ?>>
                </div>
                <div class="field">
                    <label for="email">E-Mail</label>
                    <input class="input" type="email" id="email" name="email" maxlength="255"
                           value="<?= e($values['email']) ?>" <?= $readonly ? 'disabled' : '' ?>>
                </div>
                <div class="field">
                    <label for="phone">Telefon</label>
                    <input class="input" type="text" id="phone" name="phone" maxlength="50"
                           value="<?= e($values['phone']) ?>" <?= $readonly ? 'disabled' : '' ?>>
                </div>
                <div class="field">
                    <label for="website">Website</label>
                    <input class="input" type="text" id="website" name="website" maxlength="255"
                           placeholder="https://…" value="<?= e($values['website']) ?>" <?= $readonly ? 'disabled' : '' ?>>
                </div>
            </div>
            <div class="field">
                <label for="jobs">Berufe & Tätigkeiten</label>
                <textarea id="jobs" name="jobs" rows="4" <?= $readonly ? 'disabled' : '' ?>><?= e($values['jobs']) ?></textarea>
            </div>
            <div class="field">
                <label for="features">Besonderheiten</label>
                <textarea id="features" name="features" rows="4" <?= $readonly ? 'disabled' : '' ?>><?= e($values['features']) ?></textarea>
            </div>
            <div class="field">
                <label for="equipment">Benötigte Ausstattung</label>
                <input class="input" type="text" id="equipment" name="equipment" maxlength="500"
                       placeholder="z. B. Beamer, Steckdosen, WLAN"
                       value="<?= e($values['equipment']) ?>" <?= $readonly ? 'disabled' : '' ?>>
                <div class="hint">Freitext. Konkrete Anfragen laufen über den Bereich Ausstattung.</div>
            </div>
        </div>
    </div>

    <div class="grid-2">
        <div class="card mb-2">
            <div class="card-header"><h2>Angebote für Schüler:innen</h2></div>
            <div class="card-body">
                <?php foreach ($offerTypes as $i => $offer): ?>
                    <label class="checkbox-row" for="offer-<?= e((string) $i) ?>">
                        <input type="checkbox" id="offer-<?= e((string) $i) ?>" name="offer_types_selected[]"
                               value="<?= e($offer) ?>"
                               <?= in_array($offer, $values['offer_types_selected'], true) ? 'checked' : '' ?>
                               <?= $readonly ? 'disabled' : '' ?>>
                        <span><?= e($offer) ?></span>
                    </label>
                <?php endforeach; ?>
                <div class="field mt-2">
                    <label for="offer_types_custom">Weiteres Angebot</label>
                    <input class="input" type="text" id="offer_types_custom" name="offer_types_custom" maxlength="200"
                           placeholder="z. B. Trainee-Programm"
                           value="<?= e($values['offer_types_custom']) ?>" <?= $readonly ? 'disabled' : '' ?>>
                </div>
            </div>
        </div>

        <div class="card mb-2">
            <div class="card-header"><h2>Für Schüler:innen sichtbar</h2></div>
            <div class="card-body">
                <p class="text-soft text-sm">Name, Kurzbeschreibung, Beschreibung, Branchen und Angebote sind immer sichtbar.</p>
                <?php foreach ($visibleFieldLabels as $key => $label): ?>
                    <label class="checkbox-row" for="vf-<?= e($key) ?>">
                        <input type="checkbox" id="vf-<?= e($key) ?>" name="visible_fields[]" value="<?= e($key) ?>"
                               <?= in_array($key, $values['visible_fields'], true) ? 'checked' : '' ?>
                               <?= $readonly ? 'disabled' : '' ?>>
                        <span><?= e($label) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card mb-2">
        <div class="card-header"><h2>Logo</h2></div>
        <div class="card-body">
            <div class="cluster">
                <?php if (!$isNew && !empty($exhibitor['logo'])): ?>
                    <img src="<?= e($ctx->url('/medien/logos/' . $exhibitor['logo'])) ?>" alt="Logo"
                         style="width:80px;height:80px;object-fit:contain;border-radius:8px;">
                <?php endif; ?>
                <div class="field mb-0" style="flex:1;min-width:240px;">
                    <label for="logo">Neues Logo hochladen</label>
                    <input class="input" type="file" id="logo" name="logo"
                           accept="image/jpeg,image/png,image/gif,image/webp" <?= $readonly ? 'disabled' : '' ?>>
                    <div class="hint">JPG, PNG, GIF oder WebP — maximal 2 MB.</div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$readonly): ?>
        <div class="cluster mb-2">
            <button class="btn btn-primary btn-lg" type="submit"><?= $isNew ? 'Aussteller anlegen' : 'Änderungen speichern' ?></button>
            <a class="btn btn-ghost" href="<?= e($ctx->schoolUrl('/admin/aussteller')) ?>">Abbrechen</a>
        </div>
    <?php endif; ?>
</form>

<?php elseif ($blockKey === 'akquise'): ?>
    <?php if (!$isNew): ?>
        <div class="grid-2">
            <div class="card">
                <div class="card-header"><h3 class="mt-0 mb-0">Frühere Jahrgänge</h3></div>
                <div class="card-body">
                    <?php if ($history === []): ?>
                        <p class="text-faint mb-0">Dieses Unternehmen ist zum ersten Mal dabei.</p>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                <tr><th>Jahr</th><th>Messe</th><th>Anmeldungen</th><th>Besuche</th></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($history as $entry): ?>
                                    <tr>
                                        <td><strong><?= e((string) $entry['year']) ?></strong></td>
                                        <td class="text-sm"><?= e((string) $entry['edition_name']) ?></td>
                                        <td><?= e((string) $entry['registrations']) ?></td>
                                        <td><?= e((string) $entry['attendances']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="mt-0 mb-0">Gesprächsverlauf</h3>
                    <a class="btn btn-sm btn-ghost" href="<?= e($ctx->schoolUrl('/admin/aussteller/pipeline')) ?>">Pipeline</a>
                </div>
                <div class="card-body">
                    <?php if ($can(P::AUSSTELLER_BEARBEITEN)): ?>
                        <form method="post" action="<?= e($ctx->schoolUrl('/admin/aussteller/' . (int) $exhibitor['id'] . '/notiz')) ?>">
                            <?= $csrf->field() ?>
                            <input type="hidden" name="zurueck" value="profil">
                            <div class="field">
                                <label for="neue-notiz">Neue Notiz</label>
                                <textarea id="neue-notiz" name="note" rows="2" maxlength="2000"
                                          placeholder="Was wurde besprochen?"></textarea>
                            </div>
                            <button class="btn btn-sm btn-accent" type="submit">Notiz speichern</button>
                        </form>
                        <hr class="divider">
                    <?php endif; ?>

                    <?php if ($notes === []): ?>
                        <p class="text-faint mb-0">Noch keine Einträge.</p>
                    <?php else: ?>
                        <div class="stack">
                            <?php foreach ($notes as $note): ?>
                                <div>
                                    <div class="cluster">
                                        <span class="text-sm text-faint"><?= e(format_datetime($note['created_at'])) ?></span>
                                        <?php if ($note['username'] !== null): ?>
                                            <span class="text-sm text-soft">
                                                <?= e(trim((string) $note['firstname'] . ' ' . (string) $note['lastname']) ?: (string) $note['username']) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($note['status_to'] !== null): ?>
                                            <span class="badge badge-info">
                                                <?= e(\App\Controllers\ExhibitorPipelineController::STAGES[$note['status_from']] ?? (string) $note['status_from']) ?>
                                                →
                                                <?= e(\App\Controllers\ExhibitorPipelineController::STAGES[$note['status_to']] ?? (string) $note['status_to']) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($can(P::AUSSTELLER_BEARBEITEN)): ?>
                                            <form method="post" style="margin-left:auto;"
                                                  action="<?= e($ctx->schoolUrl('/admin/notiz/' . (int) $note['id'] . '/loeschen')) ?>"
                                                  data-confirm="Diesen Eintrag wirklich löschen?">
                                                <?= $csrf->field() ?>
                                                <button class="btn btn-sm btn-danger-ghost" type="submit">×</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                    <p class="mb-0"><?= nl2br(e((string) $note['body'])) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

<?php elseif ($blockKey === 'logo-entfernen'): ?>
<?php if (!$isNew && !empty($exhibitor['logo']) && $can(P::AUSSTELLER_BEARBEITEN)): ?>
    <form method="post" class="mb-2"
          action="<?= e($ctx->schoolUrl('/admin/aussteller/' . (int) $exhibitor['id'] . '/logo-loeschen')) ?>"
          data-confirm="Logo wirklich entfernen?">
        <?= $csrf->field() ?>
        <button class="btn btn-sm btn-danger-ghost" type="submit">Logo entfernen</button>
    </form>
<?php endif; ?>

<?php elseif ($blockKey === 'dokumente'): ?>
<?php if (!$isNew && $can(P::AUSSTELLER_DOKUMENTE_VERWALTEN)): ?>
    <div class="card mb-2">
        <div class="card-header"><h2>Dokumente</h2></div>
        <div class="card-body">
            <form method="post" enctype="multipart/form-data"
                  action="<?= e($ctx->schoolUrl('/admin/aussteller/' . (int) $exhibitor['id'] . '/dokumente')) ?>">
                <?= $csrf->field() ?>
                <div class="field">
                    <label for="document">Datei hochladen</label>
                    <input class="input" type="file" id="document" name="document" required
                           accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.webp">
                    <div class="hint">PDF, Word, PowerPoint oder Bild — maximal 10 MB.</div>
                </div>
                <label class="checkbox-row" for="visible_for_students">
                    <input type="checkbox" id="visible_for_students" name="visible_for_students" value="1">
                    <span>Direkt für Schüler:innen sichtbar machen</span>
                </label>
                <button class="btn btn-primary" type="submit">Hochladen</button>
            </form>

            <hr class="divider">

            <?php if ($documents === []): ?>
                <p class="text-faint">Noch keine Dokumente hinterlegt.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                        <tr>
                            <th>Datei</th>
                            <th>Größe</th>
                            <th>Hochgeladen</th>
                            <th>Für Schüler:innen</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($documents as $document): ?>
                            <tr>
                                <td>
                                    <a href="<?= e($ctx->schoolUrl('/api/dokumente/download/' . (int) $document['id'])) ?>">
                                        📄 <?= e($document['original_name']) ?>
                                    </a>
                                </td>
                                <td class="nowrap"><?= e((string) round(((int) $document['file_size']) / 1024)) ?> KB</td>
                                <td class="nowrap"><?= e(format_datetime($document['uploaded_at'])) ?></td>
                                <td>
                                    <form method="post"
                                          action="<?= e($ctx->schoolUrl('/admin/dokumente/' . (int) $document['id'] . '/sichtbarkeit')) ?>">
                                        <?= $csrf->field() ?>
                                        <button class="btn btn-sm" type="submit">
                                            <?= (int) $document['visible_for_students'] === 1 ? '✅ Sichtbar' : '🚫 Verborgen' ?>
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <div class="row-actions">
                                        <form method="post"
                                              action="<?= e($ctx->schoolUrl('/admin/dokumente/' . (int) $document['id'] . '/loeschen')) ?>"
                                              data-confirm="Dokument wirklich löschen?">
                                            <?= $csrf->field() ?>
                                            <button class="btn btn-sm btn-danger-ghost" type="submit">Löschen</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php elseif ($blockKey === 'loeschen'): ?>
<?php if (!$isNew && $can(P::AUSSTELLER_LOESCHEN)): ?>
    <div class="card">
        <div class="card-header"><h2>Aussteller löschen</h2></div>
        <div class="card-body">
            <p class="text-soft text-sm">Anmeldungen, Dokumente und Ausstattungsanfragen dieses Ausstellers werden mitgelöscht. Das lässt sich nicht rückgängig machen.</p>
            <form method="post"
                  action="<?= e($ctx->schoolUrl('/admin/aussteller/' . (int) $exhibitor['id'] . '/loeschen')) ?>"
                  data-confirm="Aussteller wirklich unwiderruflich löschen?">
                <?= $csrf->field() ?>
                <button class="btn btn-danger" type="submit">Endgültig löschen</button>
            </form>
        </div>
    </div>
<?php endif; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
