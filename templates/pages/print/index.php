<?php
/**
 * Druckzentrale — Übersicht aller Berichte und Exporte.
 *
 * Erwartet: $edition, $classes, $students, $rooms, $timeslots, $exhibitors,
 * $stats, $base, $canPrint, $canResetPasswords, $passwordRoles.
 */

/** @var callable $slotText Beschriftung eines Slots inkl. Zeitraum. */
$slotText = static function (array $slot): string {
    $name = trim((string) ($slot['slot_name'] ?? ''));
    if ($name === '') {
        $name = 'Slot ' . (string) (int) $slot['slot_number'];
    }
    $from = substr((string) ($slot['start_time'] ?? ''), 0, 5);
    $to = substr((string) ($slot['end_time'] ?? ''), 0, 5);

    return $from !== '' ? $name . ' (' . $from . '–' . $to . ')' : $name;
};
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">🖨️ Druckzentrale</h1>
        <p class="page-sub">
            PDF-Berichte und Datenexporte für <?= e($edition['name']) ?>.
            Alle Auswertungen beziehen sich ausschließlich auf diese Messe.
        </p>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-value"><?= e((string) $stats['students']) ?></div>
        <div class="stat-label">Schüler:innen</div>
    </div>
    <div class="stat-card stat-accent">
        <div class="stat-value"><?= e((string) $stats['assigned']) ?></div>
        <div class="stat-label">Zugeteilte Anmeldungen</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= e((string) $stats['rooms']) ?></div>
        <div class="stat-label">Räume</div>
    </div>
    <div class="stat-card<?= $stats['without_password'] > 0 ? ' stat-danger' : ' stat-success' ?>">
        <div class="stat-value"><?= e((string) $stats['without_password']) ?></div>
        <div class="stat-label">Konten ohne Passwort</div>
    </div>
</div>

<?php if (!$canPrint): ?>
    <div class="alert alert-info">
        Du kannst die Druckzentrale einsehen, aber keine Dokumente erzeugen.
        Dafür wird die Berechtigung „Berichte drucken“ benötigt.
    </div>
<?php endif; ?>

<?php if ($timeslots === []): ?>
    <div class="alert alert-warning">
        Für diese Messe sind noch keine Zeitslots angelegt — Tages- und Raumpläne bleiben dadurch leer.
    </div>
<?php endif; ?>

