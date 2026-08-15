<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AccountRepository;
use App\Repository\CategoryRepository;
use App\Repository\CompanyRepository;
use App\Repository\RecurringRuleRepository;
use App\Repository\TransactionRepository;
use App\Repository\TransferRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

final class RecurringService
{
    private const TYPES = ['income', 'expense', 'transfer'];
    private const RECURRENCES = ['daily', 'weekly', 'monthly', 'yearly'];

    public function __construct(
        private RecurringRuleRepository $recurringRuleRepository,
        private AccountRepository $accountRepository,
        private TransactionRepository $transactionRepository,
        private CompanyContext $companyContext,
        private PDO $pdo,
        private ?CategoryRepository $categoryRepository = null,
        private ?CompanyRepository $companyRepository = null,
        private ?AuditLogService $auditLogService = null,
        private ?TransferRepository $transferRepository = null
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): int
    {
        $company = $this->companyContext->current();
        if ($company === null) {
            throw new InvalidArgumentException('No company selected.');
        }

        $normalized = $this->normalize($payload, $company->id());
        $ruleId = $this->recurringRuleRepository->create($normalized);

        if ($this->auditLogService !== null) {
            $this->auditLogService->log(
                'recurring.create',
                'recurring_rule',
                (string) $ruleId,
                null,
                $normalized,
                $company->id()
            );
        }

        return $ruleId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForCurrentCompany(int $ruleId): ?array
    {
        $company = $this->companyContext->current();
        if ($company === null) {
            return null;
        }

        return $this->recurringRuleRepository->findInCompany($ruleId, $company->id());
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(int $ruleId, array $payload): void
    {
        $company = $this->companyContext->current();
        if ($company === null) {
            throw new InvalidArgumentException('No company selected.');
        }

        $existing = $this->recurringRuleRepository->findInCompany($ruleId, $company->id());
        if ($existing === null) {
            throw new InvalidArgumentException('Recurring rule not found in the current company.');
        }

        $normalized = $this->normalize($payload, $company->id());
        $normalized['id'] = $ruleId;
        $this->recurringRuleRepository->update($normalized);

        if ($this->auditLogService !== null) {
            $this->auditLogService->log(
                'recurring.update',
                'recurring_rule',
                (string) $ruleId,
                $existing,
                $normalized,
                $company->id()
            );
        }
    }

    public function setActive(int $ruleId, bool $active): void
    {
        $company = $this->companyContext->current();
        if ($company === null) {
            throw new InvalidArgumentException('No company selected.');
        }

        if ($this->recurringRuleRepository->findInCompany($ruleId, $company->id()) === null) {
            throw new InvalidArgumentException('Recurring rule not found in the current company.');
        }

        $this->recurringRuleRepository->setActive($ruleId, $company->id(), $active);

        if ($this->auditLogService !== null) {
            $this->auditLogService->log(
                $active ? 'recurring.activate' : 'recurring.deactivate',
                'recurring_rule',
                (string) $ruleId,
                ['is_active' => $active ? 0 : 1],
                ['is_active' => $active ? 1 : 0],
                $company->id()
            );
        }
    }

    public function delete(int $ruleId): void
    {
        $company = $this->companyContext->current();
        if ($company === null) {
            throw new InvalidArgumentException('No company selected.');
        }

        if ($this->recurringRuleRepository->findInCompany($ruleId, $company->id()) === null) {
            throw new InvalidArgumentException('Recurring rule not found in the current company.');
        }

        $this->recurringRuleRepository->delete($ruleId, $company->id());

        if ($this->auditLogService !== null) {
            $this->auditLogService->log(
                'recurring.delete',
                'recurring_rule',
                (string) $ruleId,
                null,
                null,
                $company->id()
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForCurrentCompany(): array
    {
        $company = $this->companyContext->current();
        if ($company === null) {
            return [];
        }

        return $this->recurringRuleRepository->allInCompany($company->id());
    }

    /**
     * Process all due recurring rules for every company.
     *
     * @return array<string, mixed>
     */
    public function processDue(?string $referenceDate = null, ?int $companyId = null): array
    {
        $referenceDate = $referenceDate ?? date('Y-m-d');
        if (!$this->isIsoDate($referenceDate)) {
            throw new InvalidArgumentException('Reference date must use the YYYY-MM-DD format.');
        }

        $companies = $companyId !== null ? $this->companyRepository?->byIds([$companyId]) ?? [] : $this->companyRepository?->all() ?? [];

        $transactionsCreated = 0;
        $rulesProcessed = 0;
        $errors = [];

        foreach ($companies as $company) {
            try {
                $result = $this->processCompany($company->id(), $referenceDate);
                $transactionsCreated += $result['transactions_created'];
                $rulesProcessed += $result['rules_processed'];
                foreach ($result['errors'] as $error) {
                    $errors[] = [
                        'company_id' => $company->id(),
                        'rule_id' => $error['rule_id'],
                        'message' => $error['message'],
                    ];
                }
            } catch (\Throwable $throwable) {
                $errors[] = [
                    'company_id' => $company->id(),
                    'message' => $throwable->getMessage(),
                ];
            }
        }

        return [
            'reference_date' => $referenceDate,
            'transactions_created' => $transactionsCreated,
            'rules_processed' => $rulesProcessed,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function processCompany(int $companyId, string $referenceDate): array
    {
        $rules = $this->recurringRuleRepository->dueRules($companyId, $referenceDate);
        $created = 0;
        $processed = 0;
        $errors = [];

        foreach ($rules as $candidate) {
            try {
                $this->pdo->beginTransaction();
                $rule = $this->recurringRuleRepository->findDueForUpdate((int) $candidate['id'], $companyId, $referenceDate);
                if ($rule === null) {
                    $this->pdo->commit();
                    continue;
                }

                $nextDate = (string) $rule['next_execution_date'];
                $ruleProcessed = 0;
                $createdForRule = 0;
                $finished = false;

                while ($nextDate <= $referenceDate) {
                    if ($rule['end_date'] !== null && $rule['end_date'] !== '' && $nextDate > (string) $rule['end_date']) {
                        $finished = true;
                        break;
                    }

                    if ($ruleProcessed >= 366) {
                        break;
                    }

                    $createdForRule += $this->createTransactionForRule($rule, $nextDate);
                    $ruleProcessed++;
                    $nextDate = $this->advance((string) $rule['recurrence'], (int) $rule['interval_value'], $nextDate);
                }

                if (!$finished && $rule['end_date'] !== null && $rule['end_date'] !== '' && $nextDate > (string) $rule['end_date']) {
                    $finished = true;
                }

                if ($finished) {
                    $this->recurringRuleRepository->setActive((int) $rule['id'], $companyId, false);
                }

                $this->recurringRuleRepository->setNextExecutionDate((int) $rule['id'], $companyId, $nextDate);
                if ($this->companyRepository !== null) {
                    $this->companyRepository->updateCounters($companyId);
                }

                $this->pdo->commit();
                $created += $createdForRule;
                $processed++;
            } catch (\Throwable $throwable) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $errors[] = [
                    'rule_id' => (int) ($candidate['id'] ?? 0),
                    'message' => $throwable->getMessage(),
                ];
            }
        }

        return [
            'transactions_created' => $created,
            'rules_processed' => $processed,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function createTransactionForRule(array $rule, string $date): int
    {
        $amount = (string) $rule['amount'];
        if ((string) $rule['type'] === 'expense') {
            $amount = '-' . ltrim($amount, '-');
        } elseif ((string) $rule['type'] === 'income') {
            $amount = ltrim($amount, '-');
        }

        if ((string) $rule['type'] === 'transfer') {
            $destinationAccountId = $rule['destination_account_id'] !== null ? (int) $rule['destination_account_id'] : null;

            return $this->createTransfer((int) $rule['account_id'], $destinationAccountId, $rule, $date, $amount);
        }

        $this->transactionRepository->create([
            'company_id' => (int) $rule['company_id'],
            'account_id' => (int) $rule['account_id'],
            'category_id' => $rule['category_id'],
            'transaction_date' => $date,
            'value_date' => '',
            'label' => (string) $rule['label'],
            'description' => '',
            'amount' => $amount,
            'is_reconciled' => 0,
            'recurring_rule_id' => (int) $rule['id'],
        ]);

        return 1;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function createTransfer(int $sourceAccountId, ?int $destinationAccountId, array $rule, string $date, string $amount): int
    {
        if ($destinationAccountId === null || $sourceAccountId === $destinationAccountId) {
            throw new InvalidArgumentException('Recurring transfers require two distinct accounts.');
        }
        if ($this->transferRepository === null) {
            throw new \LogicException('A transfer repository is required to process recurring transfers.');
        }

        $transferId = $this->transferRepository->create([
            'company_id' => (int) $rule['company_id'],
            'source_account_id' => $sourceAccountId,
            'destination_account_id' => $destinationAccountId,
            'amount' => ltrim($amount, '-'),
            'transaction_date' => $date,
            'label' => (string) $rule['label'],
        ]);

        $this->transactionRepository->create([
            'company_id' => (int) $rule['company_id'],
            'account_id' => $sourceAccountId,
            'category_id' => $rule['category_id'],
            'transaction_date' => $date,
            'value_date' => '',
            'label' => (string) $rule['label'],
            'description' => '',
            'amount' => '-' . ltrim($amount, '-'),
            'is_reconciled' => 0,
            'transfer_id' => $transferId,
            'recurring_rule_id' => (int) $rule['id'],
        ]);

        $this->transactionRepository->create([
            'company_id' => (int) $rule['company_id'],
            'account_id' => $destinationAccountId,
            'category_id' => $rule['category_id'],
            'transaction_date' => $date,
            'value_date' => '',
            'label' => (string) $rule['label'],
            'description' => '',
            'amount' => ltrim($amount, '-'),
            'is_reconciled' => 0,
            'transfer_id' => $transferId,
            'recurring_rule_id' => (int) $rule['id'],
        ]);

        return 2;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalize(array $payload, int $companyId): array
    {
        $accountId = (int) ($payload['account_id'] ?? 0);
        if ($accountId <= 0 || $this->accountRepository->findInCompany($accountId, $companyId) === null) {
            throw new InvalidArgumentException('Select a valid account.');
        }

        $type = (string) ($payload['type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Select a valid rule type.');
        }

        $destinationAccountId = (int) ($payload['destination_account_id'] ?? 0);
        if ($type === 'transfer') {
            if ($destinationAccountId <= 0 || $this->accountRepository->findInCompany($destinationAccountId, $companyId) === null) {
                throw new InvalidArgumentException('Select a valid destination account for transfers.');
            }
            if ($destinationAccountId === $accountId) {
                throw new InvalidArgumentException('Source and destination accounts must be different.');
            }
        } else {
            $destinationAccountId = 0;
        }

        $categoryId = (int) ($payload['category_id'] ?? 0);
        if ($categoryId > 0 && $this->categoryRepository !== null && $this->categoryRepository->findInCompany($categoryId, $companyId) === null) {
            throw new InvalidArgumentException('Select a valid category.');
        }

        $label = trim((string) ($payload['label'] ?? ''));
        if ($label === '') {
            throw new InvalidArgumentException('Label is required.');
        }

        $amount = trim((string) ($payload['amount'] ?? ''));
        if ($amount === '' || !preg_match('/^-?\d+(?:\.\d{1,2})?$/', $amount)) {
            throw new InvalidArgumentException('Amount must use a decimal format like -45.90 or 152.45.');
        }

        if ((float) $amount === 0.0) {
            throw new InvalidArgumentException('Amount cannot be zero.');
        }

        $recurrence = (string) ($payload['recurrence'] ?? '');
        if (!in_array($recurrence, self::RECURRENCES, true)) {
            throw new InvalidArgumentException('Select a valid recurrence.');
        }

        $intervalValue = (int) ($payload['interval_value'] ?? 1);
        if ($intervalValue < 1 || $intervalValue > 365) {
            throw new InvalidArgumentException('Interval must be between 1 and 365.');
        }

        $nextExecutionDate = trim((string) ($payload['next_execution_date'] ?? ''));
        if (!$this->isIsoDate($nextExecutionDate)) {
            throw new InvalidArgumentException('Next execution date is required.');
        }

        $endDate = trim((string) ($payload['end_date'] ?? ''));
        if ($endDate !== '' && !$this->isIsoDate($endDate)) {
            throw new InvalidArgumentException('End date must use the YYYY-MM-DD format.');
        }
        if ($endDate !== '' && $endDate < $nextExecutionDate) {
            throw new InvalidArgumentException('End date cannot be earlier than the next execution date.');
        }

        return [
            'company_id' => $companyId,
            'account_id' => $accountId,
            'destination_account_id' => $destinationAccountId > 0 ? $destinationAccountId : null,
            'category_id' => $categoryId > 0 ? $categoryId : null,
            'type' => $type,
            'label' => $label,
            'amount' => $amount,
            'recurrence' => $recurrence,
            'interval_value' => $intervalValue,
            'next_execution_date' => $nextExecutionDate,
            'end_date' => $endDate !== '' ? $endDate : null,
            'is_active' => !empty($payload['is_active']) ? 1 : 0,
        ];
    }

    private function advance(string $recurrence, int $interval, string $date): string
    {
        $iso = new DateTimeImmutable($date);

        return match ($recurrence) {
            'daily' => $iso->modify('+' . $interval . ' days')->format('Y-m-d'),
            'weekly' => $iso->modify('+' . ($interval * 7) . ' days')->format('Y-m-d'),
            'monthly' => $this->addMonths($date, $interval),
            'yearly' => $this->addMonths($date, $interval * 12),
            default => $date,
        };
    }

    private function addMonths(string $date, int $months): string
    {
        $iso = new DateTimeImmutable($date);
        $day = (int) $iso->format('j');
        $year = (int) $iso->format('Y');
        $month = (int) $iso->format('n');

        $total = $year * 12 + ($month - 1) + $months;
        $newYear = intdiv($total, 12);
        $newMonth = ($total % 12) + 1;
        $lastDay = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $newYear, $newMonth)))->format('t');

        return sprintf('%04d-%02d-%02d', $newYear, $newMonth, min($day, $lastDay));
    }

    private function isIsoDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
    }
}
