<?php

declare(strict_types=1);

namespace FeedWatch\Simulation;

use FeedWatch\Domain\Exchange;
use FeedWatch\Domain\Feed;

/**
 * Simulated one-way delay from exchange to monitor, per feed (FR-01).
 */
final class LatencyModel
{
    public const int A_BASE_US = 2_000;
    public const int B_BASE_US = 3_500;
    public const int JITTER_US = 300;
    public const float SPIKE_PROBABILITY = 0.005;
    public const int SPIKE_MIN_US = 5_000;
    public const int SPIKE_MAX_US = 20_000;

    public function latencyUs(SeededRandom $random, Exchange $exchange, int $sequence, Feed $feed): int
    {
        $base = $feed === Feed::A ? self::A_BASE_US : self::B_BASE_US;
        $jitter = (int) round(($random->float($exchange->code, $sequence, $feed->value, 'jitter') * 2 - 1) * self::JITTER_US);

        $spike = 0;
        if ($random->float($exchange->code, $sequence, $feed->value, 'spike') < self::SPIKE_PROBABILITY) {
            $size = $random->float($exchange->code, $sequence, $feed->value, 'spike-size');
            $spike = self::SPIKE_MIN_US + (int) round($size * (self::SPIKE_MAX_US - self::SPIKE_MIN_US));
        }

        return $base + $exchange->latencyOffsetUs + $jitter + $spike;
    }
}
