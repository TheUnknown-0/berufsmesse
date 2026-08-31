<?php
/**
 * Einstellungen mit fünf Tabs: Messe, Zeitslots, Zugang, QR/Check-in, Wartung.
 * Jeder Tab wird serverseitig einzeln gerendert und hat deshalb einen eigenen
 * Seiten-Key für den Anordnen-Modus.
 * Erwartet: $tab, $edition, $timeslots, $settings, $canEdit, $isGlobalAdmin,
 *           $canMaintain, $attendanceCount, $tokenCount, $assignedCount,
 *           $minPasswordLength.
 */
$dis = $canEdit ? '' : ' disabled';
$dtValue = static function (mixed $value): string {
    $value = (string) ($value ?? '');

    return $value === '' ? '' : str_replace(' ', 'T', substr($value, 0, 16));
};
$time = static fn (mixed $t): string => substr((string) $t, 0, 5);
$tabs = [
    'messe' => '🎪 Messe',
    'zeitslots' => '🕒 Zeitslots',
    'zugang' => '🔐 Zugang',
    'qr' => '🔳 QR & Check-in',
];
if ($canMaintain) {
    $tabs['wartung'] = '🧹 Wartung';
}
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><?= e($edition['name']) ?></div>
        <h1 class="page-title">Einstellungen</h1>
        <p class="page-sub">Messe-Rahmendaten, Zeitslots, Zugang und Check-in konfigurieren.</p>
    </div>
</div>

<?php if (!$canEdit): ?>
    <div class="alert alert-info">
        <span aria-hidden="true">👁️</span>
        <div>Du kannst die Einstellungen ansehen, aber nicht ändern.</div>
    </div>
<?php endif; ?>

<div class="tabs">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="tab<?= $tab === $key ? ' active' : '' ?>"
           href="<?= e($ctx->schoolUrl('/admin/einstellungen?tab=' . $key)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'messe'): ?>
    <?php foreach (page_blocks('admin-einstellungen-messe', [
        'messe-rahmendaten' => 'Messe & Einschreibezeitraum',
    ]) as $blockKey => $blockLabel): ?>
        <?= block_open($blockKey, $blockLabel) ?>
        <?php if ($blockKey === 'messe-rahmendaten'): ?>
            <div class="card">
                <div class="card-header"><h2>Messe & Einschreibezeitraum</h2></div>
                <form method="post" action="<?= e($ctx->schoolUrl('/admin/einstellungen/messe')) ?>">
                    <div class="card-body">
                        <?= $csrf->field() ?>
                        <div class="field">
                            <label for="edition-name">Name der Messe</label>
                            <input class="input" type="text" id="edition-name" name="name" maxlength="200" required<?= $dis ?>
                                   value="<?= e($edition['name']) ?>">
                        </div>

                        <div class="form-grid">
                            <div class="field">
                                <label for="reg-start">Einschreibung ab</label>
                                <input class="input" type="datetime-local" id="reg-start" name="registration_start"<?= $dis ?>
                                       value="<?= e($dtValue($edition['registration_start'])) ?>">
                                <div class="hint">Leer lassen = kein Startzeitpunkt.</div>
                            </div>
                            <div class="field">
                                <label for="reg-end">Einschreibung bis</label>
                                <input class="input" type="datetime-local" id="reg-end" name="registration_end"<?= $dis ?>
                                       value="<?= e($dtValue($edition['registration_end'])) ?>">
                                <div class="hint">Nach diesem Zeitpunkt ist die Einschreibung geschlossen.</div>
                            </div>
                            <div class="field">
                                <label for="event-date">Datum der Berufsmesse</label>
                                <input class="input" type="date" id="event-date" name="event_date"<?= $dis ?>
                                       value="<?= e((string) ($edition['event_date'] ?? '')) ?>">
                            </div>
                            <div class="field">
                                <label for="max-reg">Maximale Anmeldungen je Schüler:in</label>
                                <input class="input" type="number" id="max-reg" name="max_registrations_per_student"
                                       min="1" max="10" required<?= $dis ?>
                                       value="<?= e((string) $edition['max_registrations_per_student']) ?>">
                                <div class="hint">Entspricht der Anzahl vergebbarer Prioritäten.</div>
                            </div>
                        </div>

                        <label class="checkbox-row">
                            <span class="switch">
                                <input type="checkbox" name="auto_close_registration" value="1"
                                    <?= $settings['auto_close_registration'] ? 'checked' : '' ?><?= $dis ?>>
                                <span class="slider"></span>
                            </span>
                            <span>Einschreibung nach der Zuteilung als abgeschlossen markieren</span>
                        </label>
                        <div class="hint">
                            Rein informativ — maßgeblich für die Öffnung ist immer das oben gesetzte Ende des Zeitraums.
                        </div>
                    </div>
                    <?php if ($canEdit): ?>
                        <div class="card-footer">
                            <button class="btn btn-primary" type="submit">Speichern</button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        <?php endif; ?>
        <?= block_close() ?>
    <?php endforeach; ?>

