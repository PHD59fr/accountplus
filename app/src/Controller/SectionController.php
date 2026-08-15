<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Service\AuthService;
use App\Service\AppDataService;
use App\Service\CompanyContext;
use App\Support\Config;
use App\Support\View;

final class SectionController
{
    public function __construct(
        private View $view,
        private Config $config,
        private AppDataService $dataService,
        private ?AuthService $authService = null,
        private ?CompanyContext $companyContext = null
    ) {
    }

    public function recurring(Request $request): Response
    {
        return Response::html($this->view->render('recurring/index', [
            'appName' => $this->config->get('app.name', 'Account+'),
            'currentCompany' => $this->companyContext !== null ? $this->companyContext->current() : null,
            'rules' => $this->dataService->recurringRules(),
        ]));
    }

    public function reports(Request $request): Response
    {
        return Response::html($this->view->render('reports/index', [
            'appName' => $this->config->get('app.name', 'Account+'),
            'currentCompany' => $this->companyContext !== null ? $this->companyContext->current() : null,
            'cards' => $this->dataService->reportCards(),
        ]));
    }

    public function administration(Request $request): Response
    {
        if ($this->authService === null || !$this->authService->isAdmin()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $items = [
            [
                'title_key' => 'companies.title',
                'detail_key' => 'companies.admin_help',
                'link' => '/companies',
                'action_key' => 'action.open_companies',
                'list_keys' => ['companies.new_company', 'companies.selection'],
            ],
            [
                'title_key' => 'admin.users',
                'detail_key' => 'admin.users_card_detail',
                'link' => '/admin/users',
                'action_key' => 'admin.open_users',
                'list_keys' => ['admin.users_list_roles', 'admin.users_list_assignments'],
            ],
            [
                'title_key' => 'admin.data',
                'detail_key' => 'admin.data_card_detail',
                'link' => '/admin/data',
                'action_key' => 'admin.open_data',
                'list_keys' => ['admin.data_list_import', 'admin.data_list_export'],
            ],
            [
                'title_key' => 'admin.audit',
                'detail_key' => 'admin.audit_card_detail',
                'link' => '/admin/audit',
                'action_key' => 'admin.open_audit',
                'list_keys' => ['admin.audit_list_auth', 'admin.audit_list_changes'],
            ],
            [
                'title_key' => 'admin.settings',
                'detail_key' => 'admin.settings_card_detail',
                'link' => '/admin/settings',
                'action_key' => 'admin.open_settings',
                'list_keys' => ['admin.settings_list_date', 'settings.dashboard_title', 'settings.ip_title'],
            ],
        ];

        return Response::html($this->view->render('admin/index', [
            'appName' => $this->config->get('app.name', 'Account+'),
            'currentCompany' => $this->companyContext !== null ? $this->companyContext->current() : null,
            'items' => $items,
        ]));
    }
}
