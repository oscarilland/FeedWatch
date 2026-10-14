<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

use FeedWatch\Domain\Feed;
use FeedWatch\Simulation\LatencyModel;

/**
 * The latency the simulator is set up to produce, used as a feed's baseline
 * until there's enough history to learn one. A real monitor would start from
 * a configured guess in the same way.
 */
final class ExpectedLatency
{
    public static function us(Feed $feed, int $exchangeOffsetUs): int
    {
        return ($feed === Feed::A ? LatencyModel::A_BASE_US : LatencyModel::B_BASE_US) + $exchangeOffsetUs;
    }
}
