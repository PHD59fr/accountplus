<?php

/**
 * Renders a human-readable list of changes for a single audit log entry.
 *
 * Expects: $log (audit row with old_values/new_values arrays),
 *          $entityNames (map of entity type => [id => name]),
 *          $t (translator callable).
 */
$t = $t ?? static fn ($key) => $key;
$formatDate = $formatDate ?? static fn ($value) => (string) $value;
$entityNames = is_array($entityNames ?? null) ? $entityNames : [];

$skipFields = ['id', 'company_id', 'user_id', 'password_hash', 'created_at', 'updated_at'];

$fieldLabels = [
    'label' => 'transactions.label',
    'description' => 'transactions.description_field',
    'amount' => 'transactions.amount',
    'transaction_date' => 'transactions.date',
    'value_date' => 'transactions.value_date',
    'is_reconciled' => 'transactions.reconciled',
    'is_archived' => 'status.archived',
    'is_active' => 'categories.is_active',
    'category_id' => 'transactions.category',
    'account_id' => 'transactions.account',
    'destination_account_id' => 'recurring.destination_account',
    'name' => 'categories.name',
    'bank_name' => 'accounts.bank_name',
    'account_number' => 'accounts.account_number',
    'currency' => 'accounts.currency',
    'initial_balance' => 'accounts.initial_balance',
    'category_type' => 'categories.type',
    'type' => 'recurring.type',
    'status' => 'companies.status',
    'recurrence' => 'recurring.frequency',
    'interval_value' => 'recurring.interval',
    'next_execution_date' => 'recurring.next_execution_date',
    'end_date' => 'recurring.end_date',
    'username' => 'users.username',
    'display_name' => 'users.display_name',
    'role' => 'users.role',
    'source_label' => 'imports.source',
];

$boolFields = ['is_reconciled', 'is_archived', 'is_active'];

$dateFields = ['transaction_date', 'value_date', 'next_execution_date', 'end_date'];

$entityFields = [
    'category_id' => 'category',
    'account_id' => 'account',
    'destination_account_id' => 'account',
];

$formatValue = static function (mixed $value, string $field) use ($t, $entityNames, $boolFields, $entityFields, $dateFields, $formatDate): string {
    if ($value === null || $value === '') {
        return $t('audit.none');
    }
    if (in_array($field, $boolFields, true)) {
        return (int) $value === 1 ? $t('audit.yes') : $t('audit.no');
    }
    if (isset($entityFields[$field])) {
        $map = $entityNames[$entityFields[$field]] ?? [];
        $id = (int) $value;
        if (array_key_exists($id, $map)) {
            return (string) $map[$id];
        }

        return (string) $value;
    }
    if (in_array($field, $dateFields, true)) {
        return $formatDate((string) $value);
    }

    return (string) $value;
};

$old = is_array($log['old_values']) ? $log['old_values'] : [];
$new = is_array($log['new_values']) ? $log['new_values'] : [];
$action = (string) $log['action'];
$isCreate = str_ends_with($action, '.create');
$isDelete = str_ends_with($action, '.delete');

$lines = [];
foreach (array_keys(array_merge($old, $new)) as $field) {
    if (in_array($field, $skipFields, true) || !isset($fieldLabels[$field])) {
        continue;
    }
    $from = $old[$field] ?? null;
    $to = $new[$field] ?? null;
    if ($from === $to) {
        continue;
    }
    $label = $t($fieldLabels[$field]);
    if ($isCreate || $isDelete) {
        $value = $isCreate ? $to : $from;
        $lines[] = e($label) . ' : ' . e($formatValue($value, $field));
    } elseif (array_key_exists($field, $new)) {
        if (array_key_exists($field, $old)) {
            $lines[] = e($label) . ' : ' . e($formatValue($from, $field)) . ' → ' . e($formatValue($to, $field));
        } else {
            $lines[] = e($label) . ' : ' . e($formatValue($to, $field));
        }
    }
}
?>
<?php if ($lines !== []): ?>
    <ul class="audit-diff">
        <?php foreach ($lines as $line): ?>
            <li><?= $line ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
