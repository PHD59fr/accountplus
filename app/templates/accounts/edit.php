<?php

$content = ob_start();
$t = $t ?? static fn ($key) => $key;
?>
<section class="ft-card narrow form-card">
    <span class="ft-kicker ft-kicker-blue"><?= e($t('accounts.kicker')) ?></span>
    <h2><?= e($t('accounts.edit_title_page')) ?></h2>
    <?php if ($currentCompany !== null): ?>
        <p class="section-text"><?= e($t('accounts.belongs_to', ['name' => $currentCompany->name()])) ?></p>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="alert"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/accounts/update" class="ft-form ft-form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e((string) $accountId) ?>">
        <label>
            <span><?= e($t('accounts.name')) ?></span>
            <input type="text" name="name" value="<?= e((string) $values['name']) ?>">
        </label>
        <label>
            <span><?= e($t('accounts.bank_name')) ?></span>
            <input type="text" name="bank_name" value="<?= e((string) $values['bank_name']) ?>">
        </label>
        <label>
            <span><?= e($t('accounts.account_number')) ?></span>
            <input type="text" name="account_number" value="<?= e((string) $values['account_number']) ?>">
        </label>
        <label>
            <span><?= e($t('accounts.currency')) ?></span>
            <input type="text" name="currency" value="<?= e((string) $values['currency']) ?>">
        </label>
        <label>
            <span><?= e($t('accounts.initial_balance')) ?></span>
            <input type="text" name="initial_balance" value="<?= e((string) $values['initial_balance']) ?>">
        </label>
        <label>
            <span><?= e($t('accounts.description_field')) ?></span>
            <input type="text" name="description" value="<?= e((string) $values['description']) ?>">
        </label>
        <button type="submit" class="ft-form-btn"><?= e($t('accounts.save')) ?></button>
    </form>
</section>
<?php
$content = (string) ob_get_clean();

echo $this->render('layout/app', [
    'title' => $t('accounts.edit_title_page') . ' - ' . $appName,
    'pageTitle' => $t('accounts.edit_title_page'),
    'pageDescription' => $t('accounts.edit_description_page'),
    'appName' => $appName,
    'currentCompany' => $currentCompany ?? null,
    'content' => $content,
]);
