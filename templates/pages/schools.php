<?php /** Landingpage: Schulauswahl. Erwartet: $schools. */ ?>
<h1 style="font-size:1.35rem;">Wähle deine Schule</h1>

<?php if ($schools === []): ?>
    <div class="empty-state">
        <div class="empty-icon">🏫</div>
        <p>Es sind noch keine Schulen eingerichtet.</p>
    </div>
<?php else: ?>
    <div class="school-grid" style="grid-template-columns:1fr;">
        <?php foreach ($schools as $school): ?>
            <a class="school-card" href="<?= e($ctx->url('/' . $school['slug'] . '/')) ?>">
                <?php if (!empty($school['logo'])): ?>
                    <img class="brand-logo" style="width:42px;height:42px;margin-bottom:8px;"
                         src="<?= e($ctx->url('/medien/logos/' . $school['logo'])) ?>" alt="">
                <?php endif; ?>
                <h3><?= e($school['name']) ?></h3>
                <?php if (!empty($school['address'])): ?>
                    <div class="text-sm text-soft"><?= e($school['address']) ?></div>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<p class="text-sm" style="margin-top:20px;">
    <a class="text-soft" href="<?= e($ctx->url('/login')) ?>">Login für Admins & Aussteller</a>
</p>
