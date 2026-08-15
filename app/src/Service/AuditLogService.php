<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AuditLogRepository;

final class AuditLogService
{
    public function __construct(
        private AuditLogRepository $auditLogRepository,
        private ?AuthService $authService = null,
        private ?CompanyContext $companyContext = null
    ) {
    }

    /**
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     */
    public function log(
        string $action,
        string $entityType,
        ?string $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $companyId = null
    ): void {
        if ($companyId === null && $this->companyContext !== null) {
            $company = $this->companyContext->current();
            $companyId = $company !== null ? $company->id() : null;
        }

        $this->auditLogRepository->create([
            'company_id' => $companyId,
            'user_id' => $this->authService !== null ? $this->authService->userId() : null,
            'username' => $this->authService !== null ? $this->authService->username() : null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => \App\Http\Request::fromGlobals()->clientIp() ?: null,
        ]);
    }
}
