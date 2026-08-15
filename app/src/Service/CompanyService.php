<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CompanyRepository;
use App\Repository\UserCompanyRepository;
use App\Repository\UserRepository;
use InvalidArgumentException;

final class CompanyService
{
    public function __construct(
        private CompanyRepository $companyRepository,
        private UserCompanyRepository $userCompanyRepository,
        private CompanyContext $companyContext,
        private AuthService $authService,
        private ?AuditLogService $auditLogService = null,
        private ?UserRepository $userRepository = null
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): int
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Company name is required.');
        }

        $sourceLabel = trim((string) ($payload['source_label'] ?? 'MANUAL'));
        if ($sourceLabel === '') {
            $sourceLabel = 'MANUAL';
        }

        $companyId = $this->companyRepository->create($name, $sourceLabel, 'Active');
        $userId = $this->authService->userId();
        if ($userId !== null) {
            $this->userCompanyRepository->assign($userId, $companyId);
        }

        $this->companyContext->select($companyId);

        if ($this->auditLogService !== null) {
            $this->auditLogService->log(
                'company.create',
                'company',
                (string) $companyId,
                null,
                [
                    'name' => $name,
                    'source_label' => $sourceLabel,
                    'status' => 'Active',
                ],
                $companyId
            );
        }

        return $companyId;
    }

    public function update(int $companyId, string $name, string $status): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Company name is required.');
        }

        $status = trim($status);
        if ($status === '') {
            $status = 'Active';
        }

        $existing = $this->companyRepository->find($companyId);
        $this->companyRepository->update($companyId, $name, $status);

        if ($this->auditLogService !== null) {
            $this->auditLogService->log(
                'company.update',
                'company',
                (string) $companyId,
                $existing !== null ? [
                    'name' => $existing->name(),
                    'status' => $existing->status(),
                ] : null,
                [
                    'name' => $name,
                    'status' => $status,
                ],
                $companyId
            );
        }
    }

    public function delete(int $companyId): void
    {
        $existing = $this->companyRepository->find($companyId);
        if ($existing === null) {
            throw new InvalidArgumentException('Company not found.');
        }

        if ($this->companyRepository->count() <= 1) {
            throw new InvalidArgumentException('Cannot delete the last company.');
        }

        if ($this->auditLogService !== null) {
            $this->auditLogService->log(
                'company.delete',
                'company',
                (string) $companyId,
                [
                    'name' => $existing->name(),
                    'status' => $existing->status(),
                ],
                null,
                $companyId
            );
        }

        $this->companyRepository->delete($companyId);
        $this->companyContext->clear($companyId);
    }

    /**
     * Creates the demo company when the companies table is empty and attaches
     * it to the current user (or the first active administrator).
     */
    public function ensureDemoCompany(): int
    {
        if ($this->companyRepository->count() > 0) {
            return 0;
        }

        $companyId = $this->companyRepository->create('Sample company', 'SAMPLE-COMPANY', 'Active');

        $userId = $this->authService->userId();
        if ($userId === null) {
            $userId = $this->userRepository?->firstActiveAdmin()?->id() ?? 0;
        }

        if ($userId > 0) {
            $this->userCompanyRepository->assign($userId, $companyId);
        }

        $this->companyContext->select($companyId);

        if ($this->auditLogService !== null) {
            $this->auditLogService->log(
                'company.create',
                'company',
                (string) $companyId,
                null,
                [
                    'name' => 'Sample company',
                    'source_label' => 'SAMPLE-COMPANY',
                    'status' => 'Active',
                ],
                $companyId
            );
        }

        return $companyId;
    }
}
