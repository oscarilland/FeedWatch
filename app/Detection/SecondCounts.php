<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

/**
 * A/B arbitration for one exchange in one second.
 */
final class SecondCounts
{
    public function __construct(
        public int $accepted = 0,
        public int $aWins = 0,
        public int $bWins = 0,
        public int $duplicates = 0,
    ) {
    }
}
