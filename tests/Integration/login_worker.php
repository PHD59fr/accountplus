<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/src/Support/Autoloader.php';
App\Support\Autoloader::register(dirname(__DIR__, 2) . '/app');
$database = getenv('TEST_DB_DATABASE') ?: '';
if (preg_match('/^accountplus_test(?:_[a-z0-9]+)?$/', $database) !== 1) {
    throw new RuntimeException('A dedicated test database is required.');
}
$pdo = App\Support\Database::connect([
    'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
    'database' => $database,
    'username' => getenv('TEST_DB_USERNAME') ?: 'compte',
    'password' => getenv('TEST_DB_PASSWORD') ?: 'compte',
]);
$result = (new App\Service\LoginRateLimiter($pdo))->attempt('concurrent-worker', '192.0.2.99', static function (): bool {
    usleep(50_000); // Make overlap between independent PHP processes observable.
    return false;
});
echo json_encode($result, JSON_THROW_ON_ERROR);
