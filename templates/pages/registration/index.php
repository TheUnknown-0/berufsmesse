<?php
/**
 * Schüler-Einschreibung: Aussteller mit Priorität wählen.
 * Erwartet: $edition, $exhibitors, $selected, $own, $max, $open, $tester.
 */
$prioLabel = static function (int $p): string {
    return match ($p) {
        1 => '1 · Hoch',
        2 => '2 · Mittel',
        3 => '3 · Niedrig',
        default => 'Priorität ' . $p,
    };
};
$chosen = count($selected);
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><?= e($edition['name']) ?></div>
        <h1 class="page-title">Einschreibung</h1>
        <p class="page-sub">Wähle bis zu <?= e((string) $max) ?> Aussteller und lege fest, wie wichtig sie dir sind.</p>
    </div>
    <div class="page-actions">
        <a class="btn" href="<?= e($ctx->schoolUrl('/meine-anmeldungen')) ?>">⭐ Meine Anmeldungen</a>
    </div>
</div>

<?php $registrationBlocks = page_blocks('einschreibung', [
    'hinweise' => 'Hinweise',
    'auswahl' => 'Ausstellerauswahl',
    'hilfe' => 'So funktioniert die Einschreibung',
]); ?>
<?php foreach ($registrationBlocks as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'hinweise'): ?>
    <?php if ($tester): ?>
        <div class="alert alert-warning">
            <span aria-hidden="true">🧪</span>
            <div>
                <strong>Testmodus.</strong> Du bist als Administration angemeldet und kannst dich auch außerhalb
                des Einschreibezeitraums für dich selbst eintragen.
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$open): ?>
        <div class="alert alert-info">
            <span aria-hidden="true">🕒</span>
            <div>
                <strong>Die Einschreibung ist gerade nicht geöffnet.</strong><br>
                <?php if (!empty($edition['registration_start']) || !empty($edition['registration_end'])): ?>
                    Zeitraum:
                    <?= e(!empty($edition['registration_start']) ? format_datetime($edition['registration_start']) : 'offen') ?>
                    bis
                    <?= e(!empty($edition['registration_end']) ? format_datetime($edition['registration_end']) : 'offen') ?> Uhr.
                <?php else: ?>
                    Der Zeitraum wurde noch nicht festgelegt. Bitte schau später noch einmal vorbei.
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

<?php elseif ($blockKey === 'auswahl'): ?>
    <?php if ($open || $tester): ?>
        <?php if ($exhibitors === []): ?>
            <div class="card card-pad">
                <div class="empty-state">
                    <div class="empty-icon" aria-hidden="true">🏢</div>
                    <p>Zurzeit stehen keine Aussteller zur Auswahl bereit.</p>
                </div>
            </div>
        <?php else: ?>
            <form method="post" action="<?= e($ctx->schoolUrl('/einschreibung')) ?>" data-registration-form data-max="<?= e((string) $max) ?>">
                <?= $csrf->field() ?>

                <div class="card mb-2">
                    <div class="card-body">
                        <div class="cluster">
                            <div style="flex:1;min-width:220px;">
                                <label class="text-sm text-soft" for="exhibitor-search">Aussteller suchen</label>
                                <input class="input" type="search" id="exhibitor-search" data-exhibitor-filter
                                       placeholder="Name oder Branche …" autocomplete="off">
                            </div>
                            <div>
                                <div class="text-sm text-soft">Gewählt</div>
                                <div class="stat-value"><span data-selected-count><?= e((string) $chosen) ?></span> / <?= e((string) $max) ?></div>
                            </div>
                        </div>
                        <p class="hint" style="margin-top:10px;">
                            Priorität 1 ist dein größter Wunsch. Jede Priorität darfst du nur einmal vergeben.
                            Der genaue Zeitslot wird später automatisch zugeteilt.
                        </p>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                        <tr>
                            <th>Aussteller</th>
                            <th>Raum</th>
                            <th style="width:190px;">Priorität</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($exhibitors as $exhibitor): ?>
                            <?php
                            $exhibitorId = (int) $exhibitor['id'];
                            $current = $selected[$exhibitorId] ?? null;
                            $categories = [];
                            if (!empty($exhibitor['categories'])) {
                                $decoded = json_decode((string) $exhibitor['categories'], true);
                                if (is_array($decoded)) {
                                    $categories = array_slice(array_filter($decoded, 'is_string'), 0, 3);
                                }
                            }
                            $haystack = $exhibitor['name'] . ' ' . implode(' ', $categories) . ' ' . ($exhibitor['short_description'] ?? '');
                            ?>
                            <tr data-exhibitor-row data-search="<?= e(mb_strtolower($haystack)) ?>">
                                <td>
                                    <strong><?= e($exhibitor['name']) ?></strong>
                                    <?php if (!empty($exhibitor['short_description'])): ?>
                                        <div class="text-sm text-soft"><?= e($exhibitor['short_description']) ?></div>
                                    <?php endif; ?>
                                    <?php if ($categories !== []): ?>
                                        <div class="chip-row" style="margin-top:4px;">
                                            <?php foreach ($categories as $category): ?>
                                                <span class="badge"><?= e($category) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-sm text-soft">
                                    <?php if (!empty($exhibitor['room_number'])): ?>
                                        <?= e($exhibitor['room_number']) ?>
                                        <?php if (!empty($exhibitor['room_name'])): ?>
                                            · <?= e($exhibitor['room_name']) ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-faint">noch offen</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <label class="text-sm text-soft" for="prio-<?= e((string) $exhibitorId) ?>">Priorität</label>
                                    <select id="prio-<?= e((string) $exhibitorId) ?>"
                                            name="priority[<?= e((string) $exhibitorId) ?>]" data-priority>
                                        <option value="">— nicht wählen —</option>
                                        <?php for ($p = 1; $p <= $max; $p++): ?>
                                            <option value="<?= e((string) $p) ?>" <?= $current === $p ? 'selected' : '' ?>>
                                                <?= e($prioLabel($p)) ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="cluster mt-2">
                    <button class="btn btn-primary btn-lg" type="submit">Auswahl speichern</button>
                    <span class="text-sm text-soft" data-selection-hint></span>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>

<?php elseif ($blockKey === 'hilfe'): ?>
    <div class="card mt-2">
        <div class="card-header"><h3>So funktioniert die Einschreibung</h3></div>
        <div class="card-body text-sm">
            <ul style="margin:0;padding-left:20px;">
                <li>Du kannst dich für bis zu <?= e((string) $max) ?> Aussteller eintragen.</li>
                <li>Jeden Aussteller kannst du nur einmal wählen.</li>
                <li>Priorität 1 zählt am meisten — sie wird bei der Zuteilung zuerst berücksichtigt.</li>
                <li>Die Zuteilung zu den festen Zeitslots erfolgt später automatisch.</li>
                <li>Solange der Einschreibezeitraum läuft, kannst du deine Auswahl jederzeit ändern.</li>
            </ul>
        </div>
    </div>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
