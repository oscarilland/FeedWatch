<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

/**
 * One copy of a message arriving on one feed, with how long it took to
 * arrive if known.
 */
final class Delivery
{
    public function __construct(
        public readonly int $sequence,
        public readonly int $receivedUs,
        public readonly ?int $latencyUs = null,
    ) {
    }
}
