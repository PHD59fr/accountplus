<?php

$content = ob_start();
$t = $t ?? static fn ($key) => $key;
?>
<section class="ft-card">
    <header class="ft-card-head">
        <div>
            <span class="ft-kicker ft-kicker-blue"><?= e($t('recurring.kicker')) ?></span>
            <h3><?= e($t('recurring.title')) ?></h3>
        </div>
        <?php if (($canManageData ?? false) === true && $currentCompany !== null): ?>
            <a href="/recurring/create" class="ft-form-btn"><?= icon('add') ?><?= e($t('recurring.create')) ?></a>
        <?php endif; ?>
    </header>
    <p class="section-text"><?= e($t('recurring.description')) ?></p>
    <?php if ($rules === []): ?>
        <p class="section-text"><?= e($t('recurring.no_rows')) ?></p>
    <?php else: ?>
        <div class="ft-table-responsive">
        <table class="table ft-table table-recurring">
            <thead>
            <tr>
                <th><?= e($t('recurring.label')) ?></th>
                <th><?= e($t('recurring.type')) ?></th>
                <th><?= e($t('recurring.amount')) ?></th>
                <th><?= e($t('recurring.frequency')) ?></th>
                <th><?= e($t('recurring.next_execution')) ?></th>
                <th><?= e($t('recurring.status')) ?></th>
                <?php if (($canManageData ?? false) === true): ?>
                    <th class="col-actions"><?= e($t('recurring.actions')) ?></th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rules as $rule): ?>
                <tr>
                    <td><?= e($rule['label']) ?></td>
                    <td><?= e($t('recurring.type_' . $rule['type'])) ?></td>
                    <td><?= e($rule['amount']) ?></td>
                    <td><?= e($rule['interval_value']) ?> <?= e($t('recurring.unit_' . $rule['recurrence'])) ?></td>
                    <td><?= e($formatDate((string) $rule['next_date'])) ?></td>
                    <td>
                        <?php if ($rule['is_active'] === 1): ?>
                            <span class="badge"><?= e($t('status.active')) ?></span>
                        <?php else: ?>
                            <span class="badge badge-muted"><?= e($t('status.archived')) ?></span>
                        <?php endif; ?>
                    </td>
                    <?php if (($canManageData ?? false) === true): ?>
                        <td class="row-actions">
                            <a href="/recurring/edit?id=<?= e((string) $rule['id']) ?>" class="ft-row-btn" title="<?= e($t('recurring.edit')) ?>"><?= icon('edit', $t('recurring.edit')) ?></a>
                            <form method="post" action="/recurring/activate" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= e((string) $rule['id']) ?>">
                                <input type="hidden" name="active" value="<?= $rule['is_active'] === 1 ? '0' : '1' ?>">
                                <button type="submit" class="ft-row-btn" title="<?= e($t($rule['is_active'] === 1 ? 'recurring.deactivate' : 'recurring.activate')) ?>">
                                    <?= icon($rule['is_active'] === 1 ? 'deactivate' : 'activate', $t($rule['is_active'] === 1 ? 'recurring.deactivate' : 'recurring.activate')) ?>
                                </button>
                            </form>
                            <form method="post" action="/recurring/delete" class="inline-form" data-confirm="<?= e($t('recurring.delete_confirm')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= e((string) $rule['id']) ?>">
                                <button type="submit" class="ft-row-btn ft-row-btn-danger" title="<?= e($t('recurring.delete')) ?>"><?= icon('delete', $t('recurring.delete')) ?></button>
                            </form>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>
<?php
$content = (string) ob_get_clean();

echo $this->render('layout/app', [
    'title' => $t('recurring.title') . ' - ' . $appName,
    'pageTitle' => $t('recurring.title'),
    'pageDescription' => $t('recurring.description'),
    'appName' => $appName,
    'currentCompany' => $currentCompany ?? null,
    'content' => $content,
]);
