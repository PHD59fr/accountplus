<?php

$content = ob_start();
$t = $t ?? static fn ($key) => $key;
$exportQuery = http_build_query([
    'date_from' => (string) ($dateFrom ?? ''),
    'date_to' => (string) ($dateTo ?? ''),
    'category_id' => (string) ($categoryId ?? ''),
    'q' => (string) ($search ?? ''),
]);
?>
<section class="ft-card">
    <header class="ft-card-head">
        <div>
            <span class="ft-kicker ft-kicker-blue"><?= e($t('reports.kicker')) ?></span>
            <h3><?= e($t('reports.transactions_title')) ?></h3>
        </div>
        <?php if (($canManageData ?? false) === true): ?>
            <a href="/reports/transactions/export?<?= e($exportQuery) ?>" class="ft-row-btn"><?= icon('download') ?><?= e($t('reports.export_csv')) ?></a>
        <?php endif; ?>
    </header>

    <?php if (($currentCompany ?? null) === null): ?>
        <div class="alert"><?= e($t('reports.no_company')) ?></div>
        <a href="/companies" class="ft-form-btn"><?= icon('back') ?><?= e($t('action.open_companies')) ?></a>
    <?php else: ?>
        <form method="get" action="/reports/transactions" class="ft-form">
            <label>
                <span><?= e($t('reports.date_from')) ?></span>
                <input type="date" name="date_from" value="<?= e((string) ($dateFrom ?? '')) ?>">
            </label>
            <label>
                <span><?= e($t('reports.date_to')) ?></span>
                <input type="date" name="date_to" value="<?= e((string) ($dateTo ?? '')) ?>">
            </label>
            <label>
                <span><?= e($t('reports.account')) ?></span>
                <select name="account">
                    <option value=""><?= e($t('reports.account_all')) ?></option>
                    <?php foreach (($accounts ?? []) as $account): ?>
                        <option value="<?= e((string) $account['id']) ?>"<?= (string) ($accountId ?? '') === (string) $account['id'] ? ' selected' : '' ?>><?= e($account['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?= e($t('reports.category')) ?></span>
                <select name="category_id">
                    <option value=""><?= e($t('reports.category_all')) ?></option>
                    <?php foreach (($categories ?? []) as $category): ?>
                        <option value="<?= e((string) $category['id']) ?>"<?= (string) ($categoryId ?? '') === (string) $category['id'] ? ' selected' : '' ?>><?= e($category['path'] ?? $category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="ft-form-label">
                <span><?= e($t('reports.search')) ?></span>
                <input type="search" name="q" class="search-input" value="<?= e((string) ($search ?? '')) ?>" placeholder="<?= e($t('reports.search_placeholder')) ?>">
            </label>
            <label class="ft-form-label">
                <input type="checkbox" name="include_archived" value="1"<?= ($includeArchived ?? true) ? ' checked' : '' ?>>
                <span><?= e($t('reports.include_archived')) ?></span>
            </label>
            <button type="submit" class="ft-form-btn"><?= icon('search') ?><?= e($t('reports.filter')) ?></button>
        </form>

        <div class="hero-metrics report-metrics">
            <div class="metric-card">
                <span><?= e($t('reports.income')) ?></span>
                <strong><?= e(number_format((float) ($totals['income'] ?? 0), 2, '.', ',')) ?> <?= e((string) ($currency ?? 'EUR')) ?></strong>
            </div>
            <div class="metric-card">
                <span><?= e($t('reports.expenses')) ?></span>
                <strong><?= e(number_format((float) ($totals['expenses'] ?? 0), 2, '.', ',')) ?> <?= e((string) ($currency ?? 'EUR')) ?></strong>
            </div>
            <div class="metric-card">
                <span><?= e($t('reports.net_cash_flow')) ?></span>
                <strong><?= e(number_format((float) ($totals['net'] ?? 0), 2, '.', ',')) ?> <?= e((string) ($currency ?? 'EUR')) ?></strong>
            </div>
            <div class="metric-card">
                <span><?= e($t('reports.entries')) ?></span>
                <strong><?= e((int) ($totals['count'] ?? 0)) ?></strong>
            </div>
        </div>

        <div class="report-balances">
            <div class="metric-card">
                <span><?= e($t('reports.opening_balance')) ?> (<?= e((string) ($dateFrom ?? '')) ?>)</span>
                <strong><?= e(number_format((float) ($openingBalance ?? 0), 2, '.', ',')) ?> <?= e((string) ($currency ?? 'EUR')) ?></strong>
            </div>
            <div class="metric-card">
                <span><?= e($t('reports.closing_balance')) ?> (<?= e((string) ($dateTo ?? '')) ?>)</span>
                <strong><?= e(number_format((float) ($closingBalance ?? 0), 2, '.', ',')) ?> <?= e((string) ($currency ?? 'EUR')) ?></strong>
            </div>
        </div>

        <?php if (($rows ?? []) === []): ?>
            <p class="section-text"><?= e($t('reports.no_transactions')) ?></p>
        <?php else: ?>
            <table class="table ft-table">
                <thead>
                <tr>
                    <th><?= e($t('reports.date')) ?></th>
                    <th><?= e($t('reports.label')) ?></th>
                    <th><?= e($t('reports.category')) ?></th>
                    <th><?= e($t('reports.amount')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e($formatDate((string) $row['date'])) ?></td>
                        <td><?= e($row['label']) ?></td>
                        <td><?= e($row['category']) ?></td>
                        <td><?= e($row['amount']) ?> <?= e($row['currency']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if (isset($breakdown) && is_array($breakdown) && ($breakdown['categories'] ?? []) !== []): ?>
    <section class="ft-card">
        <header class="ft-card-head">
            <div>
                <span class="ft-kicker ft-kicker-blue"><?= e($t('reports.kicker')) ?></span>
                <h3><?= e($t('reports.by_category')) ?></h3>
            </div>
        </header>
        <?php
        $chartPrefix = 'reports';
        require __DIR__ . '/../partials/_breakdown_chart.php';
        ?>
    </section>
<?php endif; ?>
<?php
$content = (string) ob_get_clean();

echo $this->render('layout/app', [
    'title' => $t('reports.transactions_title') . ' - ' . $appName,
    'pageTitle' => $t('reports.transactions_title'),
    'pageDescription' => $t('reports.transactions_description'),
    'appName' => $appName,
    'currentCompany' => $currentCompany ?? null,
    'content' => $content,
]);
