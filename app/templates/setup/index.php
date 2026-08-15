<?php

$values = is_array($values ?? null) ? $values : [];
$content = ob_start();
$t = $t ?? static fn ($key) => $key;
?>
<section class="ft-card narrow">
    <span class="ft-kicker ft-kicker-blue"><?= e($t('setup.kicker')) ?></span>
    <h2><?= e($t('setup.title')) ?></h2>
    <p class="section-text">
        <?= e($t('setup.intro')) ?>
    </p>
    <?php if ($installed): ?>
        <div class="alert alert-success"><?= e($t('setup.state_exists')) ?></div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="alert"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/setup" class="ft-form">
        <?= csrf_field() ?>
        <label>
            <span><?= e($t('setup.token')) ?></span>
            <input type="password" name="setup_token" autocomplete="off" required>
        </label>
        <label>
            <span><?= e($t('setup.app_name')) ?></span>
            <input type="text" name="app_name" value="<?= e((string) ($values['app_name'] ?? $appName)) ?>">
        </label>
        <label>
            <span><?= e($t('setup.admin_username')) ?></span>
            <input type="text" name="admin_username" value="<?= e((string) ($values['admin_username'] ?? 'admin')) ?>">
        </label>
        <label>
            <span><?= e($t('setup.admin_password')) ?></span>
            <input type="password" name="admin_password" autocomplete="new-password">
        </label>
        <label>
            <span><?= e($t('setup.admin_password_confirm')) ?></span>
            <input type="password" name="admin_password_confirm" autocomplete="new-password">
        </label>
        <label>
            <span><?= e($t('setup.db_host')) ?></span>
            <input type="text" name="db_host" value="<?= e((string) ($values['db_host'] ?? '127.0.0.1')) ?>">
        </label>
        <label>
            <span><?= e($t('setup.db_port')) ?></span>
            <input type="text" name="db_port" value="<?= e((string) ($values['db_port'] ?? '3306')) ?>">
        </label>
        <label>
            <span><?= e($t('setup.db_name')) ?></span>
            <input type="text" name="db_name" value="<?= e((string) ($values['db_name'] ?? 'compte')) ?>">
        </label>
        <label>
            <span><?= e($t('setup.db_username')) ?></span>
            <input type="text" name="db_username" value="<?= e((string) ($values['db_username'] ?? 'compte')) ?>">
        </label>
        <label>
            <span><?= e($t('setup.db_password')) ?></span>
            <input type="password" name="db_password" autocomplete="new-password">
        </label>
        <button type="submit" class="ft-form-btn"><?= e($t('setup.save')) ?></button>
    </form>
</section>
<?php
$content = (string) ob_get_clean();

echo $this->render('layout/setup', [
    'title' => $t('setup.title') . ' - ' . $appName,
    'appName' => $appName,
    'content' => $content,
]);
