<?php

$content = ob_start();
$t = $t ?? static fn ($key) => $key;
?>
<section class="ft-page">
    <section class="ft-card company-list-panel">
        <header class="ft-card-head">
            <div>
                <span class="ft-kicker ft-kicker-blue"><?= e($t('companies.selection')) ?></span>
                <h4><?= e($t('companies.selection')) ?></h4>
            </div>
            <div class="ft-header-actions">
                <span class="ft-count-badge"><?= e((string) count($companies)) ?></span>
                <?php if ($canManage): ?>
                    <a href="/companies/create" class="ft-form-btn"><?= icon('add') ?><?= e($t('companies.create')) ?></a>
                <?php endif; ?>
            </div>
        </header>
        <?php if ($companies === [] && !$canManage): ?>
            <p class="ft-empty"><?= e($t('companies.no_assigned_text')) ?></p>
        <?php elseif ($companies === []): ?>
            <p class="ft-empty"><?= e($t('companies.no_company')) ?></p>
        <?php else: ?>
            <?php if (!$canManage): ?>
                <p class="ft-card-text"><?= e($t('companies.user_help')) ?></p>
            <?php endif; ?>
            <div class="ft-table-scroll">
                <table class="table ft-table table-companies">
                <thead>
                <tr>
                    <th><?= e($t('companies.name')) ?></th>
                    <th><?= e($t('companies.status')) ?></th>
                    <th><?= e($t('companies.accounts')) ?></th>
                    <th><?= e($t('companies.transactions')) ?></th>
                    <th class="col-actions"><?= e($t('companies.actions')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($companies as $company): ?>
                    <tr>
                        <td class="ft-table-main"><?= e($company->name()) ?></td>
                        <td><span class="badge<?= strtolower($company->status()) === 'active' ? '' : ' badge-muted' ?>"><?= e($t(status_key($company->status()))) ?></span></td>
                        <td><?= e((string) $company->accountCount()) ?></td>
                        <td><?= e((string) $company->transactionCount()) ?></td>
                        <td class="ft-table-actions">
                            <div class="admin-actions">
                                <?php if ($currentCompany !== null && $currentCompany->id() === $company->id()): ?>
                                    <span class="badge"><?= e($t('companies.current_badge')) ?></span>
                                <?php else: ?>
                                    <form method="post" action="/companies/select" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="company_id" value="<?= e((string) $company->id()) ?>">
                                        <button type="submit" class="ft-row-btn" title="<?= e($t('companies.select')) ?>"><?= icon('select', $t('companies.select')) ?></button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canManage): ?>
                                    <a href="/companies/edit?id=<?= e((string) $company->id()) ?>" class="ft-row-btn" title="<?= e($t('companies.rename')) ?>"><?= icon('edit', $t('companies.rename')) ?></a>
                                    <?php if (count($companies) > 1): ?>
                                        <form method="post" action="/companies/delete" class="inline-form" data-confirm="<?= e($t('companies.delete_confirm', ['name' => $company->name()])) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $company->id()) ?>">
                                            <button type="submit" class="ft-row-btn" title="<?= e($t('companies.delete')) ?>"><?= icon('delete', $t('companies.delete')) ?></button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</section>
<?php
$content = (string) ob_get_clean();

echo $this->render('layout/app', [
    'title' => $t('companies.title') . ' - ' . $appName,
    'pageTitle' => $t('companies.title'),
    'pageDescription' => $t('companies.description'),
    'appName' => $appName,
    'currentCompany' => $currentCompany,
    'content' => $content,
]);
