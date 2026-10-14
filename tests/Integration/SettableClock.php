<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Integration;

use DateTimeImmutable;
use FeedWatch\Infrastructure\Clock;

/**
 * A clock tests can move forward, in microseconds since the Unix epoch.
 */
final class SettableClock implements Clock
{
    public function __construct(public int $us)
    {
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->us += $seconds * 1_000_000;
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('@%d.%06d', intdiv($this->us, 1_000_000), $this->us % 1_000_000));
    }
}
