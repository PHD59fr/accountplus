<?php

declare(strict_types=1);

use App\Repository\UserCompanyRepository;
use App\Repository\UserRepository;
use App\Service\AdminService;

/**
 * @return array<string, callable>
 */
return [
    'rejects an empty date format' => static function (): void {
        expectAdminInvalidArgument(
            static fn () => adminValidationService()->updateDateFormat(''),
            'Date format is required.'
        );
    },

    'rejects an unsafe date format' => static function (): void {
        expectAdminInvalidArgument(
            static fn () => adminValidationService()->updateDateFormat('Y-m-d<script>'),
            'Invalid date format.'
        );
    },

    'rejects an empty username' => static function (): void {
        expectAdminInvalidArgument(
            static fn () => adminValidationService()->createUser('', 'Jane', 'password123', 'USER'),
            'Username is required.'
        );
    },

    'rejects a short password' => static function (): void {
        expectAdminInvalidArgument(
            static fn () => adminValidationService()->createUser('jane', 'Jane', 'short', 'USER'),
            'Password must contain at least 8 characters.'
        );
    },

    'rejects invalid role and assignment identifiers' => static function (): void {
        $service = adminValidationService();
        expectAdminInvalidArgument(
            static fn () => $service->updateRole(1, 'OWNER'),
            'Role must be ADMIN or USER.'
        );
        expectAdminInvalidArgument(
            static fn () => $service->assignCompany(0, 0),
            'Select a user and a company.'
        );
    },
];

function adminValidationService(): AdminService
{
    $userRepository = (new ReflectionClass(UserRepository::class))->newInstanceWithoutConstructor();
    $userCompanyRepository = (new ReflectionClass(UserCompanyRepository::class))->newInstanceWithoutConstructor();

    return new AdminService($userRepository, $userCompanyRepository);
}

function expectAdminInvalidArgument(callable $callback, string $expectedMessage): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        if (!str_contains($exception->getMessage(), $expectedMessage)) {
            throw new RuntimeException(
                'Unexpected exception message: ' . $exception->getMessage()
            );
        }

        return;
    }

    throw new RuntimeException('Expected InvalidArgumentException was not thrown.');
}
