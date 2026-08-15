<?php

$content = ob_start();
$t = $t ?? static fn ($key) => $key;
?>
<section class="ft-page">
    <section class="ft-card narrow form-card">
        <header class="ft-card-head">
            <div>
                <span class="ft-kicker ft-kicker-blue"><?= e($t('companies.title')) ?></span>
                <h3><?= e($company->name()) ?></h3>
            </div>
        </header>
        <?php if ($error !== null): ?>
            <div class="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" action="/companies/update" class="ft-form ft-form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e((string) $company->id()) ?>">
            <label class="span-2">
                <span><?= e($t('companies.name')) ?></span>
                <input type="text" name="name" value="<?= e((string) $values['name']) ?>">
            </label>
            <label>
                <span><?= e($t('companies.status')) ?></span>
                <select name="status">
                    <option value="Active"<?= (string) $values['status'] === 'Active' ? ' selected' : '' ?>><?= e($t('status.active')) ?></option>
                    <option value="Archived"<?= (string) $values['status'] === 'Archived' ? ' selected' : '' ?>><?= e($t('status.archived')) ?></option>
                    <option value="Draft"<?= (string) $values['status'] === 'Draft' ? ' selected' : '' ?>><?= e($t('status.draft')) ?></option>
                </select>
            </label>
            <button type="submit" class="ft-form-btn"><?= icon('save', $t('companies.save')) ?><?= e($t('companies.save')) ?></button>
        </form>
    </section>
</section>
<?php
$content = (string) ob_get_clean();

echo $this->render('layout/app', [
    'title' => $t('companies.edit_title') . ' - ' . $appName,
    'pageTitle' => $t('companies.edit_title'),
    'pageDescription' => $t('companies.edit_description'),
    'appName' => $appName,
    'currentCompany' => $currentCompany,
    'content' => $content,
]);
