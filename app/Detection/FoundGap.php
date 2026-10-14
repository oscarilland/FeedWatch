<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

use FeedWatch\Domain\Feed;

/**
 * A run of sequence numbers one feed never delivered, before checking the
 * other feed to classify it.
 */
final class FoundGap
{
    public function __construct(
        public readonly Feed $feed,
        public readonly int $first,
        public readonly int $last,
        public readonly int $detectedUs,
    ) {
    }
}
