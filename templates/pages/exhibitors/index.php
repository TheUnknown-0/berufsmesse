<?php
/**
 * Aussteller-Übersicht für Schüler:innen, Lehrkräfte & Orga.
 * Erwartet: $exhibitors, $branchen, $branche, $search.
 */
$base = $ctx->schoolUrl('/aussteller');
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Berufsmesse</div>
        <h1 class="page-title">Aussteller</h1>
        <p class="page-sub">Alle Unternehmen und Einrichtungen, die du auf der Messe treffen kannst.</p>
    </div>
</div>

<?php foreach (page_blocks('aussteller', [
    'filter' => 'Suche & Branchenfilter',
    'liste' => 'Aussteller-Karten',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'filter'): ?>
<div class="card mb-2">
    <div class="card-body">
        <form method="get" action="<?= e($base) ?>">
            <div class="form-grid">
                <div class="field mb-0">
                    <label for="q">Suche</label>
                    <input class="input" type="search" id="q" name="q" value="<?= e($search) ?>"
                           placeholder="Name oder Stichwort">
                </div>
                <div class="field mb-0">
                    <label for="branche">Branche</label>
                    <select id="branche" name="branche">
                        <option value="">Alle Branchen</option>
                        <?php foreach ($branchen as $name): ?>
                            <option value="<?= e($name) ?>" <?= $branche === $name ? 'selected' : '' ?>><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field mb-0">
                    <label for="search-submit">&nbsp;</label>
                    <div class="cluster">
                        <button class="btn btn-primary" id="search-submit" type="submit">Suchen</button>
                        <?php if ($search !== '' || $branche !== ''): ?>
                            <a class="btn btn-ghost" href="<?= e($base) ?>">Zurücksetzen</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<?php elseif ($blockKey === 'liste'): ?>
<?php if ($exhibitors === []): ?>
    <div class="empty-state">
        <div class="empty-icon">🔍</div>
        <p>Keine Aussteller gefunden. Probiere einen anderen Suchbegriff oder eine andere Branche.</p>
    </div>
<?php else: ?>
    <p class="text-soft text-sm"><?= e((string) count($exhibitors)) ?> Aussteller</p>
    <div class="school-grid">
        <?php foreach ($exhibitors as $exhibitor): ?>
            <a class="school-card" href="<?= e($ctx->schoolUrl('/aussteller/' . (int) $exhibitor['id'])) ?>">
                <div class="cluster mb-2">
                    <?php if (!empty($exhibitor['logo'])): ?>
                        <img src="<?= e($ctx->url('/medien/logos/' . $exhibitor['logo'])) ?>" alt=""
                             style="width:48px;height:48px;object-fit:contain;border-radius:8px;">
                    <?php else: ?>
                        <span style="font-size:2rem;line-height:1;" aria-hidden="true">🏢</span>
                    <?php endif; ?>
                    <h3 class="mb-0" style="flex:1;min-width:0;"><?= e($exhibitor['name']) ?></h3>
                </div>

                <?php if (!empty($exhibitor['short_description'])): ?>
                    <p class="text-sm text-soft"><?= e($exhibitor['short_description']) ?></p>
                <?php endif; ?>

                <?php if ($exhibitor['category_list'] !== []): ?>
                    <div class="chip-row mt-2">
                        <?php foreach ($exhibitor['category_list'] as $category): ?>
                            <span class="badge badge-primary"><?= e($category) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($exhibitor['offer_list'] !== []): ?>
                    <div class="chip-row mt-2">
                        <?php foreach ($exhibitor['offer_list'] as $offer): ?>
                            <span class="badge badge-accent"><?= e($offer) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($exhibitor['room_number'] !== null): ?>
                    <p class="text-sm text-faint mt-2 mb-0">
                        📍 Raum <?= e($exhibitor['room_number']) ?><?php
                        if (!empty($exhibitor['building'])) {
                            echo ' · ' . e($exhibitor['building']);
                        }
                        if (!empty($exhibitor['floor'])) {
                            echo ' · ' . e($exhibitor['floor']);
                        }
                        ?>
                    </p>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
