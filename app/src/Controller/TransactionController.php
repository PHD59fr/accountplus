<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Repository\AccountRepository;
use App\Repository\AuditLogRepository;
use App\Repository\CategoryRepository;
use App\Service\AuthService;
use App\Service\CompanyContext;
use App\Service\TransactionService;
use App\Support\Config;
use App\Support\View;
use InvalidArgumentException;

final class TransactionController
{
    public function __construct(
        private View $view,
        private Config $config,
        private CompanyContext $companyContext,
        private AccountRepository $accountRepository,
        private AuthService $authService,
        private ?TransactionService $transactionService = null,
        private ?CategoryRepository $categoryRepository = null,
        private ?AuditLogRepository $auditLogRepository = null
    ) {
    }

    public function create(Request $request): Response
    {
        if (!$this->canManageData()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $preselectedAccount = (int) $request->input('account', 0);
        $copyId = (int) $request->input('copy', 0);

        if ($copyId > 0 && $this->transactionService !== null) {
            $transaction = $this->transactionService->findForCurrentCompany($copyId);
            if ($transaction !== null) {
                $preselectedAccount = (int) ($transaction['account_id'] ?? 0) ?: $preselectedAccount;

                return Response::html($this->view->render('transactions/create', $this->viewData(null, [
                    'account_id' => (string) ($transaction['account_id'] ?? ''),
                    'category_id' => (string) ($transaction['category_id'] ?? ''),
                    'transaction_date' => (string) ($transaction['transaction_date'] ?? date('Y-m-d')),
                    'value_date' => (string) ($transaction['value_date'] ?? ''),
                    'label' => (string) ($transaction['label'] ?? ''),
                    'description' => (string) ($transaction['description'] ?? ''),
                    'amount' => (string) ($transaction['amount'] ?? ''),
                    'is_reconciled' => 0,
                ], 0, $preselectedAccount)));
            }
        }

        return Response::html($this->view->render('transactions/create', $this->viewData(null, [
            'account_id' => $preselectedAccount > 0 ? (string) $preselectedAccount : '',
            'category_id' => '',
            'transaction_date' => date('Y-m-d'),
            'value_date' => '',
            'label' => '',
            'description' => '',
            'amount' => '',
            'is_reconciled' => 0,
        ], 0, $preselectedAccount)));
    }

    public function store(Request $request): Response
    {
        if (!$this->canManageData()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $values = $this->requestValues($request);
        if ($this->transactionService === null) {
            return Response::html($this->view->render('transactions/create', $this->viewData('Transaction creation is unavailable because the database connection is not ready.', $values)), 500);
        }

        try {
            $this->transactionService->create($values);
        } catch (InvalidArgumentException $exception) {
            return Response::html($this->view->render('transactions/create', $this->viewData($exception->getMessage(), $values)), 422);
        }

        flash('success', $this->view->translate('flash.transaction_created'));

        return $this->redirectToAccount((int) ($values['account_id'] ?? 0));
    }

    public function edit(Request $request): Response
    {
        if (!$this->canManageData()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $transactionId = (int) $request->input('id', 0);
        $backAccount = (int) $request->input('account', 0);
        $transaction = $this->transactionService !== null ? $this->transactionService->findForCurrentCompany($transactionId) : null;
        if ($transaction === null) {
            return $this->redirectToAccount($backAccount);
        }

        return Response::html($this->view->render('transactions/edit', $this->viewData(null, [
            'account_id' => (string) ($transaction['account_id'] ?? ''),
            'category_id' => (string) ($transaction['category_id'] ?? ''),
            'transaction_date' => (string) ($transaction['transaction_date'] ?? ''),
            'value_date' => (string) ($transaction['value_date'] ?? ''),
            'label' => (string) ($transaction['label'] ?? ''),
            'description' => (string) ($transaction['description'] ?? ''),
            'amount' => (string) ($transaction['amount'] ?? ''),
            'is_reconciled' => (int) ($transaction['is_reconciled'] ?? 0),
        ], $transactionId, $backAccount)));
    }

    public function update(Request $request): Response
    {
        if (!$this->canManageData()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $transactionId = (int) $request->input('id', 0);
        $values = $this->requestValues($request);
        if ($this->transactionService === null) {
            return $this->redirectToAccount((int) ($values['account_id'] ?? 0));
        }

        try {
            $this->transactionService->update($transactionId, $values);
        } catch (InvalidArgumentException $exception) {
            return Response::html($this->view->render('transactions/edit', $this->viewData($exception->getMessage(), $values, $transactionId)), 422);
        }

        flash('success', $this->view->translate('flash.transaction_updated'));

        return $this->redirectToAccount((int) ($values['account_id'] ?? 0));
    }

    public function archive(Request $request): Response
    {
        if (!$this->canManageData()) {
            return Response::html($this->view->renderError(403), 403);
        }

        return $this->mutateArchived(
            (int) $request->input('id', 0),
            (bool) $request->input('archived', 0),
            (int) $request->input('account_id', 0)
        );
    }

    public function delete(Request $request): Response
    {
        if (!$this->canManageData()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $transactionId = (int) $request->input('id', 0);
        $accountId = (int) $request->input('account_id', 0);
        if ($this->transactionService === null) {
            flash('error', $this->view->translate('flash.db_unavailable'));

            return $this->redirectToAccount($accountId);
        }

        try {
            $this->transactionService->delete($transactionId);
            flash('success', $this->view->translate('flash.transaction_deleted'));
        } catch (InvalidArgumentException $exception) {
            flash('error', $exception->getMessage());
        }

        return $this->redirectToAccount($accountId);
    }

    private function mutateArchived(int $transactionId, bool $archived, int $accountId = 0): Response
    {
        if ($this->transactionService === null) {
            flash('error', $this->view->translate('flash.db_unavailable'));

            return $this->redirectToAccount($accountId);
        }

        try {
            $this->transactionService->archive($transactionId, $archived);
            flash('success', $this->view->translate($archived ? 'flash.transaction_archived' : 'flash.transaction_unarchived'));
        } catch (InvalidArgumentException $exception) {
            flash('error', $exception->getMessage());
        }

        return $this->redirectToAccount($accountId);
    }

    public function history(Request $request): Response
    {
        if (!$this->authService->isAuthenticated()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $company = $this->companyContext->current();
        if ($company === null) {
            return Response::html($this->view->renderError(404), 404);
        }

        $transactionId = (int) $request->input('id', 0);
        if ($transactionId <= 0) {
            return Response::redirect('/accounts');
        }

        $transaction = $this->transactionService !== null
            ? $this->transactionService->findForCurrentCompany($transactionId)
            : null;
        if ($transaction === null) {
            return Response::redirect('/accounts');
        }

        $logs = $this->auditLogRepository !== null
            ? $this->auditLogRepository->forEntity('transaction', (string) $transactionId, $company->id())
            : [];

        $accountNames = [];
        $categoryNames = [];
        foreach ($this->accountRepository->allInCompany($company->id()) as $account) {
            $accountNames[(int) $account['id']] = (string) ($account['name'] ?? '');
        }
        if ($this->categoryRepository !== null) {
            $allCategories = $this->categoryRepository->allInCompany($company->id());
            $categoryNameById = [];
            foreach ($allCategories as $category) {
                $categoryNameById[(int) $category['id']] = (string) ($category['name'] ?? '');
            }
            foreach ($allCategories as $category) {
                $parentId = isset($category['parent_id']) && $category['parent_id'] !== '' ? (int) $category['parent_id'] : null;
                $name = (string) ($category['name'] ?? '');
                $categoryNames[(int) $category['id']] = ($parentId !== null && isset($categoryNameById[$parentId]))
                    ? $categoryNameById[$parentId] . ' › ' . $name
                    : $name;
            }
        }

        return Response::html($this->view->render('transactions/history', [
            'appName' => $this->config->get('app.name', 'Account+'),
            'currentCompany' => $company,
            'transaction' => $transaction,
            'backAccount' => (int) $request->input('account', 0),
            'logs' => $logs,
            'entityNames' => [
                'account' => $accountNames,
                'category' => $categoryNames,
            ],
        ]));
    }

    private function redirectToAccount(int $accountId): Response
    {
        if ($accountId > 0) {
            return Response::redirect('/accounts?account=' . $accountId);
        }

        return Response::redirect('/accounts');
    }

    private function canManageData(): bool
    {
        return $this->authService->isAuthenticated() && $this->companyContext->current() !== null;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function viewData(?string $error, array $values, int $transactionId = 0, int $backAccount = 0): array
    {
        $currentCompany = $this->companyContext->current();
        $accounts = [];
        $categories = [];
        if ($currentCompany !== null) {
            foreach ($this->accountRepository->allInCompany($currentCompany->id()) as $account) {
                $accounts[] = [
                    'id' => (int) ($account['id'] ?? 0),
                    'name' => (string) ($account['name'] ?? ''),
                ];
            }
            if ($this->categoryRepository !== null) {
                $allCategories = $this->categoryRepository->allInCompany($currentCompany->id());
                $nameById = [];
                foreach ($allCategories as $category) {
                    $nameById[(int) $category['id']] = (string) ($category['name'] ?? '');
                }
                foreach ($allCategories as $category) {
                    $parentId = isset($category['parent_id']) && $category['parent_id'] !== '' ? (int) $category['parent_id'] : null;
                    $name = (string) ($category['name'] ?? '');
                    $categories[] = [
                        'id' => (int) ($category['id'] ?? 0),
                        'name' => $name,
                        'path' => ($parentId !== null && isset($nameById[$parentId]))
                            ? $nameById[$parentId] . ' › ' . $name
                            : $name,
                        'emoji' => (string) ($category['emoji'] ?? ''),
                    ];
                }
            }
        }

        return [
            'appName' => $this->config->get('app.name', 'Account+'),
            'currentCompany' => $currentCompany,
            'accounts' => $accounts,
            'categories' => $categories,
            'error' => $error,
            'values' => $values,
            'transactionId' => $transactionId,
            'isTransfer' => $transactionId > 0 && !empty($this->transactionService?->findForCurrentCompany($transactionId)['transfer_id']),
            'backAccount' => $backAccount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestValues(Request $request): array
    {
        return [
            'account_id' => trim((string) $request->input('account_id', '')),
            'category_id' => trim((string) $request->input('category_id', '')),
            'transaction_date' => trim((string) $request->input('transaction_date', '')),
            'value_date' => trim((string) $request->input('value_date', '')),
            'label' => trim((string) $request->input('label', '')),
            'description' => trim((string) $request->input('description', '')),
            'amount' => trim((string) $request->input('amount', '')),
            'is_reconciled' => (int) $request->input('is_reconciled', 0),
        ];
    }
}
