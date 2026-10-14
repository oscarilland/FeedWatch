<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

use Closure;
use PDO;
use Throwable;

final class PdoDatabaseCheck implements DatabaseCheck
{
    /**
     * @param Closure(): PDO $connect
     */
    public function __construct(private readonly Closure $connect)
    {
    }

    public function isHealthy(): bool
    {
        try {
            $result = ($this->connect)()->query('SELECT 1');

            return $result !== false && (int) $result->fetchColumn() === 1;
        } catch (Throwable $e) {
            // The detail goes to the server log only; callers just learn "error".
            error_log('FeedWatch health: database check failed: ' . $e::class . ': ' . $e->getMessage());

            return false;
        }
    }
}
