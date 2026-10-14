<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

/**
 * Everything the status rules look at for one exchange.
 */
final class HealthInputs
{
    public function __construct(
        public readonly bool $aSilent = false,
        public readonly bool $bSilent = false,
        public readonly int $messagesLostRecently = 0,
        public readonly int $lineGapsA = 0,
        public readonly int $lineGapsB = 0,
        public readonly LatencyLevel $latencyA = LatencyLevel::Normal,
        public readonly LatencyLevel $latencyB = LatencyLevel::Normal,
    ) {
    }
}
