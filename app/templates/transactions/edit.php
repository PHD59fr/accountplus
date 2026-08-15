<?php

$content = ob_start();
$t = $t ?? static fn ($key) => $key;
?>
<section class="ft-card narrow form-card">
    <span class="ft-kicker ft-kicker-blue"><?= e($t('transactions.kicker')) ?></span>
    <h2><?= e($t('transactions.edit_title')) ?></h2>
    <?php if ($isTransfer ?? false): ?>
        <p class="section-text"><?= e($t('transactions.transfer_edit_notice')) ?></p>
    <?php endif; ?>
    <?php if ($currentCompany !== null): ?>
        <p class="section-text"><?= e($t('transactions.current_company_text', ['name' => $currentCompany->name()])) ?></p>
    <?php endif; ?>
    <?php if ((int) ($backAccount ?? 0) > 0): ?>
        <p class="section-text"><a href="/accounts?account=<?= e((string) $backAccount) ?>"><?= e($t('action.back_to_account')) ?></a></p>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="alert"><?= e($error) ?></div>
    <?php endif; ?>
        <form method="post" action="/transactions/update" class="ft-form ft-form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e((string) $transactionId) ?>">
            <label>
                <span><?= e($t('transactions.account')) ?></span>
                <select name="account_id">
                    <option value=""><?= e($t('transactions.account_select')) ?></option>
                    <?php foreach ($accounts as $account): ?>
                        <option value="<?= e((string) $account['id']) ?>"<?= (string) $values['account_id'] === (string) $account['id'] ? ' selected' : '' ?>><?= e($account['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?= e($t('transactions.category')) ?></span>
                <select name="category_id">
                    <option value=""><?= e($t('transactions.category_select')) ?></option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e((string) $category['id']) ?>"<?= (string) ($values['category_id'] ?? '') === (string) $category['id'] ? ' selected' : '' ?>><?= !empty($category['emoji']) ? e($category['emoji']) . ' ' : '' ?><?= e($category['path']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?= e($t('transactions.amount')) ?></span>
                <input type="text" name="amount" value="<?= e((string) $values['amount']) ?>">
            </label>
            <label>
                <span><?= e($t('transactions.date')) ?></span>
                <input type="date" name="transaction_date" value="<?= e((string) $values['transaction_date']) ?>">
            </label>
            <label>
                <span><?= e($t('transactions.value_date')) ?></span>
                <input type="date" name="value_date" value="<?= e((string) $values['value_date']) ?>">
            </label>
            <label class="ft-form-label-checkbox span-1">
                <input type="checkbox" name="is_reconciled" value="1"<?= (int) $values['is_reconciled'] === 1 ? ' checked' : '' ?>>
                <span><?= e($t('transactions.reconciled')) ?></span>
            </label>
            <label>
                <span><?= e($t('transactions.label')) ?></span>
                <input type="text" name="label" value="<?= e((string) $values['label']) ?>">
            </label>
            <label class="span-2">
                <span><?= e($t('transactions.description_field')) ?></span>
                <input type="text" name="description" value="<?= e((string) $values['description']) ?>">
            </label>
            <button type="submit" class="ft-form-btn"><?= e($t('transactions.save')) ?></button>
        </form>
</section>
<?php
$content = (string) ob_get_clean();

echo $this->render('layout/app', [
    'title' => $t('transactions.edit_title') . ' - ' . $appName,
    'pageTitle' => $t('transactions.edit_title'),
    'pageDescription' => $t('transactions.edit_description'),
    'appName' => $appName,
    'currentCompany' => $currentCompany,
    'content' => $content,
]);
