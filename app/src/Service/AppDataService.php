<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

final class AppDataService
{
    public function __construct(
        private ?PDO $pdo,
        private ?CompanyContext $companyContext = null
    ) {
    }

    private function currentCompanyId(): ?int
    {
        if ($this->companyContext === null) {
            return null;
        }

        $company = $this->companyContext->current();

        return $company !== null ? $company->id() : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function accountCards(): array
    {
        if ($this->pdo === null) {
            return [];
        }

        $companyId = $this->currentCompanyId();
        if ($companyId === null) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT a.id, a.name, a.currency, a.initial_balance, a.is_archived, COALESCE(SUM(t.amount), 0) AS movement_total
             FROM accounts a
             LEFT JOIN transactions t ON t.account_id = a.id AND t.is_archived = 0
             WHERE a.company_id = :company_id AND a.is_archived = 0
             GROUP BY a.id, a.name, a.currency, a.initial_balance, a.is_archived
             ORDER BY a.name ASC
             LIMIT 6'
        );
        $statement->execute(['company_id' => $companyId]);

        $cards = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $balance = (float) $row['initial_balance'] + (float) $row['movement_total'];
            $cards[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'balance' => number_format($balance, 2, '.', ',') . ' ' . (string) ($row['currency'] ?? 'EUR'),
                'status_key' => ((int) $row['is_archived'] === 1) ? 'status.archived' : 'status.active',
            ];
        }

        return $cards;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function recentTransactions(): array
    {
        if ($this->pdo === null) {
            return [];
        }

        $companyId = $this->currentCompanyId();
        if ($companyId === null) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT t.id, t.account_id, t.transaction_date, t.label, t.amount, COALESCE(a.currency, "EUR") AS currency
             FROM transactions t
             INNER JOIN accounts a ON a.id = t.account_id
             WHERE t.company_id = :company_id AND a.is_archived = 0 AND t.is_archived = 0
             ORDER BY t.transaction_date DESC, t.id DESC
             LIMIT 5'
        );
        $statement->execute(['company_id' => $companyId]);

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[] = [
                'id' => (int) $row['id'],
                'account_id' => (int) $row['account_id'],
                'date' => (string) $row['transaction_date'],
                'label' => (string) $row['label'],
                'amount' => number_format((float) $row['amount'], 2, '.', ',') . ' ' . (string) ($row['currency'] ?? 'EUR'),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    public function metrics(): array
    {
        if ($this->pdo === null) {
            return [
                'globalBalance' => '0.00 EUR',
                'monthlyIncome' => '0.00 EUR',
                'monthlyExpenses' => '0.00 EUR',
                'netCashFlow' => '0.00 EUR',
                'upcomingRecurring' => 0,
            ];
        }

        $companyId = $this->currentCompanyId();
        if ($companyId === null) {
            return [
                'globalBalance' => '0.00 EUR',
                'monthlyIncome' => '0.00 EUR',
                'monthlyExpenses' => '0.00 EUR',
                'netCashFlow' => '0.00 EUR',
                'upcomingRecurring' => 0,
            ];
        }

        $statement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(initial_balance), 0) FROM accounts WHERE company_id = :company_id AND is_archived = 0'
        );
        $statement->execute(['company_id' => $companyId]);
        $accountsBalance = (float) $statement->fetchColumn();

