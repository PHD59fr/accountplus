<?php

declare(strict_types=1);

namespace App\Application;

use App\Controller\AccountController;
use App\Controller\AdminController;
use App\Controller\AuthController;
use App\Controller\CategoryController;
use App\Controller\CompanyController;
use App\Controller\DashboardController;
use App\Controller\LocaleController;
use App\Controller\RecurringController;
use App\Controller\ReportController;
use App\Controller\SectionController;
use App\Controller\SetupController;
use App\Controller\TransactionController;
use App\Controller\TransferController;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Service\AuthService;
use App\Service\AppDataService;
use App\Service\ExportService;
use App\Service\ImportService;
use App\Service\InstallerService;
use App\Service\InstallationState;
use App\Service\IpRestrictionService;
use App\Service\LocaleService;
use App\Service\LoginRateLimiter;
use App\Service\RecurringService;
use App\Service\TransactionService;
use App\Support\Config;
use App\Support\Database;
use App\Support\DatabaseConfig;
use App\Support\View;
use App\Repository\CategoryRepository;
use App\Repository\ImportRepository;
use App\Repository\RecurringRuleRepository;
use App\Repository\TransferRepository;
use App\Repository\UserRepository;
use App\Repository\AccountRepository;
use App\Repository\AuditLogRepository;
use App\Repository\CompanyRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserCompanyRepository;
use App\Repository\SettingsRepository;
use App\Repository\TransactionTypeRepository;
use App\Service\AccountService;
use App\Service\AdminService;
use App\Service\AuditLogService;
use App\Service\CompanyContext;
use App\Service\DashboardSettings;
use App\Service\CompanyService;
use App\Support\DateFormatter;
use Throwable;

final class AppKernel
{
    public function __construct(
        private Config $config,
        private View $view
    ) {
    }

