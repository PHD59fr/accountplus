<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Company;
use App\Repository\CompanyRepository;
use App\Repository\UserCompanyRepository;

final class CompanyContext
{
    private const SESSION_KEY = 'php_compte_company_id';

    public function __construct(
        private ?CompanyRepository $companyRepository,
        private ?UserCompanyRepository $userCompanyRepository = null,
        private ?AuthService $authService = null
    ) {
    }

    public function current(): ?Company
    {
        if ($this->companyRepository === null) {
            return null;
        }

        $companies = $this->availableCompanies();
        if ($companies === []) {
            return null;
        }

        $selectedId = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_numeric($selectedId)) {
            foreach ($companies as $company) {
                if ($company->id() === (int) $selectedId) {
                    return $company;
                }
            }
        }

        $company = $companies[0];
        $_SESSION[self::SESSION_KEY] = $company->id();

        return $company;
    }

    public function select(int $companyId): bool
    {
        foreach ($this->availableCompanies() as $company) {
            if ($company->id() === $companyId) {
                $_SESSION[self::SESSION_KEY] = $companyId;

                return true;
            }
        }

        return false;
    }

    public function clear(int $companyId): void
    {
        if (isset($_SESSION[self::SESSION_KEY]) && (int) $_SESSION[self::SESSION_KEY] === $companyId) {
            unset($_SESSION[self::SESSION_KEY]);
        }
    }

    /**
     * @return list<Company>
     */
    public function availableCompanies(): array
    {
        if ($this->companyRepository === null) {
            return [];
        }

        if ($this->authService === null || $this->authService->isAdmin()) {
            return $this->companyRepository->all();
        }

        $userId = $this->authService->userId();
        if ($userId === null || $this->userCompanyRepository === null) {
            return [];
        }

        return $this->companyRepository->byIds($this->userCompanyRepository->companyIdsForUser($userId));
    }
}
