<?php

$content = ob_start();
$t = $t ?? static fn ($key) => $key;

$children = [];
$ids = [];
foreach ($categories as $category) {
    $ids[(int) $category['id']] = true;
}
$roots = [];
foreach ($categories as $category) {
    $pid = (int) ($category['parent_id'] ?? 0);
    if ($pid > 0 && isset($ids[$pid])) {
        $children[$pid][] = $category;
    } else {
        $roots[] = $category;
    }
}

$renderTree = function (array $nodes, int $depth) use (&$renderTree, $t, $children, $canManageData): void {
    foreach ($nodes as $category) {
        $id = (int) $category['id'];
        $isActive = (int) ($category['is_active'] ?? 1) === 1;
        $indent = $depth > 0 ? ' style="padding-left:' . (0.5 + $depth * 1) . 'rem"' : '';
        ?>
        <tr class="<?= $depth > 0 ? 'cat-child' : 'cat-parent' ?>">
            <td>
                <?php if ($depth > 0): ?><span class="cat-tree" aria-hidden="true">↳&nbsp;</span><?php endif; ?>
                <?php if (!empty($category['emoji'])): ?>
                    <span class="category-emoji" aria-hidden="true"><?= e((string) $category['emoji']) ?></span>
                <?php endif; ?>
                <span class="cat-name"<?= $indent ?>><?= e($category['name']) ?></span>
                <?php if (!$isActive): ?>
                    <span class="inline-status-icon" title="<?= e($t('status.inactive')) ?>"><?= icon('inactive') ?></span>
                <?php endif; ?>
            </td>
            <td><?= e(!empty($category['category_type']) ? $t('categories.type_' . $category['category_type']) : '—') ?></td>
            <td class="col-num"><?= e((int) $category['sort_order']) ?></td>
            <?php if (($canManageData ?? false) === true): ?>
                <td class="row-actions">
                    <a href="/categories/edit?id=<?= e((string) $category['id']) ?>" class="ft-row-btn" title="<?= e($t('categories.edit')) ?>"><?= icon('edit', $t('categories.edit')) ?></a>
                    <form method="post" action="/categories/delete" class="inline-form" data-confirm="<?= e($t('categories.delete_confirm')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= e((string) $category['id']) ?>">
                        <button type="submit" class="ft-row-btn ft-row-btn-danger" title="<?= e($t('categories.delete')) ?>"><?= icon('delete', $t('categories.delete')) ?></button>
                    </form>
                </td>
            <?php endif; ?>
        </tr>
        <?php
        if (!empty($children[$id])) {
            $renderTree($children[$id], $depth + 1);
        }
    }
};
?>
<section class="ft-card">
    <header class="ft-card-head">
        <div>
            <span class="ft-kicker ft-kicker-blue"><?= e($t('categories.kicker')) ?></span>
            <h3><?= e($t('categories.title')) ?></h3>
        </div>
        <?php if (($canManageData ?? false) === true && $currentCompany !== null): ?>
            <a href="/categories/create" class="ft-form-btn"><?= icon('add') ?><?= e($t('categories.create')) ?></a>
        <?php endif; ?>
    </header>
    <p class="section-text"><?= e($t('categories.description')) ?></p>
    <?php if ($categories === []): ?>
        <p class="section-text"><?= e($t('categories.no_rows')) ?></p>
    <?php else: ?>
        <div class="ft-table-responsive">
        <table class="table ft-table table-categories">
            <thead>
            <tr>
                <th><?= e($t('categories.name')) ?></th>
                <th><?= e($t('categories.type')) ?></th>
                <th class="col-num"><?= e($t('categories.order')) ?></th>
                <?php if (($canManageData ?? false) === true): ?>
                    <th class="col-actions"><?= e($t('categories.actions')) ?></th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php $renderTree($roots, 0); ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>
<?php
$content = (string) ob_get_clean();

echo $this->render('layout/app', [
    'title' => $t('categories.title') . ' - ' . $appName,
    'pageTitle' => $t('categories.title'),
    'pageDescription' => $t('categories.description'),
    'appName' => $appName,
    'currentCompany' => $currentCompany ?? null,
    'content' => $content,
]);
