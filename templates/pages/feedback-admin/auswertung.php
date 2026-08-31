<?php
/**
 * Feedback-Auswertung.
 * Erwartet: $form, $service (FeedbackService), $results, $total, $byRole, $roleFilter.
 */

use App\Services\FeedbackService;

$base = $ctx->schoolUrl('/admin/feedback/' . (int) $form['id']);
$roleNames = ['student' => 'Schüler:innen', 'teacher' => 'Lehrkräfte', 'exhibitor' => 'Aussteller'];
$filterUrl = static fn (string $role): string => $ctx->schoolUrl('/admin/feedback/' . (int) $form['id'] . '/auswertung')
    . ($role !== '' ? '?rolle=' . urlencode($role) : '');
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">
            <a class="text-soft" href="<?= e($ctx->schoolUrl('/admin/feedback')) ?>">← Feedback</a>
        </div>
        <h1 class="page-title">Auswertung</h1>
        <p class="page-sub">
            <?= e((string) $form['title']) ?> ·
            <?= (int) $form['is_anonymous'] === 1 ? 'anonym erhoben' : 'namentlich erhoben' ?>
        </p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="<?= e($base . '/export?format=csv') ?>">⬇ CSV</a>
        <a class="btn btn-ghost" href="<?= e($base . '/export?format=xlsx') ?>">⬇ Excel</a>
    </div>
</div>

<?php foreach (page_blocks('admin-feedback-auswertung', [
    'kennzahlen' => 'Kennzahlen & Filter',
    'ergebnisse' => 'Ergebnisse je Frage',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'kennzahlen'): ?>
    <div class="stat-grid">
        <div class="stat-card stat-accent">
            <div class="stat-value"><?= e((string) $total) ?></div>
            <div class="stat-label">Rückmeldungen</div>
        </div>
        <?php foreach ($service->audienceRoles($form) as $role): ?>
            <div class="stat-card">
                <div class="stat-value"><?= e((string) ($byRole[$role] ?? 0)) ?></div>
                <div class="stat-label"><?= e($roleNames[$role]) ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (count($service->audienceRoles($form)) > 1): ?>
        <div class="tabs">
            <a class="tab<?= $roleFilter === '' ? ' active' : '' ?>" href="<?= e($filterUrl('')) ?>">Alle</a>
            <?php foreach ($service->audienceRoles($form) as $role): ?>
                <a class="tab<?= $roleFilter === $role ? ' active' : '' ?>" href="<?= e($filterUrl($role)) ?>">
                    <?= e($roleNames[$role]) ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php elseif ($blockKey === 'ergebnisse'): ?>
    <?php if ($total === 0): ?>
        <div class="empty-state">
            <div class="empty-icon">📊</div>
            <p>Noch keine Rückmeldungen eingegangen.</p>
        </div>
    <?php else: ?>
        <?php foreach ($results as $index => $result): ?>
            <?php
            $question = $result['question'];
            $type = (string) $question['type'];
            $answered = (int) $result['answer_count'];
            ?>
            <div class="card card-pad">
                <h2 class="mt-0">
                    <?= e((string) ($index + 1)) ?>. <?= e((string) $question['label']) ?>
                </h2>
                <p class="text-sm text-soft">
                    <?= e(FeedbackService::TYPES[$type] ?? $type) ?>
                    <?php if ($type === 'multi_choice'): ?>
                        · <?= e((string) $answered) ?> Nennungen
                    <?php else: ?>
                        · <?= e((string) $answered) ?> Antworten
                    <?php endif; ?>
                    <?php if ($result['average'] !== null): ?>
                        · Durchschnitt <strong><?= e(number_format((float) $result['average'], 2, ',', '')) ?></strong>
                    <?php endif; ?>
                </p>

                <?php if ($result['buckets'] !== []): ?>
                    <?php
                    $maxCount = max(array_map(static fn (array $b): int => $b['count'], $result['buckets']));
                    $maxCount = $maxCount > 0 ? $maxCount : 1;
                    ?>
                    <div class="stack">
                        <?php foreach ($result['buckets'] as $bucket): ?>
                            <?php
                            $share = $answered > 0 ? round($bucket['count'] / $answered * 100) : 0;
                            $width = round($bucket['count'] / $maxCount * 100);
                            ?>
                            <div>
                                <div class="cluster">
                                    <span style="flex:1;min-width:120px;"><?= e($bucket['label']) ?></span>
                                    <span class="text-sm text-soft">
                                        <?= e((string) $bucket['count']) ?> · <?= e((string) $share) ?> %
                                    </span>
                                </div>
                                <div class="progress"><span style="width:<?= e((string) $width) ?>%;"></span></div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                <?php elseif ($result['texts'] !== []): ?>
                    <div class="stack">
                        <?php foreach ($result['texts'] as $text): ?>
                            <div class="alert alert-info"><?= nl2br(e($text)) ?></div>
                        <?php endforeach; ?>
                    </div>

                <?php else: ?>
                    <p class="text-faint">Keine Antworten auf diese Frage.</p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
