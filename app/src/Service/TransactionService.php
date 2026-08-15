<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AccountRepository;
use App\Repository\CategoryRepository;
use App\Repository\CompanyRepository;
use App\Repository\TransactionRepository;
use InvalidArgumentException;

final class TransactionService
{
    public function __construct(
        private TransactionRepository $transactionRepository,
        private AccountRepository $accountRepository,
        private CompanyContext $companyContext,
        private ?CompanyRepository $companyRepository = null,
        private ?AuditLogService $auditLogService = null,
        private ?CategoryRepository $categoryRepository = null
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
        $transactionId = $this->transactionRepository->create($normalized);

        if ($this->companyRepository !== null) {
            $this->companyRepository->updateCounters($company->id());
        }

        if ($this->auditLogService !== null) {
            $this->auditLogService->log(
                'transaction.create',
                'transaction',
                (string) $transactionId,
                null,
                $normalized,
                $company->id()
            );
        }

        return $transactionId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForCurrentCompany(int $transactionId): ?array
    {
        $company = $this->companyContext->current();
        if ($company === null) {
            return null;
        }

        return $this->transactionRepository->findInCompany($transactionId, $company->id());
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(int $transactionId, array $payload): void
    {
        $companyId = $this->requireCompanyId();
        $normalized = $this->normalize($payload, $companyId);
        $this->transactionRepository->transactional(function () use ($transactionId, $normalized, $companyId): void {
            $entries = $this->entriesForMutation($transactionId, $companyId);
            $existing = $entries[0];
            $updated = $normalized + ['id' => $transactionId];

            if (count($entries) === 2) {
                $paired = $entries[1];
                $isSource = str_starts_with((string) $existing['amount'], '-');
                if ($isSource !== str_starts_with($updated['amount'], '-')) {
                    throw new InvalidArgumentException('A transfer entry must keep its debit or credit sign.');
                }
                if ($updated['account_id'] === (int) $paired['account_id']) {
                    throw new InvalidArgumentException('Source and destination accounts must be different.');
                }

                // Reconciliation, category and description remain specific to each account.
                $pairedUpdate = array_replace($paired, [
                    'amount' => $isSource ? ltrim($updated['amount'], '-') : '-' . $updated['amount'],
                    'transaction_date' => $updated['transaction_date'],
                    'value_date' => $updated['value_date'],
                    'label' => $updated['label'],
                ]);
                $this->transactionRepository->update($pairedUpdate);
                $this->auditChange('transaction.update', $paired, $pairedUpdate, $companyId);
                $this->transactionRepository->updateTransfer([
                    'id' => (int) $existing['transfer_id'],
                    'company_id' => $companyId,
                    'source_account_id' => $isSource ? $updated['account_id'] : (int) $paired['account_id'],
                    'destination_account_id' => $isSource ? (int) $paired['account_id'] : $updated['account_id'],
                    'amount' => ltrim($updated['amount'], '-'),
                    'transaction_date' => $updated['transaction_date'],
                    'label' => $updated['label'],
                ]);
            }

            $this->transactionRepository->update($updated);
            $this->auditChange('transaction.update', $existing, $updated, $companyId);
            $this->companyRepository?->updateCounters($companyId);
        });
    }

    public function archive(int $transactionId, bool $archived = true): void
    {
        $companyId = $this->requireCompanyId();
        $this->transactionRepository->transactional(function () use ($transactionId, $archived, $companyId): void {
            foreach ($this->entriesForMutation($transactionId, $companyId) as $entry) {
                $this->transactionRepository->setArchived((int) $entry['id'], $companyId, $archived);
                $this->auditChange(
                    $archived ? 'transaction.archive' : 'transaction.unarchive',
                    $entry,
                    ['is_archived' => $archived ? 1 : 0],
                    $companyId
                );
            }
            $this->companyRepository?->updateCounters($companyId);
        });
    }

    public function delete(int $transactionId): void
    {
        $companyId = $this->requireCompanyId();
        $this->transactionRepository->transactional(function () use ($transactionId, $companyId): void {
            $entries = $this->entriesForMutation($transactionId, $companyId);
            foreach ($entries as $entry) {
                $this->transactionRepository->delete((int) $entry['id'], $companyId);
                $this->auditChange('transaction.delete', $entry, null, $companyId);
            }
            if (!empty($entries[0]['transfer_id'])) {
                $this->transactionRepository->deleteTransfer((int) $entries[0]['transfer_id'], $companyId);
            }
            $this->companyRepository?->updateCounters($companyId);
        });
    }

    private function requireCompanyId(): int
    {
        $company = $this->companyContext->current();
        if ($company === null) {
            throw new InvalidArgumentException('No company selected.');
        }

        return $company->id();
    }

    /** @return list<array<string, mixed>> selected entry first, then its counterpart */
    private function entriesForMutation(int $transactionId, int $companyId): array
    {
        $entry = $this->transactionRepository->findInCompany($transactionId, $companyId);
        if ($entry === null) {
            throw new InvalidArgumentException('Transaction not found in the current company.');
        }
        if (empty($entry['transfer_id'])) {
            $locked = $this->transactionRepository->findInCompany($transactionId, $companyId, true);
            if ($locked === null) {
                throw new InvalidArgumentException('Transaction not found in the current company.');
            }

            return [$locked];
        }

        // Always lock the shared transfer before its entries, whichever side was selected.
        $transferId = (int) $entry['transfer_id'];
        $transfer = $this->transactionRepository->lockTransfer($transferId, $companyId);
        $entries = $this->transactionRepository->transferEntriesForUpdate($transferId, $companyId);
        if ($transfer === null || count($entries) !== 2) {
            throw new InvalidArgumentException('The transfer is incomplete and must be repaired before editing.');
        }
        if ((int) $entries[0]['id'] !== $transactionId) {
            $entries = array_reverse($entries);
        }
        if ((int) $entries[0]['id'] !== $transactionId
            || str_starts_with((string) $entries[0]['amount'], '-') === str_starts_with((string) $entries[1]['amount'], '-')
            || (int) $entries[0]['account_id'] === (int) $entries[1]['account_id']) {
            throw new InvalidArgumentException('The transfer entries are inconsistent and must be repaired before editing.');
        }

        return $entries;
    }

    private function auditChange(string $action, array $before, ?array $after, int $companyId): void
    {
        $this->auditLogService?->log($action, 'transaction', (string) $before['id'], $before, $after, $companyId);
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

        $categoryId = isset($payload['category_id']) && $payload['category_id'] !== '' ? (int) $payload['category_id'] : null;
        if ($categoryId !== null && $this->categoryRepository !== null && $this->categoryRepository->findInCompany($categoryId, $companyId) === null) {
            throw new InvalidArgumentException('Select a valid category.');
        }

        $transactionDate = trim((string) ($payload['transaction_date'] ?? ''));
        if (!$this->isIsoDate($transactionDate)) {
            throw new InvalidArgumentException('Transaction date is required.');
        }

        $valueDate = trim((string) ($payload['value_date'] ?? ''));
        if ($valueDate !== '' && !$this->isIsoDate($valueDate)) {
            throw new InvalidArgumentException('Value date must use the YYYY-MM-DD format.');
        }
        if ($valueDate === '') {
            $valueDate = $transactionDate;
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

        return [
            'company_id' => $companyId,
            'account_id' => $accountId,
            'category_id' => $categoryId,
            'transaction_date' => $transactionDate,
            'value_date' => $valueDate,
            'label' => $label,
            'description' => trim((string) ($payload['description'] ?? '')),
            'amount' => $amount,
            'is_reconciled' => !empty($payload['is_reconciled']) ? 1 : 0,
        ];
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
