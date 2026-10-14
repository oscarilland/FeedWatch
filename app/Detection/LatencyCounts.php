<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

/**
 * One feed's latency in one second: enough to add seconds together and to
 * work out the mean.
 */
final class LatencyCounts
{
    public function __construct(
        public int $count = 0,
        public int $sumUs = 0,
        public int $maxUs = 0,
    ) {
    }

    public function add(int $latencyUs): void
    {
        $this->count++;
        $this->sumUs += $latencyUs;
        $this->maxUs = max($this->maxUs, $latencyUs);
    }

    public function meanUs(): ?float
    {
        return $this->count === 0 ? null : $this->sumUs / $this->count;
    }
}
