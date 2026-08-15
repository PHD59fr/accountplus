<?php
/** @var array<string, mixed> $values */
$t = $t ?? static fn ($key) => $key;
$type = (string) ($values['type'] ?? 'expense');
$types = ['expense', 'income', 'transfer'];
$recurrences = ['daily', 'weekly', 'monthly', 'yearly'];
?>
<?php if ($currentCompany !== null): ?>
    <p class="section-text"><?= e($t('recurring.current_company_text', ['name' => $currentCompany->name()])) ?></p>
<?php endif; ?>
<?php if ($error !== null): ?>
    <div class="alert"><?= e($error) ?></div>
<?php endif; ?>
<?php if ($accounts === []): ?>
    <p class="section-text"><?= e($t('recurring.no_accounts')) ?></p>
    <a href="/accounts" class="ft-form-btn"><?= e($t('action.open_accounts')) ?></a>
<?php else: ?>
    <form method="post" action="<?= e((string) ($formAction ?? '/recurring')) ?>" class="ft-form ft-form-grid">
        <?= csrf_field() ?>
        <?php if ((int) ($ruleId ?? 0) > 0): ?>
            <input type="hidden" name="id" value="<?= e((string) $ruleId) ?>">
        <?php endif; ?>
        <label>
            <span><?= e($t('recurring.type')) ?></span>
            <select name="type" id="recurring_type">
                <?php foreach ($types as $option): ?>
                    <option value="<?= e($option) ?>"<?= $type === $option ? ' selected' : '' ?>><?= e($t('recurring.type_' . $option)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span><?= e($t('recurring.account')) ?></span>
            <select name="account_id">
                <option value=""><?= e($t('recurring.account_select')) ?></option>
                <?php foreach ($accounts as $account): ?>
                    <option value="<?= e((string) $account['id']) ?>"<?= (string) ($values['account_id'] ?? '') === (string) $account['id'] ? ' selected' : '' ?>><?= e($account['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label id="recurring_destination_wrap">
            <span><?= e($t('recurring.destination_account')) ?></span>
            <select name="destination_account_id">
                <option value=""><?= e($t('recurring.account_select')) ?></option>
                <?php foreach ($accounts as $account): ?>
                    <option value="<?= e((string) $account['id']) ?>"<?= (string) ($values['destination_account_id'] ?? '') === (string) $account['id'] ? ' selected' : '' ?>><?= e($account['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span><?= e($t('recurring.category')) ?></span>
            <select name="category_id">
                <option value=""><?= e($t('recurring.category_select')) ?></option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= e((string) $category['id']) ?>"<?= (string) ($values['category_id'] ?? '') === (string) $category['id'] ? ' selected' : '' ?>><?= !empty($category['emoji']) ? e($category['emoji']) . ' ' : '' ?><?= e($category['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span><?= e($t('recurring.label')) ?></span>
            <input type="text" name="label" value="<?= e((string) ($values['label'] ?? '')) ?>">
        </label>
        <label>
            <span><?= e($t('recurring.amount')) ?></span>
            <input type="text" name="amount" placeholder="-45.90" value="<?= e((string) ($values['amount'] ?? '')) ?>">
        </label>
        <label>
            <span><?= e($t('recurring.frequency')) ?></span>
            <select name="recurrence">
                <?php foreach ($recurrences as $option): ?>
                    <option value="<?= e($option) ?>"<?= (string) ($values['recurrence'] ?? '') === $option ? ' selected' : '' ?>><?= e($t('recurring.unit_' . $option)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span><?= e($t('recurring.interval')) ?></span>
            <input type="number" name="interval_value" min="1" max="365" value="<?= e((string) ($values['interval_value'] ?? '1')) ?>">
        </label>
        <label class="ft-form-label-checkbox span-1">
            <input type="checkbox" name="is_active" value="1"<?= (int) ($values['is_active'] ?? 1) === 1 ? ' checked' : '' ?>>
            <span><?= e($t('recurring.is_active')) ?></span>
        </label>
        <label>
            <span><?= e($t('recurring.next_execution_date')) ?></span>
            <input type="date" name="next_execution_date" value="<?= e((string) ($values['next_execution_date'] ?? '')) ?>">
        </label>
        <label>
            <span><?= e($t('recurring.end_date')) ?></span>
            <input type="date" name="end_date" value="<?= e((string) ($values['end_date'] ?? '')) ?>">
        </label>
        <button type="submit" class="ft-form-btn"><?= e($t((string) ($submitKey ?? 'recurring.save'))) ?></button>
    </form>
    <script nonce="<?= e(csp_nonce()) ?>">
        (function () {
            const type = document.getElementById('recurring_type');
            const destination = document.getElementById('recurring_destination_wrap');
            const toggle = function () {
                destination.style.display = type.value === 'transfer' ? '' : 'none';
            };
            if (type) {
                type.addEventListener('change', toggle);
                toggle();
            }
        })();
    </script>
<?php endif; ?>
