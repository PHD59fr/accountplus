<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Service\AccountService;
use App\Service\AppDataService;
use App\Service\AuthService;
use App\Service\CompanyContext;
use App\Support\Config;
use App\Support\View;
use InvalidArgumentException;

final class AccountController
{
    public function __construct(
        private View $view,
        private Config $config,
        private AppDataService $dataService,
        private CompanyContext $companyContext,
        private ?AuthService $authService = null,
        private ?AccountService $accountService = null
    ) {
    }

    public function index(Request $request): Response
    {
        $perPage = 50;
        $page = max(1, (int) $request->input('page', 1));
        $search = trim((string) $request->input('q', ''));
        $accountId = (int) $request->input('account', 0);
        $highlightId = (int) $request->input('highlight', 0);

        $accounts = $this->dataService->accountsTable();

        $selectedAccount = null;
        if ($accountId > 0) {
            foreach ($accounts as $account) {
                if ((int) $account['id'] === $accountId) {
                    $selectedAccount = $account;
                    break;
                }
            }
        }
        if ($selectedAccount === null) {
            $accountId = 0;
        }

        $total = $this->dataService->transactionCount($search, $accountId > 0 ? $accountId : null);
        $pages = max(1, (int) ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }

        return Response::html($this->view->render('accounts/index', [
            'appName' => $this->config->get('app.name', 'Account+'),
            'accounts' => $accounts,
            'transactions' => $this->dataService->transactionsTable($page, $search, $perPage, $accountId > 0 ? $accountId : null),
            'selectedAccount' => $selectedAccount,
            'currentCompany' => $this->companyContext->current(),
            'canManage' => $this->canManageData(),
            'canManageAccounts' => $this->canManageAccounts(),
            'search' => $search,
            'page' => $page,
            'pages' => $pages,
            'searchBase' => '/accounts',
            'searchParams' => $accountId > 0 ? ['account' => $accountId] : [],
            'highlightId' => $highlightId,
        ]));
    }

    public function create(Request $request): Response
    {
        if (!$this->canManageAccounts()) {
            return Response::html($this->view->renderError(403), 403);
        }

        return Response::html($this->view->render('accounts/create', [
            'appName' => $this->config->get('app.name', 'Account+'),
            'currentCompany' => $this->companyContext->current(),
            'error' => null,
            'values' => [
                'name' => '',
                'bank_name' => '',
                'account_number' => '',
                'description' => '',
                'currency' => 'EUR',
                'initial_balance' => '0.00',
            ],
        ]));
    }

    public function store(Request $request): Response
    {
        if (!$this->canManageAccounts()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $values = [
            'name' => trim((string) $request->input('name', '')),
            'bank_name' => trim((string) $request->input('bank_name', '')),
            'account_number' => trim((string) $request->input('account_number', '')),
            'description' => trim((string) $request->input('description', '')),
            'currency' => trim((string) $request->input('currency', 'EUR')),
            'initial_balance' => trim((string) $request->input('initial_balance', '0.00')),
        ];

        if ($this->accountService === null) {
            return Response::html($this->view->render('accounts/create', [
                'appName' => $this->config->get('app.name', 'Account+'),
                'currentCompany' => $this->companyContext->current(),
                'error' => $this->view->translate('flash.db_unavailable'),
                'values' => $values,
            ]), 500);
        }

        try {
            $this->accountService->create($values);
        } catch (InvalidArgumentException $exception) {
            return Response::html($this->view->render('accounts/create', [
                'appName' => $this->config->get('app.name', 'Account+'),
                'currentCompany' => $this->companyContext->current(),
                'error' => $exception->getMessage(),
                'values' => $values,
            ]), 422);
        }

        flash('success', $this->view->translate('flash.account_created'));

        return Response::redirect('/accounts');
    }

    public function edit(Request $request): Response
    {
        if (!$this->canManageAccounts()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $accountId = (int) $request->input('id', 0);
        $account = $this->accountService !== null ? $this->accountService->findForCurrentCompany($accountId) : null;
        if ($account === null) {
            return Response::redirect('/accounts');
        }

        return Response::html($this->view->render('accounts/edit', [
            'appName' => $this->config->get('app.name', 'Account+'),
            'currentCompany' => $this->companyContext->current(),
            'error' => null,
            'accountId' => $accountId,
            'values' => [
                'name' => (string) ($account['name'] ?? ''),
                'bank_name' => (string) ($account['bank_name'] ?? ''),
                'account_number' => (string) ($account['account_number'] ?? ''),
                'description' => (string) ($account['description'] ?? ''),
                'currency' => (string) ($account['currency'] ?? 'EUR'),
                'initial_balance' => (string) ($account['initial_balance'] ?? '0.00'),
            ],
        ]));
    }

    public function update(Request $request): Response
    {
        if (!$this->canManageAccounts()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $accountId = (int) $request->input('id', 0);
        $values = [
            'name' => trim((string) $request->input('name', '')),
            'bank_name' => trim((string) $request->input('bank_name', '')),
            'account_number' => trim((string) $request->input('account_number', '')),
            'description' => trim((string) $request->input('description', '')),
            'currency' => trim((string) $request->input('currency', 'EUR')),
            'initial_balance' => trim((string) $request->input('initial_balance', '0.00')),
        ];

        if ($this->accountService === null) {
            return Response::redirect('/accounts');
        }

        try {
            $this->accountService->update($accountId, $values);
        } catch (InvalidArgumentException $exception) {
            return Response::html($this->view->render('accounts/edit', [
                'appName' => $this->config->get('app.name', 'Account+'),
                'currentCompany' => $this->companyContext->current(),
                'error' => $exception->getMessage(),
                'accountId' => $accountId,
                'values' => $values,
            ]), 422);
        }

        flash('success', $this->view->translate('flash.account_updated'));

        return Response::redirect('/accounts');
    }

    public function archive(Request $request): Response
    {
        if (!$this->canManageAccounts()) {
            return Response::html($this->view->renderError(403), 403);
        }

        return $this->mutateArchived((int) $request->input('id', 0), (bool) $request->input('archived', 0));
    }

    public function delete(Request $request): Response
    {
        if (!$this->canManageAccounts()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $accountId = (int) $request->input('id', 0);
        if ($this->accountService === null) {
            flash('error', $this->view->translate('flash.db_unavailable'));

            return Response::redirect('/accounts');
        }

        try {
            $this->accountService->delete($accountId);
            flash('success', $this->view->translate('flash.account_deleted'));
        } catch (InvalidArgumentException $exception) {
            flash('error', $exception->getMessage());
        }

        return Response::redirect('/accounts');
    }

    private function mutateArchived(int $accountId, bool $archived): Response
    {
        if ($this->accountService === null) {
            flash('error', $this->view->translate('flash.db_unavailable'));

            return Response::redirect('/accounts');
        }

        try {
            $this->accountService->archive($accountId, $archived);
            flash('success', $this->view->translate($archived ? 'flash.account_archived' : 'flash.account_unarchived'));
        } catch (InvalidArgumentException $exception) {
            flash('error', $exception->getMessage());
        }

        return Response::redirect('/accounts');
    }

    private function canManageData(): bool
    {
        return $this->authService !== null && $this->authService->isAuthenticated() && $this->companyContext->current() !== null;
    }

    private function canManageAccounts(): bool
    {
        return $this->canManageData() && $this->authService !== null && $this->authService->isAdmin();
    }
}
