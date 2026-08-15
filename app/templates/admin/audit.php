<?php

$content = ob_start();
$t = $t ?? static fn ($key) => $key;
?>
<section class="ft-page">
    <form method="get" action="/admin/audit" class="filter-form">
        <label class="filter-field">
            <span class="sr-only"><?= e($t('audit.search_placeholder')) ?></span>
            <input
                type="search"
                name="q"
                class="search-input"
                placeholder="<?= e($t('audit.search_placeholder')) ?>"
                value="<?= e((string) ($filters['q'] ?? '')) ?>"
            >
        </label>
        <label class="filter-field">
            <span class="filter-label"><?= e($t('audit.date_from')) ?></span>
            <input type="date" name="date_from" value="<?= e((string) ($filters['date_from'] ?? '')) ?>">
        </label>
        <label class="filter-field">
            <span class="filter-label"><?= e($t('audit.date_to')) ?></span>
            <input type="date" name="date_to" value="<?= e((string) ($filters['date_to'] ?? '')) ?>">
        </label>
        <div class="filter-actions">
            <button type="submit" class="ft-form-btn filter-submit"><?= e($t('audit.filter')) ?></button>
            <a href="/admin/audit" class="ft-row-btn filter-reset"><?= e($t('audit.reset')) ?></a>
        </div>
    </form>

    <section class="ft-card">
        <header class="ft-card-head">
            <h4><?= e($t('audit.results')) ?></h4>
            <span class="ft-count-badge"><?= e((string) count($logs)) ?></span>
        </header>
        <?php if ($logs === []): ?>
            <p class="ft-empty"><?= e($t('audit.no_rows')) ?></p>
        <?php else: ?>
            <div class="ft-table-scroll ft-table-scroll-wide">
                <table class="table ft-table">
            <thead>
            <tr>
                <th><?= e($t('audit.date')) ?></th>
                <th><?= e($t('audit.user')) ?></th>
                <th><?= e($t('audit.action')) ?></th>
                <th><?= e($t('audit.entity')) ?></th>
                <th><?= e($t('audit.entity_id')) ?></th>
                <th><?= e($t('audit.ip')) ?></th>
                <th><?= e($t('audit.details')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <?php $actionKey = 'audit.action.' . $log['action']; ?>
                <?php $actionLabel = $t($actionKey); ?>
                <?php $entityKey = 'audit.entity.' . $log['entity_type']; ?>
                <?php $entityLabel = $t($entityKey); ?>
                <tr>
                    <td class="ft-table-main"><?= e($formatDate((string) $log['created_at'])) ?></td>
                    <td><?= e((string) ($log['username'] ?? '')) ?></td>
                    <td><?= e($actionLabel === $actionKey ? $log['action'] : $actionLabel) ?></td>
                    <td><?= e($entityLabel === $entityKey ? $log['entity_type'] : $entityLabel) ?></td>
                    <td><?= e((string) ($log['entity_id'] ?? '')) ?></td>
                    <td><?= e((string) ($log['ip_address'] ?? '')) ?></td>
                    <td><?php require __DIR__ . '/../partials/_audit_diff.php'; ?></td>
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
    'title' => ($t('audit.title')) . ' - ' . $appName,
    'pageTitle' => $t('audit.title'),
    'pageDescription' => $t('audit.description'),
    'appName' => $appName,
    'currentCompany' => $currentCompany ?? null,
    'content' => $content,
]);
