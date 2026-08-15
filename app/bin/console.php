<?php

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);

require_once $basePath . '/app/src/Support/helpers.php';
require_once $basePath . '/app/src/Support/Autoloader.php';

\App\Support\Autoloader::register($basePath . '/app');

use App\Repository\AccountRepository;
use App\Repository\CategoryRepository;
use App\Repository\CompanyRepository;
use App\Repository\ImportRepository;
use App\Repository\RecurringRuleRepository;
use App\Repository\TransactionRepository;
use App\Repository\TransferRepository;
use App\Service\CompanyContext;
use App\Service\ExportService;
use App\Service\ImportService;
use App\Service\InstallerService;
use App\Service\InstallationState;
use App\Service\RecurringService;
use App\Support\Config;
use App\Support\Database;
use App\Support\DatabaseConfig;

function write_out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function write_err(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
}

$config = Config::fromBasePath($basePath);
$installationState = new InstallationState($basePath);
$databaseConfig = DatabaseConfig::resolve($config, $installationState);

$command = $argv[1] ?? 'help';

if ($command === 'help') {
    write_out('Account+ console');
    write_out('- help');
    write_out('- app:name');
    write_out('- db:migrate');
    write_out('- recurring:process [reference-date] [--company=<id>]');
    write_out('- import:from-csv <csv-directory-or-zip> --output=<path>');
    write_out('- import:inspect <archive.zip>');
    write_out('- import:run <archive.zip> --company=<id> [--force]');
    write_out('- export:run [--company=<id>]');
    exit(0);
}

if ($command === 'app:name') {
    write_out((string) $config->get('app.name', 'Account+'));
    exit(0);
}

function arg_value(array $argv, string $name): ?string
{
    foreach (array_slice($argv, 2) as $argument) {
        if (str_starts_with($argument, '--' . $name . '=')) {
            return substr($argument, strlen('--' . $name . '='));
        }
    }

    return null;
}

function arg_flag(array $argv, string $name): bool
{
    foreach (array_slice($argv, 2) as $argument) {
        if ($argument === '--' . $name || str_starts_with($argument, '--' . $name . '=')) {
            return true;
        }
    }

    return false;
}

if ($command === 'import:from-csv') {
    $csvDirectory = $argv[2] ?? null;
    $outputPath = arg_value($argv, 'output');
    if ($csvDirectory === null || $outputPath === null) {
        write_err('Usage: import:from-csv <csv-directory-or-zip> --output=<path>');
        exit(1);
    }

    $converter = new \App\Service\LegacyCsvConverter();
    try {
        $result = $converter->convert($csvDirectory, $outputPath);
    } catch (Throwable $throwable) {
        write_err('import:from-csv failed: ' . $throwable->getMessage());
        exit(1);
    }

    write_out('Source: ' . $result['source']);
    write_out('Output: ' . $result['path']);
    write_out('Accounts: ' . $result['counts']['accounts']);
    write_out('Categories: ' . $result['counts']['categories']);
    write_out('Transactions: ' . $result['counts']['transactions']);
    foreach ($result['warnings'] as $warning) {
        write_out('  warning: ' . $warning);
    }
    write_out('Run import:run with --company=<id> to import this archive.');
    exit(0);
}

if ($command === 'import:inspect') {
    $importService = new ImportService(null, null, null, null, null, null, null, $basePath . '/app/storage');
    $archivePath = $argv[2] ?? null;
    if ($archivePath === null) {
        write_err('Usage: import:inspect <archive.zip>');
        exit(1);
    }

    try {
        $summary = $importService->inspect($archivePath);
    } catch (Throwable $throwable) {
        write_err('import:inspect failed: ' . $throwable->getMessage());
        exit(1);
    }

    $manifest = $summary['manifest'];
    write_out('Archive: ' . $summary['path']);
    write_out('SHA256: ' . $summary['sha256']);
    write_out('Format: ' . (string) ($manifest['format'] ?? '?') . ' v' . (string) ($manifest['version'] ?? '?'));
    write_out('Converter: ' . (string) ($manifest['converter']['name'] ?? '?') . ' ' . (string) ($manifest['converter']['version'] ?? ''));
    write_out('Source: ' . (string) ($manifest['source']['filename'] ?? '?'));
    write_out('Accounts: ' . $summary['counts']['accounts']);
    write_out('Categories: ' . $summary['counts']['categories']);
    write_out('Transactions: ' . $summary['counts']['transactions']);
    if ($summary['warnings'] !== []) {
        write_out('Warnings:');
        foreach ($summary['warnings'] as $warning) {
            write_out('  - ' . $warning);
        }
    }
    exit(0);
}