    public function handle(Request $request): Response
    {
        $router = new Router();
        if (!(new IpRestrictionService(dirname(__DIR__, 3)))->allows($request->clientIp())) {
            return Response::html(e(security_message('security.forbidden')), 403);
        }
        // Validate authorization before even attempting the bootstrap database connection.
        $state = new InstallationState(dirname(__DIR__, 3));
        if (!$state->isInstalled() && $state->wasInitialized()) {
            return Response::html(e(security_message('security.recovery')), 503);
        }
        if (!$state->isInstalled() && (!\App\Support\SetupAuthorization::enabled()
            || ($request->method() === 'POST' && $request->path() === '/setup'
                && !\App\Support\SetupAuthorization::accepts($request->post('setup_token'))))) {
            return Response::html(e(security_message('setup.denied')), 403);
        }
        if ($request->method() === 'POST' && !csrf_validate($request->post('_csrf_token'))) {
            return Response::html(e(security_message('security.expired')), 419);
        }
        if (!$state->isInstalled()) {
            $locale = new LocaleService(dirname(__DIR__, 3), $this->config);
            $translator = $locale->resolve();
            $this->view->share([
                'translator' => $translator,
                't' => static fn (string $key, array $values = []): string => $translator->get($key, $values),
            ]);
            $setup = new SetupController($this->view, $this->config, $state);
            if ($request->path() !== '/setup') {
                return Response::redirect('/setup');
            }
            return $request->method() === 'POST' ? $setup->install($request) : $setup->show($request);
        }
        $basePath = dirname(__DIR__, 3);
        $installationState = new InstallationState($basePath);
        $databaseConfig = DatabaseConfig::resolve($this->config, $installationState);
        $localeService = null;
        $localeController = null;

        $pdo = null;
        $installerService = null;
        $authService = null;
        $bootstrapError = null;
        $companyRepository = null;
        $accountRepository = null;
        $transactionRepository = null;
        $auditLogRepository = null;
        $userRepository = null;
        $userCompanyRepository = null;
        $categoryRepository = null;
        $recurringRuleRepository = null;
        $importRepository = null;
        $transferRepository = null;
        $settingsRepository = null;

        try {
            $pdo = Database::connect($databaseConfig);
            $installerService = new InstallerService($pdo, $basePath . '/app/database/migrations');
            (new \App\Support\Migrator($pdo, $basePath . '/app/database/migrations'))->assertCurrent();
            $userRepository = new UserRepository($pdo);
            $authService = new AuthService($userRepository);
            $localeService = new LocaleService($basePath, $this->config, $userRepository, $authService);
            $companyRepository = new CompanyRepository($pdo);
            $accountRepository = new AccountRepository($pdo);
            $transactionRepository = new TransactionRepository($pdo);
            $transactionTypeRepository = new TransactionTypeRepository($pdo);
            $auditLogRepository = new AuditLogRepository($pdo);
            $userCompanyRepository = new UserCompanyRepository($pdo);
            $categoryRepository = new CategoryRepository($pdo);
            $recurringRuleRepository = new RecurringRuleRepository($pdo);
            $importRepository = new ImportRepository($pdo);
            $transferRepository = new TransferRepository($pdo);
            $settingsRepository = new SettingsRepository($pdo);
        } catch (Throwable $throwable) {
            $pdo = null;
            $bootstrapError = \App\Support\SecurityError::report($throwable, 'bootstrap.failure');
        }

        if ($localeService === null) {
            $localeService = new LocaleService($basePath, $this->config);
        }

        $translator = $localeService->resolve();
        $this->view->share([
            'translator' => $translator,
            't' => static function (string $key, array $replace = []) use ($translator): string {
                return $translator->get($key, $replace);
            },
            'currentLocale' => $translator->locale(),
            'supportedLocales' => $translator->supportedLocales(),
            'canManageAdministration' => $authService !== null && $authService->isAdmin(),
        ]);

        $dateFormat = $settingsRepository !== null
            ? $settingsRepository->get('date_format', DateFormatter::DEFAULT_FORMAT)
            : DateFormatter::DEFAULT_FORMAT;
        $this->view->share([
            'formatDate' => static fn (?string $value): string => DateFormatter::format($value, $dateFormat),
        ]);
        $localeController = new LocaleController($localeService);

        $companyContext = new CompanyContext($companyRepository, $userCompanyRepository, $authService);
        $this->view->share([
            'canManageData' => $authService !== null && $authService->isAuthenticated() && $companyContext->current() !== null,
            'availableCompanies' => $companyContext->availableCompanies(),
        ]);
        $auditLogService = ($auditLogRepository !== null)
            ? new AuditLogService($auditLogRepository, $authService, $companyContext)
            : null;
        $dataService = new AppDataService($pdo, $companyContext);
        $accountService = $accountRepository !== null ? new AccountService($accountRepository, $companyContext, $companyRepository, $authService, $auditLogService) : null;
        $transactionService = ($transactionRepository !== null && $accountRepository !== null)
            ? new TransactionService($transactionRepository, $accountRepository, $companyContext, $companyRepository, $auditLogService, $categoryRepository)
            : null;
        $recurringService = ($recurringRuleRepository !== null && $accountRepository !== null && $transactionRepository !== null)
            ? new RecurringService($recurringRuleRepository, $accountRepository, $transactionRepository, $companyContext, $pdo, $categoryRepository, $companyRepository, $auditLogService, $transferRepository)
            : null;
        $importService = ($pdo !== null && $importRepository !== null && $accountRepository !== null && $categoryRepository !== null && $transactionRepository !== null && $companyRepository !== null)
            ? new ImportService($pdo, $importRepository, $accountRepository, $categoryRepository, $transactionRepository, $transactionTypeRepository, $companyRepository, $basePath . '/app/storage')
            : null;
        $exportService = ($pdo !== null && $companyRepository !== null && $accountRepository !== null && $categoryRepository !== null && $transactionRepository !== null && $transactionTypeRepository !== null)
            ? new ExportService($pdo, $companyRepository, $accountRepository, $categoryRepository, $transactionRepository, $transactionTypeRepository, $basePath . '/app/storage')
            : null;
        $companyService = ($companyRepository !== null && $userCompanyRepository !== null && $authService !== null)
            ? new CompanyService($companyRepository, $userCompanyRepository, $companyContext, $authService, $auditLogService, $userRepository)
            : null;
        $adminService = ($userRepository !== null && $userCompanyRepository !== null)
            ? new AdminService($userRepository, $userCompanyRepository, $auditLogService, $authService, $companyRepository, $settingsRepository)
            : null;

        $dashboardSettings = new DashboardSettings($settingsRepository, $auditLogService);
        $dashboard = new DashboardController($this->view, $this->config, $dataService, $companyContext, $dashboardSettings);
        $auth = $authService !== null
            ? new AuthController($this->view, $this->config, $authService, new LoginRateLimiter($pdo), $auditLogService)
            : null;
        $companies = ($companyService !== null && $companyRepository !== null && $authService !== null)
            ? new CompanyController($this->view, $this->config, $companyContext, $companyRepository, $companyService, $authService, $auditLogService)
            : null;
        $accounts = new AccountController($this->view, $this->config, $dataService, $companyContext, $authService, $accountService);
        $transactions = ($accountRepository !== null && $authService !== null)
            ? new TransactionController($this->view, $this->config, $companyContext, $accountRepository, $authService, $transactionService, $categoryRepository, $auditLogRepository)
            : null;
        $sections = new SectionController($this->view, $this->config, $dataService, $authService, $companyContext);
        $reports = ($authService !== null && $dataService !== null)
            ? new ReportController($this->view, $this->config, $dataService, $companyContext, $authService, $basePath . '/app/storage')
            : null;
        $admin = ($authService !== null && $companyRepository !== null && $userRepository !== null && $userCompanyRepository !== null && $adminService !== null)
            ? new AdminController($this->view, $this->config, $authService, $companyContext, $userRepository, $companyRepository, $userCompanyRepository, $adminService, $auditLogRepository, $accountRepository, $categoryRepository, new IpRestrictionService($basePath, $auditLogService), $dashboardSettings)
            : null;
        $recurring = ($accountRepository !== null && $authService !== null)
            ? new RecurringController($this->view, $this->config, $companyContext, $accountRepository, $authService, $categoryRepository, $recurringService)
            : null;
        $transfer = new TransferController(
            $this->view,
            $this->config,
            $companyContext,
            $authService,
            $importRepository,
            $importService,
            $exportService,
            $companyRepository,
            $basePath . '/app/storage',
            $auditLogService
        );
        $categories = ($accountRepository !== null && $authService !== null)
            ? new CategoryController($this->view, $this->config, $companyContext, $authService, $categoryRepository, $auditLogService, $transactionRepository)
            : null;
        $setup = new SetupController(
            $this->view,
            $this->config,
            $installationState,
            $installerService,
            $bootstrapError
        );

        $configured = $installationState->isInstalled();
        $dbAvailable = $pdo !== null && $installerService !== null && $installerService->isInstalled();
        $setupNeeded = !$configured || !$dbAvailable;

        if (!$configured && $installerService !== null && $installerService->hasUsers()) {
            return Response::html($this->renderBootstrapError('The configuration file is missing. Restore config.php from your backup; setup cannot reset an existing installation.'), 503);
        }

        if ($configured && !$dbAvailable) {
            return Response::html($this->renderBootstrapError($bootstrapError), 503);
        }

        if ($setupNeeded && $request->path() !== '/setup') {
            return Response::redirect('/setup');
        }

        $publicPaths = ['/login', '/setup', '/language'];
        if ($authService !== null && !$authService->isAuthenticated() && !in_array($request->path(), $publicPaths, true)) {
            return Response::redirect('/login');
        }

        if ($companyService !== null && $authService !== null && $authService->isAuthenticated()) {
            $companyService->ensureDemoCompany();
            $this->view->share([
                'canManageData' => $companyContext->current() !== null,
                'availableCompanies' => $companyContext->availableCompanies(),
            ]);
        }

        $router->get('/', function (Request $request) use ($dashboard): Response {
            return $dashboard->index($request);
        });
        if ($auth !== null) {
            $router->get('/login', function (Request $request) use ($auth): Response {
                return $auth->showLogin($request);
            });
            $router->post('/login', function (Request $request) use ($auth): Response {
                return $auth->login($request);
            });
            $router->post('/logout', function (Request $request) use ($auth): Response {
                return $auth->logout($request);
            });
        } else {
            $router->get('/login', function (Request $request): Response {
                return Response::redirect('/setup');
            });
            $router->post('/login', function (Request $request): Response {
                return Response::redirect('/setup');
            });
            $router->post('/logout', function (Request $request): Response {
                return Response::redirect('/setup');
            });
        }
        $router->post('/language', function (Request $request) use ($localeController): Response {
            return $localeController->switch($request);
        });
        $router->get('/accounts', function (Request $request) use ($accounts): Response {
            return $accounts->index($request);
        });
        if ($companies !== null) {
            $router->get('/companies', function (Request $request) use ($companies): Response {
                return $companies->index($request);
            });
            $router->get('/companies/create', function (Request $request) use ($companies): Response {
                return $companies->create($request);
            });
            $router->post('/companies', function (Request $request) use ($companies): Response {
                return $companies->store($request);
            });
            $router->get('/companies/edit', function (Request $request) use ($companies): Response {
                return $companies->edit($request);
            });
            $router->post('/companies/update', function (Request $request) use ($companies): Response {
                return $companies->update($request);
            });
            $router->post('/companies/delete', function (Request $request) use ($companies): Response {
                return $companies->destroy($request);
            });
            $router->post('/companies/select', function (Request $request) use ($companies): Response {
                return $companies->select($request);
            });
        }
        $router->get('/accounts/create', function (Request $request) use ($accounts): Response {
            return $accounts->create($request);
        });
        $router->post('/accounts', function (Request $request) use ($accounts): Response {
            return $accounts->store($request);
        });
        $router->get('/accounts/edit', function (Request $request) use ($accounts): Response {
            return $accounts->edit($request);
        });
        $router->post('/accounts/update', function (Request $request) use ($accounts): Response {
            return $accounts->update($request);
        });
        $router->post('/accounts/archive', function (Request $request) use ($accounts): Response {
            return $accounts->archive($request);
        });
        $router->post('/accounts/delete', function (Request $request) use ($accounts): Response {
            return $accounts->delete($request);
        });
        $router->get('/transactions', function (Request $request): Response {
            return Response::redirect('/accounts');
        });
        if ($transactions !== null) {
            $router->get('/transactions/create', function (Request $request) use ($transactions): Response {
                return $transactions->create($request);
            });
            $router->post('/transactions', function (Request $request) use ($transactions): Response {
                return $transactions->store($request);
            });
            $router->get('/transactions/edit', function (Request $request) use ($transactions): Response {
                return $transactions->edit($request);
            });
            $router->post('/transactions/update', function (Request $request) use ($transactions): Response {
                return $transactions->update($request);
            });
            $router->post('/transactions/archive', function (Request $request) use ($transactions): Response {
                return $transactions->archive($request);
            });
            $router->post('/transactions/delete', function (Request $request) use ($transactions): Response {
                return $transactions->delete($request);
            });
            $router->get('/transactions/history', function (Request $request) use ($transactions): Response {
                return $transactions->history($request);
            });
        }
        if ($recurring !== null) {
            $router->get('/recurring', function (Request $request) use ($recurring): Response {
                return $recurring->index($request);
            });
            $router->get('/recurring/create', function (Request $request) use ($recurring): Response {
                return $recurring->create($request);
            });
            $router->post('/recurring', function (Request $request) use ($recurring): Response {
                return $recurring->store($request);
            });
            $router->get('/recurring/edit', function (Request $request) use ($recurring): Response {
                return $recurring->edit($request);
            });
            $router->post('/recurring/update', function (Request $request) use ($recurring): Response {
                return $recurring->update($request);
            });
            $router->post('/recurring/activate', function (Request $request) use ($recurring): Response {
                return $recurring->activate($request);
            });
            $router->post('/recurring/delete', function (Request $request) use ($recurring): Response {
                return $recurring->delete($request);
            });
        } else {
            $router->get('/recurring', function (Request $request) use ($sections): Response {
                return $sections->recurring($request);
            });
        }
        $router->get('/reports', function (Request $request) use ($sections): Response {
            return $sections->reports($request);
        });
        if ($reports !== null) {
            $router->get('/reports/transactions', function (Request $request) use ($reports): Response {
                return $reports->transactions($request);
            });
            $router->get('/reports/transactions/export', function (Request $request) use ($reports): Response {
                return $reports->export($request);
            });
        }
        $router->get('/admin', function (Request $request) use ($sections): Response {
            return $sections->administration($request);
        });
        $router->get('/admin/data', function (Request $request) use ($transfer): Response {
            return $transfer->index($request);
        });
        $router->post('/admin/data/import', function (Request $request) use ($transfer): Response {
            return $transfer->import($request);
        });
        $router->post('/admin/data/export', function (Request $request) use ($transfer): Response {
            return $transfer->export($request);
        });
        $router->get('/admin/data/download', function (Request $request) use ($transfer): Response {
            return $transfer->download($request);
        });
        $router->get('/admin/data/inspect', function (Request $request) use ($transfer): Response {
            return $transfer->inspect($request);
        });
        if ($categories !== null) {
            $router->get('/categories', function (Request $request) use ($categories): Response {
                return $categories->index($request);
            });
            $router->get('/categories/create', function (Request $request) use ($categories): Response {
                return $categories->create($request);
            });
            $router->post('/categories', function (Request $request) use ($categories): Response {
                return $categories->store($request);
            });
            $router->get('/categories/edit', function (Request $request) use ($categories): Response {
                return $categories->edit($request);
            });
            $router->post('/categories/update', function (Request $request) use ($categories): Response {
                return $categories->update($request);
            });
            $router->post('/categories/delete', function (Request $request) use ($categories): Response {
                return $categories->delete($request);
            });
        }
        if ($admin !== null) {
            $router->get('/admin/users', function (Request $request) use ($admin): Response {
                return $admin->users($request);
            });
            $router->post('/admin/users', function (Request $request) use ($admin): Response {
                return $admin->createUser($request);
            });
            $router->post('/admin/users/role', function (Request $request) use ($admin): Response {
                return $admin->updateUserRole($request);
            });
            $router->post('/admin/users/company', function (Request $request) use ($admin): Response {
                return $admin->assignUserCompany($request);
            });
            $router->post('/admin/users/active', function (Request $request) use ($admin): Response {
                return $admin->toggleUserActive($request);
            });
            $router->get('/admin/audit', function (Request $request) use ($admin): Response {
                return $admin->audit($request);
            });
            $router->get('/admin/settings', function (Request $request) use ($admin): Response {
                return $admin->settings($request);
            });
            $router->post('/admin/settings', function (Request $request) use ($admin): Response {
                return $admin->updateSettings($request);
            });
            $router->post('/admin/settings/dashboard', function (Request $request) use ($admin): Response {
                return $admin->updateDashboard($request);
            });
            $router->post('/admin/settings/ip-restrictions', function (Request $request) use ($admin): Response {
                return $admin->updateIpRestrictions($request);
            });
        }
        $router->get('/setup', function (Request $request) use ($setup): Response {
            return $setup->show($request);
        });
        $router->post('/setup', function (Request $request) use ($setup): Response {
            return $setup->install($request);
        });

        return $router->dispatch($request);
    }

    private function renderBootstrapError(?string $bootstrapError): string
    {
        $reference = preg_match('/^[a-f0-9]{16}$/D', $bootstrapError ?? '') === 1 ? $bootstrapError : '';
        return '<h1>503</h1><p>' . e(security_message('security.unavailable')) . '</p><p>'
            . e(security_message('security.reference')) . ': ' . e($reference) . '</p>';
    }
}
