<?php

$content = ob_start();
$t = $t ?? static fn ($key) => $key;
?>
<section class="ft-page">
    <section class="ft-card">
        <header class="ft-card-head">
            <h4><?= e($t('imports.inspect_id', ['id' => $importId])) ?></h4>
            <span class="ft-count-badge"><?= e((string) count($errors ?? [])) ?></span>
        </header>
        <?php if (($errors ?? []) === []): ?>
            <p class="ft-empty"><?= e($t('imports.no_errors')) ?></p>
        <?php else: ?>
            <table class="table ft-table">
                <thead>
                <tr>
                    <th><?= e($t('imports.error_severity')) ?></th>
                    <th><?= e($t('imports.error_message')) ?></th>
                    <th><?= e($t('imports.error_entity')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($errors as $error): ?>
                    <tr>
                        <td><?= e($error['severity'] ?? '') ?></td>
                        <td><?= e($error['message'] ?? '') ?></td>
                        <td>
                            <?= e(($error['entity_type'] ?? '')) ?>
                            <?= isset($error['legacy_id']) && $error['legacy_id'] !== '' ? e(' (' . $error['legacy_id'] . ')') : '' ?>
                            <?= isset($error['line_number']) && $error['line_number'] !== '' ? e(' L' . $error['line_number']) : '' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <div class="ft-cta">
            <a href="/admin/data" class="ft-cta-btn"><?= e($t('transfer.back')) ?></a>
        </div>
    </section>
</section>
<?php
$content = (string) ob_get_clean();

echo $this->render('layout/app', [
    'title' => $t('imports.inspect_title') . ' - ' . $appName,
    'pageTitle' => $t('imports.inspect_title'),
    'pageDescription' => $t('imports.inspect_description'),
    'appName' => $appName,
    'currentCompany' => $currentCompany ?? null,
    'content' => $content,
]);
