<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

use FeedWatch\Domain\Feed;

/**
 * A period a feed delivered nothing for over the silence threshold. A null
 * end means it's still silent.
 */
final class Silence
{
    public function __construct(
        public readonly Feed $feed,
        public readonly int $startedUs,
        public readonly ?int $endedUs,
    ) {
    }
}
