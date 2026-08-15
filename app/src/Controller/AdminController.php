<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Repository\AccountRepository;
use App\Repository\AuditLogRepository;
use App\Repository\CategoryRepository;
use App\Repository\CompanyRepository;
use App\Repository\UserCompanyRepository;
use App\Repository\UserRepository;
use App\Service\AdminService;
use App\Service\AuthService;
use App\Service\CompanyContext;
use App\Service\DashboardSettings;
use App\Service\IpRestrictionService;
use App\Support\Config;
use App\Support\DateFormatter;
use App\Support\View;
use InvalidArgumentException;
use RuntimeException;

final class AdminController
{
    public function __construct(
        private View $view,
        private Config $config,
        private AuthService $authService,
        private CompanyContext $companyContext,
        private UserRepository $userRepository,
        private CompanyRepository $companyRepository,
        private UserCompanyRepository $userCompanyRepository,
        private AdminService $adminService,
        private ?AuditLogRepository $auditLogRepository = null,
        private ?AccountRepository $accountRepository = null,
        private ?CategoryRepository $categoryRepository = null,
        private ?IpRestrictionService $ipRestrictions = null,
        private ?DashboardSettings $dashboardSettings = null
    ) {
    }

    public function users(Request $request, ?string $error = null, ?string $success = null): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $users = $this->userRepository->all();
        $assignments = [];
        $adminCount = 0;
        foreach ($users as $user) {
            $assignments[$user->id()] = $this->userCompanyRepository->companyIdsForUser($user->id());
            if ($user->role() === 'ADMIN') {
                $adminCount++;
            }
        }

