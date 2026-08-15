<?php

$content = ob_start();
$t = $t ?? static fn ($key) => $key;
?>
<section class="hero-metrics">
    <?php foreach ($cards as $card): ?>
        <div class="metric-card">
            <span><?= e($t((string) ($card['title_key'] ?? ''))) ?></span>
            <strong><?= e($card['value']) ?></strong>
        </div>
    <?php endforeach; ?>
</section>

<section class="ft-card">
    <header class="ft-card-head">
        <div>
            <span class="ft-kicker ft-kicker-blue"><?= e($t('reports.kicker')) ?></span>
            <h3><?= e($t('reports.title')) ?></h3>
        </div>
    </header>
    <p class="section-text"><?= e($t('reports.description')) ?></p>
    <?php if (($canManageData ?? false) === true): ?>
        <a href="/reports/transactions" class="ft-form-btn"><?= icon('next') ?><?= e($t('reports.transactions_link')) ?></a>
    <?php endif; ?>
</section>
<?php
$content = (string) ob_get_clean();

echo $this->render('layout/app', [
    'title' => $t('reports.title') . ' - ' . $appName,
    'pageTitle' => $t('reports.title'),
    'pageDescription' => $t('reports.page_description'),
    'appName' => $appName,
    'currentCompany' => $currentCompany ?? null,
    'content' => $content,
]);
