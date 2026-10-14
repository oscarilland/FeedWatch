<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

/**
 * One feed's recent latency against its baseline, for the API.
 */
final class FeedLatency
{
    public function __construct(
        public readonly ?float $recentMeanUs,
        public readonly int $baselineUs,
        public readonly LatencyLevel $level,
    ) {
    }
}
