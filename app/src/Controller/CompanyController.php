<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Repository\CompanyRepository;
use App\Service\AuthService;
use App\Service\AuditLogService;
use App\Service\CompanyContext;
use App\Service\CompanyService;
use App\Support\Config;
use App\Support\View;
use InvalidArgumentException;

final class CompanyController
{
    public function __construct(
        private View $view,
        private Config $config,
        private CompanyContext $companyContext,
        private CompanyRepository $companyRepository,
        private CompanyService $companyService,
        private AuthService $authService,
        private ?AuditLogService $auditLogService = null
    ) {
    }

    public function index(Request $request, ?string $error = null): Response
    {
        return Response::html($this->view->render('companies/index', [
            'appName' => $this->config->get('app.name', 'Account+'),
            'currentCompany' => $this->companyContext->current(),
            'companies' => $this->companyContext->availableCompanies(),
            'canManage' => $this->authService->isAdmin(),
            'error' => $error,
            'values' => [
                'name' => '',
                'source_label' => 'MANUAL',
            ],
        ]));
    }

    public function create(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::html($this->view->renderError(403), 403);
        }

        return Response::html($this->view->render('companies/create', [
            'appName' => $this->config->get('app.name', 'Account+'),
            'currentCompany' => $this->companyContext->current(),
            'error' => null,
            'values' => [
                'name' => '',
                'source_label' => 'MANUAL',
            ],
        ]));
    }

    public function store(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $values = [
            'name' => trim((string) $request->input('name', '')),
            'source_label' => trim((string) $request->input('source_label', 'MANUAL')),
        ];

        try {
            $this->companyService->create($values);
        } catch (InvalidArgumentException $exception) {
            flash('error', $exception->getMessage());

            return Response::redirect('/companies/create');
        }

        flash('success', $this->view->translate('flash.company_created'));

        return Response::redirect('/companies');
    }

    public function edit(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $companyId = (int) $request->input('id', 0);
        $company = $this->companyRepository->find($companyId);
        if ($company === null) {
            return Response::redirect('/companies');
        }

        return Response::html($this->view->render('companies/edit', [
            'appName' => $this->config->get('app.name', 'Account+'),
            'currentCompany' => $this->companyContext->current(),
            'error' => null,
            'company' => $company,
            'values' => [
                'name' => $company->name(),
                'status' => $company->status(),
            ],
        ]));
    }

    public function update(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $companyId = (int) $request->input('id', 0);
        $company = $this->companyRepository->find($companyId);
        if ($company === null) {
            return Response::redirect('/companies');
        }

        $values = [
            'name' => trim((string) $request->input('name', '')),
            'status' => trim((string) $request->input('status', 'Active')),
        ];

        try {
            $this->companyService->update($companyId, $values['name'], $values['status']);
        } catch (InvalidArgumentException $exception) {
            return Response::html($this->view->render('companies/edit', [
                'appName' => $this->config->get('app.name', 'Account+'),
                'currentCompany' => $this->companyContext->current(),
                'error' => $exception->getMessage(),
                'company' => $company,
                'values' => $values,
            ]), 422);
        }

        flash('success', $this->view->translate('flash.company_updated'));

        return Response::redirect('/companies');
    }

    public function destroy(Request $request): Response
    {
        if (!$this->authService->isAdmin()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $companyId = (int) $request->input('id', 0);
        $company = $this->companyRepository->find($companyId);
        if ($company === null) {
            return Response::redirect('/companies');
        }

        if ($this->companyRepository->count() <= 1) {
            flash('error', $this->view->translate('companies.error_last'));

            return Response::redirect('/companies');
        }

        try {
            $this->companyService->delete($companyId);
            flash('success', $this->view->translate('flash.company_deleted'));
        } catch (InvalidArgumentException $exception) {
            flash('error', $exception->getMessage());
        }

        return Response::redirect('/companies');
    }

    public function select(Request $request): Response
    {
        $companyId = (int) $request->input('company_id', 0);
        $selected = $this->companyContext->select($companyId);
        if ($selected && $companyId > 0) {
            $this->auditLogService?->log('company.select', 'company', (string) $companyId);
        }

        $redirectTo = Response::internalLocation($request->input('redirect_to', '/companies'));

        return Response::redirect($redirectTo);
    }
}
