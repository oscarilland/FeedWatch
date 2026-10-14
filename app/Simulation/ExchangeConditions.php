<?php

declare(strict_types=1);

namespace FeedWatch\Simulation;

use FeedWatch\Domain\Feed;

/**
 * The conditions on both of an exchange's feeds.
 */
final class ExchangeConditions
{
    public function __construct(
        public readonly FeedConditions $a,
        public readonly FeedConditions $b,
    ) {
    }

    public static function normal(): self
    {
        return new self(FeedConditions::normal(), FeedConditions::normal());
    }

    public function for(Feed $feed): FeedConditions
    {
        return $feed === Feed::A ? $this->a : $this->b;
    }
}
