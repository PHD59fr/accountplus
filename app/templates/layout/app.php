<!DOCTYPE html>
<html lang="<?= e($currentLocale ?? 'en') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Account+') ?></title>
    <link rel="stylesheet" href="/assets/css/app.css?v=20261026-3">
    <script defer src="/assets/vendor/chart.js/chart.umd.min.js?v=4.4.1"></script>
    <script defer src="/assets/js/security-ui.js"></script>
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <div class="sidebar-glow"></div>
        <div class="brand">
            <div class="brand-mark">PC</div>
            <div>
                <div class="brand-name"><?= e($appName ?? 'Account+') ?></div>
                <div class="brand-subtitle"><?= e(($t ?? static fn ($key) => $key)('app.tagline')) ?></div>
            </div>
        </div>
        <nav class="nav">
            <a href="/"><?= e(($t ?? static fn ($key) => $key)('nav.dashboard')) ?></a>
            <a href="/accounts"><?= e(($t ?? static fn ($key) => $key)('nav.accounts_transactions')) ?></a>
            <a href="/categories"><?= e(($t ?? static fn ($key) => $key)('nav.categories')) ?></a>
            <a href="/recurring"><?= e(($t ?? static fn ($key) => $key)('nav.recurring')) ?></a>
            <a href="/reports"><?= e(($t ?? static fn ($key) => $key)('nav.reports')) ?></a>
            <?php if (($canManageAdministration ?? false) === true): ?>
                <a href="/admin"><?= e(($t ?? static fn ($key) => $key)('nav.administration')) ?></a>
            <?php endif; ?>
        </nav>
    </aside>
    <main class="main">
        <header class="topbar">
            <div>
                <span class="ft-eyebrow"><?= e($appName ?? 'Account+') ?></span>
                <h1><?= e($pageTitle ?? 'Account+') ?></h1>
                <p><?= e($pageDescription ?? '') ?></p>
            </div>
            <div class="topbar-actions">
                <?php if (!empty($availableCompanies)): ?>
                    <form method="post" action="/companies/select" class="company-switcher" aria-label="<?= e(($t ?? static fn ($key) => $key)('companies.selection')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="redirect_to" value="<?= e((string) ($_SERVER['REQUEST_URI'] ?? '/')) ?>">
                        <label class="sr-only" for="company-select"><?= e(($t ?? static fn ($key) => $key)('companies.selection')) ?></label>
                        <select id="company-select" name="company_id" class="company-select" data-auto-submit>
                            <?php foreach ($availableCompanies as $companyOption): ?>
                                <option value="<?= e((string) $companyOption->id()) ?>"<?= isset($currentCompany) && $currentCompany !== null && $currentCompany->id() === $companyOption->id() ? ' selected' : '' ?>>
                                    <?= e($companyOption->name()) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php endif; ?>
                <form method="post" action="/language" class="locale-switcher" aria-label="<?= e(($t ?? static fn ($key) => $key)('language.label')) ?>">
                    <?= csrf_field() ?>
                    <label class="sr-only" for="locale-select"><?= e(($t ?? static fn ($key) => $key)('language.label')) ?></label>
                    <select id="locale-select" name="locale" class="locale-select" data-auto-submit>
                        <?php foreach (($supportedLocales ?? []) as $localeCode => $localeName): ?>
                            <option value="<?= e((string) $localeCode) ?>"<?= ($currentLocale ?? 'en') === $localeCode ? ' selected' : '' ?>>
                                <?= e((string) $localeName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <form method="post" action="/logout">
                    <?= csrf_field() ?>
                    <button type="submit" class="ft-form-btn"><?= e(($t ?? static fn ($key) => $key)('action.logout')) ?></button>
                </form>
            </div>
        </header>
        <div class="content-stack">
            <?php $flashSuccess = flash('success'); ?>
            <?php $flashError = flash('error'); ?>
            <?php if ($flashSuccess !== null): ?>
                <div class="alert alert-success"><?= e($flashSuccess) ?></div>
            <?php endif; ?>
            <?php if ($flashError !== null): ?>
                <div class="alert"><?= e($flashError) ?></div>
            <?php endif; ?>
            <?= $content ?? '' ?>
        </div>
    </main>
</div>
</body>
</html>
