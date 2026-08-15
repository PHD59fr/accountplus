<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Service\AppDataService;
use App\Service\CompanyContext;
use App\Service\DashboardSettings;
use App\Support\Config;
use App\Support\View;

final class DashboardController
{
    public function __construct(
        private View $view,
        private Config $config,
        private AppDataService $dataService,
        private ?CompanyContext $companyContext = null,
        private ?DashboardSettings $dashboardSettings = null
    ) {
    }

    public function index(Request $request): Response
    {
        $widgets = ($this->dashboardSettings ?? new DashboardSettings())->read();
        $showMetrics = $widgets['balance'] || $widgets['income'] || $widgets['expenses'] || $widgets['recurring'];
        $content = $this->view->render('dashboard/index', [
            'appName' => $this->config->get('app.name', 'Account+'),
            'currentCompany' => $this->companyContext !== null ? $this->companyContext->current() : null,
            'widgets' => $widgets,
            'accountCards' => $widgets['accounts'] ? $this->dataService->accountCards() : [],
            'recentTransactions' => $widgets['recent_transactions'] ? $this->dataService->recentTransactions() : [],
            'metrics' => $showMetrics ? $this->dataService->metrics() : [],
            'breakdown' => $widgets['category_breakdown'] || $widgets['type_breakdown']
                ? $this->dataService->categoryBreakdown(null, '0000-01-01', '9999-12-31', null, '', false) : [],
            'labelBreakdown' => $widgets['label_breakdown'] ? $this->dataService->labelBreakdown() : [],
        ]);

        return Response::html($content);
    }
}
