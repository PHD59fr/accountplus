<?php

declare(strict_types=1);

use App\Controller\ReportController;
use App\Repository\CompanyRepository;
use App\Service\AppDataService;
use App\Service\CompanyContext;

class BalanceFakeStatement extends PDOStatement
{
    public function __construct(private array $rows)
    {
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->rows !== [] ? $this->rows[0] : false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, ...$args): array
    {
        if ($mode === PDO::FETCH_COLUMN) {
            $column = (int) ($args[0] ?? 0);
            $values = [];
            foreach ($this->rows as $row) {
                $values[] = array_values($row)[$column] ?? null;
            }

            return $values;
        }

        return $this->rows;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->rows[0][array_key_first($this->rows[0])] ?? false;
    }
}

class BalanceFakePDO extends PDO
{
    public function __construct()
    {
    }

    public function prepare(string $statement, array $options = []): PDOStatement|false
    {
        if (str_contains($statement, 'SUM(initial_balance)')) {
            return new BalanceFakeStatement([['total' => '1000']]);
        }
        if (str_contains($statement, 'COALESCE(SUM(t.amount), 0)') && !str_contains($statement, 'AS income')) {
            return new BalanceFakeStatement([['total' => '250']]);
        }
        if (str_contains($statement, 'LEFT JOIN accounts a ON a.id = t.account_id')) {
            return new BalanceFakeStatement([
                [
                    'id' => 1,
                    'transaction_date' => '2026-02-05',
                    'label' => 'Course',
                    'category_name' => 'Alimentation',
                    'category_emoji' => '',
                    'amount' => '-25.50',
                    'is_reconciled' => 1,
                    'is_archived' => 0,
                    'currency' => 'EUR',
                ],
                [
                    'id' => 2,
                    'transaction_date' => '2026-02-10',
                    'label' => 'Salaire',
                    'category_name' => '',
                    'category_emoji' => '',
                    'amount' => '1500.00',
                    'is_reconciled' => 0,
                    'is_archived' => 0,
                    'currency' => 'EUR',
                ],
            ]);
        }
        if (str_contains($statement, 'AS income')) {
            return new BalanceFakeStatement([
                ['income' => '1500.00', 'expenses' => '25.5', 'net' => '1474.50', 'count' => 2],
            ]);
        }
        if (str_contains($statement, 'DISTINCT currency FROM accounts')) {
            return new BalanceFakeStatement([['currency' => 'EUR']]);
        }
        throw new RuntimeException('Unhandled prepare: ' . $statement);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (str_contains($query, 'FROM companies')) {
            return new BalanceFakeStatement([
                ['id' => 1, 'name' => 'Demo', 'legacy_filename' => 'MANUAL', 'status' => 'Active', 'account_count' => 0, 'transaction_count' => 0],
            ]);
        }
        throw new RuntimeException('Unhandled query: ' . $query);
    }
}

return [
    'reportTransactions returns opening, closing and running balances' => static function (): void {
        $pdo = new BalanceFakePDO();
        $companyContext = new CompanyContext(new CompanyRepository($pdo));
        $service = new AppDataService($pdo, $companyContext);

        $report = $service->reportTransactions('2026-02-01', '2026-02-28');

        if ((float) $report['opening_balance'] !== 1250.0) {
            throw new RuntimeException('Expected opening balance 1250.0, got ' . $report['opening_balance'] . '.');
        }
        if ((float) $report['closing_balance'] !== 2724.5) {
            throw new RuntimeException('Expected closing balance 2724.5, got ' . $report['closing_balance'] . '.');
        }

        $expectedBalances = ['1,224.50', '2,724.50'];
        foreach ($report['rows'] as $index => $row) {
            if ($row['balance'] !== $expectedBalances[$index]) {
                throw new RuntimeException('Expected running balance ' . $expectedBalances[$index] . ' on row ' . $index . ', got ' . $row['balance'] . '.');
            }
        }
    },

    'date and numeric inputs are sanitized before use' => static function (): void {
        $class = new ReflectionClass(ReportController::class);
        $controller = $class->newInstanceWithoutConstructor();

        $dateMethod = $class->getMethod('isoDateOrFallback');
        $dateMethod->setAccessible(true);
        if ($dateMethod->invoke($controller, ['nope'], '2026-01-01') !== '2026-01-01') {
            throw new RuntimeException('Array date input should fall back to the default date.');
        }
        if ($dateMethod->invoke($controller, '2026-02-30', '2026-01-01') !== '2026-01-01') {
            throw new RuntimeException('Invalid calendar date should fall back to the default date.');
        }
        if ($dateMethod->invoke($controller, ' 2026-02-03 ', '2026-01-01') !== '2026-02-03') {
            throw new RuntimeException('A trimmed valid date should be kept.');
        }

        $intMethod = $class->getMethod('positiveInt');
        $intMethod->setAccessible(true);
        if ($intMethod->invoke($controller, ['5']) !== 0) {
            throw new RuntimeException('Array category input should become 0.');
        }
        if ($intMethod->invoke($controller, '7') !== 7) {
            throw new RuntimeException('Numeric category input should be cast to int.');
        }

        $stringMethod = $class->getMethod('trimString');
        $stringMethod->setAccessible(true);
        if ($stringMethod->invoke($controller, ['x']) !== '') {
            throw new RuntimeException('Array search input should become an empty string.');
        }
    },
];
