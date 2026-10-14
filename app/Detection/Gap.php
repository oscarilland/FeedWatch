<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

/**
 * A classified gap, ready to store. Scope is "A", "B", or "AB" for data loss.
 */
final class Gap
{
    public function __construct(
        public readonly string $scope,
        public readonly GapClassification $classification,
        public readonly int $first,
        public readonly int $last,
        public readonly int $detectedUs,
    ) {
    }

    public function count(): int
    {
        return $this->last - $this->first + 1;
    }
}