<?php elseif ($tab === 'zeitslots'): ?>
    <?php foreach (page_blocks('admin-einstellungen-zeitslots', [
        'hinweis-zuteilungen' => 'Hinweis zu bestehenden Zuteilungen',
        'zeitslot-assistent' => 'Zeitraster-Assistent',
        'zeitslot-liste' => 'Zeitslots',
        'zeitslot-neu' => 'Neuen Zeitslot anlegen',
    ]) as $blockKey => $blockLabel): ?>
        <?= block_open($blockKey, $blockLabel) ?>
        <?php if ($blockKey === 'hinweis-zuteilungen'): ?>
            <?php if ($assignedCount > 0): ?>
                <div class="alert alert-warning">
                    <span aria-hidden="true">⚠️</span>
                    <div>
                        Es bestehen bereits <strong><?= e((string) $assignedCount) ?></strong> Zuteilungen.
                        Änderungen an „fester Slot“ wirken sich sofort auf Tagespläne und die automatische
                        Zuteilung aus — danach ggf. die Zuteilung neu ausführen.
                    </div>
                </div>
            <?php endif; ?>

        <?php elseif ($blockKey === 'zeitslot-liste'): ?>
            <div class="card mb-2">
                <div class="card-header">
                    <h2>Zeitslots</h2>
                    <span class="badge"><?= e((string) count($timeslots)) ?> Slots</span>
                </div>
                <?php if ($timeslots === []): ?>
                    <div class="card-body">
                        <div class="empty-state">
                            <div class="empty-icon" aria-hidden="true">🕒</div>
                            <p>Es sind noch keine Zeitslots angelegt.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card-body stack">
                        <?php foreach ($timeslots as $slot): ?>
                            <?php
                            $slotId = (int) $slot['id'];
                            $used = (int) $slot['anmeldungen'] + (int) $slot['anwesenheiten'];
                            ?>
                            <div class="card">
                                <div class="card-body">
                                    <form method="post" action="<?= e($ctx->schoolUrl('/admin/einstellungen/zeitslots')) ?>">
                                        <?= $csrf->field() ?>
                                        <input type="hidden" name="slot_id" value="<?= e((string) $slotId) ?>">

                                        <div class="form-grid">
                                            <div class="field">
                                                <label for="nr-<?= e((string) $slotId) ?>">Slot-Nummer</label>
                                                <input class="input" type="number" id="nr-<?= e((string) $slotId) ?>"
                                                       name="slot_number" min="1" max="255" required<?= $dis ?>
                                                       value="<?= e((string) $slot['slot_number']) ?>">
                                            </div>
                                            <div class="field">
                                                <label for="name-<?= e((string) $slotId) ?>">Name</label>
                                                <input class="input" type="text" id="name-<?= e((string) $slotId) ?>"
                                                       name="slot_name" maxlength="100"<?= $dis ?>
                                                       value="<?= e((string) ($slot['slot_name'] ?? '')) ?>">
                                            </div>
                                            <div class="field">
                                                <label for="start-<?= e((string) $slotId) ?>">Beginn</label>
                                                <input class="input" type="time" id="start-<?= e((string) $slotId) ?>"
                                                       name="start_time" required<?= $dis ?>
                                                       value="<?= e($time($slot['start_time'])) ?>">
                                            </div>
                                            <div class="field">
                                                <label for="end-<?= e((string) $slotId) ?>">Ende</label>
                                                <input class="input" type="time" id="end-<?= e((string) $slotId) ?>"
                                                       name="end_time" required<?= $dis ?>
                                                       value="<?= e($time($slot['end_time'])) ?>">
                                            </div>
                                        </div>

                                        <label class="checkbox-row">
                                            <span class="switch">
                                                <input type="checkbox" name="is_managed" value="1"
                                                    <?= (int) $slot['is_managed'] === 1 ? 'checked' : '' ?><?= $dis ?>>
                                                <span class="slider"></span>
                                            </span>
                                            <span>Fester Zuteilungsslot</span>
                                        </label>
                                        <label class="checkbox-row">
                                            <span class="switch">
                                                <input type="checkbox" name="is_break" value="1"
                                                    <?= (int) $slot['is_break'] === 1 ? 'checked' : '' ?><?= $dis ?>>
                                                <span class="slider"></span>
                                            </span>
                                            <span>Pause</span>
                                        </label>

                                        <div class="cluster">
                                            <span class="badge<?= $used > 0 ? ' badge-warning' : '' ?>">
                                                <?= e((string) $slot['anmeldungen']) ?> Zuteilungen ·
                                                <?= e((string) $slot['anwesenheiten']) ?> Anwesenheiten
                                            </span>
                                            <?php if ($canEdit): ?>
                                                <button class="btn btn-sm btn-primary" type="submit">Speichern</button>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($used > 0 && (int) $slot['is_managed'] === 1): ?>
                                            <div class="hint">
                                                Achtung: Wird „Fester Zuteilungsslot“ deaktiviert, verlieren die bestehenden
                                                Zuteilungen ihre Grundlage — Zuteilung anschließend prüfen.
                                            </div>
                                        <?php endif; ?>
                                    </form>

                                    <?php if ($canEdit && $used === 0): ?>
                                        <form method="post"
                                              action="<?= e($ctx->schoolUrl('/admin/einstellungen/zeitslots/loeschen')) ?>"
                                              data-confirm="Diesen Zeitslot wirklich löschen?" style="margin-top:8px;">
                                            <?= $csrf->field() ?>
                                            <input type="hidden" name="slot_id" value="<?= e((string) $slotId) ?>">
                                            <button class="btn btn-sm btn-danger-ghost" type="submit">Zeitslot löschen</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($blockKey === 'zeitslot-assistent'): ?>
            <?php if ($canEdit): ?>
                <div class="card">
                    <div class="card-header">
                        <h3>🪄 Zeitraster-Assistent</h3>
                    </div>
                    <form method="post" action="<?= e($ctx->schoolUrl('/admin/einstellungen/zeitslots/assistent')) ?>"
                          data-confirm="Zeitraster jetzt erzeugen? Ein bestehendes Raster wird dabei ersetzt.">
                        <div class="card-body">
                            <?= $csrf->field() ?>
                            <p class="text-sm text-soft">
                                Erzeugt das komplette Raster in einem Schritt. Pausen bekommen einen eigenen Eintrag,
                                die Slot-Nummern werden fortlaufend vergeben.
                            </p>

                            <div class="form-grid">
                                <div class="field">
                                    <label for="raster-start">Beginn *</label>
                                    <input class="input" type="time" id="raster-start" name="start" value="08:00" required>
                                </div>
                                <div class="field">
                                    <label for="raster-count">Anzahl Slots *</label>
                                    <input class="input" type="number" id="raster-count" name="count" value="6" min="1" max="20" required>
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="field">
                                    <label for="raster-duration">Slotdauer (Minuten) *</label>
                                    <input class="input" type="number" id="raster-duration" name="duration" value="45" min="5" max="240" required>
                                </div>
                                <div class="field">
                                    <label for="raster-gap">Wechselzeit zwischen Slots (Minuten)</label>
                                    <input class="input" type="number" id="raster-gap" name="gap" value="5" min="0" max="60">
                                    <div class="hint">Zeit für den Raumwechsel. 0 = Slots schließen direkt aneinander an.</div>
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="field">
                                    <label for="raster-break-after">Große Pause nach Slot</label>
                                    <input class="input" type="number" id="raster-break-after" name="break_after" value="0" min="0" max="20">
                                    <div class="hint">0 = keine Pause einplanen.</div>
                                </div>
                                <div class="field">
                                    <label for="raster-break-minutes">Pausendauer (Minuten)</label>
                                    <input class="input" type="number" id="raster-break-minutes" name="break_minutes" value="20" min="5" max="120">
                                </div>
                            </div>

                            <div class="field">
                                <label for="raster-free">Slots zur freien Wahl</label>
                                <input class="input" type="text" id="raster-free" name="free_slots" placeholder="z. B. 2, 4">
                                <div class="hint">
                                    Nummern der Slots, die <strong>nicht</strong> vorab zugeteilt werden (Check-in vor Ort schreibt ein).
                                    Mehrere durch Komma trennen, leer lassen für lauter feste Slots.
                                </div>
                            </div>

                            <label class="checkbox-row">
                                <input type="checkbox" name="replace" value="1">
                                <span>Bestehendes Raster ersetzen</span>
                            </label>
                            <div class="hint">
                                Nur möglich, solange keine Zuteilungen oder Anwesenheiten daran hängen.
                            </div>
                        </div>
                        <div class="card-footer">
                            <button class="btn btn-accent" type="submit">Raster erzeugen</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

        <?php elseif ($blockKey === 'zeitslot-neu'): ?>
            <?php if ($canEdit): ?>
                <div class="card">
                    <div class="card-header"><h3>Neuen Zeitslot anlegen</h3></div>
                    <form method="post" action="<?= e($ctx->schoolUrl('/admin/einstellungen/zeitslots')) ?>">
                        <div class="card-body">
                            <?= $csrf->field() ?>
                            <div class="form-grid">
                                <div class="field">
                                    <label for="new-number">Slot-Nummer</label>
                                    <input class="input" type="number" id="new-number" name="slot_number" min="1" max="255" required
                                           value="<?= e((string) (count($timeslots) + 1)) ?>">
                                </div>
                                <div class="field">
                                    <label for="new-name">Name</label>
                                    <input class="input" type="text" id="new-name" name="slot_name" maxlength="100" placeholder="z. B. Slot 6">
                                </div>
                                <div class="field">
                                    <label for="new-start">Beginn</label>
                                    <input class="input" type="time" id="new-start" name="start_time" required>
                                </div>
                                <div class="field">
                                    <label for="new-end">Ende</label>
                                    <input class="input" type="time" id="new-end" name="end_time" required>
                                </div>
                            </div>
                            <label class="checkbox-row">
                                <span class="switch">
                                    <input type="checkbox" name="is_managed" value="1" checked>
                                    <span class="slider"></span>
                                </span>
                                <span>Fester Zuteilungsslot (Auto-Zuteilung)</span>
                            </label>
                            <label class="checkbox-row">
                                <span class="switch">
                                    <input type="checkbox" name="is_break" value="1">
                                    <span class="slider"></span>
                                </span>
                                <span>Pause (wird nie zugeteilt)</span>
                            </label>
                        </div>
                        <div class="card-footer">
                            <button class="btn btn-primary" type="submit">Zeitslot anlegen</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <?= block_close() ?>
    <?php endforeach; ?>

