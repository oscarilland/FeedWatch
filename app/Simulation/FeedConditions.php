<?php

declare(strict_types=1);

namespace FeedWatch\Simulation;

use InvalidArgumentException;

/**
 * Faults applied to one exchange's feed (FR-07 chaos). The simulator honours
 * them; storing and changing them is plan 007's job.
 */
final class FeedConditions
{
    /**
     * @param float $dropPercent share of messages lost at random, 0-100
     * @param int $extraDelayUs added to every delivery
     * @param ?int $burstFromSequence with $burstCount: drop exactly these messages
     */
    public function __construct(
        public readonly bool $silenced = false,
        public readonly float $dropPercent = 0.0,
        public readonly int $extraDelayUs = 0,
        public readonly ?int $burstFromSequence = null,
        public readonly int $burstCount = 0,
    ) {
        if ($dropPercent < 0 || $dropPercent > 100) {
            throw new InvalidArgumentException('dropPercent must be between 0 and 100');
        }
        if ($extraDelayUs < 0 || $burstCount < 0) {
            throw new InvalidArgumentException('extraDelayUs and burstCount cannot be negative');
        }
    }

    public static function normal(): self
    {
        return new self();
    }

    public function inBurst(int $sequence): bool
    {
        return $this->burstFromSequence !== null
            && $sequence >= $this->burstFromSequence
            && $sequence < $this->burstFromSequence + $this->burstCount;
    }
}
