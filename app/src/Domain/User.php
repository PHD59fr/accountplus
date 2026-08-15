<?php

declare(strict_types=1);

namespace App\Domain;

final class User
{
    public function __construct(
        private int $id,
        private string $username,
        private string $passwordHash,
        private string $displayName,
        private string $role,
        private bool $isActive,
        private string $locale = 'en',
        private ?string $lastLoginAt = null
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function lastLoginAt(): ?string
    {
        return $this->lastLoginAt;
    }
}