        return Response::html($this->view->render('admin/users', [
            'appName' => $this->config->get('app.name', 'Account+'),
            'currentCompany' => $this->companyContext->current(),
            'users' => $users,
            'companies' => $this->companyRepository->all(),
            'assignments' => $assignments,
            'summary' => [
                'users' => count($users),
                'admins' => $adminCount,
                'companies' => $this->companyRepository->count(),
            ],
            'error' => $error,
            'success' => $success,
        ]));
    }

    public function createUser(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::html($this->view->renderError(403), 403);
        }

        try {
            $this->adminService->createUser(
                trim((string) $request->input('username', '')),
                trim((string) $request->input('display_name', '')),
                (string) $request->input('password', ''),
                trim((string) $request->input('role', 'USER'))
            );
        } catch (InvalidArgumentException $exception) {
            return $this->users($request, $exception->getMessage(), null);
        }

        return $this->users($request, null, $this->view->translate('flash.user_created'));
    }

    public function updateUserRole(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::html($this->view->renderError(403), 403);
        }

        try {
            $this->adminService->updateRole(
                (int) $request->input('user_id', 0),
                trim((string) $request->input('role', 'USER'))
            );
        } catch (InvalidArgumentException $exception) {
            return $this->users($request, $exception->getMessage(), null);
        }

        return $this->users($request, null, $this->view->translate('flash.user_role_updated'));
    }

    public function assignUserCompany(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::html($this->view->renderError(403), 403);
        }

        try {
            $this->adminService->assignCompany(
                (int) $request->input('user_id', 0),
                (int) $request->input('company_id', 0)
            );
        } catch (InvalidArgumentException $exception) {
            return $this->users($request, $exception->getMessage(), null);
        }

        return $this->users($request, null, $this->view->translate('flash.user_company_assigned'));
    }

    public function toggleUserActive(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $userId = (int) $request->input('user_id', 0);
        $isActive = ((string) $request->input('action', '')) === 'activate';

        try {
            $this->adminService->setUserActive($userId, $isActive);
        } catch (InvalidArgumentException $exception) {
            return $this->users($request, $exception->getMessage(), null);
        }

        return $this->users(
            $request,
            null,
            $this->view->translate($isActive ? 'flash.user_activated' : 'flash.user_deactivated')
        );
    }

    public function audit(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $company = $this->companyContext->current();
        $logs = [];
        $entityNames = ['account' => [], 'category' => []];
        if ($company !== null && $this->auditLogRepository !== null) {
            $query = trim((string) $request->input('q', ''));
            $dateFrom = trim((string) $request->input('date_from', ''));
            $dateTo = trim((string) $request->input('date_to', ''));
            $logs = $this->auditLogRepository->search(
                $company->id(),
                $query !== '' ? $query : null,
                $dateFrom !== '' ? $dateFrom : null,
                $dateTo !== '' ? $dateTo : null
            );
            if ($this->accountRepository !== null) {
                foreach ($this->accountRepository->allInCompany($company->id()) as $account) {
                    $entityNames['account'][(int) $account['id']] = (string) ($account['name'] ?? '');
                }
            }
            if ($this->categoryRepository !== null) {
                foreach ($this->categoryRepository->allInCompany($company->id()) as $category) {
                    $entityNames['category'][(int) $category['id']] = (string) ($category['name'] ?? '');
                }
            }
        }

        return Response::html($this->view->render('admin/audit', [
            'appName' => $this->config->get('app.name', 'Account+'),
            'currentCompany' => $company,
            'logs' => $logs,
            'entityNames' => $entityNames,
            'filters' => [
                'q' => trim((string) $request->input('q', '')),
                'date_from' => trim((string) $request->input('date_from', '')),
                'date_to' => trim((string) $request->input('date_to', '')),
            ],
        ]));
    }

    public function settings(Request $request, ?string $error = null, ?string $success = null, ?array $ipInput = null): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $currentFormat = $this->adminService->dateFormat();
        $sample = date('Y-m-d');
        $ipSettings = ['mode' => 'disabled', 'whitelist' => [], 'blacklist' => []];
        $ipError = null;
        $ipWritable = false;
        try {
            if ($this->ipRestrictions === null) {
                throw new RuntimeException('settings.ip_error_read');
            }
            $ipSettings = $this->ipRestrictions->read();
            $ipWritable = $this->ipRestrictions->writable();
        } catch (RuntimeException $exception) {
            $ipError = $this->view->translate($exception->getMessage());
        }

        return Response::html($this->view->render('admin/settings', [
            'appName' => $this->config->get('app.name', 'Account+'),
            'currentCompany' => $this->companyContext->current(),
            'formats' => [
                ['format' => 'Y-m-d', 'label_key' => 'settings.preset_iso', 'sample' => DateFormatter::format($sample, 'Y-m-d')],
                ['format' => 'd/m/Y', 'label_key' => 'settings.preset_dmy', 'sample' => DateFormatter::format($sample, 'd/m/Y')],
                ['format' => 'm/d/Y', 'label_key' => 'settings.preset_mdy', 'sample' => DateFormatter::format($sample, 'm/d/Y')],
                ['format' => 'd.m.Y', 'label_key' => 'settings.preset_dots', 'sample' => DateFormatter::format($sample, 'd.m.Y')],
                ['format' => 'd-m-Y', 'label_key' => 'settings.preset_dashes', 'sample' => DateFormatter::format($sample, 'd-m-Y')],
            ],
            'currentFormat' => $currentFormat,
            'dashboardWidgets' => ($this->dashboardSettings ?? new DashboardSettings())->read(),
            'dashboardOptions' => DashboardSettings::WIDGETS,
            'sampleDate' => DateFormatter::format($sample, $currentFormat),
            'sampleDateTime' => DateFormatter::format(date('Y-m-d H:i:s'), $currentFormat),
            'ipSettings' => $ipSettings,
            'ipInput' => $ipInput,
            'ipWritable' => $ipWritable,
            'ipError' => $ipError,
            'clientIp' => $request->clientIp(),
            'error' => $error,
            'success' => $success,
        ]));
    }

    public function updateSettings(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::html($this->view->renderError(403), 403);
        }

        try {
            $format = trim((string) $request->input('date_format', ''));
            if ($format === '__custom') {
                $format = trim((string) $request->input('custom_format', ''));
            }
            $this->adminService->updateDateFormat($format);
        } catch (InvalidArgumentException $exception) {
            return $this->settings($request, $exception->getMessage(), null);
        }

        return $this->settings($request, null, $this->view->translate('flash.settings_saved'));
    }

    public function updateDashboard(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::html($this->view->renderError(403), 403);
        }
        try {
            $selected = $request->input('dashboard_widgets', []);
            if (!is_array($selected)) {
                throw new InvalidArgumentException('settings.dashboard_invalid');
            }
            ($this->dashboardSettings ?? new DashboardSettings())->update($selected);
        } catch (InvalidArgumentException $exception) {
            return $this->settings($request, $this->view->translate($exception->getMessage()));
        }
        return $this->settings($request, null, $this->view->translate('settings.dashboard_saved'));
    }

    public function updateIpRestrictions(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::html($this->view->renderError(403), 403);
        }
        $input = [];
        foreach (['mode', 'whitelist', 'blacklist'] as $field) {
            $value = $request->input('ip_' . $field, '');
            if (!is_string($value)) {
                return $this->settings($request, $this->view->translate('settings.ip_error_address'));
            }
            $input[$field] = $value;
        }
        try {
            if ($this->ipRestrictions === null) {
                throw new RuntimeException('settings.ip_error_read');
            }
            $this->ipRestrictions->update($input['mode'], $input['whitelist'], $input['blacklist'], $request->clientIp());
        } catch (InvalidArgumentException | RuntimeException $exception) {
            return $this->settings($request, $this->view->translate($exception->getMessage()), null, $input);
        }
        return $this->settings($request, null, $this->view->translate('settings.ip_saved'));
    }
}
