<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Repository\CompanyRepository;
use App\Repository\ImportRepository;
use App\Service\AuthService;
use App\Service\AuditLogService;
use App\Service\CompanyContext;
use App\Service\ExportService;
use App\Service\ImportService;
use App\Support\Config;
use App\Support\View;
use InvalidArgumentException;

final class TransferController
{
    public function __construct(
        private View $view,
        private Config $config,
        private CompanyContext $companyContext,
        private ?AuthService $authService,
        private ?ImportRepository $importRepository = null,
        private ?ImportService $importService = null,
        private ?ExportService $exportService = null,
        private ?CompanyRepository $companyRepository = null,
        private string $storagePath = '',
        private ?AuditLogService $auditLogService = null
    ) {
    }

    public function index(Request $request): Response
    {
        if ($this->authService === null || !$this->authService->isAdmin()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $imports = $this->importRepository !== null ? $this->importRepository->recent(10) : [];
        $files = $this->recentFiles();

        return Response::html($this->view->render('admin/transfer', [
            'appName' => $this->config->get('app.name', 'Account+'),
            'currentCompany' => $this->companyContext->current(),
            'importSteps' => [
                ['title_key' => 'transfer.step_prepare', 'detail_key' => 'transfer.step_prepare_detail'],
                ['title_key' => 'transfer.step_upload', 'detail_key' => 'transfer.step_upload_detail'],
                ['title_key' => 'transfer.step_review', 'detail_key' => 'transfer.step_review_detail'],
            ],
            'importCommands' => [
                'php app/bin/console.php import:from-csv ./csv/ --output=import.zip',
                'php app/bin/console.php import:run import.zip --company=1',
            ],
            'artifacts' => [
                ['name' => 'manifest.json', 'role_key' => 'imports.artifact_manifest'],
                ['name' => 'accounts.ndjson', 'role_key' => 'imports.artifact_accounts'],
                ['name' => 'transactions.ndjson', 'role_key' => 'imports.artifact_transactions'],
                ['name' => 'categories.ndjson', 'role_key' => 'imports.artifact_categories'],
                ['name' => 'report.json', 'role_key' => 'imports.artifact_report'],
            ],
            'exportItems' => [
                ['title_key' => 'exports.item_accounts', 'detail_key' => 'exports.item_accounts_detail'],
                ['title_key' => 'exports.item_transactions', 'detail_key' => 'exports.item_transactions_detail'],
                ['title_key' => 'exports.item_archive', 'detail_key' => 'exports.item_archive_detail'],
            ],
            'imports' => $imports,
            'files' => $files,
            'companies' => $this->companyRepository !== null ? $this->companyRepository->all() : [],
            'importAvailable' => $this->importService !== null,
            'exportAvailable' => $this->exportService !== null,
            'stats' => [
                'imports' => count($imports),
                'exports' => count($files),
            ],
        ]));
    }

    public function import(Request $request): Response
    {
        if ($this->authService === null || !$this->authService->isAdmin()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $requestedCompanyId = (int) $request->input('company_id', 0);
        $company = $requestedCompanyId > 0 && $this->companyRepository !== null
            ? $this->companyRepository->find($requestedCompanyId)
            : $this->companyContext->current();
        if ($company === null) {
            flash('error', $this->view->translate('imports.no_company'));
            return Response::redirect('/admin/data');
        }

        if ($this->importService === null) {
            flash('error', $this->view->translate('flash.db_unavailable'));
            return Response::redirect('/admin/data');
        }

        $file = $request->file('import_file');
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            flash('error', $this->view->translate('imports.no_file'));
            return Response::redirect('/admin/data');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('error', $this->view->translate('imports.upload_error'));
            return Response::redirect('/admin/data');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_file($tmpName)) {
            flash('error', $this->view->translate('imports.upload_error'));
            return Response::redirect('/admin/data');
        }

        $originalName = basename((string) ($file['name'] ?? 'import.zip'));
        $directory = rtrim($this->storagePath, '/') . '/imports';
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $storedPath = tempnam($directory, 'upload-');
        if ($storedPath === false) {
            throw new \RuntimeException('Could not allocate upload file.');
        }

        if (!is_dir(dirname($storedPath))) {
            mkdir(dirname($storedPath), 0755, true);
        }
        if (!move_uploaded_file($tmpName, $storedPath)) {
            @unlink($storedPath);
            flash('error', $this->view->translate('imports.upload_error'));
            return Response::redirect('/admin/data');
        }

        chmod($storedPath, 0600);
        try {
            $force = (bool) $request->input('force', false);
            $result = $this->importService->run($storedPath, $company->id(), null, $force);
            @unlink($storedPath);

            $this->auditLogService?->log('import.run', 'import', null, null, [
                'file' => $originalName,
                'accounts' => $result['accounts_created'] ?? 0,
                'categories' => $result['categories_created'] ?? 0,
                'transactions' => $result['transactions_created'] ?? 0,
            ], $company->id());

            if (!empty($result['skipped'])) {
                flash('success', $result['message']);
            } else {
                $summary = $this->view->translate('imports.done_summary', [
                    'accounts' => (string) $result['accounts_created'],
                    'categories' => (string) $result['categories_created'],
                    'transactions' => (string) $result['transactions_created'],
                ]);
                flash('success', $summary);
                if ($result['errors'] !== []) {
                    foreach ($result['errors'] as $error) {
                        flash('error', $error);
                    }
                }
            }
        } catch (InvalidArgumentException $exception) {
            @unlink($storedPath);
            flash('error', $exception->getMessage());
        } catch (\Throwable $throwable) {
            @unlink($storedPath);
            flash('error', $this->view->translate('flash.db_unavailable'));
        }

        return Response::redirect('/admin/data');
    }

    public function export(Request $request): Response
    {
        if ($this->authService === null || !$this->authService->isAdmin()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $company = $this->companyContext->current();
        $requestedCompanyId = (int) $request->input('company_id', 0);
        if ($requestedCompanyId > 0 && $this->companyRepository !== null) {
            $chosen = $this->companyRepository->find($requestedCompanyId);
            if ($chosen !== null) {
                $company = $chosen;
            } elseif ($company === null) {
                flash('error', $this->view->translate('exports.no_company'));
                return Response::redirect('/admin/data');
            }
        }

        if ($company === null) {
            flash('error', $this->view->translate('exports.no_company'));
            return Response::redirect('/admin/data');
        }

        if ($this->exportService === null) {
            flash('error', $this->view->translate('flash.db_unavailable'));
            return Response::redirect('/admin/data');
        }

        try {
            $export = $this->exportService->exportCompany($company->id());
            $this->auditLogService?->log('export.create', 'export', null, null, [
                'file' => $export['filename'],
                'company_id' => $company->id(),
            ], $company->id());
            flash('success', $this->view->translate('exports.created', ['file' => $export['filename']]));
        } catch (InvalidArgumentException $exception) {
            flash('error', $exception->getMessage());
        }

        return Response::redirect('/admin/data');
    }

    public function download(Request $request): Response
    {
        if ($this->authService === null || !$this->authService->isAdmin()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $filename = $request->input('file', '');
        $directory = realpath(rtrim($this->storagePath, '/') . '/exports');
        $path = is_string($filename) && $directory !== false ? realpath($directory . '/' . $filename) : false;
        if (!is_string($filename) || preg_match('/^[A-Za-z0-9-][A-Za-z0-9._-]*\.compte\.zip$/D', $filename) !== 1
            || $path === false || dirname($path) !== $directory || is_link($directory . '/' . $filename)) {
            return Response::html('Not found', 404);
        }

        if ($filename === '' || !is_file($path) || !is_readable($path)) {
            flash('error', $this->view->translate('exports.download_missing'));
            return Response::redirect('/admin/data');
        }

        $this->auditLogService?->log('export.download', 'export', null, null, [
            'file' => $filename,
        ]);

        return Response::file(
            (string) file_get_contents($path),
            $filename,
            'application/zip'
        );
    }

    public function inspect(Request $request): Response
    {
        if ($this->authService === null || !$this->authService->isAdmin()) {
            return Response::html($this->view->renderError(403), 403);
        }

        $company = $this->companyContext->current();
        $importId = (int) $request->input('id', 0);

        if ($this->importRepository === null || $importId <= 0) {
            flash('error', $this->view->translate('flash.db_unavailable'));
            return Response::redirect('/admin/data');
        }

        $errors = $this->importRepository->errors($importId);

        return Response::html($this->view->render('admin/import_inspect', [
            'appName' => $this->config->get('app.name', 'Account+'),
            'currentCompany' => $company,
            'importId' => $importId,
            'errors' => $errors,
        ]));
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function recentFiles(): array
    {
        $directory = rtrim($this->storagePath, '/') . '/exports';
        if (!is_dir($directory)) {
            return [];
        }

        $files = glob($directory . '/*.compte.zip');
        if ($files === false) {
            return [];
        }

        rsort($files);
        $rows = [];
        foreach (array_slice($files, 0, 20) as $file) {
            $rows[] = [
                'name' => basename($file),
                'size' => $this->humanSize((int) filesize($file)),
                'date' => date('Y-m-d H:i:s', (int) filemtime($file)),
            ];
        }

        return $rows;
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2, '.', ',') . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1, '.', ',') . ' KB';
        }

        return $bytes . ' B';
    }
}
