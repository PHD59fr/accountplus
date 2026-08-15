<?php

$content = ob_start();
$t = $t ?? static fn ($key) => $key;

$avatarInitials = static function (?string $name): string {
    $name = trim((string) $name);
    if ($name === '') {
        return '?';
    }
    $parts = preg_split('/\s+/', $name);
    $parts = is_array($parts) ? $parts : [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
    }

    return $initials !== '' ? $initials : '?';
};

$assignedNames = static function (array $companies, array $companyIds): string {
    $names = [];
    foreach ($companies as $company) {
        if (in_array($company->id(), $companyIds, true)) {
            $names[] = $company->name();
        }
    }

    return implode(', ', $names);
};
?>
<section class="ft-page">
    <div class="ft-stats">
        <article class="ft-stat">
            <span class="ft-stat-icon ft-stat-icon-blue"><?= e($t('transfer.icon_company')) ?></span>
            <div>
                <span class="ft-stat-label"><?= e($t('users.summary_users')) ?></span>
                <strong class="ft-stat-value"><?= e((string) ($summary['users'] ?? 0)) ?></strong>
            </div>
        </article>
        <article class="ft-stat">
            <span class="ft-stat-icon ft-stat-icon-purple"><?= e($t('transfer.icon_company')) ?></span>
            <div>
                <span class="ft-stat-label"><?= e($t('users.summary_admins')) ?></span>
                <strong class="ft-stat-value"><?= e((string) ($summary['admins'] ?? 0)) ?></strong>
            </div>
        </article>
        <article class="ft-stat">
            <span class="ft-stat-icon ft-stat-icon-green"><?= e($t('transfer.icon_company')) ?></span>
            <div>
                <span class="ft-stat-label"><?= e($t('users.summary_companies')) ?></span>
                <strong class="ft-stat-value"><?= e((string) ($summary['companies'] ?? 0)) ?></strong>
            </div>
        </article>
    </div>

    <?php if ($error !== null): ?>
        <div class="alert"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($success !== null): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <div class="ft-grid">
        <section class="ft-card">
            <header class="ft-card-head">
                <div>
                    <span class="ft-kicker ft-kicker-blue"><?= e($t('users.create')) ?></span>
                    <h3><?= e($t('users.new_user')) ?></h3>
                </div>
            </header>
            <form method="post" action="/admin/users" class="ft-form">
                <?= csrf_field() ?>
                <label>
                    <span><?= e($t('users.username')) ?></span>
                    <input type="text" name="username" autocomplete="off">
                </label>
                <label>
                    <span><?= e($t('users.display_name')) ?></span>
                    <input type="text" name="display_name">
                </label>
                <label>
                    <span><?= e($t('users.password')) ?></span>
                    <input type="password" name="password" autocomplete="new-password">
                </label>
                <label>
                    <span><?= e($t('users.role')) ?></span>
                    <select name="role">
                        <option value="USER"><?= e($t('users.role_user')) ?></option>
                        <option value="ADMIN"><?= e($t('users.role_admin')) ?></option>
                    </select>
                </label>
                <button type="submit" class="ft-cta-btn"><?= e($t('users.create_user')) ?></button>
            </form>
        </section>

        <section class="ft-card">
            <header class="ft-card-head">
                <div>
                    <span class="ft-kicker ft-kicker-purple"><?= e($t('users.grant_access')) ?></span>
                    <h3><?= e($t('users.attach_user_company')) ?></h3>
                </div>
            </header>
            <form method="post" action="/admin/users/company" class="ft-form">
                <?= csrf_field() ?>
                <label>
                    <span><?= e($t('users.user')) ?></span>
                    <select name="user_id">
                        <?php foreach ($users as $user): ?>
                            <option value="<?= e((string) $user->id()) ?>"><?= e($user->username()) ?> (<?= e($user->role()) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span><?= e($t('companies.title')) ?></span>
                    <select name="company_id">
                        <?php foreach ($companies as $company): ?>
                            <option value="<?= e((string) $company->id()) ?>"><?= e($company->name()) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="ft-cta-btn"><?= e($t('users.attach_user')) ?></button>
            </form>
        </section>
    </div>

    <section class="ft-card">
        <header class="ft-card-head">
            <h4><?= e($t('users.current_users')) ?></h4>
            <span class="ft-count-badge"><?= e((string) count($users)) ?></span>
        </header>
        <?php if (empty($users)): ?>
            <p class="ft-empty"><?= e($t('users.no_users')) ?></p>
        <?php else: ?>
            <div class="ft-table-scroll ft-table-scroll-wide">
                <table class="table ft-table table-users">
                <thead>
                <tr>
                    <th><?= e($t('users.user')) ?></th>
                    <th><?= e($t('users.role')) ?></th>
                    <th><?= e($t('users.status')) ?></th>
                    <th><?= e($t('companies.title')) ?></th>
                    <th><?= e($t('users.last_login')) ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <tr class="<?= $user->isActive() ? '' : 'row-muted' ?>">
                        <td class="ft-table-main">
                            <div class="admin-user-cell">
                                <span class="avatar<?= $user->role() === 'ADMIN' ? ' avatar-accent' : '' ?>"><?= e($avatarInitials($user->displayName())) ?></span>
                                <div>
                                    <div class="admin-user-main"><?= e($user->username()) ?></div>
                                    <div class="admin-user-sub"><?= e($user->displayName()) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="badge<?= $user->role() === 'ADMIN' ? '' : ' badge-muted' ?>"><?= e($user->role()) ?></span>
                        </td>
                        <td>
                            <span class="status-badge<?= $user->isActive() ? ' status-active' : ' status-inactive' ?>">
                                <?= e($user->isActive() ? $t('users.active') : $t('users.inactive')) ?>
                            </span>
                        </td>
                        <td>
                            <div class="admin-company-list"><?= e($assignedNames($companies, $assignments[$user->id()] ?? [])) ?></div>
                        </td>
                        <td>
                            <?php if ($user->lastLoginAt() !== null): ?>
                                <?= e($formatDate($user->lastLoginAt())) ?>
                            <?php else: ?>
                                <span class="admin-user-sub"><?= e($t('users.never_logged_in')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="ft-table-actions">
                            <div class="admin-actions">
                                <?php if ($user->role() !== 'ADMIN'): ?>
                                    <form method="post" action="/admin/users/role" class="admin-inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="user_id" value="<?= e((string) $user->id()) ?>">
                                        <input type="hidden" name="role" value="ADMIN">
                                        <button type="submit" class="ft-row-btn"><?= e($t('users.grant_admin')) ?></button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="/admin/users/active" class="admin-inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="user_id" value="<?= e((string) $user->id()) ?>">
                                    <?php if ($user->isActive()): ?>
                                        <input type="hidden" name="action" value="deactivate">
                                        <button type="submit" class="ft-row-btn ft-row-btn-danger"><?= e($t('users.deactivate')) ?></button>
                                    <?php else: ?>
                                        <input type="hidden" name="action" value="activate">
                                        <button type="submit" class="ft-row-btn"><?= e($t('users.activate')) ?></button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </td>
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
    'title' => ($t('users.title')) . ' - ' . $appName,
    'pageTitle' => $t('users.title'),
    'pageDescription' => $t('users.description'),
    'appName' => $appName,
    'currentCompany' => $currentCompany,
    'content' => $content,
]);
