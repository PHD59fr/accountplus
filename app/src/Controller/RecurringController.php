<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Repository\AccountRepository;
use App\Repository\CategoryRepository;
use App\Service\AuthService;
use App\Service\CompanyContext;
use App\Service\RecurringService;
use App\Support\Config;
use App\Support\View;
use InvalidArgumentException;

final class RecurringController
{
    public function __construct(
        private View $view,
        private Config $config,
        private CompanyContext $companyContext,
        private AccountRepository $accountRepository,
        private AuthService $authService,
        private ?CategoryRepository $categoryRepository = null,
        private ?RecurringService $recurringService = null
    ) {
    }

    public function index(Request $request): Response
    {
        $rules = [];
        if ($this->recurringService !== null) {
            foreach ($this->recurringService->listForCurrentCompany() as $rule) {
                $rules[] = [
                    'id' => (int) $rule['id'],
                    'label' => (string) $rule['label'],
                    'type' => (string) $rule['type'],
                    'amount' => number_format((float) $rule['amount'], 2, '.', ',') . ' ' . (string) ($rule['currency'] ?? 'EUR'),
                    'next_date' => (string) $rule['next_execution_date'],
                    'recurrence' => (string) $rule['recurrence'],
                    'interval_value' => (int) $rule['interval_value'],
                    'account_name' => (string) $rule['account_name'],
                    'destination_account_name' => (string) $rule['destination_account_name'],
                    'category_name' => (string) $rule['category_name'],
                    'is_active' => (int) $rule['is_active'],
                ];
            }
        }

        return Response::html($this->view->render('recurring/index', [
            'appName' => $this->config->get('app.name', 'Account+'),
            'currentCompany' => $this->companyContext->current(),
            'rules' => $rules,
        ]));
    }

    public function create(Request $request): Response
    {
        if (!$this->canManageData()) {
            return Response::html($this->view->renderError(403), 403);
        }

        return Response::html($this->view->render('recurring/create', $this->viewData(null, $this->emptyValues())));
    }

    public function store(Request $request): Response
    {
        if (!$this->canManageData()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $values = $this->requestValues($request);
        if ($this->recurringService === null) {
            return Response::html($this->view->render('recurring/create', $this->viewData('Recurring rule creation is unavailable because the database connection is not ready.', $values)), 500);
        }

        try {
            $this->recurringService->create($values);
        } catch (InvalidArgumentException $exception) {
            return Response::html($this->view->render('recurring/create', $this->viewData($exception->getMessage(), $values)), 422);
        }

        flash('success', $this->view->translate('flash.recurring_created'));

        return Response::redirect('/recurring');
    }

    public function edit(Request $request): Response
    {
        if (!$this->canManageData()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $ruleId = (int) $request->input('id', 0);
        $rule = $this->recurringService !== null ? $this->recurringService->findForCurrentCompany($ruleId) : null;
        if ($rule === null) {
            return Response::redirect('/recurring');
        }

        return Response::html($this->view->render('recurring/edit', $this->viewData(null, [
            'type' => (string) ($rule['type'] ?? ''),
            'account_id' => (string) ($rule['account_id'] ?? ''),
            'destination_account_id' => (string) ($rule['destination_account_id'] ?? ''),
            'category_id' => (string) ($rule['category_id'] ?? ''),
            'label' => (string) ($rule['label'] ?? ''),
            'amount' => (string) ($rule['amount'] ?? ''),
            'recurrence' => (string) ($rule['recurrence'] ?? ''),
            'interval_value' => (string) ($rule['interval_value'] ?? '1'),
            'next_execution_date' => (string) ($rule['next_execution_date'] ?? ''),
            'end_date' => (string) ($rule['end_date'] ?? ''),
            'is_active' => (int) ($rule['is_active'] ?? 1),
        ], $ruleId)));
    }

    public function update(Request $request): Response
    {
        if (!$this->canManageData()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $ruleId = (int) $request->input('id', 0);
        $values = $this->requestValues($request);
        if ($this->recurringService === null) {
            return Response::redirect('/recurring');
        }

        try {
            $this->recurringService->update($ruleId, $values);
        } catch (InvalidArgumentException $exception) {
            return Response::html($this->view->render('recurring/edit', $this->viewData($exception->getMessage(), $values, $ruleId)), 422);
        }

        flash('success', $this->view->translate('flash.recurring_updated'));

        return Response::redirect('/recurring');
    }

    public function activate(Request $request): Response
    {
        if (!$this->canManageData()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $ruleId = (int) $request->input('id', 0);
        $active = (bool) $request->input('active', 0);
        if ($this->recurringService === null) {
            flash('error', $this->view->translate('flash.db_unavailable'));

            return Response::redirect('/recurring');
        }

        try {
            $this->recurringService->setActive($ruleId, $active);
            flash('success', $this->view->translate($active ? 'flash.recurring_activated' : 'flash.recurring_deactivated'));
        } catch (InvalidArgumentException $exception) {
            flash('error', $exception->getMessage());
        }

        return Response::redirect('/recurring');
    }

    public function delete(Request $request): Response
    {
        if (!$this->canManageData()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $ruleId = (int) $request->input('id', 0);
        if ($this->recurringService === null) {
            flash('error', $this->view->translate('flash.db_unavailable'));

            return Response::redirect('/recurring');
        }

        try {
            $this->recurringService->delete($ruleId);
            flash('success', $this->view->translate('flash.recurring_deleted'));
        } catch (InvalidArgumentException $exception) {
            flash('error', $exception->getMessage());
        }

        return Response::redirect('/recurring');
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function viewData(?string $error, array $values, int $ruleId = 0): array
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
                foreach ($this->categoryRepository->allInCompany($currentCompany->id()) as $category) {
                    $categories[] = [
                        'id' => (int) ($category['id'] ?? 0),
                        'name' => (string) ($category['name'] ?? ''),
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
            'ruleId' => $ruleId,
            'formAction' => $ruleId > 0 ? '/recurring/update' : '/recurring',
        ];
    }

    private function canManageData(): bool
    {
        return $this->authService->isAuthenticated() && $this->companyContext->current() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestValues(Request $request): array
    {
        return [
            'type' => trim((string) $request->input('type', '')),
            'account_id' => trim((string) $request->input('account_id', '')),
            'destination_account_id' => trim((string) $request->input('destination_account_id', '')),
            'category_id' => trim((string) $request->input('category_id', '')),
            'label' => trim((string) $request->input('label', '')),
            'amount' => trim((string) $request->input('amount', '')),
            'recurrence' => trim((string) $request->input('recurrence', '')),
            'interval_value' => trim((string) $request->input('interval_value', '1')),
            'next_execution_date' => trim((string) $request->input('next_execution_date', '')),
            'end_date' => trim((string) $request->input('end_date', '')),
            'is_active' => (int) $request->input('is_active', 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyValues(): array
    {
        return [
            'type' => 'expense',
            'account_id' => '',
            'destination_account_id' => '',
            'category_id' => '',
            'label' => '',
            'amount' => '',
            'recurrence' => 'monthly',
            'interval_value' => '1',
            'next_execution_date' => date('Y-m-d'),
            'end_date' => '',
            'is_active' => 1,
        ];
    }
}
