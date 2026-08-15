<!DOCTYPE html>
<html lang="<?= e($currentLocale ?? 'en') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($t ?? static fn ($key) => $key)('errors.500')) ?></title>
    <link rel="stylesheet" href="/assets/css/app.css?v=20260810-1">
</head>
<body class="auth-body">
<main class="auth-shell">
    <section class="ft-card narrow">
        <span class="ft-kicker ft-kicker-blue">500</span>
        <h1><?= e(($t ?? static fn ($key) => $key)('errors.500')) ?></h1>
        <p class="section-text"><?= e(($t ?? static fn ($key) => $key)('errors.internal')) ?></p>
        <div class="hero-actions">
            <a href="/" class="ft-form-btn"><?= e(($t ?? static fn ($key) => $key)('nav.dashboard')) ?></a>
        </div>
    </section>
</main>
</body>
</html>
