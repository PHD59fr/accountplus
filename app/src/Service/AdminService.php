<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CompanyRepository;
use App\Repository\SettingsRepository;
use App\Repository\UserCompanyRepository;
use App\Repository\UserRepository;
use App\Support\DateFormatter;
use InvalidArgumentException;

final class AdminService
{
    public function __construct(
        private UserRepository $userRepository,
        private UserCompanyRepository $userCompanyRepository,
        private ?AuditLogService $auditLogService = null,
        private ?AuthService $authService = null,
        private ?CompanyRepository $companyRepository = null,
        private ?SettingsRepository $settingsRepository = null
    ) {
    }

    public function dateFormat(): string
    {
        if ($this->settingsRepository === null) {
            return DateFormatter::DEFAULT_FORMAT;
        }

        return $this->settingsRepository->get('date_format', DateFormatter::DEFAULT_FORMAT);
    }

    public function updateDateFormat(string $format): void
    {
        $format = trim($format);

        if ($format === '') {
            throw new InvalidArgumentException('Date format is required.');
        }

        if (
            strlen($format) > 64
            || preg_match('/^[A-Za-z0-9\s\-_\/\.:,]+$/', $format) !== 1
            || preg_match('/[djSmnYy]/', $format) !== 1
        ) {
            throw new InvalidArgumentException('Invalid date format. Use PHP date() tokens such as d, m, Y.');
        }

        if ($this->settingsRepository === null) {
            throw new InvalidArgumentException('Settings are not available.');
        }

        $old = $this->settingsRepository->get('date_format', DateFormatter::DEFAULT_FORMAT);
        $this->settingsRepository->set('date_format', $format);

        if ($this->auditLogService !== null) {
            $this->auditLogService->log(
                'settings.date_format.update',
                'settings',
                'date_format',
                ['date_format' => $old],
                ['date_format' => $format]
            );
        }
    }

    public function createUser(string $username, string $displayName, string $password, string $role): int
    {
        $username = trim($username);
        $displayName = trim($displayName);
        $role = strtoupper(trim($role));

        if ($username === '') {
            throw new InvalidArgumentException('Username is required.');
        }
        if ($displayName === '') {
            throw new InvalidArgumentException('Display name is required.');
        }
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('Password must contain at least 8 characters.');
        }
        if (!in_array($role, ['ADMIN', 'USER'], true)) {
            throw new InvalidArgumentException('Role must be ADMIN or USER.');
        }
        if ($this->userRepository->findByUsername($username) !== null) {
            throw new InvalidArgumentException('This username already exists.');
        }

        $userId = $this->userRepository->createUser(
            $username,
            password_hash($password, PASSWORD_DEFAULT),
            $displayName,
            $role
        );

        if ($this->auditLogService !== null) {
            $this->auditLogService->log(
                'user.create',
                'user',
                (string) $userId,
                null,
                [
                    'username' => $username,
                    'display_name' => $displayName,
                    'role' => $role,
                ]
            );
        }

        return $userId;
    }

    public function updateRole(int $userId, string $role): void
    {
        $role = strtoupper(trim($role));
        if (!in_array($role, ['ADMIN', 'USER'], true)) {
            throw new InvalidArgumentException('Role must be ADMIN or USER.');
        }

        $currentUserId = $this->authService !== null ? $this->authService->userId() : null;
        if ($currentUserId !== null && $userId === $currentUserId && $role !== 'ADMIN') {
            throw new InvalidArgumentException('You cannot revoke your own administrator role.');
        }

        $existing = $this->userRepository->findById($userId);
        if ($existing === null) {
            throw new InvalidArgumentException('User not found.');
        }

        if ($existing->role() === 'ADMIN' && $role !== 'ADMIN' && $this->userRepository->countAdmins() <= 1) {
            throw new InvalidArgumentException('At least one administrator must remain.');
        }

        $this->userRepository->updateRole($userId, $role);

        if ($this->auditLogService !== null) {
            $this->auditLogService->log(
                'user.role.update',
                'user',
                (string) $userId,
                ['role' => $existing->role()],
                ['role' => $role]
            );
        }
    }

    public function setUserActive(int $userId, bool $isActive): void
    {
        $currentUserId = $this->authService !== null ? $this->authService->userId() : null;
        if ($currentUserId !== null && $userId === $currentUserId) {
            throw new InvalidArgumentException('You cannot deactivate your own account.');
        }

        $existing = $this->userRepository->findById($userId);
        if ($existing === null) {
            throw new InvalidArgumentException('User not found.');
        }

        if ($existing->isActive() === $isActive) {
            return;
        }

        if (
            !$isActive
            && $existing->role() === 'ADMIN'
            && $this->userRepository->countAdmins() <= 1
        ) {
            throw new InvalidArgumentException('At least one administrator must remain active.');
        }

        $this->userRepository->updateActive($userId, $isActive);

        if ($this->auditLogService !== null) {
            $this->auditLogService->log(
                $isActive ? 'user.active.enable' : 'user.active.disable',
                'user',
                (string) $userId,
                ['is_active' => !$isActive],
                ['is_active' => $isActive]
            );
        }
    }

    public function assignCompany(int $userId, int $companyId): void
    {
        if ($userId <= 0 || $companyId <= 0) {
            throw new InvalidArgumentException('Select a user and a company.');
        }

        if ($this->userRepository->findById($userId) === null) {
            throw new InvalidArgumentException('User not found.');
        }

        if ($this->companyRepository === null || $this->companyRepository->find($companyId) === null) {
            throw new InvalidArgumentException('Company not found.');
        }

        $this->userCompanyRepository->assign($userId, $companyId);

        if ($this->auditLogService !== null) {
            $this->auditLogService->log(
                'user.company.assign',
                'user_company',
                $userId . ':' . $companyId,
                null,
                [
                    'user_id' => $userId,
                    'company_id' => $companyId,
                ],
                $companyId
            );
        }
    }
}
