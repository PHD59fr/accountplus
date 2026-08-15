<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class TransferRepository
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO transfers (
                company_id,
                source_account_id,
                destination_account_id,
                amount,
                transaction_date,
                label,
                created_at,
                updated_at
            ) VALUES (
                :company_id,
                :source_account_id,
                :destination_account_id,
                :amount,
                :transaction_date,
                :label,
                NOW(),
                NOW()
            )'
        );

        $statement->execute([
            'company_id' => (int) $payload['company_id'],
            'source_account_id' => (int) $payload['source_account_id'],
            'destination_account_id' => (int) $payload['destination_account_id'],
            'amount' => (string) $payload['amount'],
            'transaction_date' => (string) $payload['transaction_date'],
            'label' => (string) $payload['label'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