<?php elseif ($tab === 'zugang'): ?>
    <?php foreach (page_blocks('admin-einstellungen-zugang', [
        'zugang-optionen' => 'Zugang',
    ]) as $blockKey => $blockLabel): ?>
        <?= block_open($blockKey, $blockLabel) ?>
        <?php if ($blockKey === 'zugang-optionen'): ?>
            <div class="card">
                <div class="card-header"><h2>Zugang</h2></div>
                <form method="post" action="<?= e($ctx->schoolUrl('/admin/einstellungen/zugang')) ?>">
                    <div class="card-body">
                        <?= $csrf->field() ?>
                        <label class="checkbox-row">
                            <span class="switch">
                                <input type="checkbox" name="registration_page_enabled" value="1"
                                    <?= $settings['registration_page_enabled'] ? 'checked' : '' ?><?= $dis ?>>
                                <span class="slider"></span>
                            </span>
                            <span>Selbstregistrierung für Schüler:innen freischalten</span>
                        </label>
                        <div class="hint">Steuert die Seite <span class="mono">/registrieren</span> dieser Schule.</div>

                        <?php if ($isGlobalAdmin): ?>
                            <hr class="divider">
                            <h3>Seitenpasswort (global)</h3>
                            <p class="text-sm text-soft">
                                Schützt die gesamte Anwendung mit einem vorgeschalteten Code — gilt für alle Schulen.
                                Nur globale Administrator:innen sehen diese Einstellung.
                            </p>
                            <label class="checkbox-row">
                                <span class="switch">
                                    <input type="checkbox" name="site_password_enabled" value="1"
                                        <?= $settings['site_password_enabled'] ? 'checked' : '' ?><?= $dis ?>>
                                    <span class="slider"></span>
                                </span>
                                <span>Seitenpasswort aktiv</span>
                            </label>
                            <div class="field">
                                <label for="site-password">Neues Seitenpasswort</label>
                                <input class="input" type="password" id="site-password" name="site_password"
                                       autocomplete="new-password" minlength="<?= e((string) $minPasswordLength) ?>"<?= $dis ?>>
                                <div class="hint">
                                    Leer lassen, um das bestehende Passwort beizubehalten.
                                    Mindestens <?= e((string) $minPasswordLength) ?> Zeichen.
                                    <?= $settings['site_password_set'] ? 'Aktuell ist ein Passwort gesetzt.' : 'Aktuell ist kein Passwort gesetzt.' ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($canEdit): ?>
                        <div class="card-footer">
                            <button class="btn btn-primary" type="submit">Speichern</button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        <?php endif; ?>
        <?= block_close() ?>
    <?php endforeach; ?>

