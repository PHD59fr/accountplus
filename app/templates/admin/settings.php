<?php

$content = ob_start();
$formats = is_array($formats ?? null) ? $formats : [];
$currentFormat = (string) ($currentFormat ?? 'Y-m-d');
$isCustom = !in_array($currentFormat, array_column($formats, 'format'), true);
$t = $t ?? static fn ($key) => $key;
$ipMode = (string) ($ipInput['mode'] ?? $ipSettings['mode']);
$ipWhitelist = (string) ($ipInput['whitelist'] ?? implode("\n", $ipSettings['whitelist']));
$ipBlacklist = (string) ($ipInput['blacklist'] ?? implode("\n", $ipSettings['blacklist']));
?>
<section class="ft-page">
    <?php if ($error !== null): ?>
        <div class="alert"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($success !== null): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <section class="ft-card">
        <header class="ft-card-head">
            <div>
                <span class="ft-kicker ft-kicker-blue"><?= e($t('settings.date_format_kicker')) ?></span>
                <h3><?= e($t('settings.date_format_title')) ?></h3>
            </div>
        </header>

        <div class="ft-mini-stats ft-mini-stats-single">
            <div class="ft-mini-stat">
                <span class="ft-mini-icon ft-mini-icon-blue">Y</span>
                <div>
                    <span class="ft-mini-label"><?= e($t('settings.current_label')) ?></span>
                    <strong><?= e($currentFormat) ?> · <?= e((string) ($sampleDate ?? '')) ?></strong>
                </div>
            </div>
        </div>

        <form method="post" action="/admin/settings" class="ft-form">
            <?= csrf_field() ?>
            <p class="section-text"><?= e($t('settings.date_format_hint')) ?></p>

            <fieldset class="settings-format-grid">
                <?php foreach ($formats as $format): ?>
                    <label class="settings-format-card">
                        <input type="radio" name="date_format" value="<?= e((string) $format['format']) ?>"<?= $currentFormat === (string) $format['format'] ? ' checked' : '' ?>>
                        <span class="settings-format-sample"><?= e((string) ($format['sample'] ?? '')) ?></span>
                        <span class="settings-format-label"><?= e($t((string) ($format['label_key'] ?? ''))) ?></span>
                        <code class="code-inline"><?= e((string) $format['format']) ?></code>
                        <span class="settings-format-check"><?= e($t('settings.selected')) ?></span>
                    </label>
                <?php endforeach; ?>
                <label class="settings-format-card">
                    <input type="radio" name="date_format" value="__custom"<?= $isCustom ? ' checked' : '' ?>>
                    <span class="settings-format-sample"><?= e((string) ($sampleDate ?? '')) ?></span>
                    <span class="settings-format-label"><?= e($t('settings.custom')) ?></span>
                    <code class="code-inline"><?= e($currentFormat) ?></code>
                    <span class="settings-format-check"><?= e($t('settings.selected')) ?></span>
                </label>
            </fieldset>

            <div class="settings-controls">
                <label>
                    <span><?= e($t('settings.custom_format')) ?></span>
                    <input type="text" name="custom_format" value="<?= e($currentFormat) ?>" placeholder="d/m/Y" maxlength="64">
                </label>

                <div class="settings-preview-wrap">
                    <span class="ft-kicker ft-kicker-blue"><?= e($t('settings.preview_title')) ?></span>
                    <div class="settings-preview">
                        <div class="settings-preview-row">
                            <span><?= e($t('settings.preview_date')) ?></span>
                            <strong><?= e((string) ($sampleDate ?? '')) ?></strong>
                        </div>
                        <div class="settings-preview-row">
                            <span><?= e($t('settings.preview_datetime')) ?></span>
                            <strong><?= e((string) ($sampleDateTime ?? '')) ?></strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="ft-cta">
                <button type="submit" class="ft-cta-btn"><?= e($t('settings.save')) ?></button>
            </div>
        </form>
    </section>
    <section class="ft-card">
        <header class="ft-card-head">
            <div>
                <span class="ft-kicker ft-kicker-blue"><?= e($t('dashboard.title')) ?></span>
                <h3><?= e($t('settings.dashboard_title')) ?></h3>
            </div>
        </header>
        <form method="post" action="/admin/settings/dashboard" class="ft-form">
            <?= csrf_field() ?>
            <p class="section-text"><?= e($t('settings.dashboard_hint')) ?></p>
            <fieldset class="settings-dashboard-grid">
                <legend class="sr-only"><?= e($t('settings.dashboard_title')) ?></legend>
                <?php foreach ($dashboardOptions as $key => $label): ?>
                    <label class="ft-form-label-checkbox">
                        <input type="checkbox" name="dashboard_widgets[]" value="<?= e($key) ?>"<?= $dashboardWidgets[$key] ? ' checked' : '' ?>>
                        <span><?= e($t($label)) ?></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <div class="ft-cta">
                <button type="submit" class="ft-cta-btn"><?= e($t('settings.dashboard_save')) ?></button>
            </div>
        </form>
    </section>
    <section class="ft-card">
        <header class="ft-card-head">
            <div>
                <span class="ft-kicker ft-kicker-blue"><?= e($t('settings.ip_kicker')) ?></span>
                <h3><?= e($t('settings.ip_title')) ?></h3>
            </div>
        </header>
        <form method="post" action="/admin/settings/ip-restrictions" class="ft-form">
            <?= csrf_field() ?>
            <p class="section-text"><?= e($t('settings.ip_hint')) ?></p>
            <p class="section-text"><?= e($t('settings.ip_current')) ?> <strong><?= e($clientIp) ?></strong></p>
            <?php if ($ipError !== null): ?>
                <div class="alert"><?= e($ipError) ?></div>
            <?php elseif (!$ipWritable): ?>
                <div class="alert"><?= e($t('settings.ip_error_unwritable')) ?></div>
            <?php endif; ?>
            <fieldset class="settings-ip-fields"<?= !$ipWritable ? ' disabled' : '' ?>>
                <label>
                    <span><?= e($t('settings.ip_mode')) ?></span>
                    <select name="ip_mode">
                        <?php foreach (['disabled', 'whitelist', 'blacklist'] as $mode): ?>
                            <option value="<?= e($mode) ?>"<?= $ipMode === $mode ? ' selected' : '' ?>><?= e($t('settings.ip_mode_' . $mode)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="settings-controls">
                    <label>
                        <span><?= e($t('settings.ip_whitelist')) ?></span>
                        <textarea name="ip_whitelist" rows="7" maxlength="20000" placeholder="192.0.2.10&#10;198.51.100.0/24&#10;2001:db8::/32" spellcheck="false"><?= e($ipWhitelist) ?></textarea>
                    </label>
                    <label>
                        <span><?= e($t('settings.ip_blacklist')) ?></span>
                        <textarea name="ip_blacklist" rows="7" maxlength="20000" placeholder="192.0.2.20&#10;203.0.113.0/24&#10;2001:db8:1::/48" spellcheck="false"><?= e($ipBlacklist) ?></textarea>
                    </label>
                </div>
                <p class="section-text"><?= e($t('settings.ip_entries_hint')) ?></p>
                <div class="ft-cta">
                    <button type="submit" class="ft-cta-btn"><?= e($t('settings.ip_save')) ?></button>
                </div>
            </fieldset>
        </form>
    </section>
</section>
<?php
$content = (string) ob_get_clean();

echo $this->render('layout/app', [
    'title' => ($t('settings.title')) . ' - ' . $appName,
    'pageTitle' => $t('settings.title'),
    'pageDescription' => $t('settings.description'),
    'appName' => $appName,
    'currentCompany' => $currentCompany ?? null,
    'content' => $content,
]);
