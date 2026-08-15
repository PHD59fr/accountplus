<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;
use App\Service\AuthService;
use App\Service\AuditLogService;
use App\Service\CompanyContext;
use App\Support\Config;
use App\Support\View;
use InvalidArgumentException;

final class CategoryController
{
    public function __construct(
        private View $view,
        private Config $config,
        private CompanyContext $companyContext,
        private AuthService $authService,
        private ?CategoryRepository $categoryRepository = null,
        private ?AuditLogService $auditLogService = null,
        private ?TransactionRepository $transactionRepository = null
    ) {
    }

    public function index(Request $request): Response
    {
        $categories = [];
        if ($this->categoryRepository !== null) {
            $company = $this->companyContext->current();
            if ($company !== null) {
                $categories = $this->categoryRepository->allInCompany($company->id());
            }
        }

        return Response::html($this->view->render('categories/index', [
            'appName' => $this->config->get('app.name', 'Account+'),
            'currentCompany' => $this->companyContext->current(),
            'categories' => $categories,
        ]));
    }

    public function create(Request $request): Response
    {
        if (!$this->canManageData()) {
            return Response::html($this->view->renderError(403), 403);
        }

        return Response::html($this->view->render('categories/create', $this->viewData(null, $this->emptyValues())));
    }

    public function store(Request $request): Response
    {
        if (!$this->canManageData()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $values = $this->requestValues($request);
        $company = $this->companyContext->current();
        if ($company === null) {
            return Response::html($this->view->render('categories/create', $this->viewData('No company selected.', $values)), 422);
        }
        $values['company_id'] = $company->id();
        if ($this->categoryRepository === null) {
            return Response::html($this->view->render('categories/create', $this->viewData('Category creation is unavailable because the database connection is not ready.', $values)), 500);
        }

        try {
            $this->validateValues($values, $company->id());
            $categoryId = $this->categoryRepository->create($values);
            $this->auditLogService?->log('category.create', 'category', (string) $categoryId, null, $values);
        } catch (InvalidArgumentException $exception) {
            return Response::html($this->view->render('categories/create', $this->viewData($exception->getMessage(), $values)), 422);
        }

        flash('success', $this->view->translate('flash.category_created'));

        return Response::redirect('/categories');
    }

    public function edit(Request $request): Response
    {
        if (!$this->canManageData()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $categoryId = (int) $request->input('id', 0);
        $company = $this->companyContext->current();
        $category = null;
        if ($company !== null && $this->categoryRepository !== null) {
            $category = $this->categoryRepository->findInCompany($categoryId, $company->id());
        }
        if ($category === null) {
            return Response::redirect('/categories');
        }

        return Response::html($this->view->render('categories/edit', $this->viewData(null, [
            'name' => (string) ($category['name'] ?? ''),
            'emoji' => (string) ($category['emoji'] ?? ''),
            'category_type' => (string) ($category['category_type'] ?? ''),
            'parent_id' => ($category['parent_id'] ?? null) !== null ? (string) $category['parent_id'] : '',
            'is_active' => (int) ($category['is_active'] ?? 1),
            'sort_order' => (string) ($category['sort_order'] ?? 0),
        ], $categoryId)));
    }

    public function update(Request $request): Response
    {
        if (!$this->canManageData()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $categoryId = (int) $request->input('id', 0);
        $values = $this->requestValues($request);
        if ($this->categoryRepository === null) {
            return Response::redirect('/categories');
        }

        $company = $this->companyContext->current();
        if ($company === null) {
            return Response::redirect('/categories');
        }

        $existing = $this->categoryRepository->findInCompany($categoryId, $company->id());
        if ($existing === null) {
            return Response::redirect('/categories');
        }

        try {
            $values['id'] = $categoryId;
            $values['company_id'] = $company->id();
            $this->validateValues($values, $company->id(), $categoryId);
            $this->categoryRepository->update($values);
            $this->auditLogService?->log('category.update', 'category', (string) $categoryId, $existing, $values);
        } catch (InvalidArgumentException $exception) {
            return Response::html($this->view->render('categories/edit', $this->viewData($exception->getMessage(), $values, $categoryId)), 422);
        }

        flash('success', $this->view->translate('flash.category_updated'));

        return Response::redirect('/categories');
    }

    public function delete(Request $request): Response
    {
        if (!$this->canManageData()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $categoryId = (int) $request->input('id', 0);
        $company = $this->companyContext->current();
        if ($this->categoryRepository === null || $company === null) {
            flash('error', $this->view->translate('flash.db_unavailable'));

            return Response::redirect('/categories');
        }

        $existing = $this->categoryRepository->findInCompany($categoryId, $company->id());
        if ($existing === null) {
            flash('error', 'Category not found.');
            return Response::redirect('/categories');
        }

        if ($this->transactionRepository !== null && $this->transactionRepository->countByCategory($categoryId, $company->id()) > 0) {
            flash('error', $this->view->translate('categories.error_in_use'));
            return Response::redirect('/categories');
        }

        try {
            $this->categoryRepository->delete($categoryId, $company->id());
            $this->auditLogService?->log('category.delete', 'category', (string) $categoryId, $existing, null);
            flash('success', $this->view->translate('flash.category_deleted'));
        } catch (InvalidArgumentException $exception) {
            flash('error', $exception->getMessage());
        }

        return Response::redirect('/categories');
    }

    private function canManageData(): bool
    {
        return $this->authService->isAuthenticated() && $this->companyContext->current() !== null;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function viewData(?string $error, array $values, int $categoryId = 0): array
    {
        $currentCompany = $this->companyContext->current();
        $parentCategories = [];
        if ($currentCompany !== null && $this->categoryRepository !== null) {
            foreach ($this->categoryRepository->allInCompany($currentCompany->id()) as $cat) {
                if ((int) ($cat['id'] ?? 0) === $categoryId) {
                    continue;
                }
                $parentCategories[] = [
                    'id' => (int) ($cat['id'] ?? 0),
                    'name' => (string) ($cat['name'] ?? ''),
                    'emoji' => (string) ($cat['emoji'] ?? ''),
                ];
            }
        }

        return [
            'appName' => $this->config->get('app.name', 'Account+'),
            'currentCompany' => $currentCompany,
            'parentCategories' => $parentCategories,
            'error' => $error,
            'values' => $values,
            'categoryId' => $categoryId,
            'formAction' => $categoryId > 0 ? '/categories/update' : '/categories',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestValues(Request $request): array
    {
        return [
            'name' => trim((string) $request->input('name', '')),
            'emoji' => trim((string) $request->input('emoji', '')),
            'category_type' => trim((string) $request->input('category_type', '')),
            'parent_id' => trim((string) $request->input('parent_id', '')),
            'is_active' => (int) $request->input('is_active', 1),
            'sort_order' => trim((string) $request->input('sort_order', '0')),
        ];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function validateValues(array $values, int $companyId, int $categoryId = 0): void
    {
        if (trim((string) ($values['name'] ?? '')) === '') {
            throw new InvalidArgumentException($this->view->translate('categories.error_name_required'));
        }

        $emoji = trim((string) ($values['emoji'] ?? ''));
        if ($emoji !== '' && strlen($emoji) > 64) {
            throw new InvalidArgumentException($this->view->translate('categories.error_emoji'));
        }

        $parentValue = trim((string) ($values['parent_id'] ?? ''));
        if ($parentValue === '') {
            return;
        }
        if (!ctype_digit($parentValue) || (int) $parentValue <= 0 || $this->categoryRepository === null) {
            throw new InvalidArgumentException($this->view->translate('categories.error_parent_invalid'));
        }

        $parentId = (int) $parentValue;
        $visited = [];
        while ($parentId > 0) {
            if ($parentId === $categoryId || isset($visited[$parentId])) {
                throw new InvalidArgumentException($this->view->translate('categories.error_parent_cycle'));
            }
            $visited[$parentId] = true;

            $parent = $this->categoryRepository->findInCompany($parentId, $companyId);
            if ($parent === null) {
                throw new InvalidArgumentException($this->view->translate('categories.error_parent_invalid'));
            }
            $parentId = (int) ($parent['parent_id'] ?? 0);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyValues(): array
    {
        return [
            'name' => '',
            'emoji' => '',
            'category_type' => '',
            'parent_id' => '',
            'is_active' => 1,
            'sort_order' => '0',
        ];
    }
}
