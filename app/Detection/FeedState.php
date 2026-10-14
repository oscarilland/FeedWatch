<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

/**
 * What the detector remembers about one feed between catch-ups.
 */
final class FeedState
{
    /**
     * @param list<PendingGap> $pending
     */
    public function __construct(
        public readonly int $nextExpected,
        public readonly ?int $lastReceivedUs,
        public readonly ?int $silentSinceUs = null,
        public readonly array $pending = [],
    ) {
    }

    public function isSilent(): bool
    {
        return $this->silentSinceUs !== null;
    }
}
