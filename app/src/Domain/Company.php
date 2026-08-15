<?php

declare(strict_types=1);

namespace App\Domain;

final class Company
{
    public function __construct(
        private int $id,
        private string $name,
        private string $legacyFilename,
        private string $status,
        private int $accountCount,
        private int $transactionCount
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function legacyFilename(): string
    {
        return $this->legacyFilename;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function accountCount(): int
    {
        return $this->accountCount;
    }

    public function transactionCount(): int
    {
        return $this->transactionCount;
    }
}
