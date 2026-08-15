<?php

$content = ob_start();
$t = $t ?? static fn ($key) => $key;

$company = $currentCompany ?? null;
$lastExportName = '';
if (!empty($files)) {
    $lastExportName = (string) $files[0]['name'];
}
?>
<section class="ft-page">
    <div class="ft-stats">
        <article class="ft-stat">
            <span class="ft-stat-icon ft-stat-icon-green"><?= e($t('transfer.icon_import')) ?></span>
            <div>
                <span class="ft-stat-label"><?= e($t('transfer.stat_imports')) ?></span>
                <strong class="ft-stat-value"><?= e((string) ($stats['imports'] ?? 0)) ?></strong>
            </div>
        </article>
        <article class="ft-stat">
            <span class="ft-stat-icon ft-stat-icon-purple"><?= e($t('transfer.icon_export')) ?></span>
            <div>
                <span class="ft-stat-label"><?= e($t('transfer.stat_exports')) ?></span>
                <strong class="ft-stat-value"><?= e((string) ($stats['exports'] ?? 0)) ?></strong>
            </div>
        </article>
        <article class="ft-stat">
            <span class="ft-stat-icon ft-stat-icon-blue"><?= e($t('transfer.icon_company')) ?></span>
            <div>
                <span class="ft-stat-label"><?= e($t('transfer.stat_company')) ?></span>
                <strong class="ft-stat-value ft-stat-value-sm"><?= e($company !== null ? $company->name() : $t('transfer.no_company')) ?></strong>
            </div>
        </article>
    </div>

    <div class="ft-grid">
        <section class="ft-card">
            <header class="ft-card-head">
                <div>
                    <span class="ft-kicker ft-kicker-green"><?= e($t('transfer.import_kicker')) ?></span>
                    <h3><?= e($t('transfer.import_title')) ?></h3>
                </div>
            </header>
            <p class="ft-card-text"><?= e($t('transfer.import_text')) ?></p>

            <div class="ft-mini-stats ft-mini-stats-single">
                <div class="ft-mini-stat">
                    <span class="ft-mini-icon ft-mini-icon-green"><?= e($t('transfer.icon_import')) ?></span>
                    <div>
                        <span class="ft-mini-label"><?= e($t('transfer.import_processed')) ?></span>
                        <strong><?= e((string) ($stats['imports'] ?? 0)) ?></strong>
                    </div>
                </div>
            </div>

            <div class="ft-subsection">
                <h4><?= e($t('transfer.import_how')) ?></h4>
                <ol class="ft-artifacts ft-artifacts-plain">
                    <?php foreach (($importSteps ?? []) as $step): ?>
                        <li>
                            <span class="ft-dot" aria-hidden="true"></span>
                            <div>
                                <strong><?= e($t((string) $step['title_key'])) ?></strong>
                                <span><?= e($t((string) $step['detail_key'])) ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>

            <details class="ft-details">
                <summary><?= e($t('transfer.archive_format')) ?></summary>
                <ul class="ft-artifacts">
                    <?php foreach (($artifacts ?? []) as $artifact): ?>
                        <li>
                            <code><?= e((string) $artifact['name']) ?></code>
                            <span><?= e($t((string) $artifact['role_key'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="ft-command-list">
                    <?php foreach (($importCommands ?? []) as $command): ?>
                        <code><?= e((string) $command) ?></code>
                    <?php endforeach; ?>
                </div>
            </details>

            <?php if (!empty($companies) && ($importAvailable ?? false) === true): ?>
                <form method="post" action="/admin/data/import" enctype="multipart/form-data" class="ft-cta">
                    <?= csrf_field() ?>
                    <label class="ft-company-select">
                        <span><?= e($t('transfer.import_destination')) ?></span>
                        <select name="company_id" required>
                            <?php foreach ($companies as $c): ?>
                                <option value="<?= e((string) $c->id()) ?>"<?= $company !== null && $c->id() === $company->id() ? ' selected' : '' ?>>
                                    <?= e($c->name()) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="ft-checkbox">
                        <input type="checkbox" name="force" value="1">
                        <span><?= e($t('transfer.import_force')) ?></span>
                    </label>
                    <label class="ft-cta-btn">
                        <span><?= icon('upload') ?><?= e($t('transfer.import_cta')) ?></span>
                        <input type="file" name="import_file" accept=".zip,application/zip" required hidden data-auto-submit>
                    </label>
                </form>
            <?php elseif (empty($companies)): ?>
                <p class="ft-empty"><?= e($t('imports.no_company')) ?></p>
            <?php else: ?>
                <p class="ft-empty"><?= e($t('flash.db_unavailable')) ?></p>
            <?php endif; ?>
        </section>

        <section class="ft-card">
            <header class="ft-card-head">
                <div>
                    <span class="ft-kicker ft-kicker-purple"><?= e($t('transfer.export_kicker')) ?></span>
                    <h3><?= e($t('transfer.export_title')) ?></h3>
                </div>
            </header>
            <p class="ft-card-text"><?= e($t('transfer.export_text')) ?></p>

            <div class="ft-mini-stats">
                <div class="ft-mini-stat">
                    <span class="ft-mini-icon ft-mini-icon-purple"><?= e($t('transfer.icon_export')) ?></span>
                    <div>
                        <span class="ft-mini-label"><?= e($t('transfer.export_files_created')) ?></span>
                        <strong><?= e((string) ($stats['exports'] ?? 0)) ?></strong>
                    </div>
                </div>
                <div class="ft-mini-stat">
                    <span class="ft-mini-icon ft-mini-icon-green"><?= e($t('transfer.icon_archive')) ?></span>
                    <div>
                        <span class="ft-mini-label"><?= e($t('transfer.export_last_file')) ?></span>
                        <strong class="ft-mini-value-sm ft-mini-value-filename"><?= e($lastExportName !== '' ? $lastExportName : $t('transfer.no_export_file')) ?></strong>
                    </div>
                </div>
            </div>

            <div class="ft-subsection">
                <h4><?= e($t('transfer.export_how')) ?></h4>
                <ul class="ft-artifacts ft-artifacts-plain">
                    <?php foreach (($exportItems ?? []) as $item): ?>
                        <li>
                            <span class="ft-dot" aria-hidden="true"></span>
                            <div>
                                <strong><?= e($t((string) $item['title_key'])) ?></strong>
                                <span><?= e($t((string) $item['detail_key'])) ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <?php if ($company !== null && ($exportAvailable ?? false) === true): ?>
                <form method="post" action="/admin/data/export" class="ft-cta">
                    <?= csrf_field() ?>
                    <?php if (!empty($companies)): ?>
                        <label class="ft-company-select">
                            <span><?= e($t('transfer.export_target')) ?></span>
                            <select name="company_id">
                                <option value="0"><?= e($t('transfer.export_target_default')) ?></option>
                                <?php foreach ($companies as $c): ?>
                                    <option value="<?= e((string) $c->id()) ?>"<?= $c->id() === $company->id() ? ' selected' : '' ?>>
                                        <?= e($c->name()) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endif; ?>
                    <button type="submit" class="ft-cta-btn"><?= icon('download') ?><?= e($t('transfer.export_cta')) ?></button>
                </form>
            <?php elseif ($company === null): ?>
                <p class="ft-empty"><?= e($t('exports.no_company')) ?></p>
            <?php else: ?>
                <p class="ft-empty"><?= e($t('flash.db_unavailable')) ?></p>
            <?php endif; ?>
        </section>
    </div>

    <div class="ft-tables">
        <section class="ft-card">
            <header class="ft-card-head">
                <h4><?= e($t('transfer.recent_imports')) ?></h4>
                <span class="ft-count-badge"><?= e((string) count($imports ?? [])) ?></span>
            </header>
            <?php if (empty($imports)): ?>
                <p class="ft-empty"><?= e($t('imports.no_imports')) ?></p>
            <?php else: ?>
                <table class="table ft-table table-transfer">
                    <thead>
                    <tr>
                        <th><?= e($t('imports.source')) ?></th>
                        <th><?= e($t('imports.status')) ?></th>
                        <th><?= e($t('imports.imported_at')) ?></th>
                        <th class="col-actions"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($imports as $import): ?>
                        <tr>
                            <td class="ft-table-main"><?= e((string) ($import['source_filename'] ?? '')) ?></td>
                            <td><span class="ft-status<?= ($import['status'] ?? '') === 'completed' ? ' ft-status-green' : '' ?>"><?= e($t('imports.status_' . (string) ($import['status'] ?? 'unknown'))) ?></span></td>
                            <td><?= e($formatDate((string) ($import['completed_at'] ?? $import['created_at'] ?? ''))) ?></td>
                            <td class="ft-table-actions">
                                <a href="/admin/data/inspect?id=<?= e((string) $import['id']) ?>" class="ft-row-btn" title="<?= e($t('imports.inspect')) ?>"><?= icon('inspect', $t('imports.inspect')) ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="ft-card">
            <header class="ft-card-head">
                <h4><?= e($t('transfer.recent_exports')) ?></h4>
                <span class="ft-count-badge"><?= e((string) count($files ?? [])) ?></span>
            </header>
            <?php if (empty($files)): ?>
                <p class="ft-empty"><?= e($t('exports.no_files')) ?></p>
            <?php else: ?>
                <table class="table ft-table table-transfer">
                    <thead>
                    <tr>
                        <th><?= e($t('exports.file')) ?></th>
                        <th><?= e($t('exports.size')) ?></th>
                        <th><?= e($t('exports.created_at')) ?></th>
                        <th class="col-actions"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($files as $file): ?>
                        <tr>
                            <td class="ft-table-main"><?= e((string) $file['name']) ?></td>
                            <td><?= e((string) $file['size']) ?></td>
                            <td><?= e($formatDate((string) $file['date'])) ?></td>
                            <td class="ft-table-actions">
                                <a href="/admin/data/download?file=<?= e(urlencode((string) $file['name'])) ?>" class="ft-row-btn" title="<?= e($t('exports.download')) ?>"><?= icon('download', $t('exports.download')) ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </div>
</section>
<?php
$content = (string) ob_get_clean();

echo $this->render('layout/app', [
    'title' => ($t('transfer.title')) . ' - ' . $appName,
    'pageTitle' => $t('transfer.title'),
    'pageDescription' => $t('transfer.description'),
    'appName' => $appName,
    'currentCompany' => $company,
    'content' => $content,
]);
