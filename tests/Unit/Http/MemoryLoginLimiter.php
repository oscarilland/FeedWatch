<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Http;

use FeedWatch\Http\LoginLimiter;

/**
 * Failed logins in memory, locking after 5 per address, for tests.
 */
final class MemoryLoginLimiter implements LoginLimiter
{
    /** @var array<string, int> */
    public array $failures = [];

    public function isLocked(string $ipHash, int $nowUs): bool
    {
        return ($this->failures[$ipHash] ?? 0) >= 5;
    }

    public function recordFailure(string $ipHash, int $nowUs): void
    {
        $this->failures[$ipHash] = ($this->failures[$ipHash] ?? 0) + 1;
    }

    public function clear(string $ipHash): void
    {
        unset($this->failures[$ipHash]);
    }
}
