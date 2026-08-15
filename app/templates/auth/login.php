<!DOCTYPE html>
<html lang="<?= e($currentLocale ?? 'en') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($t ?? static fn ($key) => $key)('login.title')) ?> - <?= e($appName) ?></title>
    <link rel="stylesheet" href="/assets/css/app.css?v=20260810-1">
</head>
<body class="auth-body">
<main class="auth-shell">
    <section class="auth-hero">
        <div class="auth-brand">
            <div class="auth-mark">PC</div>
            <div>
                <div class="auth-brand-name"><?= e($appName) ?></div>
                <div class="auth-brand-subtitle"><?= e(($t ?? static fn ($key) => $key)('app.tagline')) ?></div>
            </div>
        </div>
        <span class="auth-kicker"><?= e(($t ?? static fn ($key) => $key)('login.kicker')) ?></span>
        <h1><?= e(($t ?? static fn ($key) => $key)('login.hero_title')) ?></h1>
        <p><?= e(($t ?? static fn ($key) => $key)('login.hero_text')) ?></p>
        <div class="auth-bullets">
            <span><?= e(($t ?? static fn ($key) => $key)('login.bullet_company')) ?></span>
            <span><?= e(($t ?? static fn ($key) => $key)('login.bullet_access')) ?></span>
            <span><?= e(($t ?? static fn ($key) => $key)('login.bullet_reporting')) ?></span>
        </div>
    </section>

    <section class="auth-panel-wrap">
        <div class="auth-panel">
            <div class="auth-panel-head">
                <span class="ft-kicker ft-kicker-blue"><?= e(($t ?? static fn ($key) => $key)('login.panel_kicker')) ?></span>
                <h2><?= e(($t ?? static fn ($key) => $key)('login.sign_in')) ?></h2>
                <p class="section-text"><?= e(($t ?? static fn ($key) => $key)('login.description')) ?></p>
            </div>
            <?php if ($error !== null): ?>
                <div class="alert"><?= e($error) ?></div>
            <?php endif; ?>
            <form method="post" action="/login" class="ft-form">
                <?= csrf_field() ?>
                <label>
                    <span><?= e(($t ?? static fn ($key) => $key)('login.username')) ?></span>
                    <input type="text" name="username" autocomplete="username" value="<?= e($username ?? '') ?>">
                </label>
                <label>
                    <span><?= e(($t ?? static fn ($key) => $key)('login.password')) ?></span>
                    <input type="password" name="password" autocomplete="current-password">
                </label>
                <button type="submit" class="ft-form-btn ft-form-btn-full"><?= e(($t ?? static fn ($key) => $key)('login.sign_in')) ?></button>
            </form>
        </div>
    </section>
</main>
</body>
</html>
