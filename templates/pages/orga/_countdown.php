<?php
/**
 * Countdown bis zu einem Abschlusstermin. orga.js zählt live herunter;
 * ohne JavaScript bleibt der Stand vom Seitenaufruf stehen.
 * Erwartet: $deadline (array{at: string, label: string}|null), optional $canSet (bool).
 */
if ($deadline === null): ?>
    <div class="empty-state" style="padding:24px;">
        <div class="empty-icon">⏳</div>
        <p class="mb-0">Noch kein Abschlusstermin gesetzt.</p>
        <?php if (!empty($canSet)): ?>
            <a class="btn btn-sm btn-ghost mt-2" href="<?= e($ctx->schoolUrl('/orga/verwaltung')) ?>">Termin festlegen</a>
        <?php endif; ?>
    </div>
<?php else:
    $target = new DateTimeImmutable($deadline['at']);
    $diff = max(0, $target->getTimestamp() - time());
    $over = $target->getTimestamp() <= time();
    $units = [
        'd' => [intdiv($diff, 86400), 'Tage'],
        'h' => [intdiv($diff % 86400, 3600), 'Stunden'],
        'm' => [intdiv($diff % 3600, 60), 'Minuten'],
        's' => [$diff % 60, 'Sekunden'],
    ];
?>
    <div class="stack" style="gap:10px;">
        <div>
            <strong><?= e($deadline['label']) ?></strong>
            <span class="text-soft text-sm">· <?= e(format_datetime($deadline['at'])) ?> Uhr</span>
        </div>
        <div class="countdown<?= $over ? ' is-over' : '' ?>" data-countdown="<?= e($target->format('c')) ?>">
            <?php foreach ($units as $key => [$value, $label]): ?>
                <div class="countdown-unit">
                    <div class="countdown-value" data-unit="<?= e($key) ?>"><?= e(str_pad((string) $value, 2, '0', STR_PAD_LEFT)) ?></div>
                    <div class="countdown-label"><?= e($label) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="text-sm text-danger" data-countdown-over <?= $over ? '' : 'hidden' ?>>Der Termin ist erreicht.</div>
    </div>
<?php endif; ?>
