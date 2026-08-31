<?php
/**
 * Aussteller-Portal — Unternehmensprofil bearbeiten.
 * Erwartet: $exhibitor, $offerTypes, $offerSelected, $offerCustom.
 */
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Aussteller-Portal</div>
        <h1 class="page-title"><?= e($exhibitor['name']) ?></h1>
        <p class="page-sub">Firmenname, Raum und Platzanzahl werden von der Schule gepflegt.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="<?= e($ctx->schoolUrl('/portal')) ?>">← Zurück</a>
    </div>
</div>

<form method="post" action="<?= e($ctx->schoolUrl('/portal/profil/' . (int) $exhibitor['id'])) ?>" enctype="multipart/form-data">
    <?= $csrf->field() ?>

<?php foreach (page_blocks('portal-profil', [
    'vorgaben' => 'Vorgaben der Schule',
    'profil' => 'Profil',
    'angebot' => 'Angebot',
    'logo' => 'Logo & Speichern',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'vorgaben'): ?>
    <div class="card">
        <div class="card-header"><h3 style="margin:0;">Vorgaben der Schule</h3></div>
        <div class="card-body">
            <div class="form-grid">
                <div class="field">
                    <label for="fixed-name">Unternehmen</label>
                    <input class="input" id="fixed-name" type="text" value="<?= e($exhibitor['name']) ?>" disabled>
                </div>
                <div class="field">
                    <label for="fixed-room">Raum</label>
                    <input class="input" id="fixed-room" type="text" disabled
                           value="<?= e($exhibitor['room_number'] !== null
                               ? $exhibitor['room_number'] . ($exhibitor['room_name'] !== null ? ' — ' . $exhibitor['room_name'] : '')
                               : 'noch nicht zugewiesen') ?>">
                </div>
                <div class="field">
                    <label for="fixed-slots">Plätze gesamt</label>
                    <input class="input" id="fixed-slots" type="text" value="<?= e((string) $exhibitor['total_slots']) ?>" disabled>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($blockKey === 'profil'): ?>
    <div class="card">
        <div class="card-header"><h3 style="margin:0;">Profil</h3></div>
        <div class="card-body">
            <div class="field">
                <label for="short_description">Kurzbeschreibung</label>
                <textarea class="input" id="short_description" name="short_description" rows="2" maxlength="500"><?= e($exhibitor['short_description']) ?></textarea>
                <div class="hint">Erscheint in der Aussteller-Liste. Max. 500 Zeichen.</div>
            </div>
            <div class="field">
                <label for="description">Beschreibung</label>
                <textarea class="input" id="description" name="description" rows="6"><?= e($exhibitor['description']) ?></textarea>
            </div>
            <div class="form-grid">
                <div class="field">
                    <label for="contact_person">Ansprechpartner:in</label>
                    <input class="input" type="text" id="contact_person" name="contact_person" maxlength="200"
                           value="<?= e($exhibitor['contact_person']) ?>">
                </div>
                <div class="field">
                    <label for="email">E-Mail</label>
                    <input class="input" type="email" id="email" name="email" maxlength="255"
                           value="<?= e($exhibitor['email']) ?>">
                </div>
                <div class="field">
                    <label for="phone">Telefon</label>
                    <input class="input" type="text" id="phone" name="phone" maxlength="50"
                           value="<?= e($exhibitor['phone']) ?>">
                </div>
                <div class="field">
                    <label for="website">Website</label>
                    <input class="input" type="text" id="website" name="website" maxlength="255"
                           placeholder="https://…" value="<?= e($exhibitor['website']) ?>">
                </div>
            </div>
        </div>
    </div>

<?php elseif ($blockKey === 'angebot'): ?>
    <div class="card">
        <div class="card-header"><h3 style="margin:0;">Angebot</h3></div>
        <div class="card-body">
            <div class="field">
                <label>Angebotstypen</label>
                <?php foreach ($offerTypes as $offerType): ?>
                    <label class="checkbox-row">
                        <input type="checkbox" name="offer_types[]" value="<?= e($offerType) ?>"
                               <?= in_array($offerType, $offerSelected, true) ? 'checked' : '' ?>>
                        <span><?= e($offerType) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="field">
                <label for="offer_types_custom">Weitere Angebote (Freitext)</label>
                <input class="input" type="text" id="offer_types_custom" name="offer_types_custom" maxlength="500"
                       value="<?= e($offerCustom) ?>">
            </div>
            <div class="field">
                <label for="jobs">Ausbildungsberufe / Stellen</label>
                <textarea class="input" id="jobs" name="jobs" rows="4"><?= e($exhibitor['jobs']) ?></textarea>
                <div class="hint">Eine Angabe pro Zeile.</div>
            </div>
            <div class="field">
                <label for="features">Besonderheiten / Highlights</label>
                <textarea class="input" id="features" name="features" rows="4"><?= e($exhibitor['features']) ?></textarea>
            </div>
        </div>
    </div>

<?php elseif ($blockKey === 'logo'): ?>
    <div class="card">
        <div class="card-header"><h3 style="margin:0;">Logo</h3></div>
        <div class="card-body">
            <?php if (!empty($exhibitor['logo'])): ?>
                <img src="<?= e($ctx->url('/medien/logos/' . $exhibitor['logo'])) ?>"
                     alt="Aktuelles Logo" style="max-width:180px;max-height:100px;object-fit:contain;margin-bottom:10px;">
            <?php endif; ?>
            <div class="field">
                <label for="logo">Neues Logo hochladen</label>
                <input class="input" type="file" id="logo" name="logo" accept=".jpg,.jpeg,.png,.gif,.webp">
                <div class="hint">JPG, PNG, GIF oder WebP — maximal 2 MB. Ein neues Logo ersetzt das bisherige.</div>
            </div>
        </div>
        <div class="card-footer">
            <button class="btn btn-primary" type="submit">Profil speichern</button>
        </div>
    </div>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
</form>