<?php elseif ($tab === 'qr'): ?>
    <?php foreach (page_blocks('admin-einstellungen-qr', [
        'qr-checkin' => 'QR-Codes & Check-in',
    ]) as $blockKey => $blockLabel): ?>
        <?= block_open($blockKey, $blockLabel) ?>
        <?php if ($blockKey === 'qr-checkin'): ?>
            <div class="card">
                <div class="card-header"><h2>QR-Codes & Check-in</h2></div>
                <form method="post" action="<?= e($ctx->schoolUrl('/admin/einstellungen/qr')) ?>">
                    <div class="card-body">
                        <?= $csrf->field() ?>

                        <h3>Check-in-Wege</h3>
                        <label class="checkbox-row">
                            <span class="switch">
                                <input type="checkbox" name="checkin_self_scan_enabled" value="1"
                                    <?= $settings['checkin_self_scan_enabled'] ? 'checked' : '' ?><?= $dis ?>>
                                <span class="slider"></span>
                            </span>
                            <span>Schüler:innen scannen den Aussteller-QR-Code selbst</span>
                        </label>
                        <label class="checkbox-row">
                            <span class="switch">
                                <input type="checkbox" name="checkin_teacher_scan_enabled" value="1"
                                    <?= $settings['checkin_teacher_scan_enabled'] ? 'checked' : '' ?><?= $dis ?>>
                                <span class="slider"></span>
                            </span>
                            <span>Lehrkräfte scannen die Schüler-QR-Codes</span>
                        </label>

                        <hr class="divider">
                        <h3>Zeitfenster für Schüler-Scans</h3>
                        <label class="checkbox-row">
                            <span class="switch">
                                <input type="checkbox" name="qr_validity_enabled" value="1"
                                    <?= $settings['qr_validity_enabled'] ? 'checked' : '' ?><?= $dis ?>>
                                <span class="slider"></span>
                            </span>
                            <span>Check-in nur innerhalb eines Zeitfensters erlauben</span>
                        </label>
                        <div class="form-grid">
                            <div class="field">
                                <label for="qr-before">Minuten vor Slot-Beginn</label>
                                <input class="input" type="number" id="qr-before" name="qr_validity_before" min="0" max="600"<?= $dis ?>
                                       value="<?= e((string) $settings['qr_validity_before']) ?>">
                            </div>
                            <div class="field">
                                <label for="qr-after">Minuten nach Slot-Ende</label>
                                <input class="input" type="number" id="qr-after" name="qr_validity_after" min="0" max="600"<?= $dis ?>
                                       value="<?= e((string) $settings['qr_validity_after']) ?>">
                            </div>
                        </div>

                        <hr class="divider">
                        <h3>Zeitfenster für Lehrer-Scans</h3>
                        <label class="checkbox-row">
                            <span class="switch">
                                <input type="checkbox" name="qr_validity_teacher_enabled" value="1"
                                    <?= $settings['qr_validity_teacher_enabled'] ? 'checked' : '' ?><?= $dis ?>>
                                <span class="slider"></span>
                            </span>
                            <span>Eigenes, großzügigeres Zeitfenster für Lehrkräfte</span>
                        </label>
                        <div class="form-grid">
                            <div class="field">
                                <label for="qr-t-before">Minuten vor Slot-Beginn</label>
                                <input class="input" type="number" id="qr-t-before" name="qr_validity_teacher_before"
                                       min="0" max="600"<?= $dis ?>
                                       value="<?= e((string) $settings['qr_validity_teacher_before']) ?>">
                            </div>
                            <div class="field">
                                <label for="qr-t-after">Minuten nach Slot-Ende</label>
                                <input class="input" type="number" id="qr-t-after" name="qr_validity_teacher_after"
                                       min="0" max="600"<?= $dis ?>
                                       value="<?= e((string) $settings['qr_validity_teacher_after']) ?>">
                            </div>
                        </div>
                    </div>
                    <?php if ($canEdit): ?>
                        <div class="card-footer">
                            <button class="btn btn-primary" type="submit">Speichern</button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        <?php endif; ?>
        <?= block_close() ?>
    <?php endforeach; ?>

