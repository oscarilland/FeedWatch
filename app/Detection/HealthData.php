<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

/**
 * Everything known about one exchange's health right now: the status
 * inputs, plus the times and counts incidents need. The status and the
 * incidents both come from this, so they can't disagree.
 */
final class HealthData
{
    /**
     * @param array<string, FeedLatency> $latency feed => recent latency against baseline
     * @param array<string, int> $silentSinceUs feed => when it went silent, for silent feeds only
     * @param array<string, int> $lineGaps feed => line gaps in the window
     */
    public function __construct(
        public readonly HealthInputs $inputs,
        public readonly array $latency = [],
        public readonly array $silentSinceUs = [],
        public readonly ?int $firstDataLossUs = null,
        public readonly ?int $lastDataLossUs = null,
        public readonly array $lineGaps = [],
    ) {
    }
}