<?php $printBlocks = page_blocks('admin-druckzentrale', [
    'persoenlicher-plan' => 'Persönlicher Plan',
    'klassenliste' => 'Klassenliste',
    'raumplan' => 'Raumplan',
    'raumzuteilung' => 'Raumzuteilungs-Übersicht',
    'abwesenheit' => 'Abwesenheitsliste',
    'qr-karten' => 'QR-Scheckkarten',
    'exporte' => 'Exporte (CSV / XLSX)',
    'zugangsdaten' => 'Zugangsdaten-PDF',
]); ?>
<div class="grid-2">
<?php foreach ($printBlocks as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'persoenlicher-plan'): ?>
    <section class="card">
        <div class="card-header"><h2>📋 Persönlicher Plan</h2></div>
        <div class="card-body">
            <p class="text-soft text-sm">
                Tagesplan mit allen Slots, Räumen und persönlichem Check-in-QR-Code —
                einzeln oder als Sammel-PDF mit einer Seite pro Person.
            </p>
            <form method="get" action="<?= e($base . '/persoenlicher-plan') ?>" class="stack">
                <div class="field">
                    <label for="pp-class">Ganze Klasse</label>
                    <select id="pp-class" name="class">
                        <option value="">— Klasse wählen —</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?= e($class) ?>"><?= e($class) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hint">Erzeugt ein Sammel-PDF, eine Seite je Schüler:in.</div>
                </div>
                <div class="field">
                    <label for="pp-user">Oder einzelne Person</label>
                    <select id="pp-user" name="user_id">
                        <option value="">— Einzelplan wählen —</option>
                        <?php foreach ($students as $student): ?>
                            <option value="<?= e((string) $student['id']) ?>">
                                <?= e($student['lastname'] . ', ' . $student['firstname']) ?>
                                <?= $student['class'] !== null && $student['class'] !== '' ? '(' . e($student['class']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hint">Ist eine Person gewählt, wird die Klassenauswahl ignoriert.</div>
                </div>
                <?php if ($canPrint): ?>
                    <button class="btn btn-primary" type="submit">PDF erzeugen</button>
                <?php endif; ?>
            </form>
        </div>
    </section>

<?php elseif ($blockKey === 'klassenliste'): ?>
    <section class="card">
        <div class="card-header"><h2>🧑‍🏫 Klassenliste</h2></div>
        <div class="card-body">
            <p class="text-soft text-sm">
                Für Klassenlehrkräfte: Tabelle Schüler:in × fester Zuteilungsslot
                mit Aussteller und Raum, im Querformat.
            </p>
            <form method="get" action="<?= e($base . '/klassenliste') ?>" class="stack">
                <div class="field">
                    <label for="cl-class">Klasse</label>
                    <select id="cl-class" name="class">
                        <option value="">Alle Klassen (je Klasse eine Seite)</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?= e($class) ?>"><?= e($class) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($canPrint): ?>
                    <button class="btn btn-primary" type="submit">PDF erzeugen</button>
                <?php endif; ?>
            </form>
        </div>
    </section>

<?php elseif ($blockKey === 'raumplan'): ?>
    <section class="card">
        <div class="card-header"><h2>🚪 Raumplan</h2></div>
        <div class="card-body">
            <p class="text-soft text-sm">
                Für Aushang und Aufsicht: je Raum eine Seite mit Aussteller und
                der Teilnehmerliste pro Slot.
            </p>
            <form method="get" action="<?= e($base . '/raumplan') ?>" class="stack">
                <div class="field">
                    <label for="rp-room">Raum</label>
                    <select id="rp-room" name="room">
                        <option value="">Alle Räume</option>
                        <?php foreach ($rooms as $room): ?>
                            <option value="<?= e((string) $room['id']) ?>">
                                <?= e($room['room_number']) ?><?= $room['room_name'] !== null && $room['room_name'] !== '' ? ' – ' . e($room['room_name']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($canPrint): ?>
                    <button class="btn btn-primary" type="submit">PDF erzeugen</button>
                <?php endif; ?>
            </form>
        </div>
    </section>

<?php elseif ($blockKey === 'raumzuteilung'): ?>
    <section class="card">
        <div class="card-header"><h2>🗂️ Raumzuteilungs-Übersicht</h2></div>
        <div class="card-body">
            <p class="text-soft text-sm">
                Kompakte Tabelle für die Schulleitung: Aussteller → Raum → Slots →
                Anmeldezahlen, inklusive eingeteilter Aufsichtslehrkräfte.
            </p>
            <form method="get" action="<?= e($base . '/raumzuteilung') ?>" class="stack">
                <?php if ($canPrint): ?>
                    <button class="btn btn-primary" type="submit">PDF erzeugen</button>
                <?php else: ?>
                    <p class="text-faint text-sm">Keine Berechtigung zum Erzeugen.</p>
                <?php endif; ?>
            </form>
        </div>
    </section>

<?php elseif ($blockKey === 'abwesenheit'): ?>
    <section class="card">
        <div class="card-header"><h2>🔍 Abwesenheitsliste</h2></div>
        <div class="card-body">
            <p class="text-soft text-sm">
                Je Slot alle Schüler:innen, die zugeteilt, aber nicht eingecheckt sind —
                gruppiert nach Klasse.
            </p>
            <form method="get" action="<?= e($base . '/abwesenheit') ?>" class="stack">
                <div class="field">
                    <label for="ab-slot">Zeitslot</label>
                    <select id="ab-slot" name="timeslot_id">
                        <option value="">Alle Slots</option>
                        <?php foreach ($timeslots as $slot): ?>
                            <?php if ((int) $slot['is_break'] === 1) { continue; } ?>
                            <option value="<?= e((string) $slot['id']) ?>"><?= e($slotText($slot)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($canPrint): ?>
                    <button class="btn btn-primary" type="submit">PDF erzeugen</button>
                <?php endif; ?>
            </form>
        </div>
    </section>

<?php elseif ($blockKey === 'qr-karten'): ?>
    <section class="card">
        <div class="card-header"><h2>🔳 QR-Scheckkarten</h2></div>
        <div class="card-body">
            <p class="text-soft text-sm">
                Check-in-Karten im Scheckkartenformat (85,6 × 54 mm, 10 Karten je Seite
                mit Schnittmarken) — Schulname, Name, Klasse und persönlicher QR-Code.
            </p>
            <form method="get" action="<?= e($base . '/qr-karten') ?>" class="stack">
                <div class="field">
                    <label for="qr-class">Klasse</label>
                    <select id="qr-class" name="class">
                        <option value="">Alle Klassen</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?= e($class) ?>"><?= e($class) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($canPrint): ?>
                    <button class="btn btn-primary" type="submit">PDF erzeugen</button>
                <?php endif; ?>
            </form>
        </div>
    </section>

<?php elseif ($blockKey === 'exporte'): ?>
    <section class="card">
        <div class="card-header"><h2>📊 Exporte (CSV / XLSX)</h2></div>
        <div class="card-body">
            <p class="text-soft text-sm">
                Tabellen zum Weiterverarbeiten. CSV ist semikolon-getrennt und
                UTF-8 mit BOM — Excel öffnet es direkt korrekt.
            </p>
            <form method="get" action="<?= e($base . '/export') ?>" class="stack">
                <div class="form-grid">
                    <div class="field">
                        <label for="ex-type">Datensatz</label>
                        <select id="ex-type" name="type">
                            <option value="anmeldungen">Anmeldungen</option>
                            <option value="anwesenheit">Anwesenheit</option>
                            <option value="nicht-eingeschrieben">Nicht eingeschriebene Schüler:innen</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="ex-format">Format</label>
                        <select id="ex-format" name="format">
                            <option value="csv">CSV (Semikolon, UTF-8)</option>
                            <option value="xlsx">XLSX (Excel)</option>
                        </select>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label for="ex-class">Klasse</label>
                        <select id="ex-class" name="class">
                            <option value="">Alle Klassen</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= e($class) ?>"><?= e($class) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="ex-exhibitor">Aussteller</label>
                        <select id="ex-exhibitor" name="exhibitor_id">
                            <option value="">Alle Aussteller</option>
                            <?php foreach ($exhibitors as $exhibitor): ?>
                                <option value="<?= e((string) $exhibitor['id']) ?>"><?= e($exhibitor['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="field">
                    <label for="ex-slot">Zeitslot</label>
                    <select id="ex-slot" name="timeslot_id">
                        <option value="">Alle Slots</option>
                        <?php foreach ($timeslots as $slot): ?>
                            <option value="<?= e((string) $slot['id']) ?>"><?= e($slotText($slot)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hint">Aussteller- und Slot-Filter wirken nur auf Anmeldungen und Anwesenheit.</div>
                </div>
                <?php if ($canPrint): ?>
                    <button class="btn btn-accent" type="submit">Export herunterladen</button>
                <?php endif; ?>
            </form>
        </div>
    </section>

<?php elseif ($blockKey === 'zugangsdaten'): ?>
    <section class="card" style="grid-column:1 / -1;">
        <div class="card-header"><h2>🔐 Zugangsdaten-PDF</h2></div>
        <div class="card-body">
            <div class="alert alert-warning">
                <strong>Achtung:</strong> Beim Erzeugen werden für die ausgewählten Konten neue Passwörter
                gesetzt und gespeichert. Der Klartext ist <em>ausschließlich</em> in diesem PDF sichtbar und
                lässt sich später nicht erneut anzeigen. Betroffene müssen das Passwort beim ersten Login ändern.
            </div>

            <?php if (!$canPrint || !$canResetPasswords): ?>
                <p class="text-faint text-sm">
                    Dafür werden die Berechtigungen „Berichte drucken“ und „Passwörter zurücksetzen“ benötigt.
                </p>
            <?php else: ?>
                <form method="post" action="<?= e($base . '/zugangsdaten') ?>" class="stack"
                      data-confirm="Für die ausgewählten Konten werden jetzt neue Passwörter gesetzt. Das lässt sich nicht rückgängig machen. Fortfahren?">
                    <?= $csrf->field() ?>

                    <div class="form-grid">
                        <div class="field">
                            <label for="pw-mode">Umfang</label>
                            <select id="pw-mode" name="mode">
                                <option value="missing">Nur Konten ohne Passwort (<?= e((string) $stats['without_password']) ?>)</option>
                                <option value="reset">Passwörter für die Auswahl neu setzen</option>
                            </select>
                            <div class="hint">
                                „Neu setzen“ überschreibt bestehende Passwörter und verlangt eine Einschränkung
                                auf Klasse oder Rolle.
                            </div>
                        </div>
                        <div class="field">
                            <label for="pw-class">Klasse</label>
                            <select id="pw-class" name="class">
                                <option value="">Alle Klassen</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?= e($class) ?>"><?= e($class) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="field">
                        <label for="pw-role">Rolle</label>
                        <select id="pw-role" name="role">
                            <option value="">Alle Rollen</option>
                            <?php foreach ($passwordRoles as $roleKey => $roleLabel): ?>
                                <option value="<?= e($roleKey) ?>"><?= e($roleLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="hint">
                            Das eigene Konto wird nie verändert. Globale Administratoren und Aussteller-Konten
                            sind ausgenommen.
                        </div>
                    </div>

                    <button class="btn btn-danger" type="submit">Passwörter erzeugen &amp; PDF herunterladen</button>
                </form>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
</div>
