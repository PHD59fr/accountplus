<?php

declare(strict_types=1);

$basePath = __DIR__;

// The built-in PHP server uses this file as its router; Apache uses .htaccess.
if (PHP_SAPI === 'cli-server') {
    $path = rawurldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
    if (preg_match('#(^|/)\.|^/(app|src|config|templates|storage|database|docs|tests|tools|bin|docker|public)(/|$)|^/(config\.php|composer\.(json|lock)|README\.md|Dockerfile|docker-compose\.ya?ml)$#i', $path) === 1) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';
        return;
    }

    if ((str_starts_with($path, '/assets/') || $path === '/favicon.ico') && preg_match('/\.(css|js|map|png|jpe?g|gif|svg|ico|woff2?|ttf)$/iD', $path) === 1
        && is_file($basePath . $path)
        && str_starts_with(realpath($basePath . $path) ?: '', $basePath . '/assets/')) {
        return false;
    }
}

require_once $basePath . '/app/src/Support/helpers.php';
require_once $basePath . '/app/src/Support/Autoloader.php';

\App\Support\Autoloader::register($basePath . '/app');

$isDebug = false;

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new \ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (\Throwable $throwable) use (&$isDebug): void {
    $reference = \App\Support\SecurityError::report($throwable, 'http.failure');

    http_response_code(500);

    if ($isDebug) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $throwable::class . ': ' . $throwable->getMessage() . "\n\n" . $throwable->getTraceAsString();

        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>500</h1><p>' . e(security_message('security.internal')) . '</p><p>'
        . e(security_message('security.reference')) . ': ' . $reference . '</p>';
});

session_name('php_compte');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'secure' => \App\Http\Request::fromGlobals()->isSecure() || getenv('APP_SESSION_SECURE') === 'true',
    'samesite' => 'Lax',
]);
session_start();

header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'nonce-' . csp_nonce() . '\'; style-src \'self\' \'unsafe-inline\'; img-src \'self\' data:; object-src \'none\'; base-uri \'none\'; frame-ancestors \'self\'; form-action \'self\'');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cache-Control: no-store');
$hsts = getenv('APP_HSTS_MAX_AGE') ?: '';
if (\App\Http\Request::fromGlobals()->isSecure() && ctype_digit($hsts) && (int) $hsts > 0) {
    header('Strict-Transport-Security: max-age=' . min((int) $hsts, 31536000));
}

$config = \App\Support\Config::fromBasePath($basePath);
$isDebug = $config->get('app.env') === 'development'
    && getenv('APP_LOCAL_DEBUG') === 'true'
    && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    && (bool) $config->get('app.debug', false);
ini_set('display_errors', $isDebug ? '1' : '0');
ini_set('error_reporting', (string) E_ALL);

$view = new \App\Support\View($basePath . '/app/templates');
$kernel = new \App\Application\AppKernel($config, $view);
$request = \App\Http\Request::fromGlobals();

$kernel->handle($request)->send();