<?php elseif ($tab === 'wartung' && $canMaintain): ?>
    <?php foreach (page_blocks('admin-einstellungen-wartung', [
        'wartung-hinweis' => 'Warnhinweis',
        'wartung-aktionen' => 'Wartungsaktionen',
    ]) as $blockKey => $blockLabel): ?>
        <?= block_open($blockKey, $blockLabel) ?>
        <?php if ($blockKey === 'wartung-hinweis'): ?>
            <div class="alert alert-warning">
                <span aria-hidden="true">⚠️</span>
                <div>Diese Aktionen lassen sich nicht rückgängig machen. Bitte nur nach Absprache ausführen.</div>
            </div>

        <?php elseif ($blockKey === 'wartung-aktionen'): ?>
            <div class="grid-2">
                <div class="card">
                    <div class="card-header"><h3>Anwesenheit zurücksetzen</h3></div>
                    <div class="card-body">
                        <p class="text-sm text-soft">
                            Löscht alle <strong><?= e((string) $attendanceCount) ?></strong> Anwesenheits-Einträge dieser Messe.
                            Zuteilungen und Anmeldungen bleiben erhalten.
                        </p>
                        <?php if ($canEdit): ?>
                            <form method="post" action="<?= e($ctx->schoolUrl('/admin/einstellungen/wartung')) ?>"
                                  data-confirm="Wirklich ALLE Anwesenheits-Einträge dieser Messe löschen?">
                                <?= $csrf->field() ?>
                                <input type="hidden" name="action" value="reset_attendance">
                                <button class="btn btn-danger" type="submit">Anwesenheit löschen</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h3>Schüler-QR-Tokens neu generieren</h3></div>
                    <div class="card-body">
                        <p class="text-sm text-soft">
                            Verwirft alle <strong><?= e((string) $tokenCount) ?></strong> persönlichen QR-Tokens.
                            Bereits gedruckte Karten werden damit ungültig; neue Tokens entstehen automatisch beim nächsten Aufruf.
                        </p>
                        <?php if ($canEdit): ?>
                            <form method="post" action="<?= e($ctx->schoolUrl('/admin/einstellungen/wartung')) ?>"
                                  data-confirm="Wirklich alle Schüler-QR-Tokens verwerfen? Gedruckte Karten werden ungültig.">
                                <?= $csrf->field() ?>
                                <input type="hidden" name="action" value="regenerate_tokens">
                                <button class="btn btn-danger" type="submit">Tokens neu generieren</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <?= block_close() ?>
    <?php endforeach; ?>
<?php endif; ?>
