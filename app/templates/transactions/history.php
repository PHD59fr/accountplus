<?php

$content = ob_start();
$t = $t ?? static fn ($key) => $key;
?>
<section class="ft-card">
    <header class="ft-card-head">
        <div>
            <span class="ft-kicker ft-kicker-blue"><?= e($t('transactions.kicker')) ?></span>
            <h3><?= e($t('transactions.history_title')) ?></h3>
        </div>
        <a href="<?= (int) ($backAccount ?? 0) > 0 ? '/accounts?account=' . (int) $backAccount : '/accounts' ?>" class="ft-row-btn"><?= e($t('accounts.show_all')) ?></a>
    </header>
    <?php if ($transaction !== null): ?>
        <?php
        $names = is_array($entityNames ?? null) ? $entityNames : [];
        $accountId = (int) ($transaction['account_id'] ?? 0);
        $categoryId = (int) ($transaction['category_id'] ?? 0);
        $accountName = $names['account'][$accountId] ?? null;
        $categoryName = $categoryId > 0 ? ($names['category'][$categoryId] ?? null) : null;
        ?>
        <p class="section-text">
            <strong><?= e((string) ($transaction['label'] ?? '')) ?></strong> —
            <?= e($formatDate((string) ($transaction['transaction_date'] ?? ''))) ?> —
            <?= e((string) ($transaction['amount'] ?? '')) ?>
            <?php if ($accountName !== null): ?>
                — <?= e($t('transactions.account')) ?> : <?= e($accountName) ?>
            <?php endif; ?>
            <?php if ($categoryName !== null): ?>
                — <?= e($t('transactions.category')) ?> : <?= e($categoryName) ?>
            <?php endif; ?>
        </p>
    <?php endif; ?>
    <?php if ($logs === []): ?>
        <p class="section-text"><?= e($t('transactions.history_empty')) ?></p>
    <?php else: ?>
        <table class="table ft-table">
            <thead>
            <tr>
                <th><?= e($t('audit.date')) ?></th>
                <th><?= e($t('audit.user')) ?></th>
                <th><?= e($t('audit.action')) ?></th>
                <th><?= e($t('audit.ip')) ?></th>
                <th><?= e($t('audit.details')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <?php $actionKey = 'audit.action.' . $log['action']; ?>
                <?php $actionLabel = $t($actionKey); ?>
                <tr>
                    <td><?= e($formatDate((string) $log['created_at'])) ?></td>
                    <td><?= e((string) ($log['username'] ?? '')) ?></td>
                    <td><?= e($actionLabel === $actionKey ? $log['action'] : $actionLabel) ?></td>
                    <td><?= e((string) ($log['ip_address'] ?? '')) ?></td>
                    <td><?php require __DIR__ . '/../partials/_audit_diff.php'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<?php
$content = (string) ob_get_clean();

echo $this->render('layout/app', [
    'title' => $t('transactions.history_title') . ' - ' . $appName,
    'pageTitle' => $t('transactions.history_title'),
    'pageDescription' => $t('transactions.history_description'),
    'appName' => $appName,
    'currentCompany' => $currentCompany,
    'content' => $content,
]);
