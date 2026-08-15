<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Service\AuthService;
use App\Service\AuditLogService;
use App\Service\LoginRateLimiter;
use App\Support\Config;
use App\Support\View;

final class AuthController
{
    public function __construct(
        private View $view,
        private Config $config,
        private AuthService $authService,
        private LoginRateLimiter $loginRateLimiter,
        private ?AuditLogService $auditLogService = null
    ) {
    }

    public function showLogin(Request $request): Response
    {
        if ($this->authService->isAuthenticated()) {
            return Response::redirect('/');
        }

        return Response::html($this->view->render('auth/login', [
            'appName' => $this->config->get('app.name', 'Account+'),
            'error' => null,
            'username' => '',
        ]));
    }

    public function login(Request $request): Response
    {
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');
        if (strlen($username) > 255 || strlen($password) > 4096) {
            return $this->renderLogin($this->view->translate('login.error_invalid'), '', 422);
        }

        if (!$this->authService->isConfigured()) {
            return Response::redirect('/setup');
        }

        $ip = $request->clientIp();
        $result = $this->loginRateLimiter->attempt($username, $ip, fn (): bool => $this->authService->attempt($username, $password));
        $retryAfter = $result['retry_after'];
        if ($retryAfter > 0) {
            $minutes = (int) ceil($retryAfter / 60);

            return $this->renderLogin(
                $this->view->translate('login.error_lockout', ['minutes' => $minutes]),
                $username,
                429
            )->withHeader('Retry-After', (string) $retryAfter);
        }

        if ($result['authenticated']) {
            $this->auditLogService?->log('auth.login', 'auth');

            return Response::redirect('/');
        }

        return $this->renderLogin($this->view->translate('login.error_invalid'), $username, 422);
    }

    public function logout(Request $request): Response
    {
        $this->auditLogService?->log('auth.logout', 'auth');
        $this->authService->logout();

        return Response::redirect('/login');
    }

    /**
     * @return array<string, mixed>
     */
    private function renderLogin(string $error, string $username, int $status): Response
    {
        return Response::html($this->view->render('auth/login', [
            'appName' => $this->config->get('app.name', 'Account+'),
            'error' => $error,
            'username' => $username,
        ]), $status);
    }
}
