<?php
/**
 * Aussteller-Detailseite (Schüler-Sicht).
 * Erwartet: $exhibitor, $categoryList, $offerList, $visibleFields,
 * $fieldLabels, $documents.
 */
$visible = static fn (string $field): bool => in_array($field, $visibleFields, true);
$hasContact = ($visible('contact_person') && !empty($exhibitor['contact_person']))
    || ($visible('email') && !empty($exhibitor['email']))
    || ($visible('phone') && !empty($exhibitor['phone']))
    || ($visible('website') && !empty($exhibitor['website']));
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a class="text-soft" href="<?= e($ctx->schoolUrl('/aussteller')) ?>">← Alle Aussteller</a></div>
        <h1 class="page-title"><?= e($exhibitor['name']) ?></h1>
        <?php if (!empty($exhibitor['short_description'])): ?>
            <p class="page-sub"><?= e($exhibitor['short_description']) ?></p>
        <?php endif; ?>
    </div>
</div>

<div class="grid-2">
<?php foreach (page_blocks('aussteller-detail', [
    'profil' => 'Profil & Beschreibung',
    'infospalte' => 'Raum, Kontakt & Unterlagen',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'profil'): ?>
    <div class="stack">
        <div class="card">
            <div class="card-body">
                <?php if (!empty($exhibitor['logo'])): ?>
                    <img src="<?= e($ctx->url('/medien/logos/' . $exhibitor['logo'])) ?>" alt="Logo"
                         style="max-width:160px;max-height:100px;object-fit:contain;margin-bottom:14px;">
                <?php endif; ?>

                <?php if ($categoryList !== []): ?>
                    <div class="chip-row mb-2">
                        <?php foreach ($categoryList as $category): ?>
                            <span class="badge badge-primary"><?= e($category) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($offerList !== []): ?>
                    <div class="chip-row mb-2">
                        <?php foreach ($offerList as $offer): ?>
                            <span class="badge badge-accent"><?= e($offer) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($exhibitor['description'])): ?>
                    <p style="white-space:pre-line;"><?= e($exhibitor['description']) ?></p>
                <?php else: ?>
                    <p class="text-faint">Für dieses Unternehmen liegt noch keine ausführliche Beschreibung vor.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($visible('jobs') && !empty($exhibitor['jobs'])): ?>
            <div class="card">
                <div class="card-header"><h2><?= e($fieldLabels['jobs']) ?></h2></div>
                <div class="card-body"><p class="mb-0" style="white-space:pre-line;"><?= e($exhibitor['jobs']) ?></p></div>
            </div>
        <?php endif; ?>

        <?php if ($visible('features') && !empty($exhibitor['features'])): ?>
            <div class="card">
                <div class="card-header"><h2><?= e($fieldLabels['features']) ?></h2></div>
                <div class="card-body"><p class="mb-0" style="white-space:pre-line;"><?= e($exhibitor['features']) ?></p></div>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($blockKey === 'infospalte'): ?>
    <div class="stack">
        <?php if ($exhibitor['room_number'] !== null): ?>
            <div class="card">
                <div class="card-header"><h2>Wo du sie findest</h2></div>
                <div class="card-body">
                    <div class="stat-value">Raum <?= e($exhibitor['room_number']) ?></div>
                    <div class="stat-label">
                        <?php if (!empty($exhibitor['room_name'])): ?><?= e($exhibitor['room_name']) ?><br><?php endif; ?>
                        <?php if (!empty($exhibitor['building'])): ?>Gebäude <?= e($exhibitor['building']) ?><?php endif; ?>
                        <?php if (!empty($exhibitor['floor'])): ?> · Etage <?= e($exhibitor['floor']) ?><?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($hasContact): ?>
            <div class="card">
                <div class="card-header"><h2>Kontakt</h2></div>
                <div class="card-body stack">
                    <?php if ($visible('contact_person') && !empty($exhibitor['contact_person'])): ?>
                        <div>
                            <div class="stat-label"><?= e($fieldLabels['contact_person']) ?></div>
                            <div><?= e($exhibitor['contact_person']) ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($visible('email') && !empty($exhibitor['email'])): ?>
                        <div>
                            <div class="stat-label"><?= e($fieldLabels['email']) ?></div>
                            <a href="mailto:<?= e($exhibitor['email']) ?>"><?= e($exhibitor['email']) ?></a>
                        </div>
                    <?php endif; ?>
                    <?php if ($visible('phone') && !empty($exhibitor['phone'])): ?>
                        <div>
                            <div class="stat-label"><?= e($fieldLabels['phone']) ?></div>
                            <div><?= e($exhibitor['phone']) ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($visible('website') && !empty($exhibitor['website'])): ?>
                        <div>
                            <div class="stat-label"><?= e($fieldLabels['website']) ?></div>
                            <a href="<?= e($exhibitor['website']) ?>" target="_blank" rel="noopener noreferrer">
                                <?= e($exhibitor['website']) ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($documents !== []): ?>
            <div class="card">
                <div class="card-header"><h2>Unterlagen</h2></div>
                <div class="card-body">
                    <div class="stack">
                        <?php foreach ($documents as $document): ?>
                            <a class="btn" href="<?= e($ctx->schoolUrl('/api/dokumente/download/' . (int) $document['id'])) ?>">
                                📄 <?= e($document['original_name']) ?>
                                <span class="text-faint text-sm"><?= e((string) round(((int) $document['file_size']) / 1024)) ?> KB</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
</div>
