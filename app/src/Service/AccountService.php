<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AccountRepository;
use App\Repository\CompanyRepository;
use InvalidArgumentException;

final class AccountService
{
    public function __construct(
        private AccountRepository $accountRepository,
        private CompanyContext $companyContext,
        private ?CompanyRepository $companyRepository = null,
        private ?AuthService $authService = null,
        private ?AuditLogService $auditLogService = null
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): int
    {
        $company = $this->companyContext->current();
        if ($company === null) {
            throw new InvalidArgumentException('No company available. Create a company before adding accounts.');
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Account name is required.');
        }

        $currency = strtoupper(trim((string) ($payload['currency'] ?? 'EUR')));
        if ($currency === '') {
            $currency = 'EUR';
        }

        $initialBalance = trim((string) ($payload['initial_balance'] ?? '0.00'));
        if ($initialBalance === '') {
            $initialBalance = '0.00';
        }

        if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/', $initialBalance)) {
            throw new InvalidArgumentException('Initial balance must use a decimal format like 0.00.');
        }

        $accountId = $this->accountRepository->create([
            'company_id' => $company->id(),
            'user_id' => $this->authService !== null ? $this->authService->userId() : null,
            'name' => $name,
            'bank_name' => trim((string) ($payload['bank_name'] ?? '')),
            'account_number' => trim((string) ($payload['account_number'] ?? '')),
            'description' => trim((string) ($payload['description'] ?? '')),
            'currency' => $currency,
            'initial_balance' => $initialBalance,
        ]);

        if ($this->companyRepository !== null) {
            $this->companyRepository->updateCounters($company->id());
        }

        if ($this->auditLogService !== null) {
            $this->auditLogService->log(
                'account.create',
                'account',
                (string) $accountId,
                null,
                [
                    'company_id' => $company->id(),
                    'name' => $name,
                    'bank_name' => trim((string) ($payload['bank_name'] ?? '')),
                    'account_number' => trim((string) ($payload['account_number'] ?? '')),
                    'currency' => $currency,
                    'initial_balance' => $initialBalance,
                ],
                $company->id()
            );
        }

        return $accountId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForCurrentCompany(int $accountId): ?array
    {
        $company = $this->companyContext->current();
        if ($company === null) {
            return null;
        }

        return $this->accountRepository->findInCompany($accountId, $company->id());
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(int $accountId, array $payload): void
    {
        $company = $this->companyContext->current();
        if ($company === null) {
            throw new InvalidArgumentException('No company available. Select a company before editing accounts.');
        }

        $existing = $this->accountRepository->findInCompany($accountId, $company->id());
        if ($existing === null) {
            throw new InvalidArgumentException('Account not found in the current company.');
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Account name is required.');
        }

        $currency = strtoupper(trim((string) ($payload['currency'] ?? 'EUR')));
        if ($currency === '') {
            $currency = 'EUR';
        }

        $initialBalance = trim((string) ($payload['initial_balance'] ?? '0.00'));
        if ($initialBalance === '') {
            $initialBalance = '0.00';
        }

        if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/', $initialBalance)) {
            throw new InvalidArgumentException('Initial balance must use a decimal format like 0.00.');
        }

        $this->accountRepository->update([
            'id' => $accountId,
            'company_id' => $company->id(),
            'name' => $name,
            'bank_name' => trim((string) ($payload['bank_name'] ?? '')),
            'account_number' => trim((string) ($payload['account_number'] ?? '')),
            'description' => trim((string) ($payload['description'] ?? '')),
            'currency' => $currency,
            'initial_balance' => $initialBalance,
        ]);

        if ($this->companyRepository !== null) {
            $this->companyRepository->updateCounters($company->id());
        }

        if ($this->auditLogService !== null) {
            $this->auditLogService->log(
                'account.update',
                'account',
                (string) $accountId,
                [
                    'name' => (string) ($existing['name'] ?? ''),
                    'bank_name' => (string) ($existing['bank_name'] ?? ''),
                    'account_number' => (string) ($existing['account_number'] ?? ''),
                    'description' => (string) ($existing['description'] ?? ''),
                    'currency' => (string) ($existing['currency'] ?? ''),
                    'initial_balance' => (string) ($existing['initial_balance'] ?? '0.00'),
                ],
                [
                    'name' => $name,
                    'bank_name' => trim((string) ($payload['bank_name'] ?? '')),
                    'account_number' => trim((string) ($payload['account_number'] ?? '')),
                    'description' => trim((string) ($payload['description'] ?? '')),
                    'currency' => $currency,
                    'initial_balance' => $initialBalance,
                ],
                $company->id()
            );
        }
    }

    public function archive(int $accountId, bool $archived = true): void
    {
        $company = $this->companyContext->current();
        if ($company === null) {
            throw new InvalidArgumentException('No company available. Select a company before archiving accounts.');
        }

        $existing = $this->accountRepository->findInCompany($accountId, $company->id());
        if ($existing === null) {
            throw new InvalidArgumentException('Account not found in the current company.');
        }

        $this->accountRepository->setArchived($accountId, $company->id(), $archived);

        if ($this->companyRepository !== null) {
            $this->companyRepository->updateCounters($company->id());
        }

        if ($this->auditLogService !== null) {
            $this->auditLogService->log(
                $archived ? 'account.archive' : 'account.unarchive',
                'account',
                (string) $accountId,
                ['is_archived' => (int) ($existing['is_archived'] ?? 0)],
                ['is_archived' => $archived ? 1 : 0],
                $company->id()
            );
        }
    }

    public function delete(int $accountId): void
    {
        $company = $this->companyContext->current();
        if ($company === null) {
            throw new InvalidArgumentException('No company available. Select a company before deleting accounts.');
        }

        $existing = $this->accountRepository->findInCompany($accountId, $company->id());
        if ($existing === null) {
            throw new InvalidArgumentException('Account not found in the current company.');
        }

        if ($this->accountRepository->hasTransactions($accountId)) {
            throw new InvalidArgumentException('This account has transactions and cannot be deleted. Archive it instead.');
        }

        $this->accountRepository->delete($accountId, $company->id());

        if ($this->companyRepository !== null) {
            $this->companyRepository->updateCounters($company->id());
        }

        if ($this->auditLogService !== null) {
            $this->auditLogService->log(
                'account.delete',
                'account',
                (string) $accountId,
                [
                    'name' => (string) ($existing['name'] ?? ''),
                    'currency' => (string) ($existing['currency'] ?? ''),
                    'initial_balance' => (string) ($existing['initial_balance'] ?? '0.00'),
                ],
                null,
                $company->id()
            );
        }
    }
}
