<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class UserCompanyRepository
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function assign(int $userId, int $companyId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT IGNORE INTO user_companies (user_id, company_id, created_at)
             VALUES (:user_id, :company_id, NOW())'
        );
        $statement->execute([
            'user_id' => $userId,
            'company_id' => $companyId,
        ]);
    }

    /**
     * @return list<int>
     */
    public function companyIdsForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT company_id FROM user_companies WHERE user_id = :user_id ORDER BY company_id ASC'
        );
        $statement->execute(['user_id' => $userId]);

        $ids = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $companyId) {
            $ids[] = (int) $companyId;
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    public function userIdsForCompany(int $companyId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT user_id FROM user_companies WHERE company_id = :company_id ORDER BY user_id ASC'
        );
        $statement->execute(['company_id' => $companyId]);

        $ids = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $userId) {
            $ids[] = (int) $userId;
        }

        return $ids;
    }
}
