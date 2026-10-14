<?php

declare(strict_types=1);

namespace FeedWatch\Simulation;

/**
 * Random numbers by random access: each number is a hash of the seed and a
 * key (exchange, sequence number, feed, purpose), not the next value from a
 * stream. Message 5,000 gets the same delay however the simulation is split
 * into catch-ups, and there's no generator state to save between requests.
 */
final class SeededRandom
{
    public function __construct(private readonly int $seed)
    {
    }

    /**
     * A number in [0, 1), always the same for the same seed and key.
     */
    public function float(string|int ...$key): float
    {
        $hash = hash('xxh128', $this->seed . '|' . implode('|', $key), true);
        $parts = unpack('J', $hash);
        $value = is_array($parts) && is_int($parts[1] ?? null) ? $parts[1] : 0;

        // The top 53 bits fill a float's mantissa exactly.
        return (($value >> 11) & 0x1F_FFFF_FFFF_FFFF) / 9_007_199_254_740_992;
    }
}