if ($command === 'db:migrate') {
    $username = getenv('DB_MIGRATION_USERNAME');
    if (!is_string($username) || $username === '') {
        write_err('DB_MIGRATION_USERNAME and DB_MIGRATION_PASSWORD are required.');
        exit(1);
    }
    $databaseConfig['username'] = $username;
    $databaseConfig['password'] = \App\Support\Secret::read('DB_MIGRATION_PASSWORD', true);
}

try {
    $pdo = Database::connect($databaseConfig);
    $installer = new InstallerService($pdo, $basePath . '/app/database/migrations');
    if ($command === 'db:migrate') {
        $installer->migrate();
        write_out('Database migrations completed.');
        exit(0);
    }
    (new \App\Support\Migrator($pdo, $basePath . '/app/database/migrations'))->assertCurrent();
} catch (Throwable $throwable) {
    write_err('Database unavailable or schema upgrade required. Reference: ' . \App\Support\SecurityError::report($throwable, 'cli.database'));
    exit(1);
}

$companyRepository = new CompanyRepository($pdo);
$accountRepository = new AccountRepository($pdo);
$categoryRepository = new CategoryRepository($pdo);
$transactionRepository = new TransactionRepository($pdo);
$transactionTypeRepository = new \App\Repository\TransactionTypeRepository($pdo);
$recurringRuleRepository = new RecurringRuleRepository($pdo);
$importRepository = new ImportRepository($pdo);
$transferRepository = new TransferRepository($pdo);
$companyContext = new CompanyContext($companyRepository, null, null);

$recurringService = new RecurringService($recurringRuleRepository, $accountRepository, $transactionRepository, $companyContext, $pdo, $categoryRepository, $companyRepository, null, $transferRepository);
$importService = new ImportService($pdo, $importRepository, $accountRepository, $categoryRepository, $transactionRepository, $transactionTypeRepository, $companyRepository, $basePath . '/app/storage');
$exportService = new ExportService($pdo, $companyRepository, $accountRepository, $categoryRepository, $transactionRepository, $transactionTypeRepository, $basePath . '/app/storage');

if ($command === 'recurring:process') {
    $referenceDate = $argv[2] ?? null;
    if ($referenceDate !== null && str_starts_with($referenceDate, '--')) {
        $referenceDate = null;
    }

    try {
        $result = $recurringService->processDue($referenceDate, arg_value($argv, 'company') !== null ? (int) arg_value($argv, 'company') : null);
    } catch (Throwable $throwable) {
        write_err('recurring:process failed: ' . $throwable->getMessage());
        exit(1);
    }

    write_out('Reference date: ' . $result['reference_date']);
    write_out('Rules processed: ' . $result['rules_processed']);
    write_out('Transactions created: ' . $result['transactions_created']);
    foreach ($result['errors'] as $error) {
        write_err('Company ' . $error['company_id'] . ', rule ' . ($error['rule_id'] ?? '?') . ': ' . $error['message']);
    }
    exit($result['errors'] === [] ? 0 : 1);
}

if ($command === 'import:run') {
    $archivePath = $argv[2] ?? null;
    $companyId = arg_value($argv, 'company');
    $force = arg_flag($argv, 'force');
    if ($archivePath === null || $companyId === null) {
        write_err('Usage: import:run <archive.zip> --company=<id> [--force]');
        exit(1);
    }

    try {
        $result = $importService->run($archivePath, (int) $companyId, null, $force);
    } catch (Throwable $throwable) {
        write_err('import:run failed: ' . $throwable->getMessage());
        exit(1);
    }

    if (!empty($result['skipped'])) {
        write_out('Skipped: ' . $result['message']);
        exit(0);
    }

    write_out('Import #' . $result['import_id'] . ': ' . $result['status']);
    write_out('Accounts created: ' . $result['accounts_created']);
    write_out('Categories created: ' . $result['categories_created']);
    write_out('Transactions created: ' . $result['transactions_created']);
    foreach ($result['warnings'] as $warning) {
        write_out('  warning: ' . $warning);
    }
    foreach ($result['errors'] as $error) {
        write_err('  error: ' . $error);
    }
    exit(0);
}

if ($command === 'export:run') {
    $companyId = arg_value($argv, 'company');
    if ($companyId !== null) {
        $company = $companyRepository->find((int) $companyId);
    } else {
        $company = $companyRepository->first();
    }
    if ($company === null) {
        write_err('No company available to export. Use --company=<id>.');
        exit(1);
    }

    try {
        $export = $exportService->exportCompany($company->id());
    } catch (Throwable $throwable) {
        write_err('export:run failed: ' . $throwable->getMessage());
        exit(1);
    }

    write_out('Exported company ' . $company->name() . ' to:');
    write_out($export['path']);
    exit(0);
}

write_err("Unknown command: {$command}");
exit(1);
