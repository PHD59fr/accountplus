<?php

$content = ob_start();
$t = $t ?? static fn ($key) => $key;
$widgets = $widgets ?? array_fill_keys(array_keys(\App\Service\DashboardSettings::WIDGETS), true);
?>
<?php if ($currentCompany === null): ?>
    <section class="ft-card narrow">
        <span class="ft-kicker ft-kicker-blue"><?= e($t('dashboard.start_here')) ?></span>
        <h2><?= e($t('dashboard.no_company_title')) ?></h2>
        <p class="section-text"><?= e($t('dashboard.no_company_text')) ?></p>
        <div class="hero-actions">
            <a href="/companies" class="ft-form-btn"><?= e($t('action.go_to_companies')) ?></a>
        </div>
    </section>
<?php endif; ?>

<?php if (!in_array(true, $widgets, true) && $currentCompany !== null): ?>
    <section class="ft-card">
        <p class="section-text"><?= e($t('dashboard.no_widgets')) ?></p>
        <?php if ($canManageAdministration ?? false): ?>
            <a href="/admin/settings" class="ft-cta-btn"><?= e($t('admin.open_settings')) ?></a>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($widgets['balance'] || $widgets['income'] || $widgets['expenses'] || $widgets['recurring']): ?>
<section class="hero-metrics">
    <?php if ($widgets['balance']): ?>
        <div class="metric-card" data-dashboard-widget="balance">
            <span><?= e($t('dashboard.global_balance')) ?></span>
            <strong><?= e($metrics['globalBalance']) ?></strong>
        </div>
    <?php endif; ?>
    <?php if ($widgets['income']): ?>
        <div class="metric-card" data-dashboard-widget="income">
            <span><?= e($t('dashboard.income')) ?></span>
            <strong><?= e($metrics['monthlyIncome']) ?></strong>
        </div>
    <?php endif; ?>
    <?php if ($widgets['expenses']): ?>
        <div class="metric-card" data-dashboard-widget="expenses">
            <span><?= e($t('dashboard.expenses')) ?></span>
            <strong><?= e($metrics['monthlyExpenses']) ?></strong>
        </div>
    <?php endif; ?>
    <?php if ($widgets['recurring']): ?>
        <div class="metric-card" data-dashboard-widget="recurring">
            <span><?= e($t('dashboard.recurring')) ?></span>
            <strong><?= e($t('dashboard.recurring_count', ['count' => $metrics['upcomingRecurring']])) ?></strong>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($widgets['accounts'] || $widgets['recent_transactions']): ?>
<section class="grid<?= $widgets['accounts'] && $widgets['recent_transactions'] ? '' : ' dashboard-grid-single' ?>">
    <?php if ($widgets['accounts']): ?>
    <div class="ft-card" data-dashboard-widget="accounts">
        <header class="ft-card-head">
            <div>
                <span class="ft-kicker ft-kicker-blue"><?= e($t('dashboard.ledger')) ?></span>
                <h3><?= e($t('dashboard.accounts')) ?></h3>
            </div>
            <span class="badge badge-muted"><?= e($t('dashboard.visible', ['count' => count($accountCards)])) ?></span>
        </header>
        <div class="cards">
            <?php if ($accountCards === []): ?>
                <p class="section-text"><?= e($t('dashboard.no_accounts')) ?></p>
            <?php else: ?>
                <?php foreach ($accountCards as $card): ?>
                    <a class="account-card is-link" href="/accounts?account=<?= e((string) ($card['id'] ?? 0)) ?>">
                        <div class="account-card-header">
                            <strong><?= e($card['name']) ?></strong>
                            <?php if (($card['status_key'] ?? 'status.active') === 'status.archived'): ?>
                                <span class="inline-status-icon" title="<?= e($t('status.archived')) ?>"><?= icon('archive') ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="account-balance"><?= e($card['balance']) ?></div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>
    <?php if ($widgets['recent_transactions']): ?>
    <div class="ft-card" data-dashboard-widget="recent_transactions">
        <header class="ft-card-head">
            <div>
                <span class="ft-kicker ft-kicker-blue"><?= e($t('dashboard.activity')) ?></span>
                <h3><?= e($t('dashboard.recent_transactions')) ?></h3>
            </div>
            <span class="badge badge-muted"><?= e($t('dashboard.rows', ['count' => count($recentTransactions)])) ?></span>
        </header>
        <?php if ($recentTransactions === []): ?>
            <p class="section-text"><?= e($t('dashboard.no_transactions')) ?></p>
        <?php else: ?>
            <table class="table ft-table">
                <thead>
                <tr>
                    <th><?= e($t('transactions.date')) ?></th>
                    <th><?= e($t('transactions.label')) ?></th>
                    <th><?= e($t('transactions.amount')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($recentTransactions as $transaction): ?>
                    <tr class="is-clickable"
                        data-row-link="/accounts?account=<?= e((string) ($transaction['account_id'] ?? 0)) ?>&highlight=<?= e((string) ($transaction['id'] ?? 0)) ?>">
                        <td><?= e($formatDate((string) $transaction['date'])) ?></td>
                        <td><?= e($transaction['label']) ?></td>
                        <td><?= e($transaction['amount']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ((($widgets['category_breakdown'] || $widgets['type_breakdown']) && ($breakdown['categories'] ?? []) !== [])
    || ($widgets['label_breakdown'] && $currentCompany !== null)): ?>
    <section class="ft-card">
        <header class="ft-card-head">
            <div>
                <span class="ft-kicker ft-kicker-blue"><?= e($t('reports.kicker')) ?></span>
                <h3><?= e($t('dashboard.breakdowns')) ?></h3>
            </div>
        </header>
        <div class="dashboard-breakdowns<?= $widgets['label_breakdown'] && $currentCompany !== null && $widgets['type_breakdown'] && ($breakdown['categories'] ?? []) !== [] ? ' dashboard-breakdowns-paired' : '' ?>">
        <?php
        $chartPrefix = 'dashboard';
        $showCategoryChart = $widgets['category_breakdown'];
        $showTypeChart = $widgets['type_breakdown'];
        require __DIR__ . '/../partials/_breakdown_chart.php';
        if ($widgets['label_breakdown'] && $currentCompany !== null) {
            require __DIR__ . '/../partials/_label_chart.php';
        }
        ?>
        </div>
    </section>
<?php endif; ?>
<?php
$content = (string) ob_get_clean();

echo $this->render('layout/app', [
    'title' => ($t('dashboard.title')) . ' - ' . $appName,
    'pageTitle' => $t('dashboard.title'),
    'pageDescription' => $t('dashboard.description'),
    'appName' => $appName,
    'currentCompany' => $currentCompany ?? null,
    'content' => $content,
]);
