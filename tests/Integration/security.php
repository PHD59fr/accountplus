<?php

declare(strict_types=1);

integrationCheck('schema checks do not create tables and invalid migrations fail', static function () use ($pdo, $basePath): void {
    $migrator = new App\Support\Migrator($pdo, $basePath . '/app/database/migrations');
    $migrator->assertCurrent();
    $count = $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
    $migrator->migrate();
    assertSameValue($count, $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn(), 'Migration was applied twice');
    $directory = sys_get_temp_dir() . '/bad-migration-' . bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory . '/999_invalid.php', '<?php return ["up" => ["THIS IS INVALID SQL"]];');
    try {
        try {
            (new App\Support\Migrator($pdo, $directory))->migrate();
            throw new LogicException('Invalid migration accepted');
        } catch (PDOException) {
            assertSameValue($count, $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn(), 'Failed migration entered history');
        }
    } finally {
        unlink($directory . '/999_invalid.php');
        rmdir($directory);
    }
});

integrationCheck('company assignments constrain reads mutations transfers and archive routes', static function () use ($pdo, $companies, $accounts, $categories, $transactions, $types, $basePath, $today): void {
    $users = new App\Repository\UserRepository($pdo);
    $assignments = new App\Repository\UserCompanyRepository($pdo);
    $ids = [];
    foreach (['A', 'B'] as $name) {
        $company = $companies->create('Security company ' . $name, 'SECURITY', 'Active');
        $user = $users->createUser('security-' . $name, password_hash('test-only-user-password', PASSWORD_DEFAULT), $name);
        $assignments->assign($user, $company);
        $context = new App\Service\CompanyContext($companies);
        $context->select($company);
        $account = (new App\Service\AccountService($accounts, $context))->create(['name' => 'Private account ' . $name]);
        $tx = (new App\Service\TransactionService($transactions, $accounts, $context))->create([
            'account_id' => $account, 'label' => 'Private ' . $name, 'amount' => '12.00', 'transaction_date' => $today,
        ]);
        $rules = new App\Service\RecurringService(new App\Repository\RecurringRuleRepository($pdo), $accounts, $transactions, $context, $pdo, $categories, $companies);
        $rule = $rules->create(['account_id' => $account, 'label' => 'Private recurring ' . $name,
            'amount' => '1.00', 'type' => 'expense', 'recurrence' => 'monthly', 'next_execution_date' => $today]);
        $ids[$name] = compact('company', 'user', 'account', 'tx', 'rule');
    }
    foreach (['A' => 'B', 'B' => 'A'] as $own => $other) {
        $_SESSION = ['php_compte_user' => ['user_id' => $ids[$own]['user']]];
        $auth = new App\Service\AuthService($users);
        assertSameValue(true, $auth->isAuthenticated(), 'Test user unavailable');
        $context = new App\Service\CompanyContext($companies, $assignments, $auth);
        assertSameValue(false, $context->select($ids[$other]['company']), 'Selected unauthorized company');
        assertSameValue($ids[$own]['company'], $context->current()->id(), 'Unauthorized workspace');
        $accountService = new App\Service\AccountService($accounts, $context);
        $txService = new App\Service\TransactionService($transactions, $accounts, $context);
        $rules = new App\Service\RecurringService(new App\Repository\RecurringRuleRepository($pdo), $accounts, $transactions, $context, $pdo, $categories, $companies);
        foreach ([$ids[$other]['account'], 0, -1, PHP_INT_MAX] as $id) {
            assertSameValue(null, $accountService->findForCurrentCompany($id), 'Cross-company account read');
            expectRejected(static fn () => $accountService->update($id, ['name' => 'hijacked']));
            expectRejected(static fn () => $accountService->archive($id));
            expectRejected(static fn () => $accountService->delete($id));
        }
        assertSameValue(null, $txService->findForCurrentCompany($ids[$other]['tx']), 'Cross-company transaction read');
        expectRejected(static fn () => $txService->archive($ids[$other]['tx']));
        expectRejected(static fn () => $txService->delete($ids[$other]['tx']));
        expectRejected(static fn () => $txService->create(['account_id' => $ids[$other]['account'], 'label' => 'bad', 'amount' => '1.00', 'transaction_date' => $today]));
        assertSameValue(null, $rules->findForCurrentCompany($ids[$other]['rule']), 'Cross-company recurrence read');
        expectRejected(static fn () => $rules->delete($ids[$other]['rule']));
        $view = new App\Support\View($basePath . '/app/templates');
        $transfer = new App\Controller\TransferController($view, new App\Support\Config([]), $context, $auth);
        $status = new ReflectionProperty(App\Http\Response::class, 'status');
        foreach (['index', 'import', 'export', 'download', 'inspect'] as $method) {
            $request = new App\Http\Request('POST', '/admin/data', [], ['company_id' => $ids[$other]['company'], 'file' => '../probe.compte.zip'], [], []);
            assertSameValue(403, $status->getValue($transfer->$method($request)), 'Ordinary user accessed transfer route');
        }
        $users->updateActive($ids[$own]['user'], false);
        assertSameValue(false, (new App\Service\AuthService($users))->isAuthenticated(), 'Disabled user retained access');
        $users->updateActive($ids[$own]['user'], true);
        $_SESSION = ['php_compte_user' => ['user_id' => $ids[$own]['user']]];
        $users->updateRole($ids[$own]['user'], 'ADMIN');
        assertSameValue(true, (new App\Service\AuthService($users))->isAdmin(), 'Granted role not refreshed');
        $users->updateRole($ids[$own]['user'], 'USER');
        assertSameValue(false, (new App\Service\AuthService($users))->isAdmin(), 'Revoked role retained access');
    }
    $_SESSION = [];
});

