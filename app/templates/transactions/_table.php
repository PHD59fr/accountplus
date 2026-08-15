<?php
/** @var array<int, array<string, mixed>> $transactions */
$search = (string) ($search ?? '');
$page = (int) ($page ?? 1);
$pages = (int) ($pages ?? 1);
$searchBase = (string) ($searchBase ?? '/accounts');
$searchParams = (array) ($searchParams ?? []);
$t = $t ?? static fn ($key) => $key;
$highlight = (int) ($highlight ?? 0);

$pagerUrl = static function (int $targetPage) use ($searchBase, $searchParams, $search): string {
    $params = $searchParams;
    $params['page'] = $targetPage;
    if ($search !== '') {
        $params['q'] = $search;
    }

    return $searchBase . '?' . http_build_query($params);
};
?>
<form method="get" action="<?= e($searchBase) ?>" class="search-form">
    <?php foreach ($searchParams as $paramName => $paramValue): ?>
        <input type="hidden" name="<?= e((string) $paramName) ?>" value="<?= e((string) $paramValue) ?>">
    <?php endforeach; ?>
    <input type="search" name="q" class="search-input" value="<?= e($search) ?>" placeholder="<?= e($t('transactions.search_placeholder')) ?>">
    <button type="submit" class="ft-form-btn"><?= icon('search') ?><?= e($t('transactions.search')) ?></button>
</form>
<?php if ($transactions === []): ?>
    <p class="section-text"><?= e($t('transactions.no_rows')) ?></p>
<?php else: ?>
    <div class="ft-table-responsive">
    <table class="table ft-table table-transactions">
        <thead>
        <tr>
            <th><?= e($t('transactions.date')) ?></th>
            <th><?= e($t('transactions.label')) ?></th>
            <th><?= e($t('transactions.category')) ?></th>
            <th class="col-num"><?= e($t('transactions.amount')) ?></th>
            <th><?= e($t('transactions.status')) ?></th>
            <?php if (($canManageData ?? false) === true): ?>
                <th class="col-actions"><?= e($t('transactions.actions')) ?></th>
            <?php endif; ?>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($transactions as $transaction): ?>
            <?php $transactionAccountId = (int) ($transaction['account_id'] ?? 0); ?>
            <?php $isHighlight = $highlight > 0 && (int) ($transaction['id'] ?? 0) === $highlight; ?>
            <tr<?= $isHighlight ? ' id="tx-' . e((string) $transaction['id']) . '" class="is-highlight"' : '' ?>>
                <td><?= e($formatDate((string) $transaction['date'])) ?></td>
                <td><?= e($transaction['label']) ?></td>
                <td>
                    <?php if (!empty($transaction['category']) && !empty($transaction['category_emoji'])): ?>
                        <span class="category-emoji" aria-hidden="true"><?= e((string) $transaction['category_emoji']) ?></span>
                    <?php endif; ?>
                    <?= !empty($transaction['category_key']) ? e($t((string) $transaction['category_key'])) : e($transaction['category']) ?>
                </td>
                <td class="col-num"><?= e($transaction['amount']) ?></td>
                <td>
                    <?php if (((int) ($transaction['is_archived'] ?? 0)) === 1): ?>
                        <span class="badge badge-muted"><?= e($t('status.archived')) ?></span>
                    <?php else: ?>
                        <span class="badge<?= ($transaction['status_key'] ?? '') === 'status.pending' ? ' badge-muted' : '' ?>"><?= e($t((string) ($transaction['status_key'] ?? 'status.pending'))) ?></span>
                    <?php endif; ?>
                </td>
                <?php if (($canManageData ?? false) === true): ?>
                    <td class="row-actions">
                        <a href="/transactions/history?id=<?= e((string) $transaction['id']) ?><?= $transactionAccountId > 0 ? '&account=' . e((string) $transactionAccountId) : '' ?>" class="ft-row-btn" title="<?= e($t('transactions.history')) ?>"><?= icon('history', $t('transactions.history')) ?></a>
                        <a href="/transactions/create?copy=<?= e((string) $transaction['id']) ?><?= $transactionAccountId > 0 ? '&account=' . e((string) $transactionAccountId) : '' ?>" class="ft-row-btn" title="<?= e($t('transactions.copy')) ?>"><?= icon('copy', $t('transactions.copy')) ?></a>
                        <a href="/transactions/edit?id=<?= e((string) $transaction['id']) ?><?= $transactionAccountId > 0 ? '&account=' . e((string) $transactionAccountId) : '' ?>" class="ft-row-btn" title="<?= e($t('transactions.edit')) ?>"><?= icon('edit', $t('transactions.edit')) ?></a>
                        <form method="post" action="/transactions/archive" class="inline-form"<?= !empty($transaction['is_transfer']) ? ' data-confirm="' . e($t('transactions.transfer_archive_confirm')) . '"' : '' ?>>
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= e((string) $transaction['id']) ?>">
                            <?php if ($transactionAccountId > 0): ?>
                                <input type="hidden" name="account_id" value="<?= e((string) $transactionAccountId) ?>">
                            <?php endif; ?>
                            <input type="hidden" name="archived" value="<?= ((int) ($transaction['is_archived'] ?? 0)) === 1 ? '0' : '1' ?>">
                            <button type="submit" class="ft-row-btn" title="<?= e($t(((int) ($transaction['is_archived'] ?? 0)) === 1 ? 'transactions.unarchive' : 'transactions.archive')) ?>">
                                <?= icon(((int) ($transaction['is_archived'] ?? 0)) === 1 ? 'unarchive' : 'archive', $t(((int) ($transaction['is_archived'] ?? 0)) === 1 ? 'transactions.unarchive' : 'transactions.archive')) ?>
                            </button>
                        </form>
                        <form method="post" action="/transactions/delete" class="inline-form" data-confirm="<?= e($t(!empty($transaction['is_transfer']) ? 'transactions.transfer_delete_confirm' : 'transactions.delete_confirm')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= e((string) $transaction['id']) ?>">
                            <?php if ($transactionAccountId > 0): ?>
                                <input type="hidden" name="account_id" value="<?= e((string) $transactionAccountId) ?>">
                            <?php endif; ?>
                            <button type="submit" class="ft-row-btn ft-row-btn-danger" title="<?= e($t('transactions.delete')) ?>"><?= icon('delete', $t('transactions.delete')) ?></button>
                        </form>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if ($pages > 1 || $search !== ''): ?>
        <div class="pagination">
            <a href="<?= e($pagerUrl(max(1, $page - 1))) ?>" class="ft-row-btn<?= ($page <= 1) ? ' is-disabled' : '' ?>"><?= icon('back') ?><?= e($t('pagination.previous')) ?></a>
            <span class="pagination-info"><?= e($t('pagination.page', ['current' => $page, 'total' => $pages])) ?></span>
            <a href="<?= e($pagerUrl(min($pages, $page + 1))) ?>" class="ft-row-btn<?= ($page >= $pages) ? ' is-disabled' : '' ?>"><?= e($t('pagination.next')) ?><?= icon('next') ?></a>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php if ($highlight > 0): ?>
    <script nonce="<?= e(csp_nonce()) ?>">
        (function () {
            var row = document.getElementById('tx-<?= e((string) $highlight) ?>');
            if (row) {
                row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                row.setAttribute('tabindex', '-1');
                row.focus();
            }
        })();
    </script>
<?php endif; ?>
