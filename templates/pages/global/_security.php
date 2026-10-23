<?php
/**
 * Global-Admin: Ergebnis des Sicherheitschecks der Server-Umgebung
 * (bin/security-check.php, siehe Services\SecurityCheck).
 * Erwartet: $security = ['enabled' => bool, 'stale' => bool, 'state' => ?array].
 */
$state = $security['state'] ?? null;
$findings = $state['findings'] ?? [];
$errors = $state['errors'] ?? [];
$notes = $state['notes'] ?? [];
$checkedAt = isset($state['checked_at']) ? format_datetime($state['checked_at']) : null;
$severityLabels = ['critical' => 'kritisch', 'high' => 'hoch'];
// Bei vielen Funden (typisch: veraltetes Basis-Image) nur die schwersten
// direkt zeigen, den Rest aufklappbar; dazu eine Zählung je Komponente.
$visibleCount = 5;
$perComponent = array_count_values(array_column($findings, 'component'));
?>
<?php if ($findings !== []): ?>
    <div class="alert alert-danger" role="alert"><div>
        <strong>🛡️ Sicherheitswarnung: <?= e((string) count($findings)) ?> bekannte Lücke(n) mit hohem oder kritischem Schweregrad in der Server-Umgebung.</strong>
        <div><?= e(implode(' · ', array_map(
            static fn (string $component, int $count): string => $component . ': ' . $count,
            array_keys($perComponent),
            $perComponent,
        ))) ?></div>
        <?php foreach (['top' => array_slice($findings, 0, $visibleCount), 'rest' => array_slice($findings, $visibleCount)] as $part => $rows): ?>
            <?php if ($rows === []) {
                continue;
            } ?>
            <?php if ($part === 'rest'): ?>
                <details class="mb-2"><summary>Weitere <?= e((string) count($rows)) ?> Funde anzeigen</summary>
            <?php endif; ?>
            <div class="table-wrap mb-2" style="margin-top:.5rem;">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>Komponente</th>
                        <th>Kennung</th>
                        <th>Schweregrad</th>
                        <th>Beschreibung</th>
                        <th>Behoben in</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $finding): ?>
                        <tr>
                            <td><?= e($finding['component']) ?></td>
                            <td style="white-space:nowrap;">
                                <?php if (!empty($finding['url'])): ?>
                                    <a href="<?= e($finding['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($finding['id']) ?></a>
                                <?php else: ?>
                                    <?= e($finding['id']) ?>
                                <?php endif; ?>
                            </td>
                            <td style="white-space:nowrap;">
                                <span class="badge <?= $finding['severity'] === 'critical' ? 'badge-danger' : 'badge-warning' ?>"><?= e($severityLabels[$finding['severity']] ?? $finding['severity']) ?></span>
                                <?php if ($finding['score'] !== null): ?> <?= e(number_format((float) $finding['score'], 1, ',', '')) ?><?php endif; ?>
                            </td>
                            <td><?= e(preg_replace('/^Issue summary:\s*/', '', (string) $finding['summary'])) ?></td>
                            <td><?= e($finding['fixed'] ?? '–') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($part === 'rest'): ?>
                </details>
            <?php endif; ?>
        <?php endforeach; ?>
        <div><strong>Abhilfe:</strong> Auf dem Server im Projektordner <code>git pull</code> und
            <code>docker compose up -d --build</code> ausführen. Der Build holt das aktuelle PHP-Basis-Image
            samt Systempaketen. Die Warnung verschwindet nach der nächsten Prüfung.</div>
        <div class="hint">Letzte Prüfung: <?= e($checkedAt) ?>, PHP <?= e($state['php_version'] ?? '?') ?>.</div>
    </div></div>
<?php elseif (!$security['enabled']): ?>
    <div class="alert alert-info"><div>
        🛡️ Der Sicherheitscheck der Server-Umgebung ist abgeschaltet (<code>SECURITY_CHECK_INTERVAL=0</code>).
    </div></div>
<?php elseif ($security['stale']): ?>
    <div class="alert alert-warning" role="alert"><div>
        🛡️ <strong>Der Sicherheitscheck läuft nicht.</strong>
        <?= $checkedAt !== null ? 'Letzte Prüfung: ' . e($checkedAt) . '.' : 'Es wurde noch nie geprüft.' ?>
        Ohne aktuelle Prüfung ist unklar, ob PHP und die Systempakete bekannte Lücken haben.
        Das Container-Log zeigt die Ausgabe von <code>bin/security-check.php</code>.
    </div></div>
<?php elseif ($errors !== []): ?>
    <div class="alert alert-warning" role="alert"><div>
        🛡️ <strong>Sicherheitscheck unvollständig</strong> (<?= e($checkedAt) ?>): Nicht alle Quellen waren erreichbar.
        <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
    </div></div>
<?php else: ?>
    <div class="alert alert-success"><div>
        🛡️ Keine bekannten hohen oder kritischen Lücken in PHP <?= e($state['php_version'] ?? '?') ?> und den Systempaketen.
        <span class="hint">Letzte Prüfung: <?= e($checkedAt) ?>.</span>
    </div></div>
<?php endif; ?>
<?php if ($findings !== [] && $errors !== []): ?>
    <div class="alert alert-warning"><div>
        Nicht alle Quellen waren erreichbar:
        <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
    </div></div>
<?php endif; ?>
<?php if ($notes !== [] && $security['enabled'] && !$security['stale']): ?>
    <div class="hint mb-2"><?php foreach ($notes as $note): ?><div><?= e($note) ?></div><?php endforeach; ?></div>
<?php endif; ?>
