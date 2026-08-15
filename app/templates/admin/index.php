<?php

$content = ob_start();
$t = $t ?? static fn ($key) => $key;
?>
<section class="ft-page">
    <div class="ft-grid">
        <?php foreach ($items as $item): ?>
            <article class="ft-card">
                <header class="ft-card-head">
                    <div>
                        <h3><?= e($t((string) ($item['title_key'] ?? ''))) ?></h3>
                    </div>
                </header>
                <p class="ft-card-text"><?= e($t((string) ($item['detail_key'] ?? ''))) ?></p>
                <?php if (isset($item['list_keys']) && is_array($item['list_keys'])): ?>
                    <ul class="ft-artifacts ft-artifacts-plain">
                        <?php foreach ($item['list_keys'] as $listKey): ?>
                            <li>
                                <span class="ft-dot" aria-hidden="true"></span>
                                <div><span><?= e($t((string) $listKey)) ?></span></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if (isset($item['link'])): ?>
                    <div class="ft-cta">
                        <a href="<?= e((string) $item['link']) ?>" class="ft-cta-btn"><?= e($t((string) ($item['action_key'] ?? 'admin.open'))) ?></a>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php
$content = (string) ob_get_clean();

echo $this->render('layout/app', [
    'title' => ($t('admin.title')) . ' - ' . $appName,
    'pageTitle' => $t('admin.title'),
    'pageDescription' => $t('admin.description'),
    'appName' => $appName,
    'currentCompany' => $currentCompany ?? null,
    'content' => $content,
]);
