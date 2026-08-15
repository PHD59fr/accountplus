<?php

$content = ob_start();
$t = $t ?? static fn ($key) => $key;
?>
<section class="ft-card narrow form-card">
    <span class="ft-kicker ft-kicker-blue"><?= e($t('companies.kicker')) ?></span>
    <h2><?= e($t('companies.create_title')) ?></h2>
    <p class="section-text"><?= e($t('companies.create_description')) ?></p>
    <?php if ($error !== null): ?>
        <div class="alert"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/companies" class="ft-form ft-form-grid">
        <?= csrf_field() ?>
        <label class="span-2">
            <span><?= e($t('companies.name')) ?></span>
            <input type="text" name="name" value="<?= e((string) $values['name']) ?>" placeholder="<?= e($t('companies.name_placeholder')) ?>">
        </label>
        <label>
            <span><?= e($t('companies.source_label')) ?></span>
            <input type="text" name="source_label" value="<?= e((string) $values['source_label']) ?>" placeholder="MANUAL">
        </label>
        <button type="submit" class="ft-form-btn"><?= icon('save', $t('companies.save')) ?><?= e($t('companies.save')) ?></button>
    </form>
</section>
<?php
$content = (string) ob_get_clean();

echo $this->render('layout/app', [
    'title' => $t('companies.create_title') . ' - ' . $appName,
    'pageTitle' => $t('companies.create_title'),
    'pageDescription' => $t('companies.create_description'),
    'appName' => $appName,
    'currentCompany' => $currentCompany,
    'content' => $content,
]);
