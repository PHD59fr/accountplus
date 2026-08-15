<?php

$content = ob_start();
$t = $t ?? static fn ($key) => $key;
?>
<section class="ft-card narrow form-card">
    <span class="ft-kicker ft-kicker-blue"><?= e($t('accounts.kicker')) ?></span>
    <h2><?= e($t('accounts.create_title')) ?></h2>
    <p class="section-text">
        <?php if ($currentCompany !== null): ?>
            <?= e($t('accounts.current_company_text', ['name' => $currentCompany->name()])) ?>
        <?php else: ?>
            <?= e($t('accounts.no_company_create')) ?>
        <?php endif; ?>
    </p>
    <?php if ($currentCompany === null): ?>
        <a href="/companies" class="ft-form-btn"><?= e($t('action.open_companies')) ?></a>
    <?php else: ?>
        <?php if ($error !== null): ?>
            <div class="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" action="/accounts" class="ft-form ft-form-grid">
            <?= csrf_field() ?>
            <label>
                <span><?= e($t('accounts.name')) ?></span>
                <input type="text" name="name" placeholder="Main checking" value="<?= e((string) $values['name']) ?>">
            </label>
            <label>
                <span><?= e($t('accounts.bank_name')) ?></span>
                <input type="text" name="bank_name" placeholder="Primary bank" value="<?= e((string) $values['bank_name']) ?>">
            </label>
            <label>
                <span><?= e($t('accounts.account_number')) ?></span>
                <input type="text" name="account_number" placeholder="FR76..." value="<?= e((string) $values['account_number']) ?>">
            </label>
            <label>
                <span><?= e($t('accounts.currency')) ?></span>
                <input type="text" name="currency" value="<?= e((string) $values['currency']) ?>">
            </label>
            <label>
                <span><?= e($t('accounts.initial_balance')) ?></span>
                <input type="text" name="initial_balance" placeholder="0.00" value="<?= e((string) $values['initial_balance']) ?>">
            </label>
            <label>
                <span><?= e($t('accounts.description_field')) ?></span>
                <input type="text" name="description" placeholder="Primary operational account" value="<?= e((string) $values['description']) ?>">
            </label>
            <button type="submit" class="ft-form-btn"><?= e($t('accounts.save')) ?></button>
        </form>
    <?php endif; ?>
</section>
<?php
$content = (string) ob_get_clean();

echo $this->render('layout/app', [
    'title' => $t('accounts.create_title') . ' - ' . $appName,
    'pageTitle' => $t('accounts.create_title'),
    'pageDescription' => $t('accounts.create_description'),
    'appName' => $appName,
    'currentCompany' => $currentCompany,
    'content' => $content,
]);
