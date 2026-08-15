<?php

$content = ob_start();
$t = $t ?? static fn ($key) => $key;

$selectedAccountId = (isset($selectedAccount) && is_array($selectedAccount)) ? (int) $selectedAccount['id'] : 0;
?>
<section class="ft-card">
    <header class="ft-card-head">
        <div>
            <span class="ft-kicker ft-kicker-blue"><?= e($t('accounts.kicker')) ?></span>
            <h3><?= e($t('accounts.title')) ?></h3>
        </div>
        <?php if (($canManageAccounts ?? false) === true && $currentCompany !== null): ?>
            <a href="/accounts/create" class="ft-form-btn"><?= icon('add') ?><?= e($t('action.create_account')) ?></a>
        <?php endif; ?>
    </header>
    <?php if ($currentCompany === null): ?>
        <div class="alert"><?= e($t('accounts.no_company')) ?></div>
        <a href="/companies" class="ft-form-btn"><?= e($t('action.open_companies')) ?></a>
    <?php elseif ($accounts === []): ?>
        <p class="section-text"><?= e($t('accounts.no_rows')) ?></p>
    <?php else: ?>
        <div class="ft-table-responsive">
        <table class="table ft-table table-accounts">
            <thead>
            <tr>
                <th><?= e($t('accounts.name')) ?></th>
                <th><?= e($t('accounts.bank')) ?></th>
                <th class="col-num"><?= e($t('accounts.balance')) ?></th>
                <th class="col-num"><?= e($t('accounts.transactions')) ?></th>
                <?php if (($canManageAccounts ?? false) === true): ?>
                    <th class="col-actions"><?= e($t('accounts.actions')) ?></th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($accounts as $account): ?>
                <?php $accountId = (int) $account['id']; ?>
                <tr class="<?= $accountId === $selectedAccountId ? 'is-selected ' : '' ?>is-clickable"
                    data-row-link="/accounts?account=<?= e((string) $accountId) ?>">
                    <td>
                        <a href="/accounts?account=<?= e((string) $accountId) ?>"><?= e($account['name']) ?></a>
                        <?php if (((int) ($account['is_archived'] ?? 0)) === 1): ?>
                            <span class="inline-status-icon" title="<?= e($t('status.archived')) ?>"><?= icon('archive') ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($account['bank']) ?></td>
                    <td class="col-num"><?= e($account['balance']) ?></td>
                    <td class="col-num"><?= e((string) $account['transactions']) ?></td>
                    <?php if (($canManageAccounts ?? false) === true): ?>
                        <td class="row-actions">
                            <a href="/accounts/edit?id=<?= e((string) $accountId) ?>" class="ft-row-btn" title="<?= e($t('accounts.edit')) ?>"><?= icon('edit', $t('accounts.edit')) ?></a>
                            <form method="post" action="/accounts/archive" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= e((string) $accountId) ?>">
                                <input type="hidden" name="archived" value="<?= ((int) ($account['is_archived'] ?? 0)) === 1 ? '0' : '1' ?>">
                                <button type="submit" class="ft-row-btn" title="<?= e($t(((int) ($account['is_archived'] ?? 0)) === 1 ? 'accounts.unarchive' : 'accounts.archive')) ?>">
                                    <?= icon(((int) ($account['is_archived'] ?? 0)) === 1 ? 'unarchive' : 'archive', $t(((int) ($account['is_archived'] ?? 0)) === 1 ? 'accounts.unarchive' : 'accounts.archive')) ?>
                                </button>
                            </form>
                            <form method="post" action="/accounts/delete" class="inline-form" data-confirm="<?= e($t('accounts.delete_confirm')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= e((string) $accountId) ?>">
                                <button type="submit" class="ft-row-btn ft-row-btn-danger" title="<?= e($t('accounts.delete')) ?>"><?= icon('delete', $t('accounts.delete')) ?></button>
                            </form>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ($selectedAccountId === 0): ?>
            <p class="section-text"><?= e($t('accounts.select_hint')) ?></p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($selectedAccountId > 0): ?>
    <section class="ft-card">
        <header class="ft-card-head">
            <div>
                <span class="ft-kicker ft-kicker-blue"><?= e($t('transactions.kicker')) ?></span>
                <h3><?= e((string) ($selectedAccount['name'] ?? '')) ?> — <?= e($t('transactions.title')) ?></h3>
            </div>
            <div class="row-actions">
                <a href="/accounts" class="ft-row-btn"><?= icon('back') ?><?= e($t('accounts.show_all')) ?></a>
                <?php if (($canManage ?? false) === true && $currentCompany !== null): ?>
                    <a href="/transactions/create?account=<?= e((string) $selectedAccountId) ?>" class="ft-form-btn"><?= icon('add') ?><?= e($t('transactions.create')) ?></a>
                <?php endif; ?>
            </div>
        </header>
        <p class="section-text"><?= e($t('transactions.description')) ?></p>
        <?php
        $highlight = ($highlightId ?? 0) > 0 ? (int) $highlightId : 0;
        require __DIR__ . '/../transactions/_table.php';
        ?>
    </section>
<?php endif; ?>
<?php
$content = (string) ob_get_clean();

echo $this->render('layout/app', [
    'title' => $t('accounts.title') . ' - ' . $appName,
    'pageTitle' => $t('accounts.title'),
    'pageDescription' => $t('accounts.description'),
    'appName' => $appName,
    'currentCompany' => $currentCompany ?? null,
    'content' => $content,
]);
