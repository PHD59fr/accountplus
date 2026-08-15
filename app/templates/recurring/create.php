<?php

$content = ob_start();
$t = $t ?? static fn ($key) => $key;
?>
<section class="ft-card narrow form-card">
    <span class="ft-kicker ft-kicker-blue"><?= e($t('recurring.kicker')) ?></span>
    <h2><?= e($t('recurring.create_title')) ?></h2>
    <?php require __DIR__ . '/_form.php'; ?>
</section>
<?php
$content = (string) ob_get_clean();

echo $this->render('layout/app', [
    'title' => $t('recurring.create_title') . ' - ' . $appName,
    'pageTitle' => $t('recurring.create_title'),
    'pageDescription' => $t('recurring.create_description'),
    'appName' => $appName,
    'currentCompany' => $currentCompany,
    'content' => $content,
]);
