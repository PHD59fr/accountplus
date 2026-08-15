<?php

declare(strict_types=1);

// Run only against a dedicated empty test database. See README.md.
$basePath = dirname(__DIR__, 2);
require $basePath . '/app/src/Support/helpers.php';
require $basePath . '/app/src/Support/Autoloader.php';
App\Support\Autoloader::register($basePath . '/app');
session_start();
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$database = getenv('TEST_DB_DATABASE') ?: '';
if (preg_match('/^accountplus_test(?:_[a-z0-9]+)?$/', $database) !== 1) {
    throw new RuntimeException('TEST_DB_DATABASE must be a dedicated accountplus_test database.');
}
$pdo = App\Support\Database::connect([
    'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
    'database' => $database,
    'username' => getenv('TEST_DB_USERNAME') ?: 'compte',
    'password' => getenv('TEST_DB_PASSWORD') ?: 'compte',
]);
if ($pdo->query('SHOW TABLES')->fetchColumn() !== false) {
    throw new RuntimeException('Integration tests require an empty database.');
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function integrationCheck(string $name, callable $callback): void
{
    $callback();
    fwrite(STDOUT, 'ok   ' . $name . PHP_EOL);
}
function expectRejected(callable $callback): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('Expected operation to be rejected.');
}

$installer = new App\Service\InstallerService($pdo, $basePath . '/app/database/migrations');
integrationCheck('upgrade an existing schema and preserve import history', static function () use ($pdo, $basePath, $installer): void {
    // Apply the original migrations, seed real history, then apply the new ones.
    $old = sys_get_temp_dir() . '/accountplus-old-' . bin2hex(random_bytes(4));
    mkdir($old);
    foreach (glob($basePath . '/app/database/migrations/00[1-6]*.php') as $file) {
        copy($file, $old . '/' . basename($file));
    }
    try {
        (new App\Support\Migrator($pdo, $old))->migrate();
    } finally {
        foreach (glob($old . '/*') as $file) {
            unlink($file);
        }
        rmdir($old);
    }
    $pdo->exec('INSERT INTO imports (source_filename, source_sha256, status, created_at, updated_at)
        VALUES ("old.zip", REPEAT("a", 64), "completed", NOW(), NOW())');
    $installer->migrate();
    $installer->migrate();
    assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM imports')->fetchColumn(), 'Existing import was lost');
    $indexes = $pdo->query('SHOW INDEX FROM imports')->fetchAll();
    assertSameValue(false, in_array('uniq_imports_source_sha256', array_column($indexes, 'Key_name'), true), 'Global unique index survived');
    assertSameValue(true, in_array('uniq_imports_company_sha256', array_column($indexes, 'Key_name'), true), 'Company fingerprint index is missing');
});

integrationCheck('installer refuses to reset an existing administrator', static function () use ($installer, $pdo): void {
    $payload = ['admin_username' => 'admin', 'admin_password_hash' => password_hash('integration-password', PASSWORD_DEFAULT)];
    $installer->install($payload);
    assertSameValue(true, $installer->isInstalled(), 'Installation did not finish');
    $before = $pdo->query('SELECT password_hash FROM users WHERE username = "admin"')->fetchColumn();
    try {
        $installer->install(array_replace($payload, ['admin_password_hash' => password_hash('replacement-password', PASSWORD_DEFAULT)]));
        throw new LogicException('Installer accepted an existing database.');
    } catch (RuntimeException $exception) {
        if (!str_contains($exception->getMessage(), 'already contains users')) {
            throw $exception;
        }
    }
    assertSameValue($before, $pdo->query('SELECT password_hash FROM users WHERE username = "admin"')->fetchColumn(), 'Password was changed');
});

$companies = new App\Repository\CompanyRepository($pdo);
$accounts = new App\Repository\AccountRepository($pdo);
$categories = new App\Repository\CategoryRepository($pdo);
$transactions = new App\Repository\TransactionRepository($pdo);
$types = new App\Repository\TransactionTypeRepository($pdo);
$context = new App\Service\CompanyContext($companies);
$companyId = $companies->create('A integration', 'TEST', 'Active');
$context->select($companyId);
$accountService = new App\Service\AccountService($accounts, $context);
$audit = new App\Service\AuditLogService(new App\Repository\AuditLogRepository($pdo), null, $context);
$transactionService = new App\Service\TransactionService($transactions, $accounts, $context, $companies, $audit, $categories);
$accountId = $accountService->create(['name' => 'Main account', 'initial_balance' => '100.00']);
$secondAccountId = $accountService->create(['name' => 'Second account', 'initial_balance' => '0.00']);
$today = date('Y-m-d');

integrationCheck('manual transaction creation accepts an omitted type', static function () use ($transactionService, $pdo, $accountId, $today): void {
    $id = $transactionService->create(['account_id' => $accountId, 'transaction_date' => $today, 'label' => 'Manual', 'amount' => '10.00']);
    $row = $pdo->query('SELECT amount, type FROM transactions WHERE id = ' . $id)->fetch();
    assertSameValue('10.00', $row['amount'], 'Amount mismatch');
    assertSameValue(null, $row['type'], 'Default type must be null');
});

integrationCheck('balances agree when transactions and accounts are archived', static function () use ($accountService, $accountId, $transactionService, $pdo, $context, $today): void {
    $archivedTx = $transactionService->create(['account_id' => $accountId, 'transaction_date' => $today, 'label' => 'Archived', 'amount' => '20.00']);
    $transactionService->archive($archivedTx);
    $archivedAccount = $accountService->create(['name' => 'Archived account', 'initial_balance' => '1000.00']);
    $transactionService->create(['account_id' => $archivedAccount, 'transaction_date' => $today, 'label' => 'Archived account movement', 'amount' => '200.00']);
    $accountService->archive($archivedAccount);
    $data = new App\Service\AppDataService($pdo, $context);
    assertSameValue('110.00 EUR', $data->metrics()['globalBalance'], 'Global balance includes archived data');
    assertSameValue('110.00 EUR', array_column($data->accountCards(), 'balance', 'id')[$accountId], 'Account card differs');
    assertSameValue('110.00 EUR', array_column($data->accountsTable(), 'balance', 'id')[$accountId], 'Account table differs');
    assertSameValue(2, array_column($data->accountsTable(), 'transactions', 'id')[$accountId], 'Archived entries disappeared from the activity count');
    assertSameValue('110.00 EUR', $data->reportCards()[3]['value'], 'Active report balance differs');
    assertSameValue(110.0, $data->reportTransactions($today, $today)['closing_balance'], 'Default report differs');
    $report = $data->reportTransactions($today, $today, null, '', null, true);
    assertSameValue(1330.0, $report['closing_balance'], 'Archived account initial balance was omitted');
});

integrationCheck('dashboard settings persist independent selections and reject invalid elements', static function () use ($pdo, $audit): void {
    $pdo->beginTransaction();
    try {
        $repository = new App\Repository\SettingsRepository($pdo);
        $settings = new App\Service\DashboardSettings($repository, $audit);
        $defaults = array_fill_keys(array_keys(App\Service\DashboardSettings::WIDGETS), true);
        assertSameValue($defaults, $settings->read(), 'Dashboard is not fully visible by default');
        $dateFormat = $repository->get('date_format');
        $settings->update(['balance', 'type_breakdown']);
        $selected = array_fill_keys(array_keys($defaults), false);
        $selected['balance'] = $selected['type_breakdown'] = true;
        assertSameValue($selected, (new App\Service\DashboardSettings($repository))->read(), 'Selection did not persist');
        foreach ([['unknown_widget'], [['balance']], ['balance' => true]] as $invalid) {
            expectRejected(static fn () => $settings->update($invalid));
            assertSameValue($selected, $settings->read(), 'Invalid selection changed the dashboard');
        }
        $settings->update([]);
        assertSameValue(array_fill_keys(array_keys($defaults), false), $settings->read(), 'Cannot disable every element');
        assertSameValue($dateFormat, $repository->get('date_format'), 'Date format changed with dashboard settings');
        $repository->set('dashboard_widgets', '{invalid json');
        assertSameValue($defaults, $settings->read(), 'Invalid stored configuration has no defaults');
    } finally {
        $pdo->rollBack();
    }
});

integrationCheck('label breakdown groups gross active movements and preserves the total in Other labels', static function () use ($pdo, $transactions, $context, $companies, $accounts, $companyId, $accountId, $today): void {
    $pdo->beginTransaction();
    try {
        $fixtureCompany = $companies->create('Label breakdown fixture', 'TEST', 'Active');
        $context->select($fixtureCompany);
        $fixtureAccounts = new App\Service\AccountService($accounts, $context);
        $main = $fixtureAccounts->create(['name' => 'Labels main']);
        $archived = $fixtureAccounts->create(['name' => 'Labels archived']);
        $fixtureAccounts->archive($archived);
        $data = new App\Service\AppDataService($pdo, $context);
        assertSameValue([], $data->labelBreakdown()['labels'], 'Empty company has chart data');
        $add = static function (string $label, string $amount, int $account, int $isArchived = 0) use ($transactions, $fixtureCompany, $today): void {
            $transactions->create([
                'company_id' => $fixtureCompany, 'account_id' => $account, 'transaction_date' => $today,
                'label' => $label, 'amount' => $amount, 'is_archived' => $isArchived,
                'value_date' => '', 'description' => '',
            ]);
        };
        $add(' Shared label ', '-25.00', $main);
        $add('Shared label', '25.00', $main);
        $add('', '10.00', $main);
        $add('   ', '-5.00', $main);
        $add('Income', '30.00', $main);
        $add('Other labels', '5.00', $main);
        $add('Archived movement', '999.00', $main, 1);
        $add('Archived account', '999.00', $archived);
        $transactions->create([
            'company_id' => $companyId, 'account_id' => $accountId, 'transaction_date' => $today,
            'label' => 'Foreign label', 'amount' => '1000.00', 'value_date' => '', 'description' => '',
        ]);
        $breakdown = $data->labelBreakdown();
        assertSameValue(100.0, $breakdown['total_abs'], 'Archived or foreign movements were included');
        assertSameValue(4, count($breakdown['labels']), 'Identical or blank labels were not grouped');
        $byLabel = [];
        foreach ($breakdown['labels'] as $row) {
            $byLabel[$row['label'] ?? '<blank>'] = $row;
        }
        assertSameValue(50.0, $byLabel['Shared label']['total'], 'Opposite movements cancelled out');
        assertSameValue(50.0, $byLabel['Shared label']['percent'], 'Percentage denominator is incorrect');
        assertSameValue(15.0, $byLabel['<blank>']['total'], 'Blank labels were not grouped');
        assertSameValue(false, $byLabel['Other labels']['other'], 'A real label collided with the remainder bucket');
        for ($index = 1; $index <= 13; $index++) {
            $add(sprintf('Tail %02d', $index), '1.00', $main);
        }
        $breakdown = $data->labelBreakdown();
        assertSameValue(11, count($breakdown['labels']), 'Expected ten labels plus the remainder');
        assertSameValue(113.0, array_sum(array_column($breakdown['labels'], 'total')), 'Grouping lost part of the total');
        $other = $breakdown['labels'][10];
        assertSameValue(true, $other['other'], 'Remainder bucket is missing');
        assertSameValue(7.0, $other['total'], 'Remainder amount is incorrect');
        if (abs(array_sum(array_column($breakdown['labels'], 'percent')) - 100.0) > 0.00001) {
            throw new RuntimeException('Label percentages do not sum to 100');
        }
    } finally {
        $pdo->rollBack();
        $context->select($companyId);
    }
});

integrationCheck('type breakdown includes untyped movements and balanced transfers without cancellation', static function () use ($pdo, $transactions, $context, $categories, $companyId, $accountId, $secondAccountId, $today): void {
    $pdo->beginTransaction();
    try {
        $untyped = $categories->create(['company_id' => $companyId, 'name' => 'Untyped breakdown', 'category_type' => '', 'is_active' => 1]);
        $expense = $categories->create(['company_id' => $companyId, 'name' => 'Expense breakdown', 'category_type' => ' EXPENSE ', 'is_active' => 1]);
        $transfer = (new App\Repository\TransferRepository($pdo))->create([
            'company_id' => $companyId, 'source_account_id' => $accountId, 'destination_account_id' => $secondAccountId,
            'amount' => '25.00', 'transaction_date' => $today, 'label' => 'Breakdown regression',
        ]);
        foreach ([
            [null, '100.00', null, $accountId, 0],
            [null, '-40.00', null, $accountId, 0],
            [$untyped, '30.00', null, $accountId, 0],
            [$untyped, '-30.00', null, $accountId, 0],
            [$expense, '-20.00', null, $accountId, 0],
            [$expense, '20.00', null, $accountId, 0],
            [$expense, '-25.00', $transfer, $accountId, 0],
            [null, '25.00', $transfer, $secondAccountId, 0],
            [null, '-11.00', null, $accountId, 1],
        ] as [$category, $amount, $transferId, $account, $archived]) {
            $transactions->create([
                'company_id' => $companyId, 'account_id' => $account, 'category_id' => $category,
                'transaction_date' => $today, 'value_date' => '', 'description' => '',
                'label' => 'Breakdown regression', 'amount' => $amount, 'transfer_id' => $transferId, 'is_archived' => $archived,
            ]);
        }
        $data = new App\Service\AppDataService($pdo, $context);
        $breakdown = $data->categoryBreakdown(null, $today, $today, null, 'Breakdown regression');
        assertSameValue(['income' => 130.0, 'expense' => 110.0, 'transfer' => 50.0], $breakdown['by_type'], 'Untyped entries or opposite amounts were lost');
        $account = $data->categoryBreakdown($accountId, $today, $today, null, 'Breakdown regression');
        assertSameValue(25.0, $account['by_type']['transfer'], 'Account filter was ignored');
        $category = $data->categoryBreakdown(null, $today, $today, $expense, 'Breakdown regression');
        assertSameValue(['income' => 0.0, 'expense' => 40.0, 'transfer' => 25.0], $category['by_type'], 'Category filter or transfer priority was ignored');
        $archived = $data->categoryBreakdown(null, $today, $today, null, 'Breakdown regression', true);
        assertSameValue(121.0, $archived['by_type']['expense'], 'Archived flag was ignored');
        $empty = $data->categoryBreakdown(null, '1900-01-01', '1900-01-02', null, 'Breakdown regression');
        assertSameValue(['income' => 0.0, 'expense' => 0.0, 'transfer' => 0.0], $empty['by_type'], 'Date filter was ignored');
    } finally {
        $pdo->rollBack();
    }
});

integrationCheck('transfer edits, rollback, archiving and deletion keep both entries together', static function () use ($pdo, $accounts, $transactions, $context, $categories, $companies, $transactionService, $companyId, $accountId, $secondAccountId, $today): void {
    $recurring = new App\Service\RecurringService(new App\Repository\RecurringRuleRepository($pdo), $accounts, $transactions, $context, $pdo, $categories, $companies, null, new App\Repository\TransferRepository($pdo));
    $recurring->create(['account_id' => $accountId, 'destination_account_id' => $secondAccountId, 'type' => 'transfer', 'label' => 'Transfer', 'amount' => '25.00', 'recurrence' => 'monthly', 'interval_value' => 1, 'next_execution_date' => $today, 'end_date' => $today, 'is_active' => 1]);
    $result = $recurring->processDue($today, $companyId);
    assertSameValue([], $result['errors'], 'Recurring transfer failed');
    assertSameValue(2, $result['transactions_created'], 'Transfer entries were not created');
    $pair = $pdo->query('SELECT * FROM transactions WHERE transfer_id IS NOT NULL ORDER BY amount')->fetchAll();
    $source = (int) $pair[0]['id'];
    $destination = (int) $pair[1]['id'];
    $transferId = (int) $pair[0]['transfer_id'];
    $payload = ['account_id' => $accountId, 'transaction_date' => $today, 'label' => 'Edited transfer', 'amount' => '-40.00'];
    $transactionService->update($source, $payload);
    assertSameValue(['-40.00', '40.00'], $pdo->query('SELECT amount FROM transactions WHERE transfer_id = ' . $transferId . ' ORDER BY amount')->fetchAll(PDO::FETCH_COLUMN), 'Counterpart amount differs');
    assertSameValue('40.00', $pdo->query('SELECT amount FROM transfers WHERE id = ' . $transferId)->fetchColumn(), 'Transfer metadata differs');
    $transactionService->update($destination, array_replace($payload, ['account_id' => $secondAccountId, 'amount' => '50.00']));
    assertSameValue(['-50.00', '50.00'], $pdo->query('SELECT amount FROM transactions WHERE transfer_id = ' . $transferId . ' ORDER BY amount')->fetchAll(PDO::FETCH_COLUMN), 'Editing the destination broke the pair');
    expectRejected(static fn () => $transactionService->update($source, array_replace($payload, ['amount' => '40.00'])));
    expectRejected(static fn () => $transactionService->update($source, array_replace($payload, ['account_id' => $secondAccountId])));

    $pdo->exec('CREATE TRIGGER fail_transfer_update BEFORE UPDATE ON transactions FOR EACH ROW BEGIN
        IF NEW.id = ' . $source . ' AND NEW.amount = -99 THEN SIGNAL SQLSTATE "45000" SET MESSAGE_TEXT = "test rollback"; END IF; END');
    $auditCount = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
    try {
        $transactionService->update($source, array_replace($payload, ['amount' => '-99.00']));
        throw new RuntimeException('Expected database failure was not raised.');
    } catch (PDOException $exception) {
        if (!str_contains($exception->getMessage(), 'test rollback')) {
            throw $exception;
        }
    } finally {
        $pdo->exec('DROP TRIGGER fail_transfer_update');
    }
    assertSameValue(['-50.00', '50.00'], $pdo->query('SELECT amount FROM transactions WHERE transfer_id = ' . $transferId . ' ORDER BY amount')->fetchAll(PDO::FETCH_COLUMN), 'Partial transfer update was committed');
    assertSameValue('50.00', $pdo->query('SELECT amount FROM transfers WHERE id = ' . $transferId)->fetchColumn(), 'Metadata rollback failed');
    assertSameValue($auditCount, (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn(), 'Rolled back update left audit records');
    $context->select(1); // The other company created by the installer.
    expectRejected(static fn () => $transactionService->update($source, array_replace($payload, ['account_id' => $accountId])));
    expectRejected(static fn () => $transactionService->archive($source));
    expectRejected(static fn () => $transactionService->delete($source));
    $context->select($companyId);
    $transactionService->archive($source);
    assertSameValue(2, (int) $pdo->query('SELECT COUNT(*) FROM transactions WHERE transfer_id = ' . $transferId . ' AND is_archived = 1')->fetchColumn(), 'Only one entry was archived');
    $transactionService->archive($destination, false);
    assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM transactions WHERE transfer_id = ' . $transferId . ' AND is_archived = 1')->fetchColumn(), 'Only one entry was restored');
    $transactionService->delete($destination);
    assertSameValue(2, (int) $pdo->query('SELECT COUNT(*) FROM audit_logs WHERE action="transaction.delete" AND entity_id IN ("' . $source . '","' . $destination . '")')->fetchColumn(), 'Deletion did not audit both entries');
    assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM transactions WHERE transfer_id = ' . $transferId)->fetchColumn(), 'Counterpart survived deletion');
    assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM transfers WHERE id = ' . $transferId)->fetchColumn(), 'Transfer metadata survived deletion');
});

integrationCheck('export/import preserves colliding legacy IDs, references, emojis and company-scoped deduplication', static function () use ($pdo, $accounts, $categories, $transactions, $types, $companies, $companyId, $accountId, $today): void {
    $importedAccount = $accounts->create(['company_id' => $companyId, 'user_id' => null, 'name' => 'Imported account', 'bank_name' => '', 'account_number' => '', 'description' => '', 'currency' => 'EUR', 'initial_balance' => '7.00', 'legacy_source' => 'legacy.csv', 'legacy_id' => (string) $accountId]);
    $parent = $categories->create(['company_id' => $companyId, 'name' => 'Parent', 'category_type' => 'expense', 'emoji' => '🍎', 'is_active' => 1]);
    $child = $categories->create(['company_id' => $companyId, 'name' => 'Child', 'category_type' => 'expense', 'emoji' => '🥕', 'parent_id' => $parent, 'is_active' => 1, 'legacy_source' => 'legacy.csv', 'legacy_id' => (string) $parent]);
    $transactions->create(['company_id' => $companyId, 'account_id' => $importedAccount, 'category_id' => $child, 'transaction_date' => $today, 'value_date' => '', 'label' => 'Imported reference', 'description' => '', 'amount' => '-3.00', 'legacy_source' => 'legacy.csv', 'legacy_table' => 'ECRITURE', 'legacy_id' => '1']);
    $transferId = (new App\Repository\TransferRepository($pdo))->create([
        'company_id' => $companyId, 'source_account_id' => $accountId, 'destination_account_id' => $importedAccount,
        'amount' => '4.00', 'transaction_date' => $today, 'label' => 'Portable transfer',
    ]);
    foreach ([[$accountId, '-4.00'], [$importedAccount, '4.00']] as [$id, $amount]) {
        $transactions->create(['company_id' => $companyId, 'account_id' => $id, 'transaction_date' => $today,
            'value_date' => '', 'label' => 'Portable transfer', 'description' => '', 'amount' => $amount, 'transfer_id' => $transferId]);
    }
    $storage = sys_get_temp_dir() . '/accountplus-integration-' . bin2hex(random_bytes(4));
    $export = (new App\Service\ExportService($pdo, $companies, $accounts, $categories, $transactions, $types, $storage))->exportCompany($companyId);
    $importer = new App\Service\ImportService($pdo, new App\Repository\ImportRepository($pdo), $accounts, $categories, $transactions, $types, $companies, $storage);
    $inspection = $importer->inspect($export['path']);
    $b = $companies->create('B integration', 'TEST', 'Active');
    $c = $companies->create('C integration', 'TEST', 'Active');
    foreach ([$b, $c] as $target) {
        $result = $importer->run($export['path'], $target);
        assertSameValue('completed', $result['status'], 'Export could not be restored');
        assertSameValue($inspection['counts']['accounts'], $result['accounts_created'], 'Accounts disappeared during restore');
        assertSameValue($inspection['counts']['transactions'], $result['transactions_created'], 'Transactions disappeared during restore');
        $row = $pdo->query('SELECT a.name AS account_name, c.name AS category_name, c.emoji, p.name AS parent_name FROM transactions t
            JOIN accounts a ON a.id=t.account_id JOIN categories c ON c.id=t.category_id JOIN categories p ON p.id=c.parent_id
            WHERE t.company_id=' . $target . ' AND t.label="Imported reference"')->fetch();
        assertSameValue(['account_name' => 'Imported account', 'category_name' => 'Child', 'emoji' => '🥕', 'parent_name' => 'Parent'], $row, 'References were restored incorrectly');
        assertSameValue(2, (int) $pdo->query('SELECT COUNT(*) FROM transactions WHERE company_id=' . $target . ' AND transfer_id IS NOT NULL')->fetchColumn(), 'Transfer links disappeared during restore');
    }
    assertSameValue(true, $importer->run($export['path'], $b)['skipped'], 'Duplicate in same company was imported twice');
    $badEntries = App\Support\Archive::read($export['path']);
    $records = array_map(static fn (string $line) => json_decode($line, true), explode("\n", $badEntries['transactions.ndjson']));
    $removed = false;
    $records = array_filter($records, static function (array $record) use (&$removed): bool {
        if (!$removed && !empty($record['transfer_legacy_id'])) {
            $removed = true;
            return false;
        }
        return true;
    });
    $badEntries['transactions.ndjson'] = implode("\n", array_map(static fn (array $record) => json_encode($record), $records));
    $badPath = $storage . '/incomplete.zip';
    App\Support\Archive::write($badEntries, $badPath);
    $d = $companies->create('D integration', 'TEST', 'Active');
    expectRejected(static fn () => $importer->run($badPath, $d));
    assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM accounts WHERE company_id=' . $d)->fetchColumn(), 'Incomplete transfer left a partial import');
    unlink($badPath);
    unlink($export['path']);
    rmdir(dirname($export['path']));
    rmdir($storage);
});

integrationCheck('pair throttling preserves other origins and shared network failures', static function () use ($pdo, $basePath): void {
    $limiter = new App\Service\LoginRateLimiter($pdo);
    for ($i = 0; $i < 5; $i++) {
        assertSameValue(0, $limiter->attempt('target', '192.0.2.1', static fn () => false)['retry_after'], 'Attempt was throttled too early');
    }
    assertSameValue(true, $limiter->attempt('TARGET', '192.0.2.1', static fn () => false)['retry_after'] > 0, 'Pair limit bypassed');
    assertSameValue(true, $limiter->attempt('target', '192.0.2.2', static fn () => true)['authenticated'], 'Legitimate other origin was locked');
    for ($i = 0; $i < 30; $i++) {
        $limiter->attempt('rotating-' . $i, '192.0.2.3', static fn () => false);
    }
    assertSameValue(true, $limiter->attempt('new', '192.0.2.3', static fn () => true)['retry_after'] > 0, 'Network budget bypassed');
    $limiter->recordFailure('target', '192.0.2.4');
    $limiter->recordFailure('other', '192.0.2.4');
    $limiter->attempt('target', '192.0.2.4', static fn () => true);
    $networkKey = hash('sha256', 'network:' . bin2hex(inet_pton('192.0.2.4')));
    assertSameValue(2, (int) $pdo->query("SELECT failures FROM login_attempts WHERE attempt_key = '$networkKey'")->fetchColumn(), 'Success erased network failures');
    $pairKey = hash('sha256', 'pair:other:' . bin2hex(inet_pton('192.0.2.4')));
    assertSameValue(1, (int) $pdo->query("SELECT failures FROM login_attempts WHERE attempt_key = '$pairKey'")->fetchColumn(), 'Success erased another user failures');
    $pdo->exec('UPDATE login_attempts SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND)');
    assertSameValue(0, $limiter->attempt('target', '192.0.2.1', static fn () => false)['retry_after'], 'Expired counters did not reset');
});

integrationCheck('concurrent login attempts share the same failure budget', static function () use ($basePath): void {
    $workers = [];
    for ($i = 0; $i < 8; $i++) {
        $process = proc_open([PHP_BINARY, $basePath . '/tests/Integration/login_worker.php'], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start login worker.');
        }
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $allowed = 0;
    $errors = [];
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            $errors[] = $error . $output;
            continue;
        }
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $allowed += $result['retry_after'] === 0 ? 1 : 0;
    }
    assertSameValue([], $errors, 'Concurrent worker failed');
    assertSameValue(5, $allowed, 'Parallel requests bypassed the failure budget');
});

require __DIR__ . '/security.php';

fwrite(STDOUT, 'All integration checks passed.' . PHP_EOL);
