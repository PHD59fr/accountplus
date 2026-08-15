<?php

$content = ob_start();
$t = $t ?? static fn ($key) => $key;
?>
<section class="ft-card narrow form-card">
    <span class="ft-kicker ft-kicker-blue"><?= e($t('categories.kicker')) ?></span>
    <h2><?= e($t('categories.edit_title')) ?></h2>
    <?php require __DIR__ . '/_form.php'; ?>
</section>
<?php
$content = (string) ob_get_clean();

echo $this->render('layout/app', [
    'title' => $t('categories.edit_title') . ' - ' . $appName,
    'pageTitle' => $t('categories.edit_title'),
    'pageDescription' => $t('categories.edit_description'),
    'appName' => $appName,
    'currentCompany' => $currentCompany,
    'content' => $content,
]);
