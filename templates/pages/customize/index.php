<?php
/**
 * Darstellung — schulspezifisches Customizing.
 * Erwartet: $theme, $navLayout, $dashboardLayout, $navSections, $dashboardWidgets.
 */

/**
 * Rendert eine sortierbare Liste: gespeicherte Reihenfolge anwenden,
 * unbekannte Keys hinten anhängen, hidden-Status als data-Attribut.
 */
$sortableList = static function (array $items, array $layout, array $locked = []) {
    $order = is_array($layout['order'] ?? null) ? $layout['order'] : [];
    $hidden = is_array($layout['hidden'] ?? null) ? $layout['hidden'] : [];
    $sorted = [];
    foreach ($order as $key) {
        if (isset($items[$key])) {
            $sorted[$key] = $items[$key];
        }
    }
    foreach ($items as $key => $item) {
        if (!isset($sorted[$key])) {
            $sorted[$key] = $item;
        }
    }
    foreach ($sorted as $key => $item) {
        [$icon, $label] = is_array($item) ? $item : ['•', $item];
        $isHidden = in_array($key, $hidden, true);
        $isLocked = in_array($key, $locked, true);
        echo '<li class="sort-item' . ($isHidden ? ' is-hidden' : '') . '" draggable="true" data-key="' . e($key) . '">'
            . '<span class="drag-handle" aria-hidden="true">⠿</span>'
            . '<span class="nav-icon" aria-hidden="true">' . $icon . '</span>'
            . '<span class="sort-label">' . e($label) . '</span>'
            . ($isLocked
                ? '<span class="badge">immer sichtbar</span>'
                : '<button type="button" class="btn btn-sm" data-toggle-hidden aria-pressed="' . ($isHidden ? 'true' : 'false') . '">'
                    . ($isHidden ? '🚫 ausgeblendet' : '👁 sichtbar') . '</button>')
            . '</li>';
    }
};
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><?= e($ctx->school['name']) ?></div>
        <h1 class="page-title">Darstellung</h1>
        <p class="page-sub">Passe Logo, Farben und Anordnung der Website für deine Schule an.</p>
    </div>
    <div class="page-actions">
        <form method="post" action="<?= e($ctx->schoolUrl('/admin/darstellung/zuruecksetzen')) ?>"
              data-confirm="Farben und Anordnung wirklich auf den Standard zurücksetzen?">
            <?= $csrf->field() ?>
            <button class="btn btn-danger-ghost" type="submit">↺ Alles zurücksetzen</button>
        </form>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3>🖼️ Logo</h3></div>
        <div class="card-body">
            <?php if (!empty($ctx->school['logo'])): ?>
                <div class="cluster mb-2">
                    <img class="brand-logo" style="width:64px;height:64px;"
                         src="<?= e($ctx->url('/medien/logos/' . $ctx->school['logo'])) ?>" alt="Aktuelles Schullogo">
                    <form method="post" action="<?= e($ctx->schoolUrl('/admin/darstellung/logo')) ?>">
                        <?= $csrf->field() ?>
                        <input type="hidden" name="remove" value="1">
                        <button class="btn btn-sm btn-danger-ghost" type="submit">Entfernen</button>
                    </form>
                </div>
            <?php else: ?>
                <p class="text-soft text-sm">Noch kein Logo — aktuell wird das Standard-„B" angezeigt.</p>
            <?php endif; ?>
            <form method="post" action="<?= e($ctx->schoolUrl('/admin/darstellung/logo')) ?>" enctype="multipart/form-data">
                <?= $csrf->field() ?>
                <div class="field">
                    <label for="logo">Neues Logo (PNG, JPG, WebP oder SVG, max. 2 MB)</label>
                    <input class="input" type="file" id="logo" name="logo" accept=".png,.jpg,.jpeg,.webp,.svg" required>
                    <div class="hint">Erscheint in der Seitenleiste, auf der Login-Seite und der Schulauswahl. Quadratisch wirkt am besten.</div>
                </div>
                <button class="btn btn-primary" type="submit">Logo hochladen</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>🎨 Farben</h3></div>
        <div class="card-body">
            <form method="post" action="<?= e($ctx->schoolUrl('/admin/darstellung/farben')) ?>">
                <?= $csrf->field() ?>
                <div class="form-grid">
                    <div class="field">
                        <label for="primary">Primärfarbe (Buttons, Links, Akzente)</label>
                        <div class="cluster">
                            <input type="color" id="primary" name="primary" value="<?= e($theme['primary'] ?? '#2563eb') ?>"
                                   style="width:48px;height:38px;padding:2px;border:1px solid var(--border-strong);border-radius:6px;background:var(--surface);cursor:pointer;">
                            <label class="checkbox-row mb-0">
                                <input type="checkbox" name="primary_default" value="1" <?= $theme['primary'] === null ? 'checked' : '' ?>>
                                <span class="text-sm">Standard (Blau)</span>
                            </label>
                        </div>
                    </div>
                    <div class="field">
                        <label for="bg">Seitenhintergrund (helles Design)</label>
                        <div class="cluster">
                            <input type="color" id="bg" name="bg" value="<?= e($theme['bg'] ?? '#f3f4f6') ?>"
                                   style="width:48px;height:38px;padding:2px;border:1px solid var(--border-strong);border-radius:6px;background:var(--surface);cursor:pointer;">
                            <label class="checkbox-row mb-0">
                                <input type="checkbox" name="bg_default" value="1" <?= $theme['bg'] === null ? 'checked' : '' ?>>
                                <span class="text-sm">Standard (Hellgrau)</span>
                            </label>
                        </div>
                        <div class="hint">Der Dunkelmodus behält seinen neutralen Hintergrund.</div>
                    </div>
                </div>
                <div class="cluster">
                    <button class="btn btn-primary" type="submit">Farben speichern</button>
                    <button class="btn" type="submit" name="reset" value="1">Zurücksetzen</button>
                </div>
            </form>
            <hr class="divider">
            <form method="post" action="<?= e($ctx->schoolUrl('/admin/darstellung/hintergrund')) ?>" enctype="multipart/form-data">
                <?= $csrf->field() ?>
                <div class="field">
                    <label for="background">Login-Hintergrundbild (JPG, PNG oder WebP, max. 4 MB)</label>
                    <input class="input" type="file" id="background" name="background" accept=".jpg,.jpeg,.png,.webp">
                    <div class="hint">Erscheint hinter der Anmelde-Karte, leicht abgedunkelt — z. B. ein Foto eures Schulgebäudes.</div>
                </div>
                <div class="cluster">
                    <button class="btn btn-primary" type="submit">Hintergrund speichern</button>
                    <?php if ($theme['login_image'] !== null): ?>
                        <button class="btn btn-danger-ghost" type="submit" name="remove" value="1">Bild entfernen</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="card mt-2" data-save-url="<?= e($ctx->schoolUrl('/api/darstellung/navigation')) ?>" id="nav-editor">
    <div class="card-header">
        <h3>📋 Navigation anordnen</h3>
        <button class="btn btn-primary btn-sm" type="button" data-save-nav>Anordnung speichern</button>
    </div>
    <div class="card-body">
        <p class="text-sm text-soft">
            Ziehe Einträge mit der Maus in die gewünschte Reihenfolge und blende aus, was deine Schule nicht braucht.
            Ausgeblendete Seiten bleiben über ihre Adresse erreichbar — Berechtigungen filtern immer zusätzlich.
        </p>
        <div class="grid-2">
            <?php foreach ($navSections as $sectionKey => [$sectionTitle, $items]): ?>
                <div>
                    <h4 class="text-sm" style="text-transform:uppercase;letter-spacing:.06em;color:var(--ink-soft);"><?= e($sectionTitle) ?></h4>
                    <ul class="sort-list" data-sortable data-section="<?= e($sectionKey) ?>">
                        <?php $sortableList($items, $navLayout[$sectionKey] ?? [], $sectionKey === 'admin' ? ['darstellung'] : []) ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card mt-2">
    <div class="card-header"><h3>🧩 Seiten-Inhalte anordnen</h3></div>
    <div class="card-body">
        <p class="text-sm text-soft">
            Jede Seite lässt sich <strong>direkt auf der Seite selbst</strong> anordnen: öffnen, oben rechts
            <span class="badge">🧩 Anordnen</span> klicken, Abschnitte ziehen oder ausblenden.
            In der Leiste unten wählst du, ob die Anordnung für <strong>alle Rollen</strong> oder nur für eine
            bestimmte Rolle (Schüler:innen, Lehrkräfte, Aussteller, Verwaltung) gelten soll.
            Die Links unten öffnen jede Seite sofort im Anordnen-Modus — auch Schüler- und Portal-Seiten,
            die du sonst nicht nutzt. „Alles zurücksetzen" oben entfernt sämtliche Anordnungen.
        </p>
        <?php
        $arrangeCatalog = [
            'Schüler:innen' => [
                ['/uebersicht', 'Übersicht (Startseite)'],
                ['/aussteller', 'Ausstellerübersicht'],
                ['/einschreibung', 'Einschreibung'],
                ['/meine-anmeldungen', 'Meine Anmeldungen'],
                ['/tagesplan', 'Tagesplan'],
                ['/checkin', 'Check-in'],
            ],
            'Lehrkräfte' => [
                ['/klassen', 'Klassenübersicht'],
                ['/scan', 'Scanner'],
            ],
            'Aussteller-Portal' => [
                ['/portal', 'Portal-Übersicht'],
                ['/portal/slots', 'Slots & Anmeldungen'],
                ['/portal/ausstattung', 'Ausstattung'],
                ['/portal/dokumente', 'Dokumente'],
            ],
            'Verwaltung' => [
                ['/admin/dashboard', 'Dashboard'],
                ['/admin/aussteller', 'Aussteller'],
                ['/admin/aussteller/neu', 'Aussteller-Formular'],
                ['/admin/branchen', 'Branchen'],
                ['/admin/raeume', 'Räume'],
                ['/admin/kapazitaeten', 'Kapazitäten'],
                ['/admin/anmeldungen', 'Anmeldungen'],
                ['/admin/benutzer', 'Benutzer'],
                ['/admin/benutzer/neu', 'Benutzer-Formular'],
                ['/admin/benutzer/import', 'CSV-Import'],
                ['/admin/berechtigungen', 'Berechtigungen'],
                ['/admin/berechtigungen/gruppen', 'Berechtigungsgruppen'],
                ['/admin/qr-codes', 'QR-Codes'],
                ['/admin/qr-codes/schueler', 'Schüler-QR-Codes'],
                ['/admin/anwesenheit', 'Anwesenheit'],
                ['/admin/anwesenheit-live', 'Anwesenheit live'],
                ['/admin/anwesenheit-bericht', 'Anwesenheitsbericht'],
                ['/admin/aufsicht', 'Aufsichtsplan'],
                ['/admin/druckzentrale', 'Druckzentrale'],
                ['/admin/ausstattung', 'Ausstattung'],
                ['/admin/ankuendigungen', 'Ankündigungen'],
                ['/admin/einstellungen', 'Einstellungen (je Tab)'],
                ['/admin/audit-log', 'Audit-Log'],
                ['/admin/aussteller-konten', 'Aussteller-Konten'],
            ],
        ];
        ?>
        <div class="grid-2">
            <?php foreach ($arrangeCatalog as $groupLabel => $pages): ?>
                <div>
                    <h4 class="text-sm" style="text-transform:uppercase;letter-spacing:.06em;color:var(--ink-soft);"><?= e($groupLabel) ?></h4>
                    <div class="chip-row" style="margin-bottom:14px;">
                        <?php foreach ($pages as [$path, $label]): ?>
                            <a class="badge" style="text-decoration:none;"
                               href="<?= e($ctx->schoolUrl($path) . '?anordnen=1') ?>">🧩 <?= e($label) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="text-sm text-faint mb-0">
            Detailseiten (z. B. ein einzelner Aussteller oder eine Klasse) ordnest du an, indem du einen
            beliebigen Datensatz öffnest und dort auf „🧩 Anordnen" klickst.
        </p>
    </div>
</div>
