<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

/**
 * A run of sequence numbers skipped on a feed, waiting out the tolerance
 * window in case they're only out of order.
 */
final class PendingGap
{
    public function __construct(
        public readonly int $first,
        public readonly int $last,
        public readonly int $deadlineUs,
    ) {
    }

    public function contains(int $sequence): bool
    {
        return $sequence >= $this->first && $sequence <= $this->last;
    }

    /**
     * This gap with one late arrival removed: zero, one or two gaps.
     *
     * @return list<self>
     */
    public function without(int $sequence): array
    {
        $parts = [];
        if ($sequence > $this->first) {
            $parts[] = new self($this->first, $sequence - 1, $this->deadlineUs);
        }
        if ($sequence < $this->last) {
            $parts[] = new self($sequence + 1, $this->last, $this->deadlineUs);
        }

        return $parts;
    }
}