integrationCheck('archive downloads preserve legacy Unicode names and reject symlinks and paths', static function () use ($pdo, $basePath, $companies, $accounts, $categories, $transactions, $types): void {
    $directory = sys_get_temp_dir() . '/download-security-' . bin2hex(random_bytes(4));
    mkdir($directory . '/exports', 0700, true);
    $originalSession = $_SESSION;
    try {
        $company = $companies->create('🧾', 'SECURITY', 'Active');
        $export = (new App\Service\ExportService($pdo, $companies, $accounts, $categories, $transactions, $types, $directory))->exportCompany($company);
        assertSameValue(true, str_starts_with($export['filename'], 'company-'), 'Non-ASCII company produced an invalid basename');
        $legacy = $directory . '/exports/-legacy.compte.zip';
        copy($export['path'], $legacy);
        file_put_contents($directory . '/private.txt', 'synthetic private marker');
        symlink($directory . '/private.txt', $directory . '/exports/symlink.compte.zip');
        $users = new App\Repository\UserRepository($pdo);
        $_SESSION = ['php_compte_user' => ['user_id' => $users->findByUsername('admin')->id()]];
        $auth = new App\Service\AuthService($users);
        $controller = new App\Controller\TransferController(new App\Support\View($basePath . '/app/templates'), new App\Support\Config([]), new App\Service\CompanyContext($companies), $auth, null, null, null, $companies, $directory);
        $status = new ReflectionProperty(App\Http\Response::class, 'status');
        $request = static fn ($file) => new App\Http\Request('GET', '/admin/data/download', ['file' => $file], [], [], []);
        assertSameValue(200, $status->getValue($controller->download($request(basename($legacy)))), 'Existing Unicode-company archive download broke');
        foreach (['symlink.compte.zip', '../private.txt', '/etc/passwd', ['bad'], '.hidden.compte.zip'] as $file) {
            assertSameValue(404, $status->getValue($controller->download($request($file))), 'Unsafe download identifier accepted');
        }
    } finally {
        $_SESSION = $originalSession;
        foreach (glob($directory . '/exports/*') as $file) { unlink($file); }
        unlink($directory . '/private.txt');
        rmdir($directory . '/exports');
        rmdir($directory);
    }
});
