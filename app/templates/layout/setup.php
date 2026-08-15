<!DOCTYPE html>
<html lang="<?= e($currentLocale ?? 'en') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Account+') ?></title>
    <link rel="stylesheet" href="/assets/css/app.css?v=20260810-1">
</head>
<body class="setup-body">
<main class="setup-shell">
    <section class="setup-intro">
        <div class="brand brand-standalone">
            <div class="brand-mark">PC</div>
            <div>
                <div class="brand-name"><?= e($appName ?? 'Account+') ?></div>
                <div class="brand-subtitle"><?= e(($t ?? static fn ($key) => $key)('setup.layout_subtitle')) ?></div>
            </div>
        </div>
        <h1><?= e(($t ?? static fn ($key) => $key)('setup.wizard_title')) ?></h1>
        <p>
            <?= e(($t ?? static fn ($key) => $key)('setup.wizard_text')) ?>
        </p>
        <div class="cards">
            <article class="account-card">
                <strong><?= e(($t ?? static fn ($key) => $key)('setup.card_bootstrap')) ?></strong>
                <div class="company-detail-list">
                    <span><?= e(($t ?? static fn ($key) => $key)('setup.card_bootstrap_1')) ?></span>
                    <span><?= e(($t ?? static fn ($key) => $key)('setup.card_bootstrap_2')) ?></span>
                    <span><?= e(($t ?? static fn ($key) => $key)('setup.card_bootstrap_3')) ?></span>
                </div>
            </article>
        </div>
    </section>
    <section class="setup-panel">
        <?= $content ?? '' ?>
    </section>
</main>
</body>
</html>
