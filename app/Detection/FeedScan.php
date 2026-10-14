<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

/**
 * The result of scanning one feed's deliveries up to a horizon.
 */
final class FeedScan
{
    /**
     * @param list<FoundGap> $gaps gaps whose tolerance window has passed
     * @param list<Silence> $silences silences that ended, plus one still open at the horizon
     * @param array<int, LatencyCounts> $latency by Unix second of arrival
     */
    public function __construct(
        public readonly FeedState $state,
        public readonly array $gaps,
        public readonly array $silences,
        public readonly int $outOfOrder,
        public readonly int $duplicates,
        public readonly array $latency = [],
    ) {
    }
}
