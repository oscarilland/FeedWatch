<?php

declare(strict_types=1);

namespace FeedWatch\Domain;

/**
 * A simulated exchange. All names are fictional.
 */
final class Exchange
{
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $name,
        public readonly string $city,
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly string $timeZone,
        public readonly int $latencyOffsetUs,
    ) {
    }
}
