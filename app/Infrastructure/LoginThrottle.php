<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

use FeedWatch\Http\LoginLimiter;
use PDO;

/**
 * Failed-login counting in the database (NFR-01 AC-4): 5 failures from one
 * address, or 20 from anywhere, within 15 minutes refuses further logins
 * until the window passes.
 */
final class LoginThrottle implements LoginLimiter
{
    public const int WINDOW_US = 15 * 60 * 1_000_000;
    public const int PER_ADDRESS = 5;
    public const int OVERALL = 20;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function isLocked(string $ipHash, int $nowUs): bool
    {
        $since = $nowUs - self::WINDOW_US;
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS overall, COALESCE(SUM(ip_hash = ?), 0) AS mine FROM login_attempts WHERE at_us >= ?'
        );
        $statement->execute([$ipHash, $since]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return false;
        }

        return Row::int($row, 'mine') >= self::PER_ADDRESS || Row::int($row, 'overall') >= self::OVERALL;
    }

    public function recordFailure(string $ipHash, int $nowUs): void
    {
        $statement = $this->pdo->prepare('INSERT INTO login_attempts (ip_hash, at_us) VALUES (?, ?)');
        $statement->execute([$ipHash, $nowUs]);
    }

    public function clear(string $ipHash): void
    {
        $statement = $this->pdo->prepare('DELETE FROM login_attempts WHERE ip_hash = ?');
        $statement->execute([$ipHash]);
    }

    /**
     * Removes attempts older than $cutoffUs (cron keeps a day).
     */
    public function pruneBefore(int $cutoffUs): int
    {
        $statement = $this->pdo->prepare('DELETE FROM login_attempts WHERE at_us < ?');
        $statement->execute([$cutoffUs]);

        return $statement->rowCount();
    }
}