        $statement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(t.amount), 0) FROM transactions t
             INNER JOIN accounts a ON a.id = t.account_id
             WHERE t.company_id = :company_id AND a.is_archived = 0 AND t.is_archived = 0'
        );
        $statement->execute(['company_id' => $companyId]);
        $transactionBalance = (float) $statement->fetchColumn();
        $globalBalance = $accountsBalance + $transactionBalance;

        $statement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(t.amount), 0) FROM transactions t
             INNER JOIN accounts a ON a.id = t.account_id
             WHERE t.company_id = :company_id AND a.is_archived = 0 AND t.amount > 0 AND t.is_archived = 0 AND DATE_FORMAT(t.transaction_date, "%Y-%m") = DATE_FORMAT(CURDATE(), "%Y-%m")'
        );
        $statement->execute(['company_id' => $companyId]);
        $monthlyIncome = (float) $statement->fetchColumn();

        $statement = $this->pdo->prepare(
            'SELECT ABS(COALESCE(SUM(t.amount), 0)) FROM transactions t
             INNER JOIN accounts a ON a.id = t.account_id
             WHERE t.company_id = :company_id AND a.is_archived = 0 AND t.amount < 0 AND t.is_archived = 0 AND DATE_FORMAT(t.transaction_date, "%Y-%m") = DATE_FORMAT(CURDATE(), "%Y-%m")'
        );
        $statement->execute(['company_id' => $companyId]);
        $monthlyExpenses = (float) $statement->fetchColumn();

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM recurring_rules WHERE company_id = :company_id AND is_active = 1'
        );
        $statement->execute(['company_id' => $companyId]);
        $upcomingRecurring = (int) $statement->fetchColumn();

        $currency = $this->defaultCurrency($companyId);

        return [
            'globalBalance' => number_format($globalBalance, 2, '.', ',') . ' ' . $currency,
            'monthlyIncome' => number_format($monthlyIncome, 2, '.', ',') . ' ' . $currency,
            'monthlyExpenses' => number_format($monthlyExpenses, 2, '.', ',') . ' ' . $currency,
            'netCashFlow' => number_format($monthlyIncome - $monthlyExpenses, 2, '.', ',') . ' ' . $currency,
            'upcomingRecurring' => $upcomingRecurring,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function accountsTable(): array
    {
        if ($this->pdo === null) {
            return [];
        }

        $companyId = $this->currentCompanyId();
        if ($companyId === null) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT a.id, a.name, a.bank_name, a.currency, a.initial_balance, a.is_archived,
                    COALESCE(SUM(CASE WHEN t.is_archived = 0 THEN t.amount ELSE 0 END), 0) AS movement_total, COUNT(t.id) AS transaction_count
             FROM accounts a
             LEFT JOIN transactions t ON t.account_id = a.id
             WHERE a.company_id = :company_id
             GROUP BY a.id, a.name, a.bank_name, a.currency, a.initial_balance, a.is_archived
             ORDER BY a.name ASC'
        );
        $statement->execute(['company_id' => $companyId]);

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $balance = (float) $row['initial_balance'] + (float) $row['movement_total'];
            $rows[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'bank' => (string) ($row['bank_name'] ?? ''),
                'balance' => number_format($balance, 2, '.', ',') . ' ' . (string) ($row['currency'] ?? 'EUR'),
                'transactions' => (int) $row['transaction_count'],
                'is_archived' => (int) $row['is_archived'],
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function transactionsTable(int $page = 1, string $search = '', int $perPage = 50, ?int $accountId = null): array
    {
        if ($this->pdo === null) {
            return [];
        }

        $companyId = $this->currentCompanyId();
        if ($companyId === null) {
            return [];
        }

        $conditions = ['t.company_id = :company_id'];
        $parameters = ['company_id' => $companyId];
        if ($search !== '') {
            $conditions[] = 't.label LIKE :search';
            $parameters['search'] = '%' . $this->escapeLike($search) . '%';
        }
        if ($accountId !== null && $accountId > 0) {
            $conditions[] = 't.account_id = :account_id';
            $parameters['account_id'] = $accountId;
        }

        $where = implode(' AND ', $conditions);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $statement = $this->pdo->prepare(
            'SELECT t.id, t.account_id, t.transfer_id, t.transaction_date, t.label, COALESCE(CASE WHEN c.parent_id IS NOT NULL AND cp.name IS NOT NULL THEN CONCAT(cp.name, " › ", c.name) ELSE c.name END, "Uncategorized") AS category_name, COALESCE(c.emoji, "") AS category_emoji, t.amount, t.is_reconciled, t.is_archived, COALESCE(a.currency, "EUR") AS currency
             FROM transactions t
             LEFT JOIN categories c ON c.id = t.category_id
             LEFT JOIN categories cp ON cp.id = c.parent_id
             INNER JOIN accounts a ON a.id = t.account_id
             WHERE ' . $where . '
             ORDER BY t.transaction_date DESC, t.id DESC
             LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset
        );
        $statement->execute($parameters);

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $categoryName = (string) $row['category_name'];
            $rows[] = [
                'id' => (int) $row['id'],
                'account_id' => (int) $row['account_id'],
                'date' => (string) $row['transaction_date'],
                'label' => (string) $row['label'],
                'category' => $categoryName === 'Uncategorized' ? '' : $categoryName,
                'category_emoji' => $categoryName === 'Uncategorized' ? '' : (string) ($row['category_emoji'] ?? ''),
                'category_key' => $categoryName === 'Uncategorized' ? 'status.uncategorized' : '',
                'is_transfer' => !empty($row['transfer_id']),
                'amount' => number_format((float) $row['amount'], 2, '.', ',') . ' ' . (string) ($row['currency'] ?? 'EUR'),
                'status_key' => ((int) $row['is_reconciled'] === 1) ? 'status.reconciled' : 'status.pending',
                'is_archived' => (int) $row['is_archived'],
            ];
        }

        return $rows;
    }

    public function transactionCount(string $search = '', ?int $accountId = null): int
    {
        if ($this->pdo === null) {
            return 0;
        }

        $companyId = $this->currentCompanyId();
        if ($companyId === null) {
            return 0;
        }

        $conditions = ['company_id = :company_id'];
        $parameters = ['company_id' => $companyId];
        if ($search !== '') {
            $conditions[] = 'label LIKE :search';
            $parameters['search'] = '%' . $this->escapeLike($search) . '%';
        }
        if ($accountId !== null && $accountId > 0) {
            $conditions[] = 'account_id = :account_id';
            $parameters['account_id'] = $accountId;
        }

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM transactions WHERE ' . implode(' AND ', $conditions)
        );
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function recurringRules(): array
    {
        if ($this->pdo === null) {
            return [];
        }

        $companyId = $this->currentCompanyId();
        if ($companyId === null) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT r.id, r.label, r.type, r.amount, r.recurrence, r.interval_value, r.is_active, r.next_execution_date, COALESCE(a.currency, "EUR") AS currency
             FROM recurring_rules r
             LEFT JOIN accounts a ON a.id = r.account_id
             WHERE r.company_id = :company_id
             ORDER BY r.next_execution_date ASC, r.id ASC'
        );
        $statement->execute(['company_id' => $companyId]);

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[] = [
                'id' => (int) $row['id'],
                'label' => (string) $row['label'],
                'type' => (string) $row['type'],
                'amount' => number_format((float) $row['amount'], 2, '.', ',') . ' ' . (string) ($row['currency'] ?? 'EUR'),
                'recurrence' => (string) $row['recurrence'],
                'interval_value' => (int) $row['interval_value'],
                'is_active' => (int) $row['is_active'],
                'next_date' => (string) $row['next_execution_date'],
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function reportCards(): array
    {
        $metrics = $this->metrics();

        return [
            ['title_key' => 'reports.card_monthly_income', 'value' => $metrics['monthlyIncome']],
            ['title_key' => 'reports.card_monthly_expenses', 'value' => $metrics['monthlyExpenses']],
            ['title_key' => 'reports.card_net_cash_flow', 'value' => $metrics['netCashFlow']],
            ['title_key' => 'reports.card_active_accounts_total', 'value' => $this->activeAccountsTotal()],
        ];
    }

    private function activeAccountsTotal(): string
    {
        if ($this->pdo === null) {
            return '0.00 EUR';
        }

        $companyId = $this->currentCompanyId();
        if ($companyId === null) {
            return '0.00 EUR';
        }

        $statement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(initial_balance), 0) + COALESCE((
                SELECT SUM(t.amount)
                FROM transactions t
                INNER JOIN accounts a ON a.id = t.account_id
                WHERE a.company_id = :company_id AND a.is_archived = 0 AND t.is_archived = 0
            ), 0)
            FROM accounts
            WHERE company_id = :company_id AND is_archived = 0'
        );
        $statement->execute(['company_id' => $companyId]);
        $value = (float) $statement->fetchColumn();

        return number_format($value, 2, '.', ',') . ' ' . $this->defaultCurrency($companyId);
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }

    private function defaultCurrency(int $companyId): string
    {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT currency FROM accounts WHERE company_id = :company_id AND currency IS NOT NULL LIMIT 2'
        );
        $statement->execute(['company_id' => $companyId]);

        $currencies = array_values(array_filter(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)), static fn (string $c): bool => $c !== ''));

        return count($currencies) === 1 ? $currencies[0] : 'EUR';
    }

    /**
     * @return array<string, mixed>
     */
    public function reportTransactions(string $dateFrom, string $dateTo, ?int $categoryId = null, string $search = '', ?int $accountId = null, bool $includeArchived = false): array
    {
        if ($this->pdo === null) {
            return [
                'rows' => [],
                'totals' => ['income' => 0.0, 'expenses' => 0.0, 'net' => 0.0, 'count' => 0],
                'currency' => 'EUR',
                'opening_balance' => 0.0,
                'closing_balance' => 0.0,
            ];
        }

        $companyId = $this->currentCompanyId();
        if ($companyId === null) {
            return [
                'rows' => [],
                'totals' => ['income' => 0.0, 'expenses' => 0.0, 'net' => 0.0, 'count' => 0],
                'currency' => 'EUR',
                'opening_balance' => 0.0,
                'closing_balance' => 0.0,
            ];
        }

        $conditions = ['t.company_id = :company_id', 'DATE(t.transaction_date) >= :date_from', 'DATE(t.transaction_date) <= :date_to'];
        $parameters = ['company_id' => $companyId, 'date_from' => $dateFrom, 'date_to' => $dateTo];
        if ($accountId !== null && $accountId > 0) {
            $conditions[] = 't.account_id = :account_id';
            $parameters['account_id'] = $accountId;
        }
        if ($categoryId !== null && $categoryId > 0) {
            $conditions[] = 't.category_id = :category_id';
            $parameters['category_id'] = $categoryId;
        }
        if ($search !== '') {
            $conditions[] = 't.label LIKE :search';
            $parameters['search'] = '%' . $this->escapeLike($search) . '%';
        }
        if ($includeArchived !== true) {
            $conditions[] = 't.is_archived = 0';
            $conditions[] = 'a.is_archived = 0';
        }

        $where = implode(' AND ', $conditions);

        $statement = $this->pdo->prepare(
            'SELECT t.id, t.transaction_date, t.label, 
                    COALESCE(CASE WHEN c.parent_id IS NOT NULL AND cp.name IS NOT NULL 
                              THEN CONCAT(cp.name, " › ", c.name) ELSE c.name END, "") AS category_name, 
                    COALESCE(c.emoji, "") AS category_emoji, 
                    t.amount, t.is_reconciled, t.is_archived, 
                    COALESCE(a.currency, "EUR") AS currency
FROM transactions t
             LEFT JOIN categories c ON c.id = t.category_id
             LEFT JOIN categories cp ON cp.id = c.parent_id
             LEFT JOIN accounts a ON a.id = t.account_id
             WHERE ' . $where . '
             ORDER BY t.transaction_date ASC, t.id ASC'
        );
        $statement->execute($parameters);

        $openingBalance = $this->openingBalance($companyId, $dateFrom, $accountId, $includeArchived);
        $running = $openingBalance;

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $amount = (float) $row['amount'];
            $running += $amount;

            $rows[] = [
                'id' => (int) $row['id'],
                'date' => (string) $row['transaction_date'],
                'label' => (string) $row['label'],
                'category' => (string) ($row['category_name'] ?? ''),
                'category_emoji' => (string) ($row['category_emoji'] ?? ''),
                'amount' => number_format($amount, 2, '.', ','),
                'balance' => number_format($running, 2, '.', ','),
                'is_reconciled' => (int) $row['is_reconciled'],
                'is_archived' => (int) $row['is_archived'],
                'currency' => (string) ($row['currency'] ?? 'EUR'),
            ];
        }

        $sumStatement = $this->pdo->prepare(
            'SELECT
                COALESCE(SUM(CASE WHEN t.amount > 0 THEN t.amount ELSE 0 END), 0) AS income,
                ABS(COALESCE(SUM(CASE WHEN t.amount < 0 THEN t.amount ELSE 0 END), 0)) AS expenses,
                COALESCE(SUM(t.amount), 0) AS net,
                COUNT(*) AS count
             FROM transactions t
             INNER JOIN accounts a ON a.id = t.account_id
             WHERE ' . $where
        );
        $sumStatement->execute($parameters);
        $totals = $sumStatement->fetch();

        return [
            'rows' => $rows,
            'totals' => [
                'income' => (float) ($totals['income'] ?? 0),
                'expenses' => (float) ($totals['expenses'] ?? 0),
                'net' => (float) ($totals['net'] ?? 0),
                'count' => (int) ($totals['count'] ?? 0),
            ],
            'currency' => (string) ($rows !== [] ? ($rows[0]['currency'] ?? 'EUR') : $this->defaultCurrency($companyId)),
            'opening_balance' => $openingBalance,
            'closing_balance' => $openingBalance + (float) ($totals['net'] ?? 0),
        ];
    }

    /**
     * Category and type breakdown for one account (or the whole company) over a
     * date range. Percentages are relative to the account's total movement.
     *
     * @return array<string, mixed>
     */
    public function categoryBreakdown(?int $accountId, string $dateFrom, string $dateTo, ?int $categoryId = null, string $search = '', bool $includeArchived = false): array
    {
        $empty = [
            'currency' => 'EUR',
            'total_income' => 0.0,
            'total_expense' => 0.0,
            'total_abs' => 0.0,
            'categories' => [],
            'by_type' => ['income' => 0.0, 'expense' => 0.0, 'transfer' => 0.0],
        ];

        if ($this->pdo === null) {
            return $empty;
        }

        $companyId = $this->currentCompanyId();
        if ($companyId === null) {
            return $empty;
        }

        $conditions = ['t.company_id = :company_id', 'DATE(t.transaction_date) >= :date_from', 'DATE(t.transaction_date) <= :date_to'];
        $parameters = ['company_id' => $companyId, 'date_from' => $dateFrom, 'date_to' => $dateTo];
        if (!$includeArchived) {
            $conditions[] = 't.is_archived = 0';
            $conditions[] = 'a.is_archived = 0';
        }
        if ($accountId !== null && $accountId > 0) {
            $conditions[] = 't.account_id = :account_id';
            $parameters['account_id'] = $accountId;
        }
        if ($categoryId !== null && $categoryId > 0) {
            $conditions[] = 't.category_id = :category_id';
            $parameters['category_id'] = $categoryId;
        }
        if ($search !== '') {
            $conditions[] = 't.label LIKE :search';
            $parameters['search'] = '%' . $this->escapeLike($search) . '%';
        }

        $where = implode(' AND ', $conditions);

        $statement = $this->pdo->prepare(
            'SELECT COALESCE(c.id, 0) AS category_id,
                    COALESCE(CASE WHEN c.parent_id IS NOT NULL AND cp.name IS NOT NULL THEN CONCAT(cp.name, " › ", c.name) ELSE c.name END, "Non catégorisé") AS category_path,
                    COALESCE(c.emoji, "") AS emoji,
                    COALESCE(c.category_type, "") AS category_type,
                    SUM(t.amount) AS total
             FROM transactions t
             LEFT JOIN categories c ON c.id = t.category_id
             LEFT JOIN categories cp ON cp.id = c.parent_id
             INNER JOIN accounts a ON a.id = t.account_id
             WHERE ' . $where . '
             GROUP BY category_id, category_path, emoji, category_type
             ORDER BY ABS(SUM(t.amount)) DESC'
        );
        $statement->execute($parameters);

        $categories = [];
        $totalIncome = 0.0;
        $totalExpense = 0.0;
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $total = (float) $row['total'];
            if ($total > 0) {
                $totalIncome += $total;
            } elseif ($total < 0) {
                $totalExpense += -$total;
            }

            $categories[] = [
                'id' => (int) $row['category_id'],
                'name' => (string) $row['category_path'],
                'emoji' => (string) ($row['emoji'] ?? ''),
                'type' => (string) ($row['category_type'] ?? ''),
                'total' => $total,
            ];
        }

        $totalAbs = $totalIncome + $totalExpense;
        foreach ($categories as &$category) {
            $category['percent'] = $totalAbs > 0 ? (abs($category['total']) / $totalAbs) * 100 : 0.0;
        }
        unset($category);

        // Gross movements keep balanced transfers and refunds visible. Imported
        // categories may have no type, so fall back to the amount's sign.
        $byTypeStatement = $this->pdo->prepare(
            'SELECT CASE
                        WHEN t.transfer_id IS NOT NULL THEN "transfer"
                        WHEN LOWER(TRIM(c.category_type)) IN ("income", "expense", "transfer") THEN LOWER(TRIM(c.category_type))
                        WHEN t.amount > 0 THEN "income"
                        ELSE "expense"
                    END AS movement_type,
                    SUM(ABS(t.amount)) AS total
             FROM transactions t
             LEFT JOIN categories c ON c.id = t.category_id
             INNER JOIN accounts a ON a.id = t.account_id
             WHERE ' . $where . '
             GROUP BY movement_type'
        );
        $byTypeStatement->execute($parameters);
        $byType = ['income' => 0.0, 'expense' => 0.0, 'transfer' => 0.0];
        foreach ($byTypeStatement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $type = (string) ($row['movement_type'] ?? '');
            $amount = (float) $row['total'];
            if ($type === 'income') {
                $byType['income'] += $amount;
            } elseif ($type === 'expense') {
                $byType['expense'] += $amount;
            } elseif ($type === 'transfer') {
                $byType['transfer'] += abs($amount);
            }
        }

        return [
            'currency' => $this->defaultCurrency($companyId),
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
            'total_abs' => $totalAbs,
            'categories' => $categories,
            'by_type' => $byType,
        ];
    }

    /**
     * Gross movements by transaction label for the current company's active
     * accounts. Keep the ten largest groups and combine the remainder.
     *
     * @return array{currency: string, total_abs: float, labels: list<array{label: ?string, total: float, percent: float, other: bool}>}
     */
    public function labelBreakdown(): array
    {
        $empty = ['currency' => 'EUR', 'total_abs' => 0.0, 'labels' => []];
        if ($this->pdo === null || ($companyId = $this->currentCompanyId()) === null) {
            return $empty;
        }

        $from = ' FROM transactions t
                  INNER JOIN accounts a ON a.id = t.account_id
                  WHERE t.company_id = :company_id AND a.company_id = :account_company_id
                    AND t.is_archived = 0 AND a.is_archived = 0';
        $parameters = ['company_id' => $companyId, 'account_company_id' => $companyId];
        $totals = $this->pdo->prepare('SELECT COALESCE(SUM(ABS(t.amount)), 0)' . $from);
        $totals->execute($parameters);
        $total = (float) $totals->fetchColumn();
        if ($total <= 0) {
            return $empty;
        }

        $statement = $this->pdo->prepare(
            'SELECT NULLIF(TRIM(t.label), "") AS label, SUM(ABS(t.amount)) AS total' . $from . '
             GROUP BY NULLIF(TRIM(t.label), "")
             HAVING SUM(ABS(t.amount)) > 0
             ORDER BY total DESC, label ASC
             LIMIT 10'
        );
        $statement->execute($parameters);
        $labels = [];
        $displayed = 0.0;
        foreach ($statement->fetchAll() as $row) {
            $amount = (float) $row['total'];
            $displayed += $amount;
            $labels[] = [
                'label' => $row['label'] !== null ? (string) $row['label'] : null,
                'total' => $amount,
                'percent' => $amount / $total * 100,
                'other' => false,
            ];
        }
        $remaining = round($total - $displayed, 2);
        if ($remaining > 0) {
            $labels[] = ['label' => null, 'total' => $remaining, 'percent' => $remaining / $total * 100, 'other' => true];
        }

        return ['currency' => $this->defaultCurrency($companyId), 'total_abs' => $total, 'labels' => $labels];
    }

    /**
     * Balance of the company just before the given date, from the initial
     * balances of the active accounts plus all non-archived transactions.
     */
    private function openingBalance(int $companyId, string $dateFrom, ?int $accountId = null, bool $includeArchived = false): float
    {
        $accountWhere = $accountId !== null && $accountId > 0 ? ' AND id = :account_id' : '';
        $accountParams = $accountId !== null && $accountId > 0 ? ['company_id' => $companyId, 'account_id' => $accountId] : ['company_id' => $companyId];

        $accountsStatement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(initial_balance), 0)
             FROM accounts
             WHERE company_id = :company_id' . ($includeArchived ? '' : ' AND is_archived = 0') . $accountWhere
        );
        $accountsStatement->execute($accountParams);

        $opening = (float) $accountsStatement->fetchColumn();

        $txWhere = 't.company_id = :company_id AND DATE(t.transaction_date) < :date_from';
        $txParams = ['company_id' => $companyId, 'date_from' => $dateFrom];
        if (!$includeArchived) {
            $txWhere .= ' AND t.is_archived = 0 AND a.is_archived = 0';
        }
        if ($accountId !== null && $accountId > 0) {
            $txWhere .= ' AND t.account_id = :account_id';
            $txParams['account_id'] = $accountId;
        }

        $transactionsStatement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(t.amount), 0)
             FROM transactions t
             LEFT JOIN accounts a ON a.id = t.account_id
             WHERE ' . $txWhere
        );
        $transactionsStatement->execute($txParams);

        return $opening + (float) $transactionsStatement->fetchColumn();
    }

    /**
     * @return array<int, array<string, int|string>>
     */
    public function categoriesForReport(): array
    {
        if ($this->pdo === null) {
            return [];
        }

        $companyId = $this->currentCompanyId();
        if ($companyId === null) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT id, parent_id, name FROM categories WHERE company_id = :company_id AND is_active = 1 ORDER BY name ASC'
        );
        $statement->execute(['company_id' => $companyId]);

        $rows = [];
        $nameById = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = (int) $row['id'];
            $nameById[$id] = (string) ($row['name'] ?? '');
            $rows[] = [
                'id' => $id,
                'parent_id' => isset($row['parent_id']) && $row['parent_id'] !== '' ? (int) $row['parent_id'] : null,
                'name' => (string) ($row['name'] ?? ''),
            ];
        }

        foreach ($rows as &$row) {
            $row['path'] = ($row['parent_id'] !== null && isset($nameById[$row['parent_id']]))
                ? $nameById[$row['parent_id']] . ' › ' . $row['name']
                : $row['name'];
        }
        unset($row);

        return $rows;
    }
}
