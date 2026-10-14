<?php

declare(strict_types=1);

namespace FeedWatch\Http;

/**
 * Counts failed logins so password guessing is refused (NFR-01 AC-4).
 * Addresses are identified by a keyed hash, never stored as typed.
 */
interface LoginLimiter
{
    public function isLocked(string $ipHash, int $nowUs): bool;

    public function recordFailure(string $ipHash, int $nowUs): void;

    public function clear(string $ipHash): void;
}
