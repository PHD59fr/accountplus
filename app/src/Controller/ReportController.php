<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Service\AppDataService;
use App\Service\AuthService;
use App\Service\CompanyContext;
use App\Support\Config;
use App\Support\View;

final class ReportController
{
    public function __construct(
        private View $view,
        private Config $config,
        private AppDataService $dataService,
        private CompanyContext $companyContext,
        private AuthService $authService,
        private string $storagePath = ''
    ) {
    }

    public function transactions(Request $request): Response
    {
        if (!$this->canManageData()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $company = $this->companyContext->current();
        $dateFrom = $this->isoDateOrFallback($request->input('date_from'), date('Y-m-01'));
        $dateTo = $this->isoDateOrFallback($request->input('date_to'), date('Y-m-d'));
        $accountId = $this->positiveInt($request->input('account'));
        $categoryId = $this->positiveInt($request->input('category_id'));
        $search = $this->trimString($request->input('q'));
        $includeArchived = $request->input('include_archived') !== '0'; // default true

        $report = $this->dataService->reportTransactions($dateFrom, $dateTo, $categoryId > 0 ? $categoryId : null, $search, $accountId > 0 ? $accountId : null, $includeArchived);

        $breakdown = $this->dataService->categoryBreakdown(
            $accountId > 0 ? $accountId : null,
            $dateFrom,
            $dateTo,
            $categoryId > 0 ? $categoryId : null,
            $search,
            $includeArchived
        );

        return Response::html($this->view->render('reports/transactions', [
            'appName' => $this->config->get('app.name', 'Account+'),
            'currentCompany' => $company,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'accountId' => $accountId,
            'accounts' => $this->dataService->accountsTable(),
            'categoryId' => $categoryId,
            'search' => $search,
            'includeArchived' => $includeArchived,
            'rows' => $report['rows'],
            'totals' => $report['totals'],
            'currency' => $report['currency'],
            'openingBalance' => (float) ($report['opening_balance'] ?? 0),
            'closingBalance' => (float) ($report['closing_balance'] ?? 0),
            'categories' => $this->dataService->categoriesForReport(),
            'breakdown' => $breakdown,
        ]));
    }

    public function export(Request $request): Response
    {
        if (!$this->canManageData()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $dateFrom = $this->isoDateOrFallback($request->input('date_from'), date('Y-m-01'));
        $dateTo = $this->isoDateOrFallback($request->input('date_to'), date('Y-m-d'));
        $accountId = $this->positiveInt($request->input('account'));
        $categoryId = $this->positiveInt($request->input('category_id'));
        $search = $this->trimString($request->input('q'));
        $includeArchived = $request->input('include_archived') !== '0'; // default true

        $report = $this->dataService->reportTransactions($dateFrom, $dateTo, $categoryId > 0 ? $categoryId : null, $search, $accountId > 0 ? $accountId : null, $includeArchived);

        $csv = fopen('php://temp/maxmemory:1048576', 'r+');
        fputcsv($csv, ['date', 'label', 'category', 'amount', 'balance', 'currency', 'reconciled'], ';', '"', '\\');

        foreach ($report['rows'] as $row) {
            $rawAmount = (float) str_replace(',', '', $row['amount']);
            $rawBalance = (float) str_replace(',', '', $row['balance']);
            fputcsv($csv, [
                $this->csvText((string) $row['date']),
                $this->csvText((string) $row['label']),
                $this->csvText((string) $row['category']),
                $rawAmount,
                $rawBalance,
                $this->csvText((string) $row['currency']),
                (int) $row['is_reconciled'] === 1 ? '1' : '0',
            ], ';', '"', '\\');
        }

        $opening = (float) ($report['opening_balance'] ?? 0);
        $closing = (float) ($report['closing_balance'] ?? $opening);
        $currency = $this->csvText((string) ($report['currency'] ?? 'EUR'));

        fputcsv($csv, [
            $this->view->translate('reports.opening_balance') . ' (' . $dateFrom . ')',
            '',
            '',
            '',
            $opening,
            $currency,
            '',
        ], ';', '"', '\\');
        fputcsv($csv, [
            $this->view->translate('reports.closing_balance') . ' (' . $dateTo . ')',
            '',
            '',
            '',
            $closing,
            $currency,
            '',
        ], ';', '"', '\\');

        rewind($csv);
        $content = stream_get_contents($csv);
        fclose($csv);

        $filename = 'transactions-' . $dateFrom . '-' . $dateTo . '.csv';

        return Response::file($content, $filename, 'text/csv; charset=utf-8');
    }

    private function csvText(string $value): string
    {
        return preg_match('/^\s*[=+\-@]/u', $value) === 1 ? "'" . $value : $value;
    }

    private function isIsoDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
    }

    private function isoDateOrFallback(mixed $value, string $fallback): string
    {
        if (!is_string($value)) {
            return $fallback;
        }

        $value = trim($value);

        return $this->isIsoDate($value) ? $value : $fallback;
    }

    private function positiveInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function trimString(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function canManageData(): bool
    {
        return $this->authService !== null && $this->authService->isAuthenticated() && $this->companyContext->current() !== null;
    }
}
