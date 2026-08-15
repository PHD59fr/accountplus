<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Service\InstallerService;
use App\Service\InstallationState;
use App\Support\Config;
use App\Support\Database;
use App\Support\View;
use Throwable;
use App\Support\SetupAuthorization;
use App\Support\SecurityError;

final class SetupController
{
    public function __construct(
        private View $view,
        private Config $config,
        private InstallationState $installationState,
        private ?InstallerService $installerService = null,
        private ?string $bootstrapError = null
    ) {
    }

    public function show(Request $request): Response
    {
        if (!SetupAuthorization::enabled()) {
            return Response::html($this->view->translate('setup.denied'), 403);
        }
        if ($this->installerService !== null && $this->installerService->hasUsers() && !$this->installationState->isInstalled()) {
            return Response::html(e(security_message('security.recovery')), 503);
        }
        if ($this->installationState->isInstalled()) {
            return Response::redirect('/');
        }

        return Response::html($this->view->render('setup/index', [
            'appName' => $this->config->get('app.name', 'Account+'),
            'error' => null,
            'installed' => $this->installationState->isInstalled(),
            'values' => $this->installationState->read(),
        ]));
    }

    public function install(Request $request): Response
    {
        if (!SetupAuthorization::enabled()) {
            return Response::html($this->view->translate('setup.denied'), 403);
        }
        if ($this->installerService !== null && $this->installerService->hasUsers() && !$this->installationState->isInstalled()) {
            return Response::html(e(security_message('security.recovery')), 503);
        }
        if ($this->installationState->isInstalled()) {
            return Response::redirect('/');
        }

        if (!SetupAuthorization::accepts($request->post('setup_token'))) {
            return Response::html($this->view->translate('setup.denied'), 403);
        }
        if (!csrf_validate($request->post('_csrf_token'))) {
            return Response::html(e(security_message('security.expired')), 419);
        }

        $payload = [
            'installed_at' => date(DATE_ATOM),
            'app_name' => trim((string) $request->input('app_name', 'Account+')),
            'admin_username' => trim((string) $request->input('admin_username', '')),
            'admin_password_hash' => '',
            'db_host' => trim((string) $request->input('db_host', '127.0.0.1')),
            'db_port' => (int) $request->input('db_port', 3306),
            'db_name' => trim((string) $request->input('db_name', 'compte')),
            'db_username' => trim((string) $request->input('db_username', 'compte')),
            'db_password' => (string) $request->input('db_password', ''),
        ];
        $adminPassword = (string) $request->input('admin_password', '');
        $adminPasswordConfirm = (string) $request->input('admin_password_confirm', '');

        if ($payload['admin_username'] === '') {
            return $this->renderSetup($this->view->translate('setup.error_admin_username'), $payload, 422, false);
        }

        if ($adminPassword === '' || strlen($adminPassword) < 8) {
            return $this->renderSetup($this->view->translate('setup.error_password_length'), $payload, 422, false);
        }

        if (!hash_equals($adminPassword, $adminPasswordConfirm)) {
            return $this->renderSetup($this->view->translate('setup.error_password_mismatch'), $payload, 422, false);
        }

        $payload['admin_password_hash'] = password_hash($adminPassword, PASSWORD_DEFAULT);

        try {
            $pdo = Database::connect([
                'driver' => 'mysql',
                'host' => $payload['db_host'],
                'port' => $payload['db_port'],
                'database' => $payload['db_name'],
                'username' => $payload['db_username'],
                'password' => $payload['db_password'],
                'charset' => 'utf8mb4',
            ]);
            $pdo->query('SELECT 1');
        } catch (Throwable $throwable) {
            return $this->renderSetup(
                $this->view->translate('setup.error_db_connection') . ' [' . SecurityError::report($throwable, 'setup.connection') . ']',
                $payload,
                422,
                false
            );
        }

        try {
            $installer = new InstallerService($pdo, dirname(__DIR__, 3) . '/app/database/migrations');
            $installer->install($payload);
            $this->installationState->markInitialized();
            $this->installationState->write($payload);
        } catch (Throwable $throwable) {
            return $this->renderSetup(
                $this->view->translate('setup.error_failed') . ' [' . SecurityError::report($throwable, 'setup.install') . ']',
                $payload,
                500,
                false
            );
        }

        return Response::redirect('/login');
    }

    /**
     * @param array<string, mixed> $values
     */
    private function renderSetup(string $error, array $values, int $status, bool $installed): Response
    {
        return Response::html($this->view->render('setup/index', [
            'appName' => $this->config->get('app.name', 'Account+'),
            'error' => $error,
            'installed' => $installed,
            'values' => $values,
        ]), $status);
    }
}
