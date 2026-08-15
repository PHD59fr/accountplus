<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

final class LoginRateLimiter
{
    private const MAX_FAILURES = 5;
    private const MAX_NETWORK_FAILURES = 30;
    private const WINDOW_SECONDS = 900;

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{authenticated: bool, retry_after: int} */
    public function attempt(string $username, string $ip, callable $authenticate): array
    {
        $this->pdo->exec('DELETE FROM login_attempts WHERE expires_at <= NOW() LIMIT 100');
        $this->pdo->beginTransaction();
        try {
            // Serialize pair and network attempts in stable order across concurrent sessions.
            $keys = [$this->pairKey($username, $ip), $this->ipKey($ip)];
            sort($keys);
            $reserve = $this->pdo->prepare(
                'INSERT INTO login_attempts (attempt_key, failures, expires_at)
                 VALUES (:attempt_key, 0, DATE_ADD(NOW(), INTERVAL ' . self::WINDOW_SECONDS . ' SECOND))
                 ON DUPLICATE KEY UPDATE attempt_key = attempt_key'
            );
            foreach ($keys as $key) {
                $reserve->execute(['attempt_key' => $key]);
            }
            $retryAfter = $this->retryAfter($username, $ip);
            $authenticated = false;
            if ($retryAfter === 0) {
                $authenticated = (bool) $authenticate();
                if ($authenticated) {
                    $this->clearUser($username, $ip);
                } else {
                    $this->recordFailure($username, $ip);
                }
            }
            $this->pdo->commit();

            return ['authenticated' => $authenticated, 'retry_after' => $retryAfter];
        } catch (\Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }
    }

    public function retryAfter(string $username, string $ip): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(MAX(TIMESTAMPDIFF(SECOND, NOW(), expires_at)), 0)
             FROM login_attempts
             WHERE attempt_key IN (:user_key, :ip_key) AND ((attempt_key = :pair_match AND failures >= :max_failures)
                 OR (attempt_key = :network_match AND failures >= :network_max)) AND expires_at > NOW()'
        );
        $statement->execute([
            'user_key' => $this->pairKey($username, $ip),
            'ip_key' => $this->ipKey($ip),
            'max_failures' => self::MAX_FAILURES,
            'pair_match' => $this->pairKey($username, $ip),
            'network_match' => $this->ipKey($ip),
            'network_max' => self::MAX_NETWORK_FAILURES,
        ]);

        return max(0, (int) $statement->fetchColumn());
    }

    public function recordFailure(string $username, string $ip): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->exec('DELETE FROM login_attempts WHERE expires_at <= NOW() LIMIT 100');
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO login_attempts (attempt_key, failures, expires_at)
             VALUES (:attempt_key, 1, DATE_ADD(NOW(), INTERVAL ' . self::WINDOW_SECONDS . ' SECOND))
             ON DUPLICATE KEY UPDATE
                failures = IF(expires_at <= NOW(), 1, failures + 1),
                expires_at = IF(expires_at <= NOW(), DATE_ADD(NOW(), INTERVAL ' . self::WINDOW_SECONDS . ' SECOND), expires_at)'
        );
        foreach ([$this->pairKey($username, $ip), $this->ipKey($ip)] as $key) {
            $statement->execute(['attempt_key' => $key]);
        }
    }

    public function clearUser(string $username, string $ip): void
    {
        // Clear only this account/origin pair; retain the shared network budget.
        $this->pdo->prepare('DELETE FROM login_attempts WHERE attempt_key = :attempt_key')
            ->execute(['attempt_key' => $this->pairKey($username, $ip)]);
    }

    private function pairKey(string $username, string $ip): string
    {
        return hash('sha256', 'pair:' . strtolower(trim($username)) . ':' . $this->network($ip));
    }

    private function ipKey(string $ip): string
    {
        return hash('sha256', 'network:' . $this->network($ip));
    }

    private function network(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return 'invalid';
        }
        // Aggregate IPv6 privacy addresses in a /64; canonicalize IPv4-mapped IPv6.
        if (strlen($packed) === 16 && substr($packed, 0, 12) === hex2bin('00000000000000000000ffff')) {
            return bin2hex(substr($packed, 12));
        }
        return bin2hex(strlen($packed) === 16 ? substr($packed, 0, 8) : $packed);
    }
}
