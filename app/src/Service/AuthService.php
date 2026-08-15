<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\UserRepository;

final class AuthService
{
    private const SESSION_KEY = 'php_compte_user';

    private ?bool $authenticatedCache = null;

    private ?bool $adminCache = null;

    public function __construct(
        private UserRepository $userRepository
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->userRepository->count() > 0;
    }

    public function isAuthenticated(): bool
    {
        if ($this->authenticatedCache !== null) {
            return $this->authenticatedCache;
        }

        $session = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($session) || !isset($session['user_id'])) {
            return $this->authenticatedCache = false;
        }

        $user = $this->userRepository->findById((int) $session['user_id']);
        if ($user === null || !$user->isActive()) {
            unset($_SESSION[self::SESSION_KEY]);
            unset($_SESSION['php_compte_company_id']);

            return $this->authenticatedCache = false;
        }

        $_SESSION[self::SESSION_KEY] = [
            'user_id' => $user->id(),
            'username' => $user->username(),
            'role' => $user->role(),
            'locale' => $user->locale(),
            'logged_in_at' => $session['logged_in_at'] ?? date(DATE_ATOM),
        ];

        return $this->authenticatedCache = true;
    }

    public function attempt(string $username, string $password): bool
    {
        $user = $this->userRepository->findByUsername($username);
        if ($user === null || !$user->isActive()) {
            password_verify($password, '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
            return false;
        }

        if (!password_verify($password, $user->passwordHash())) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = [
            'user_id' => $user->id(),
            'username' => $user->username(),
            'role' => $user->role(),
            'locale' => $user->locale(),
            'logged_in_at' => date(DATE_ATOM),
        ];
        $this->authenticatedCache = true;
        $this->adminCache = $user->role() === 'ADMIN';
        $this->userRepository->updateLastLogin($user->id());

        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        $this->authenticatedCache = false;
        $this->adminCache = false;
    }

    public function username(): ?string
    {
        $session = $_SESSION[self::SESSION_KEY] ?? null;

        return is_array($session) && isset($session['username']) ? (string) $session['username'] : null;
    }

    public function userId(): ?int
    {
        $session = $_SESSION[self::SESSION_KEY] ?? null;

        return is_array($session) && isset($session['user_id']) ? (int) $session['user_id'] : null;
    }

    public function isAdmin(): bool
    {
        if ($this->adminCache !== null) {
            return $this->adminCache;
        }

        if (!$this->isAuthenticated()) {
            return $this->adminCache = false;
        }

        return $this->adminCache = (($_SESSION[self::SESSION_KEY]['role'] ?? '') === 'ADMIN');
    }

    public function locale(): ?string
    {
        $session = $_SESSION[self::SESSION_KEY] ?? null;

        return is_array($session) && isset($session['locale']) ? (string) $session['locale'] : null;
    }

    public function setLocale(string $locale): void
    {
        if (isset($_SESSION[self::SESSION_KEY]) && is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY]['locale'] = $locale;
        }
    }
}
